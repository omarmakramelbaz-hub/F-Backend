<?php

namespace App\Services;

use App\Mail\PartnerVerificationCode;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Email ownership only. These challenges never verify ownership of a phone. */
class PartnerEmailVerification
{
    public static function mobile(string $value): string
    {
        $mobile = preg_replace('/\D+/', '', $value);
        if (substr($mobile, 0, 2) === '20' && strlen($mobile) > 10) {
            $mobile = substr($mobile, 2);
        }
        return ltrim($mobile, '0');
    }

    public function issue(string $purpose, string $mobile, ?string $email, ?int $subjectId, string $ip): array
    {
        $mobile = self::mobile($mobile);
        if (strlen($mobile) < 10 || strlen($mobile) > 15) {
            throw ValidationException::withMessages(['mobile' => 'اكتب رقم هاتف صحيحًا.']);
        }
        $email = $email ? strtolower(trim($email)) : null;
        $identity = hash('sha256', $purpose.'|'.$mobile);
        return Cache::lock('partner-email:send-lock:'.$identity, 45)->block(5, function () use ($purpose, $mobile, $email, $subjectId, $ip, $identity) {
            // Count unknown accounts too; the response does not disclose account existence.
            $this->limit('cooldown:'.$identity, 1, 60);
            $this->limit('mobile:'.hash('sha256', $mobile), 5, 3600);
            $this->limit('ip:'.hash('sha256', $ip), 20, 3600);
            if ($email) {
                $this->limit('email:'.hash('sha256', $email), 5, 3600);
            }
            $id = (string) Str::uuid();
            $code = (string) random_int(100000, 999999);
            $record = compact('purpose', 'mobile', 'email', 'subjectId', 'identity');
            $record['hash'] = hash_hmac('sha256', $id.'|'.$code, config('app.key'));
            $record['attempts'] = 0;
            $record['expires_at'] = now()->addMinutes(10)->timestamp;
            if ($email) {
                $mailer = config('partner_auth.mailer');
                $transport = config('mail.mailers.'.$mailer.'.transport');
                if (!app()->environment('testing') && in_array($transport, [null, 'log', 'array'], true)) {
                    throw new HttpException(503, 'خدمة إرسال البريد غير جاهزة. حاول لاحقًا أو تواصل مع الدعم.');
                }
                try {
                    Mail::mailer($mailer)->to($email)->send(new PartnerVerificationCode($code));
                } catch (\Throwable $e) {
                    // Do not log SMTP credentials, addresses, or OTP content.
                    throw new HttpException(503, 'تعذر إرسال كود البريد الآن. حاول لاحقًا.');
                }
            }
            $activeKey = 'partner-email:active:'.$identity;
            if ($previous = Cache::get($activeKey)) {
                Cache::forget('partner-email:challenge:'.$previous);
            }
            Cache::put('partner-email:challenge:'.$id, $record, 600);
            Cache::put($activeKey, $id, 600);
            return ['challenge_id' => $id, 'expires_in' => 600, 'resend_after' => 60];
        });
    }

    public function verify(string $id, string $code): string
    {
        return Cache::lock('partner-email:verify-lock:'.$id, 10)->block(5, function () use ($id, $code) {
            $key = 'partner-email:challenge:'.$id;
            $record = Cache::get($key);
            if (!$record || $record['expires_at'] <= now()->timestamp || $record['attempts'] >= 5
                || Cache::get('partner-email:active:'.$record['identity']) !== $id) {
                $this->invalidCode();
            }
            $record['attempts']++;
            Cache::put($key, $record, max(1, $record['expires_at'] - now()->timestamp));
            if (!$record['email'] || !hash_equals($record['hash'], hash_hmac('sha256', $id.'|'.$code, config('app.key')))) {
                $this->invalidCode();
            }
            Cache::forget($key);
            $proof = Str::random(64);
            unset($record['hash'], $record['attempts']);
            $record['expires_at'] = now()->addMinutes(10)->timestamp;
            Cache::put('partner-email:proof:'.hash('sha256', $proof), $record, 600);
            return $proof;
        });
    }

    /** Hold the proof lock until the database transaction commits; never reuse a proof. */
    public function consume(string $proof, string $purpose, string $mobile, Closure $action)
    {
        $key = 'partner-email:proof:'.hash('sha256', $proof);
        return Cache::lock($key.':lock', 60)->block(5, function () use ($key, $purpose, $mobile, $action) {
            $record = Cache::get($key);
            if (!$record || $record['expires_at'] <= now()->timestamp || $record['purpose'] !== $purpose
                || $record['mobile'] !== self::mobile($mobile)) {
                throw ValidationException::withMessages(['email_verification_token' => 'أكد البريد الإلكتروني مرة أخرى لإكمال العملية.']);
            }
            // Burn before running the action: even a lost HTTP response cannot replay it.
            Cache::forget($key);
            return DB::transaction(function () use ($action, $record) { return $action($record); });
        });
    }

    private function limit(string $key, int $max, int $seconds): void
    {
        $key = 'partner-email:rate:'.$key;
        Cache::lock($key.':lock', 5)->block(3, function () use ($key, $max, $seconds) {
            $value = Cache::get($key, ['count' => 0, 'until' => now()->timestamp + $seconds]);
            if ($value['count'] >= $max && $value['until'] > now()->timestamp) {
                throw new HttpException(429, 'تم إرسال طلبات كثيرة. انتظر قليلًا ثم حاول مرة أخرى.', null, ['Retry-After' => (string) max(1, $value['until'] - now()->timestamp)]);
            }
            $value['count']++;
            Cache::put($key, $value, max(1, $value['until'] - now()->timestamp));
        });
    }

    private function invalidCode(): void
    {
        throw ValidationException::withMessages(['code' => 'الكود غير صحيح أو انتهت صلاحيته. اطلب كودًا جديدًا.']);
    }
}
