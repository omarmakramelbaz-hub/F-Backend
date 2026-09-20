<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Http\Traits\ApiResponses;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
        $scope = $this->header('X-App-Scope') === 'go' ? 'go' : 'fasakhansta';

        return [
            'account_type'          => 'required',
            'name'                  => 'required|string|min:2|max:150',
            'password'              => 'required|min:6|confirmed',
            'country_code'          => 'required|numeric',
            'email'                 => [
                'required',
                'email',
                Rule::unique('users', 'email')->where(function ($query) use ($scope) {
                    return $query->where('account_type', 'user')
                        ->where('app_scope', $scope);
                }),
            ],
            'mobile'                =>'required|numeric|digits:10',
            'fcm_id'                =>'sometimes|nullable|string',
        ];
    }
    
    public function attributes() {
        return [
            'name'                  => trans('main.name'),
            'mobile'                => trans('main.mobile'),
            'email'                 => trans('main.email'),
            'password'              => trans('main.password'),
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException($this->errorResponse($validator->errors()->first()));

    }

    protected function prepareForValidation()
    {
        $this->merge([
            'mobile' => ltrim($this->mobile,0),
        ]);
    }
}
