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
        $this->middleware('permission:contract-list', ['only' => ['show','pdfviewContract']]);
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
        // Contracts are saved templates, not populated vendor/date instances.
        // Keep their placeholders literal and retain the model's AdminScope.
        $id = filter_var(request()->query('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless($id, 404);
        $contract = Contract::query()->findOrFail($id);
        $lines = preg_replace('/<\s*(?:br\b[^>]*|\/(?:p|div|li|h[1-6]|tr)\s*)>/i', "\n", (string) $contract->template);
        $contractText = html_entity_decode(strip_tags($lines), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (request()->has('download')) {
            return PDF::loadView('admin.contracts.pdf', compact('contractText'))
                ->setOptions([
                    'encoding' => 'UTF-8',
                    'disable-javascript' => true,
                    'disable-local-file-access' => true,
                    'disable-plugins' => true,
                    'no-images' => true,
                    'disable-external-links' => true,
                    'disable-internal-links' => true,
                    'load-error-handling' => 'abort',
                    'load-media-error-handling' => 'abort',
                ])
                ->download('contract_'.$contract->id.'.pdf');
        }

        return view('admin.contracts.pdf', compact('contractText'));
    }

}
