<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreCouponWheelRequest;
use App\Http\Traits\UploadImageTrait;
use App\Models\CouponWheel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\CouponWheelResturant;
use App\Events\CouponWheelUpdated;

class CouponWheelController extends Controller
{
    use UploadImageTrait;

    function __construct()
    {
        $this->middleware('permission:coupon_wheel-list', ['only' => ['index','show']]);
        $this->middleware('permission:coupon_wheel-list', ['only' => ['show']]);
        $this->middleware('permission:coupon_wheel-create', ['only' => ['create','store']]);
        $this->middleware('permission:coupon_wheel-edit', ['only' => ['edit','update']]);
        $this->middleware('permission:coupon_wheel-delete', ['only' => ['destroy','deleteAll']]);
    }

    public function index()
    {
        $request = request();
        $searchQuery = trim($request->query('search'));

        $dash_coupon_wheels = CouponWheel::query();

        if($request->query('from_date')){
            $dash_coupon_wheels = $dash_coupon_wheels->whereDate('created_at','>=',date($request->query('from_date')));
        }
        if($request->query('to_date')){
            $dash_coupon_wheels = $dash_coupon_wheels->whereDate('created_at','<=',date($request->query('to_date')));
        }

        $dash_coupon_wheels = $dash_coupon_wheels->where('name', 'like',  '%' . $searchQuery .'%');

        $dash_coupon_wheels = $dash_coupon_wheels->orderBy('id', 'desc')->paginate(30);
        return view('admin.coupon_wheels.index', compact('dash_coupon_wheels'));
    }


    public function create()
    {
        $coupon_wheel = new CouponWheel() ;
        return view('admin.coupon_wheels.create' , compact('coupon_wheel'));
    }

    public function store(StoreCouponWheelRequest $request)
    {
       
        
       
        
        $coupon_wheel = CouponWheel::create($this->values($request));
         broadcast(new CouponWheelUpdated($coupon_wheel))->toOthers();
       if($request->hasFile('images') )
        {
            $this->convertImageToWebp($request->images,$coupon_wheel,'coupon_wheel_image','coupon_wheels');
            
        }
        if($request->has('restraunt_id')){
            foreach($request->restraunt_id as $resturant){
                CouponWheelResturant::create([
                    'coupon_wheel_id'=>$coupon_wheel->id,
                    'resturant_id'=>$resturant
                    ]);
            }
        }
        
        return redirect()->route('coupon_wheels.index')->with('success',trans('messages.AddSuccessfully'));

    }


    public function show(CouponWheel $coupon_wheel)
    {
        return view('admin.coupon_wheels.show', compact('coupon_wheel') );
    }


    public function edit(CouponWheel $coupon_wheel)
    {

        return view('admin.coupon_wheels.edit' , compact('coupon_wheel'));

    }


    public function update(StoreCouponWheelRequest $request,CouponWheel $coupon_wheel)
    {
      if($request->hasFile('images') )
        {
            $this->convertImageToWebp($request->images,$coupon_wheel,'coupon_wheel_image','coupon_wheels');
            
        }
       
        $coupon_wheel->update($this->values($request));
                      broadcast(new CouponWheelUpdated($coupon_wheel))->toOthers();

        $coupon_wheel->resturants()->delete();
          if($request->has('restraunt_id')){
            foreach($request->restraunt_id as $resturant){
                CouponWheelResturant::create([
                    'coupon_wheel_id'=>$coupon_wheel->id,
                    'resturant_id'=>$resturant
                    ]);
            }
        }
        return redirect()->route('coupon_wheels.index')->with('success',trans('messages.UpdateSuccessfully'));
    }


    public function destroy(CouponWheel $coupon_wheel)
    {
        $coupon_wheel->delete();
        $coupon_wheel->clearMediaCollection('coupon_wheel_image');
        broadcast(new CouponWheelUpdated($coupon_wheel))->toOthers();
        return redirect()->route('coupon_wheels.index')->with('success',trans('messages.DeleteSuccessfully'));
    }


    public function deleteAll(Request $request)
    {
        $ids = $request->ids;
        CouponWheel::whereIn('id',explode(",",$ids))->delete();

        return response()->json(['success'=> trans('messages.RecordsDeleteSuccessfully')]);
    }
    
    /** The draw's fields: its rule (order total or a product) once the server has the columns for it. */
    private function values(Request $request): array
    {
        $values = $request->except('images', 'restraunt_id', 'policy', 'product_id');
        if (!\App\Services\Draws\ProductDraw::ready()) return $values;
        $product = $request->input('policy') === \App\Services\Draws\ProductDraw::PRODUCT;
        $values['policy'] = $product ? \App\Services\Draws\ProductDraw::PRODUCT : \App\Services\Draws\ProductDraw::ORDER_TOTAL;
        $values['product_id'] = $product ? (int) $request->input('product_id') : null;
        if ($product) $values['price'] = $request->filled('price') ? $request->input('price') : 0;
        return $values;
    }

     public function delete_image(Request $request){
        //   dd($request);
          $coupon_wheel=CouponWheel::find($request->coupon_wheel_id);
          $coupon_wheel->deleteMedia($request->id);
          return response()->json(['success'=> trans('messages.DeleteSuccessfully')]);
    
          
    }

  
}