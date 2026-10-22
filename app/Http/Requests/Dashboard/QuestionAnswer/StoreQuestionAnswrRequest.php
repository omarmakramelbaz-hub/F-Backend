<?php

namespace App\Http\Requests\Dashboard\QuestionAnswer;

use Illuminate\Foundation\Http\FormRequest;

// Existing Composer classmaps can still map StoreQuestionAnswerRequest to this
// misspelled path. Settings discovery also autoloads this path by its filename.
// The installer publishes this bridge before the corrected file. Keep the
// original request available during that transition, and declare it only once.
if (!class_exists(StoreQuestionAnswerRequest::class, false)) {
    if (is_file(__DIR__.'/StoreQuestionAnswerRequest.php')) {
        require_once __DIR__.'/StoreQuestionAnswerRequest.php';
    } else {
        class StoreQuestionAnswerRequest extends FormRequest
        {
            public function authorize()
            {
                return true;
            }

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
    }
}
