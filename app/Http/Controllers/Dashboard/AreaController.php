<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Area;
use App\Http\Requests\Dashboard\AreaRequest;
class AreaController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:areas-list', ['only' => ['index','show']]);
        $this->middleware('permission:areas-create', ['only' => ['create','store']]);
        $this->middleware('permission:areas-edit', ['only' => ['update','edit']]);
        $this->middleware('permission:areas-delete', ['only' => ['destroy','delete_all']]);

    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
      
        $parent=request('parent')??null;
        $request = request();

        $fields = ['title_ar','title_en'];
        $searchQuery = trim($request->query('search'));

        $areas = Area::when(request()->has('parent'), function($query)  {
            $query->where('parent_id', request('parent'));
            })->when(!request()->has('parent'), function($query)  {
                $query->whereNull('parent_id');
                })->when($request->query('search'),function($query) use($searchQuery, $fields) {
            foreach ($fields as $field)
                $query->orWhere($field, 'like',  '%' . $searchQuery .'%');
            })->when($request->query('from_date'), function($query, $from_date) {
                $query->where('created_at', '>=', $from_date);
            })->when($request->query('to_date'), function($query, $to_date) {
                $query->where('created_at', '<=', $to_date);
            })
            ->orderBy('id', 'desc')
            ->paginate(30);
        $mainParent=Area::find(request()->parent);
        return view('admin.areas.index',compact('areas','parent','mainParent'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $area=new Area();
        $parent=request('parent')??null;
       return view('admin.areas.create',compact('parent','area'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\AreaRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(AreaRequest $request)
    {

        $area=Area::create($request->except('_token','_method','_desktop_command','idempotency_key'));
        if($request['parent_id'] && $request['parent_id']!=null){
        return redirect('admin/areas/?parent='.$request['parent_id'])->with(["success"=>__('site.recored created successfully.')]);
        }else{
         return redirect('admin/areas')->with(["success"=>__('site.recored created successfully.')]);   
        }


    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        return view('admin.areas.show',compact('area'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Area $area)
    {
        $parent=$area->parent_id;
        return view('admin.areas.edit',compact('area','parent'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\AreaRequest  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(AreaRequest $request, $id)
    {
        $area=Area::findOrFail($id);
        $area->update($request->except('_token','_method','_desktop_command','idempotency_key'));
          if($area->parent_id && $area->parent_id!=null){
        return redirect('admin/areas/?parent='.$area->parent_id)->with(["success"=>__('site.recored updated successfully.')]);
        }else{
         return redirect('admin/areas')->with(["success"=>__('site.recored updated successfully.')]);   
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Area $area)
    {
         $area->delete();
         return redirect()->back()->with('success',trans('messages.DeleteSuccessfully'));
        }

      //=========================delete all==================
      public function delete_all(Request $request){
        $ids = $request->ids;
        Area::whereIn('id',explode(",",$ids))->delete();
        return response()->json(['success'=> trans('messages.RecordsDeleteSuccessfully')]);
    }

    //=======================get child ajax================
    public function child_ajax(Request $request){
            $child=Area::find($request['id'])->child->pluck('id','title')->toArray();
            return $child;
    }

}
