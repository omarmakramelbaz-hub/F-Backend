<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreAdvertisingRequest;
use App\Http\Traits\UploadImageTrait;
use App\Models\Advertising;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
class AdvertisingController extends Controller
{
    use UploadImageTrait;

    function __construct()
    {
        $this->middleware('permission:slidear-list', ['only' => ['index','show']]);
        $this->middleware('permission:slidear-list', ['only' => ['show']]);
        $this->middleware('permission:slidear-create', ['only' => ['create','store']]);
        $this->middleware('permission:slidear-edit', ['only' => ['edit','update']]);
        $this->middleware('permission:slidear-delete', ['only' => ['destroy','deleteAll']]);
    }

    public function index()
    {
        $request = request();
        $searchQuery = trim($request->query('search'));

        $dash_advertisings = app(\App\Services\Dashboard\DesktopDashboardWorkspaceReadAuthority::class)
            ->restaurants(Advertising::query(),'advertisings.resturant_id');

        if($request->query('from_date')){
            $dash_advertisings = $dash_advertisings->whereDate('created_at','>=',date($request->query('from_date')));
        }
        if($request->query('to_date')){
            $dash_advertisings = $dash_advertisings->whereDate('created_at','<=',date($request->query('to_date')));
        }
        $dash_advertisings = $dash_advertisings->orderBy('id', 'desc')->paginate(30);
        return view('admin.advertisings.index', compact('dash_advertisings'));
    }


    public function create()
    {
        $advertising = new Advertising() ;
        return view('admin.advertisings.create' , compact('advertising'));
    }

    public function store(StoreAdvertisingRequest $request)
    {
       
        
       
        
        $advertising = Advertising::create($request->except('images'));
       if($request->hasFile('images') )
        {
            foreach($request->file('images') as $image){
            $this->convertImageToWebp($image,$advertising,'advertising_image','advertisings');
            }
        }
        
        return redirect()->route('advertisings.index')->with('success',trans('messages.AddSuccessfully'));

    }


    public function show(Advertising $advertising)
    {
        return view('admin.advertisings.show', compact('advertising') );
    }


    public function edit(Advertising $advertising)
    {

        return view('admin.advertisings.edit' , compact('advertising'));

    }


    public function update(StoreAdvertisingRequest $request,Advertising $advertising)
    {
      if($request->hasFile('images') )
        {
            foreach($request->file('images') as $image){
            $this->convertImageToWebp($image,$advertising,'advertising_image','advertisings');
            }
        }
       
        $advertising->update($request->except('images'));

        return redirect()->route('advertisings.index')->with('success',trans('messages.UpdateSuccessfully'));
    }


    public function destroy(Advertising $advertising)
    {
        $advertising->delete();
        $advertising->clearMediaCollection('advertising_image');
        return redirect()->route('advertisings.index')->with('success',trans('messages.DeleteSuccessfully'));
    }


    public function deleteAll(Request $request)
    {
        $ids = $request->ids;
        Advertising::whereIn('id',explode(",",$ids))->delete();
        return response()->json(['success'=> trans('messages.RecordsDeleteSuccessfully')]);
    }
    
     public function delete_image(Request $request){
        //   dd($request);
          $advertising=Advertising::find($request->advertising_id);
          $advertising->deleteMedia($request->id);
          return response()->json(['success'=> trans('messages.DeleteSuccessfully')]);
    
          
    }

  
}
