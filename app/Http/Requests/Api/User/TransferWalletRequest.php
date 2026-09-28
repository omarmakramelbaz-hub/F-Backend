<?php

namespace App\Http\Requests\Api\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Http\Traits\ApiResponses;
use Carbon\Carbon;

class TransferWalletRequest extends FormRequest
{
  use ApiResponses;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'mobile' => 'required|string|regex:/^[+0-9 ()-]{7,24}$/',
            'amount' => ['required', 'numeric', 'min:1', 'max:5000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'target_wallet' => 'required|in:go_customer,go_partner,fasakhansta_customer',
            'recipient_id' => ($this->is('api/transfer/wallet') ? 'required' : 'sometimes').'|integer|min:1',
            'transfer_token' => ($this->is('api/transfer/wallet') ? 'required' : 'sometimes').'|string|max:4096',
        ];
    }

    public function messages()
    {
        return ['target_wallet.required' => __('wallet_transfer.select_wallet'),
            'target_wallet.in' => __('wallet_transfer.select_wallet'),
            'recipient_id.required' => __('wallet_transfer.confirm_again'),
            'transfer_token.required' => __('wallet_transfer.confirm_again')];
    }
    
    public function attributes() {
        return [
            'amount'               => trans('main.amount'),
            'mobile'               => trans('main.mobile'),
            'account_type'               => trans('main.account_type'),

        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException($this->errorResponse($validator->errors()->first(), 422));

    }
}
