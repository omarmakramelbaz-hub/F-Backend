<?php

namespace App\Http\Controllers\Dashboard;
use App\Interfaces\QuestionAnswerRepositoryInterface;
use App\Models\QuestionAnswer;
use App\Http\Requests\Dashboard\QuestionAnswer\StoreQuestionAnswerRequest;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Excel;
use App\Exports\ExportQuestionAnswer;

class QuestionAnswerController extends Controller
{
    private QuestionAnswerRepositoryInterface $question_answerRepository;

    public function __construct(QuestionAnswerRepositoryInterface $question_answerRepository) 
    {
        $this->middleware('permission:question_answer-list', ['only' => ['index','show']]);
        $this->middleware('permission:question_answer-create', ['only' => ['create','store']]);
        $this->middleware('permission:question_answer-edit', ['only' => ['update','edit']]);
        $this->middleware('permission:question_answer-delete', ['only' => ['destroy','delete_all','deleteAll']]);
        $this->question_answerRepository = $question_answerRepository;
    }


    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $question_answers = $this->question_answerRepository->getAllQuestionAnswers($request);
        return view('admin.question_answers.index', compact('question_answers'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $question_answer = new QuestionAnswer() ;
        return view('admin.question_answers.create', compact('question_answer'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreQuestionAnswerRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreQuestionAnswerRequest $request)
    {
        $question_answerDetails = $request->except('_token','_method','_desktop_command','idempotency_key');

        $question_answer = $this->question_answerRepository->createQuestionAnswer($question_answerDetails);
        
                return redirect('admin/question_answers')->with('success',trans('messages.AddSuccessfully'));
            }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\QuestionAnswer  $question_answer
     * @return \Illuminate\Http\Response
     */
    public function show(QuestionAnswer $question_answer)
    {
        return view('admin.question_answers.show', compact('question_answer'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\QuestionAnswer  $question_answer
     * @return \Illuminate\Http\Response
     */
    public function edit(QuestionAnswer $question_answer)
    {
        return view('admin.question_answers.edit', compact('question_answer'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateQuestionAnswerRequest  $request
     * @param  \App\Models\QuestionAnswer  $question_answer
     * @return \Illuminate\Http\Response
     */
    public function update(StoreQuestionAnswerRequest $request, QuestionAnswer $question_answer)
    {
        $question_answerDetails = $request->except('_token','_method','_desktop_command','idempotency_key');
        $this->question_answerRepository->updateQuestionAnswer($question_answer->id, $question_answerDetails);
    
        return redirect('admin/question_answers')->with('success',trans('messages.UpdateSuccessfully'));
    }
          

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\QuestionAnswer  $question_answer
     * @return \Illuminate\Http\Response
     */
    public function destroy(QuestionAnswer $question_answer)
    {
        $this->question_answerRepository->deleteQuestionAnswer($question_answer->id);
                return redirect('admin/question_answers')->with('success',trans('messages.DeleteSuccessfully'));
                
    }
    public function deleteAll(Request $request)
    {
        $ids = $request->ids;
        
        $this->question_answerRepository->deleteAllQuestionAnswers($ids);
        return response()->json(['success'=> trans('messages.RecordsDeleteSuccessfully')]);

    }

    public function exportQuestionAnswers(Request $request){
        return Excel::download(new ExportQuestionAnswer, 'faqs-'.now().'.xlsx');
    }
}
