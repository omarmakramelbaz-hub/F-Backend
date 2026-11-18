<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A customer may change only their own Fasakhansta app messaging choices. */
class AppCustomerConsent
{
    public const VERSION = '2026-10-17.v1';
    private CustomerDirectory $directory;

    public function __construct(CustomerDirectory $directory)
    {
        $this->directory = $directory;
    }

    public function update(array $values, ?object $authenticatedUser, string $requestedScope = ''): array
    {
        abort_unless($authenticatedUser && (int)($authenticatedUser->id ?? 0) > 0, 401);
        // There is deliberately no client-supplied phone, customer ID or actor.
        $allowed = ['whatsapp_consent_version', 'service_consent', 'marketing_consent'];
        foreach (array_keys($values) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw ValidationException::withMessages([$field=>'هذا الحقل غير مسموح في إعدادات رسائلك.']);
            }
        }
        $v = Validator::make($values, [
            'whatsapp_consent_version'=>'required|string|in:'.self::VERSION,
            'service_consent'=>'sometimes|required|boolean',
            'marketing_consent'=>'sometimes|required|boolean',
        ])->validate();
        abort_unless($this->directory->ready(), 503, 'إعدادات متابعة العملاء غير جاهزة بعد.');

        return DB::transaction(function () use ($v, $authenticatedUser, $requestedScope) {
            // Reload under a lock: a stale authenticated object cannot select the
            // previous number after a completed change-phone request.
            $user = DB::table('users')->where('id', (int)$authenticatedUser->id)->lockForUpdate()->first();
            abort_unless($user, 401);
            abort_unless(($user->account_type ?? '') === 'user' && ($user->status ?? '') !== 'declined', 403);
            // A generic GO registration is not consent to Fasakhansta messages.
            abort_unless(in_array($requestedScope, ['', 'fasakhansta'], true)
                && in_array($user->app_scope ?? '', ['', 'fasakhansta'], true), 403);
            $phone = CustomerDirectory::normalizePhone((string)($user->mobile ?? ''));
            if ($phone === null) {
                throw ValidationException::withMessages(['mobile'=>'حدّث رقم الهاتف المحفوظ بحسابك أولاً.']);
            }
            $customer = DB::table('crm_customers')->where('phone', $phone)->lockForUpdate()->first();
            if (!array_key_exists('service_consent', $v) && !array_key_exists('marketing_consent', $v)) {
                return $this->present($customer, false);
            }
            if (!$customer) $customer = $this->directory->capture(['phone'=>$phone, 'name'=>(string)($user->name ?? '')], 'app', '');
            $service = $this->choice($v, 'service', $customer);
            $marketing = $this->choice($v, 'marketing', $customer);
            if ($service === null && $marketing === null) return $this->present($customer, false);
            $evidence = 'customer-self-choice:authenticated-app-form:'.self::VERSION.':fasakhansta:user:'.$user->id;
            $customer = $this->directory->consent((int)$customer->id, $service, $marketing, $evidence, (int)$user->id);
            return $this->present($customer, true);
        }, 3);
    }

    private function choice(array $values, string $purpose, object $customer): ?bool
    {
        $field = $purpose.'_consent';
        if (!array_key_exists($field, $values)) return null;
        $choice = (bool)$values[$field];
        // An explicit positive choice is needed to reopen messages after STOP.
        if ($choice && ($customer->opted_out_at || !$customer->{$purpose.'_consented_at'})) return true;
        return $choice === (bool)$customer->$field ? null : $choice;
    }

    private function present(?object $customer, bool $changed): array
    {
        $stopped = $customer && $customer->opted_out_at !== null;
        return [
            'service_consent'=>$customer && !$stopped && (bool)$customer->service_consent,
            'marketing_consent'=>$customer && !$stopped && (bool)$customer->marketing_consent,
            'opted_out_at'=>$customer->opted_out_at ?? null,
            'whatsapp_consent_version'=>self::VERSION,
            'changed'=>$changed,
        ];
    }
}
