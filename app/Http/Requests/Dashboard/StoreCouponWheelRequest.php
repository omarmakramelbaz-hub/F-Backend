<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class StoreCouponWheelRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'added_by' => 'required|exists:users,id',
            'name' => 'required|string|min:3|max:200',
            // A draw by products runs in every branch; an order-total draw in the branches chosen.
            'restraunt_id'=>$this->input('policy') === \App\Services\Draws\ProductDraw::PRODUCT ? 'nullable|array' : 'required|array|min:1',
            'restraunt_id.*' => 'sometimes|nullable|exists:resturants,id',
            'start_date'=>'required|date',
            'end_date'=>'required|date|after:start_date',
            // A draw by product has no minimum order: each piece of its product is an entry.
            'price'=>$this->input('policy') === \App\Services\Draws\ProductDraw::PRODUCT ? 'nullable|numeric|min:0' : 'required|numeric|min:1',
            'policy'=>'nullable|in:'.implode(',', \App\Services\Draws\ProductDraw::POLICIES),
            'item_names'=>'nullable|required_if:policy,'.\App\Services\Draws\ProductDraw::PRODUCT.'|array|min:1|max:200',
            'item_names.*'=>'string|max:190',
            'prize_amount'=>'nullable|numeric|min:0',
        ]
        +
         ($this->isMethod('POST') ? $this->store() : $this->update());
    }

    protected function store()
    {
         return [
            'images'=>'required|image|mimes:png,jpeg,jpg,webp',
         ];
    }

    protected function update()
    {
         return [
            'images' => 'nullable|image|mimes:png,jpeg,jpg,webp',
         ];
    }

    public function messages()
    {
        return ['item_names.required_if' => 'اختار الأصناف اللي شراؤها بيدخّل العميل السحب.'];
    }
}
