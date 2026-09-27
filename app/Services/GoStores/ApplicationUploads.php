<?php

namespace App\Services\GoStores;

use App\Services\PartnerEmailVerification;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Private, short-lived uploads authorized by the verified applicant, never public file URLs. */
class ApplicationUploads
{
    public const ROOT = 'go-store-signup';

    public function start(Request $request): array
    {
        $data = $request->validate([
            'mobile' => 'required|string|min:10|max:20', 'email' => 'required|email:rfc|max:254',
            'email_verification_token' => 'required|string|size:64',
        ]);
        app(Catalog::class)->ready();
        return app(PartnerEmailVerification::class)->consume($data['email_verification_token'], 'application', $data['mobile'], function ($proof) use ($data) {
            if ($proof['email'] !== strtolower(trim($data['email']))) $this->invalid();
            $token = Str::random(64);
            $record = ['mobile' => $proof['mobile'], 'email' => $proof['email'],
                'expires_at' => now()->addHours(2)->timestamp, 'files' => []];
            $this->save($this->directory($token), $record);
            return ['upload_token' => $token, 'expires_in' => 7200];
        });
    }

    public function upload(Request $request): array
    {
        $request->validate([
            'catalog_upload_token' => 'required|string|size:64',
            'images' => 'required|array|min:1|max:5',
            'images.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:1024|dimensions:max_width=4096,max_height=4096',
        ]);
        foreach (array_keys($request->file('images')) as $slot) {
            if (!preg_match('/^(logo|p(?:[0-9]|[1-5][0-9]))$/D', (string) $slot)) $this->invalid();
        }
        return $this->locked($request->input('catalog_upload_token'), function ($directory, $record) use ($request) {
            if (isset($record['receipt'])) $this->invalid();
            foreach ($request->file('images') as $slot => $image) {
                // A fixed slot can be retried/replaced without growing storage or changing product order.
                $filename = $slot.'.'.$image->extension();
                $old = $record['files'][$slot] ?? null;
                $stored = Storage::disk('local')->putFileAs($directory.'/images', $image, $filename);
                if (!$stored) abort(503, 'تعذر حفظ الصورة. حاول مرة أخرى.');
                $record['files'][$slot] = $filename;
                $this->save($directory, $record);
                if ($old && $old !== $filename) Storage::disk('local')->delete($directory.'/images/'.$old);
            }
            return ['uploaded' => array_keys($request->file('images'))];
        });
    }

    public function submit(Request $request, Closure $action)
    {
        $request->validate([
            'catalog_upload_token' => 'required|string|size:64',
            'mobile' => 'required|string|min:10|max:20', 'email' => 'required|email:rfc|max:254',
            'profession_key' => 'required|in:store_owner',
        ]);
        return $this->locked($request->input('catalog_upload_token'), function ($directory, $record) use ($request, $action) {
            if ($record['mobile'] !== PartnerEmailVerification::mobile($request->input('mobile'))
                || $record['email'] !== strtolower(trim($request->input('email')))) $this->invalid();
            if (isset($record['receipt'])) return response()->json($record['receipt']);
            $store = $request->input('storefront');
            if (is_string($store)) $store = json_decode($store, true);
            if (!is_array($store) || !isset($store['products']) || !is_array($store['products'])
                || count($store['products']) < 1 || count($store['products']) > 60
                || array_keys($store['products']) !== range(0, count($store['products']) - 1)) {
                throw ValidationException::withMessages(['storefront.products' => 'أضف من 1 إلى 60 منتجًا.']);
            }
            $request = Request::createFrom($request);
            $request->merge(['storefront' => $store]);
            $request->files->set('store_logo', $this->file($directory, $record, 'logo'));
            $images = [];
            foreach ($store['products'] as $index => $product) $images[] = $this->file($directory, $record, 'p'.$index);
            $request->files->set('product_images', $images);
            // This attribute is set only here, never from client input/headers.
            $request->attributes->set('go_staged_catalog', true);
            return Cache::lock('partner-application:'.hash('sha256', $record['mobile']), 180)->block(5, function () use ($action, $record, $directory, $request) {
                $response = DB::transaction(fn () => $action($record['email'], $request));
                $result = $response->getData(true);
                if (($result['status'] ?? null) === 'Success') {
                    $record['receipt'] = $result;
                    $this->save($directory, $record);
                    Storage::disk('local')->deleteDirectory($directory.'/images');
                }
                return $response;
            });
        });
    }

    private function file(string $directory, array $record, string $slot): UploadedFile
    {
        $filename = $record['files'][$slot] ?? null;
        if (!$filename || !Storage::disk('local')->exists($directory.'/images/'.$filename)) {
            throw ValidationException::withMessages(['product_images' => 'لم يكتمل رفع الصور. حاول الإرسال مرة أخرى.']);
        }
        return new UploadedFile(Storage::disk('local')->path($directory.'/images/'.$filename), $filename, null, null, true);
    }

    private function locked(string $token, Closure $action)
    {
        $directory = $this->directory($token);
        return Cache::lock('go-store-upload:'.hash('sha256', $token), 180)->block(5, function () use ($directory, $action) {
            $disk = Storage::disk('local');
            $record = $disk->exists($directory.'/manifest.json') ? json_decode($disk->get($directory.'/manifest.json'), true) : null;
            if (!$record || $record['expires_at'] <= now()->timestamp) $this->invalid();
            return $action($directory, $record);
        });
    }

    private function directory(string $token): string
    {
        return self::ROOT.'/'.hash('sha256', $token);
    }

    private function save(string $directory, array $record): void
    {
        if (!Storage::disk('local')->put($directory.'/manifest.json', json_encode($record))) abort(503);
    }

    public function prune(): int
    {
        $disk = Storage::disk('local');
        $count = 0;
        foreach ($disk->directories(self::ROOT) as $directory) {
            if (!preg_match('/^[a-f0-9]{64}$/D', basename($directory))) continue;
            $record = $disk->exists($directory.'/manifest.json') ? json_decode($disk->get($directory.'/manifest.json'), true) : null;
            // Extra hour avoids racing a final request that started just before expiry.
            if ($record && ($record['expires_at'] ?? PHP_INT_MAX) < now()->subHour()->timestamp) {
                $disk->deleteDirectory($directory);
                $count++;
            }
        }
        return $count;
    }

    private function invalid(): void
    {
        throw ValidationException::withMessages(['catalog_upload_token' => 'جلسة رفع الصور غير صالحة أو انتهت. أكد البريد مرة أخرى؛ بيانات المنتجات ما زالت محفوظة في الشاشة.']);
    }
}
