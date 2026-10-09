<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\TakeawayAccess;
use App\Services\Dashboard\TakeawayCatalog;
use App\Services\Dashboard\WhatsAppInboxAccess;
use App\Services\Dashboard\WhatsAppOrderWorkflow;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Central review only; checkout identities and prices are resolved by the workflow. */
class WhatsAppOrderController extends Controller
{
    public function meta(WhatsAppInboxAccess $access)
    {
        return $this->respond($access, false, function ($actor) {
            $branches = [];
            foreach (app(TakeawayAccess::class)->branches($actor) as $branch) {
                if (($branch['kind'] ?? null) === 'f' && preg_match('/\Af:[1-9][0-9]{0,18}\z/', $branch['value'] ?? '')) {
                    $branches[] = ['value' => $branch['value'], 'name' => (string) ($branch['name'] ?? '')];
                }
            }
            $configuration = config('whatsapp_orders', []);
            $configuration = is_array($configuration) ? $configuration : [];
            $enabled = in_array($configuration['enabled'] ?? false, [true, 1, '1'], true);
            $key = $configuration['api_key'] ?? null;
            $model = $configuration['model'] ?? null;
            $configured = $enabled && is_string($key) && strlen($key) >= 16 && strlen($key) <= 512
                && !preg_match('/\s|[\x00-\x1f\x7f]/', $key) && is_string($model)
                && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,79}\z/', $model);
            unset($key, $model, $configuration);
            return ['success' => true, 'available' => $this->available(), 'ai_ready' => (bool) $configured,
                'mode' => config('whatsapp_orders.mode') === 'auto' ? 'auto' : 'review',
                'can_checkout' => (bool) (app(TakeawayAccess::class)->permissions($actor)['can_checkout'] ?? false),
                'branches' => $branches, 'urls' => ['catalog' => route('whatsapp-orders.catalog'),
                    'conversations_base' => url('/admin/whatsapp/conversations'), 'drafts_base' => url('/admin/whatsapp/orders')]];
        });
    }

    public function catalog(Request $request, WhatsAppInboxAccess $access)
    {
        return $this->respond($access, false, function ($actor) use ($request) {
            $values = $request->validate(['branch' => ['required', 'string', 'regex:/\Af:[1-9][0-9]{0,18}\z/'],
                'search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1|max:100000',
                'per_page' => 'nullable|integer|min:1|max:100']);
            $values['page'] = (int) ($values['page'] ?? 1);
            $values['per_page'] = (int) ($values['per_page'] ?? 100);
            $result = app(TakeawayCatalog::class)->listing($values, $actor);
            $items = [];
            foreach ($result['items'] ?? [] as $item) {
                $options = [];
                foreach ($item['options'] ?? [] as $option) {
                    $options[] = ['id' => (string) $option['id'], 'label' => (string) ($option['label'] ?? ''),
                        'price' => $option['price'] ?? null];
                }
                $items[] = ['id' => (int) $item['id'], 'name' => (string) ($item['name'] ?? ''),
                    'price' => $item['price'] ?? null, 'available' => (bool) ($item['available'] ?? false),
                    'options' => $options];
            }
            return ['success' => true, 'items' => $items, 'pagination' => $result['pagination'] ?? null];
        });
    }

    public function state(string $conversation, WhatsAppInboxAccess $access)
    {
        return $this->respond($access, false, function ($actor) use ($conversation) {
            $id = $this->id($conversation);
            if (!$this->available()) return ['success' => true, 'available' => false, 'drafts' => [], 'pending_analysis' => false];
            return app(WhatsAppOrderWorkflow::class)->state($id, $actor);
        });
    }

    public function analyze(Request $request, string $conversation, WhatsAppInboxAccess $access)
    {
        return $this->respond($access, true, function ($actor) use ($request, $conversation) {
            abort_unless($this->available(), 503);
            $values = $request->validate(['force' => 'nullable|boolean']);
            return app(WhatsAppOrderWorkflow::class)->analyze($this->id($conversation), $actor, (bool) ($values['force'] ?? false));
        });
    }

    public function quote(Request $request, string $draft, WhatsAppInboxAccess $access)
    {
        return $this->respond($access, true, function ($actor) use ($request, $draft) {
            abort_unless($this->available(), 503);
            return app(WhatsAppOrderWorkflow::class)->quote($this->id($draft), $this->review($request, false), $actor);
        });
    }

    public function submitOrder(Request $request, string $draft, WhatsAppInboxAccess $access)
    {
        return $this->respond($access, true, function ($actor) use ($request, $draft) {
            abort_unless($this->available(), 503);
            return app(WhatsAppOrderWorkflow::class)->dispatch($this->id($draft), $this->review($request, true), $actor);
        });
    }

    private function review(Request $request, bool $dispatch): array
    {
        $rules = ['expected_revision' => 'required|integer|min:1|max:' . PHP_INT_MAX,
            'branch' => ['required', 'string', 'regex:/\Af:[1-9][0-9]{0,18}\z/'],
            'customer_name' => 'required|string|max:100', 'customer_phone' => 'required|string|max:30',
            'address' => 'required|string|max:500', 'area' => 'nullable|string|max:150',
            'delivery_notes' => 'nullable|string|max:500', 'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180', 'location_confirmed' => 'required|accepted',
            'items' => 'required|array|min:1|max:60', 'items.*.product_id' => 'required|integer|min:1|max:' . PHP_INT_MAX,
            'items.*.quantity' => ['required', 'string', 'regex:/\A[0-9]{1,4}(?:\.[0-9]{1,3})?\z/'],
            'items.*.quantity_mode' => 'required|in:piece,weight',
            'items.*.feature_id' => 'nullable|integer|min:0|max:' . PHP_INT_MAX,
            'items.*.product_clean' => 'nullable|in:extra_clear,extra_clean,extra_vacuim',
            'items.*.option_id' => ['nullable', 'string', 'max:80', 'regex:/\Af:[0-9]{1,19}:(?:base|extra_clear|extra_clean|extra_vacuim)\z/']];
        if ($dispatch) {
            $rules['quote_hash'] = ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'];
            $rules['delivery_quote_hash'] = ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'];
        }
        $values = $request->validate($rules);
        // Laravel's parent array validation may retain extra item keys; explicitly bound the workflow input.
        $values['items'] = array_map(function ($item) {
            return \Illuminate\Support\Arr::only($item, ['product_id', 'quantity', 'quantity_mode', 'option_id', 'feature_id', 'product_clean']);
        }, $values['items']);
        return $values;
    }

    private function id(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless(is_int($id), 404);
        return $id;
    }

    private function available(): bool
    {
        return class_exists(WhatsAppOrderWorkflow::class) && app(WhatsAppOrderWorkflow::class)->available();
    }

    private function respond(WhatsAppInboxAccess $access, bool $write, callable $callback)
    {
        $headers = ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff'];
        try {
            $actor = $access->actor(auth('admin')->user());
            abort_unless($access->available(), 503);
            if ($write) abort_unless(app(TakeawayAccess::class)->permissions($actor)['can_checkout'] ?? false, 403);
            return response()->json($callback($actor))->withHeaders($headers);
        } catch (ValidationException $error) {
            $status = 422;
        } catch (HttpException $error) {
            $status = in_array($error->getStatusCode(), [401, 403, 404, 409, 422, 503], true) ? $error->getStatusCode() : 503;
        } catch (\Throwable $error) {
            $status = 503;
        }
        return response()->json(['success' => false, 'error' => $status === 409 ? 'REVIEW_CHANGED' : 'ORDER_REVIEW_UNAVAILABLE'], $status)
            ->withHeaders($headers);
    }
}
