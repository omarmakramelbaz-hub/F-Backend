<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\About\StoreContractRequest;
use App\Models\Contract;
use Illuminate\Http\Request;
use PDF;
class ContractController extends Controller
{
    function __construct()
    {
        $this->middleware('permission:contract-list|contract-edit', ['only' => ['index','store']]);
        $this->middleware('permission:contract-list', ['only' => ['show']]);
        $this->middleware('permission:contract-edit', ['only' => ['create','store']]);
        $this->middleware('permission:contract-edit', ['only' => ['edit','update']]);
        $this->middleware('permission:contract-delete', ['only' => ['destroy','deleteAll']]);
    }

    public function index()
    {
        $request = request();
        $searchQuery = trim($request->query('search'));
        $contracts = Contract::query();

        if($request->query('from_date')){
            $contracts = $contracts->whereDate('created_at','>=',date($request->query('from_date')));
        }
        if($request->query('to_date')){
            $contracts = $contracts->whereDate('created_at','<=',date($request->query('to_date')));
        }
        $contracts = $contracts->orderBy('id', 'desc')->paginate(30);

        return view('admin.contracts.index', compact('contracts'));
    }


    public function create()
    {
        $contract = new Contract() ;
        return view('admin.contracts.create' , compact('contract'));
    }


    public function store(StoreContractRequest $request)
    {
        $data = \Illuminate\Support\Arr::except($request->validated(), 'contract_image');
        $contract = Contract::create($data);
        return redirect()->route('contracts.index')->with('success',trans('messages.AddSuccessfully'));
    }

    public function show(Contract $contract)
    {
        return view('admin.contracts.show', compact('contract') );
    }


    public function edit(Contract $contract)
    {
        return view('admin.contracts.edit' , compact('contract'));
    }


    public function update(StoreContractRequest $request,Contract $contract)
    {        
        $data = \Illuminate\Support\Arr::except($request->validated(), 'contract_image');
        $contract->update($data);
        return redirect()->route('contracts.index')->with('success',trans('messages.UpdateSuccessfully'));
    }


    public function destroy(Contract $contract)
    {
        $contract->delete();
      
        return redirect()->route('contracts.index')->with('success',trans('messages.DeleteSuccessfully'));
    }


    public function deleteAll(Request $request)
    {
        $ids = $request->ids;
        $contracts = Contract::whereIn('id',explode(",",$ids))->delete();
        return response()->json(['success'=> trans('messages.RecordsDeleteSuccessfully')]);
    }

    public function contracts(){
        $types = Contract::get();
        return view('admin.partials.contract', compact('types'));
    }

    public function pdfviewContract()
    {
        $contract = Contract::where('id',request()->id)->first();
        $old_word = ["[contractDate]","[contractDay]","[vendorName]"];
        $new_word   = [$contract->contractDate,Carbon::parse($contract->contractDate)->translatedFormat('l') ,$contract->contractDateFrom,$contract->contractDateTo, $contract->lender?->name];  

        $request = strip_tags(str_replace($old_word,$new_word, $contract->contract_form?->contract_form));
        view()->share('request',$request);

        if(request()->has('download')) {
            // pass view file
            $pdf = PDF::loadView('pdfview');
            // dd($pdf);
            // download pdf
            return $pdf->download('contract_'.request()->id.'.pdf');
        }
        return view('pdfview');
    }

}