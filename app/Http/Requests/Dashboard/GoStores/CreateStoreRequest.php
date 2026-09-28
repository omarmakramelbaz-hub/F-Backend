<?php

namespace App\Http\Requests\Dashboard\GoStores;

use Illuminate\Foundation\Http\FormRequest;

class CreateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = auth('admin')->user();
        return $admin && $admin->account_type === 'admin'
            && ((int) $admin->id === 1 || ($admin->can('resturant-list') && $admin->can('resturant-create')));
    }

    protected function prepareForValidation(): void
    {
        foreach (['owner_name', 'name', 'address', 'email'] as $key) {
            if (is_string($this->input($key))) $this->merge([$key => trim($this->input($key))]);
        }
        if (is_string($this->input('email'))) $this->merge(['email' => strtolower($this->input('email')) ?: null]);
        if (is_string($this->input('mobile'))) {
            $digits = array_combine(preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789'));
            $mobile = preg_replace('/[\s()\-]+/u', '', strtr($this->input('mobile'), $digits));
            if (preg_match('/^(?:\+20|0020|20|0)?(1[0125]\d{8})$/D', $mobile, $match)) $mobile = $match[1];
            $this->merge(['mobile' => $mobile]);
        }
    }

    public function rules(): array
    {
        return [
            'owner_name' => 'required|string|min:2|max:130',
            'mobile' => ['required', 'string', 'regex:/^1[0125]\d{8}$/D'],
            'email' => 'nullable|email:rfc|max:254',
            'password' => 'required|string|min:8|max:72|confirmed',
            'name' => 'required|string|min:2|max:150',
            'kind' => 'required|in:supermarket,restaurant,pharmacy,clinic',
            'address' => 'required|string|min:5|max:500',
            'commission_rate' => ['required', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/D'],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.regex' => 'اكتب رقم موبايل مصري صحيحًا، مثل 01012345678.',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن 8 أحرف.',
            'commission_rate.between' => 'نسبة خدمة التطبيق يجب أن تكون بين 0 و100%.',
            'commission_rate.regex' => 'اكتب النسبة بحد أقصى رقمين بعد العلامة العشرية.',
        ];
    }

    public function attributes(): array
    {
        return ['owner_name'=>'اسم صاحب المتجر', 'mobile'=>'رقم الموبايل', 'email'=>'البريد الإلكتروني',
            'password'=>'كلمة المرور', 'name'=>'اسم المتجر', 'kind'=>'نوع النشاط',
            'address'=>'العنوان بالتفصيل', 'commission_rate'=>'نسبة خدمة التطبيق'];
    }
}
