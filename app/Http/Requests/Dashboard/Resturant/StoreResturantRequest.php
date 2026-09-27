<?php

namespace App\Http\Requests\Dashboard\Resturant;

use Illuminate\Foundation\Http\FormRequest;

class StoreResturantRequest extends FormRequest
{
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
            'service_fees' => ['sometimes', 'required', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/D'],
            'added_by' => 'required|exists:users,id',
            'name' => 'required|string|min:3|max:500',
            'status' => 'required|string|in:opened,busy,closed,hide',

        ];
    }
}