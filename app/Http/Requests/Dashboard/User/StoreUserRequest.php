<?php

namespace App\Http\Requests\Dashboard\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
        // dd(request()->pending_vendor_id);
        return [
            'delegate_fees' => ['sometimes', 'required', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/D'],
            'name' => ['required','min:2', 'max:130'],
            'mobile' => ['sometimes','nullable','required_if:account_type,==,user','required_if:account_type,==,vendor','required_if:account_type,==,delegate','numeric','digits:10'],
            'email' => ['sometimes','nullable','required_if:account_type,==,admin','email'],
            'photo_profile' => 'sometimes|nullable|image',
            // 'gender' => 'sometimes','nullable','required_if:account_type,==,user|string',
            // 'roles_name' => 'sometimes','nullable','required_if:account_type,==,admin|array',
            // 'roles_name.*.0' => 'sometimes','nullable','required_if:account_type,==,admin|string',
 'owner_resturant_id' => 'sometimes|nullable|required_if:account_type,==,resturant_owner|exists:resturants,id',
            // 'category_id' => 'sometimes|nullable|required_if:account_type,==,valet|exists:categories,id',
            // 'gate_id' => 'sometimes|nullable|required_if:account_type,==,valet|exists:gates,id',
            // 'category_type_id' => 'sometimes|nullable|required_if:account_type,==,operator|exists:category_types,id',
            'owner_name'            =>request()->account_type=='vendor'?'nullable|string|min:2|max:50':'nullable|string|min:2|max:50',
            'branches_no'           =>request()->account_type=='vendor'?'nullable|numeric|min:1':'nullable|numeric|min:0',
            'national_id'           =>'nullable|unique:pending_vendors,national_id,'.request()->pending_vendor_id.'|numeric|digits:14',
            'commercial_registration_no' =>request()->account_type=='vendor'?'nullable|numeric':'nullable',
            'driving_license_no'    =>request()->account_type=='delegate'?'nullable|numeric':'nullable',
            'tax_no' =>request()->account_type=='vendor'?'nullable|numeric':'nullable',
            'location'    =>request()->account_type=='delegate'?'nullable|string|min:2|max:255':'nullable|string|min:2|max:255',
            'mobile'                =>'nullable|numeric|regex:/[0-9]{7}/',
            'another_mobile'                =>'sometimes|nullable|numeric|regex:/[0-9]{7}/',
            'national_id_image'           =>'nullable|image|mimes:png,jpg,webp',
            'commercial_registration_no_image' =>request()->account_type=='vendor'?'nullable|image|mimes:png,jpg,webp':'nullable|image|mimes:png,jpg,webp5',
            'driving_license_image'    =>request()->account_type=='delegate'?'nullable|image|mimes:png,jpg,webp':'nullable|image|mimes:png,jpg,webp',
            'tax_no_image' =>request()->account_type=='vendor'?'nullable|image|mimes:png,jpg,webp':'nullable|image|mimes:png,jpg,webp',
            'vodafone_cash_mobile'=>'nullable|numeric|regex:/[0-9]{7}/',
            
		]  
        +
         ($this->isMethod('POST') ? $this->store() : $this->update());
    }

    protected function store()
    {
         return [
            'password' => 'required|string|min:6|max:20',
         ];
    }

    protected function update()
    {
         return [
            'password' => 'sometimes|nullable|string|min:6|max:20',
         ];
    }
}
