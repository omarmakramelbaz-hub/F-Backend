<?php

namespace App\Http\Requests\Api\Shipping;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Http\Traits\ApiResponses;


class ShippingRequest extends FormRequest
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
            'from_lat'=>'required',
            'to_lat'=>'required',
            'from_lng'=>'required',
            'to_lng'=>'required',
            'from_address'=>'required',
            'to_address'=>'required',
            'actual_price'=>'required|numeric|gt:0|max:100000', // a price is never negative or zero
            'expected_price'=>'required|numeric|gt:0|max:100000',
            'description'=>'required',
            'payment_type'=>'required|in:wallet,cash,v_cash,online'

        ];
    }
    
    public function attributes() {
        return [
            'from_lat'=>__('main.from_lat'),
            'to_lat'=>__('main.to_lat'),
            'from_lng'=>__('main.from_lng'),
            'to_lng'=>__('main.to_lng'),
            'from_address'=>__('main.from_address'),
            'to_address'=>__('main.to_address'),
            'expected_price'=>__('main.expected_price'),
            'actual_price'=>__('main.actual_price'),
            'description'=>__('main.description'),
            'payment_type'=>__('main.payment_type'),
            

        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException($this->errorResponse($validator->errors()->first()));

    }
}
