<?php

namespace App\Http\Requests\Dashboard\QuestionAnswer;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionAnswerRequest extends FormRequest
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
            'added_by' => 'required|exists:users,id',
            'question_ar' => 'required|string|min:3|max:1200',
            'question_en' => 'required|string|min:3|max:1200',
            'answer_ar' => 'required|string|min:3|max:1200',
            'answer_en' => 'required|string|min:3|max:1200',

        ];
    }
}