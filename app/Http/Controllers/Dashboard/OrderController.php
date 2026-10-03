<?php

namespace App\Http\Controllers\Dashboard;
use App\Models\Order;
use App\Models\Cart;
use App\Models\ShippingZone;
use App\Models\GeneralSettings;
use App\Models\User;
use App\Models\Product;
use App\Models\Wallet;
use App\Models\ProductCapacity;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use PDF;
use Mail;
use Notification;
use App\Http\Traits\ApiResponses;
use App\Events\VendorUpdated;
use App\Events\DelegateUpdated;
use App\Events\OrderUpdated;
class OrderController extends Controller
{  use ApiResponses;
    public function __construct() 
    {
        $this->middleware('permission:order-list', ['only' => ['index','show']]);
        $this->middleware('permission:order-delete', ['only' => ['destroy','delete_all']]);
    }

    public function getOrders(Request $request)
    {
        // Get orders for each status group with limit 20
        $pendingOrders = Order::has('carts')->orderBy('id','DESC')->whereIn('status', ['pending', 'another_delegate'])
            ->latest()
            ->limit(20)
            ->get();

        $acceptedOrders = Order::has('carts')->orderBy('id','DESC')->whereIn('status', ['accepted', 'shipped', 'new_order'])
            ->latest()
            ->limit(20)
            ->get();

        $completedOrders = Order::has('carts')->orderBy('id','DESC')->where(function($query) {
                $query->whereIn('status', ['completed', 'cancelled', 'declined'])
                    ->orWhereNull('status');
            })
            ->latest()
            ->limit(20)
            ->get();

        // Combine all orders
        $orders = $pendingOrders->merge($acceptedOrders)->merge($completedOrders);

        $view = view('admin.orders.order_section', compact('orders'))->render();

        return response()->json([   
            'view' => $view,
            'count' => $orders->count()
        ]);
    }
     public function downloadInvoice(Request $request)
    {        
        
        $invoice = Order::where('id',$request->id)->first();       
        view()->share('invoice',$invoice);
        
        if($request->type == 'admin'){
            if(request()->has('download')) {
            // pass view file
            $pdf = PDF::loadView('pdfInvoice')->setOption('images', true);
            // download pdf
            return $pdf->download('invoice_of_no_'.$request->id.'_'.now().'.pdf');
        }
        return view('pdfInvoice');
        }elseif($request->type == 'worker'){
            if(request()->has('download')) {
            // pass view file
            $pdfss = PDF::loadView('pdfWorkerInvoice');
            // download pdf
            return $pdfss->download('invoice_of_worker_no_'.$request->id.'_'.now().'.pdf');
        }
        return view('pdfWorkerInvoice');
        }
    }

    public function index(Request $request)
    {
        $searchQuery = trim($request->query('search'));
        $orders = Order::query();
        if(! empty(request('status')) ){
            $orders = $orders->where('status', request('status'));
        }
        if(! empty(request('user')) ){
            $orders = $orders->where('user_id', request('user'));
        }
        if(! empty(request('order_type')) ){
            $orders = $orders->where('type', request('order_type'));
        }
        if(! empty(request('resturant_id')) ){
            $orders = $orders->where('resturant_id', request('resturant_id'));
        }
      
        if(! empty(request('order_no')) ){
            $orders = $orders->where('order_no' , 'like', '%' . (int) request('order_no') . '%');
        }
        if($searchQuery != null){
            $orders = $orders->whereHas('user', function($q) use($searchQuery){
                $q->where('email', 'like',  '%' . $searchQuery .'%')->orWhere('mobile', 'like',  '%' . $searchQuery .'%')->orWhere('name', 'like',  '%' . $searchQuery .'%');
            });
        }   
        $orders = $orders->when($request->query('from_date'), function($query, $from_date) {
                $query->where('created_at', '>=', $from_date);
            })->when($request->query('to_date'), function($query, $to_date) {
                $query->where('created_at', '<=', $to_date);
            });
            // ->where('type','!=','wallet');
            // ->whereNotNull('status')
            if(auth('admin')->user()->account_type=='vendor'){
                // $orders=$orders->where('type','current');
            }
           $orders=$orders->orderBy('id','DESC')->paginate(30);
        return view('admin.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        if($order->order_type=='shipping'){
          return view('admin.orders.shipping_show', compact('order'));
        }else{
            if(auth('admin')->user()->account_type=='admin'){
            return view('admin.orders.show', compact('order'));
            }else{
                return view('admin.orders.single_apply', compact('order'));
            }
        }
    }
    
    public function applies()
    {
        $orders = Order::query()->with('carts');
        

        if(request('q') == 'accepted'){
        $orders = $orders->whereIn('status',['accepted','shipped','new_order']);
        }
        else if(request('q') == 'completed'){
        $orders = $orders->where(function ($q) {
                    $q->whereIn('status', ['cancelled', 'completed', 'declined'])
                      ->orWhereNull('status');
                });
        }
        else if(request('q') == 'pending'){
        $orders = $orders->whereIn('status',['pending','another_delegate']);
        }
        if(! empty(request('status'))){
            $orders = $orders->where('status',request('status'));
        }
        
        if(! empty(request('order_no'))){
            $orders = $orders->where('order_no' , 'like', '%' . request()->order_no . '%');
        }
        if(! empty(request()->delegate_from_out) ){
            $orders = $orders->where('delegate_from_out' , request()->delegate_from_out);
        }
        
        if(! empty(request()->date) ){
            $orders = $orders->whereDate('created_at' , request()->date);
        }
       
         if(request()->q){
              $orders = $orders->has('carts')->orderBy('id','DESC')->paginate(10);
            return view('admin.orders.applies', compact('orders'));
         }else{
              $orders = $orders->has('carts')->orderBy('id','DESC')->get();
              return view('admin.orders.applies_card', compact('orders'));
         }
    }
     public function changeStatus(Order $order, Request $request)
    {
        if($order->status!= $request->status){
        $update = $order->update([
            'status' => $request->status,
            // 'order_date' => $request->order_date,
        ]);   
        
             $user_order_owner = User::where('id',$order->user_id)->first();
        $resturant_owner = User::where('id',$order->resturant->user_id)->first();
        if($order->status=='completed'){
               if($order->payment_type!='cash'){
                 $user_price=$order->total-$order->updated_total;

                 if($user_price>0){
                    if($user_order_owner){
                        if($user_price>0){
                    //  return $resturant_owner->id;
                        // if($user_order_owner && $resturant_owner && $resturant_owner->balance>=$user_price){
                            $resturant_owner->update(['balance' => $resturant_owner->balance-$user_price]);
                            $user_order_owner->update(['balance' => $user_order_owner->balance+$user_price]); 
                             Wallet::create([
                            'from_user'=>$resturant_owner->id,
                            'to_user'=>$user_order_owner->id,
                            'amount'=>$user_price,
                            'payment'=>'wallet',
                            'type'=>'transfer',
                            'order_id' => $order->id,
                            'status' => 'completed',
                            ]);
                            Notification::send($user_order_owner,new \App\Notifications\NotifyOrderPriceTransferToWalletNotification($order,$user_price));
                        // }
                 }
            }
         }}
        }
        
        $body = $request->body;
        if($order->user_id){
            $to_email = $order->user?->email;
        }else{
            $to_email = $order->user_address?->email;
        }
            // $mail=Mail::send('emails.order_track', ['cart' => $order, 'body' => $body], function($message) use ($request, $to_email) {
            //      $message->to($to_email);
            //      $message->subject('Order Tracking');
            // });
        if($order->user){
         Notification::send($order->user,new \App\Notifications\NotifyUserOrderStatusUpdatedNotification($order));
        }
        return redirect()->back()->with('success',trans('messages.UpdatedSuccessfully'));
        }else{
            return redirect()->back()->with('success',trans('messages.UpdatedSuccessfully'));
        }
        
    }




    public function cancel_order_delegate(Request $request , $id, GeneralSettings $setting){
        $order = Order::findOrFail($id);
         $update = $order->update([
            'status' => 'new_order',
            'reason'=>'تم إلغاء الطلب بسبب تعطل المندوب برجاء تنفيذ طلب جديد',
        ]);   
        
        $user_order_owner = User::where('id',$order->user_id)->first();
        $resturant_owner = User::where('id',$order->resturant?->user_id)->first();
      
        $this->transfer_order_total_from_delegate($order->id, $setting);
        if($resturant_owner && $resturant_owner->email){
            $to_email = $resturant_owner->email;
        // dd($resturant_owner->email);
            $mail=Mail::send('emails.resturant_schedule_order', ['cart' => $order], function($message) use ($request, $to_email) {
                 $message->to($to_email);
                 $message->subject('Order Tracking');
            });
                                 broadcast(new VendorUpdated($order,1,$resturant_owner->id));
                                 if($order->delegate_id){
                                 broadcast(new DelegateUpdated($order,1,$order->delegate_id));
                                 }

            Notification::send($resturant_owner,new \App\Notifications\NotifyUserOrderStatusUpdatedNotification($order));
        }
        if($order->user){
                                 broadcast(new OrderUpdated($order,1,$order->user_id));

           Notification::send($order->user,new \App\Notifications\NotifyUserOrderStatusUpdatedNotification($order));
        }
        return redirect()->back()->with('success',trans('messages.UpdatedSuccessfully'));
    }

    public function destroy(Cart $order)
    {
        $order->delete();      
        return redirect('admin/orders')->with('success',trans('messages.DeleteSuccessfully'));
        
    }
    public function deleteAll(Request $request)
    {
        $ids = $request->ids;
        $orders = Order::whereIn('id',explode(",",$ids))->delete();

        return response()->json(['success'=> trans('messages.RecordsDeleteSuccessfully')]);
    }

    public function singleOrder($id){
        $order = Order::where('id', $id)->first();
        if($order){
            $img = $order->getFirstMediaUrl('front_cover','thumb');
            return response()->json($img);
        }else{
            return 2;
        }
    }
    
    public function fetchCapacity(Request $request)
    {
        if($request->ajax()){
            $capacitys = ProductCapacity::where("product_id",$request->product_id)->get(["amount",'product_id', "id"]);
            if($capacitys->isEmpty()){
                $prices = Product::where("id",$request->product_id)->first(["from_price", "id"]);
                $shipping = 1;
                $data = view('admin.orders.ajax-product-price-select',compact('prices','shipping'))->render();
                return response()->json(['options'=>$data,'prices'=> $prices, 'shipping' => $shipping]);
            }else{
                // dd($capacitys);
                $data = view('admin.orders.ajax-capacity-select',compact('capacitys'))->render();
                return response()->json(['options'=>$data,'capacitys'=> $capacitys]);
            }
        }
    }

    public function fetchShipping(Request $request)
    {
        if($request->ajax()){
            $is_powdered = Product::where("id",$request->product_id)->first(["is_powdered", "id"]);
            $data = view('admin.orders.ajax-is_powdered-select',compact('is_powdered'))->render();
            return response()->json(['options'=>$data,'is_powdered'=> $is_powdered]);
        }
    }

    public function fetchPrice(Request $request)
    { 
        if($request->ajax()){
            $prices = priceOfCapacity($request->product_id, $request->capacity_id);
            $shipping = 1;
            $data = view('admin.orders.ajax-price-select',compact('prices','shipping'))->render();
            return response()->json(['options'=>$data,'prices'=> $prices, 'shipping' => $shipping]);
        }
    }
    
    public function updateOrder(Cart $order , Request $request){
        // dd($request->all());
        $order->orders()->delete();
        $sum = 0;
        for($i=0; $i< count($request->product_id); $i++){
            if($request->product_id[$i] != null){
            $create_order = Order::create([
                // 'is_powdered' => ($request->is_powdered[$i])? true: false,
                'capacity' => $request->capacity_id[$i],
                'cart_id' => $order->id,
                'is_powdered' => $request->is_powdered[$i],
                'product_id' => $request->product_id[$i],
                'product_price' => $request->price[$i],
                'qty' => $request->quantity[$i],  //1
                'total_price' => $request->quantity[$i] * $request->price[$i] ,
                'user_id' => $order->user_id,
            ]);
            $sum += $create_order->total_price;
            }
        }
        $update = $order->update([
            'total_price' => $sum,
            ]);
            
            if($order->user_id){
            $to_email = $order->user?->email;
        }else{
            $to_email = $order->user_address?->email;
        }
            $mail=Mail::send('emails.order_updated', ['cart' => $order], function($message) use ($request, $to_email) {
                 $message->to($to_email);
                 $message->subject('Order Updates');
            });
        return redirect()->back()->with('success',trans('messages.UpdateSuccessfully'));
    }
    
    
    
    public function download_fatora(){
    
        $invoice=Order::find(request()->id);
        view()->share('invoice',$invoice);

        if(request()->has('download')) {
            // pass view file
            $pdf = PDF::loadView('pdfOrder');
            // download pdf
            return $pdf->download('order_'.now().'.pdf');
        }
        return view('pdfOrder');
    
    }
    public function print_fatora(){
        $invoice=Order::find(request()->id);
        view()->share('invoice',$invoice);

        if(request()->has('download')) {
            // pass view file
            $pdf = PDF::loadView('printReceipt');
            // download pdf
            return $pdf->download('order_'.now().'.pdf');
        }
        return view('printReceipt');
    }
    public function download_daily_report(){
        $user = User::where('id',request('vendor'))->first();
         
        if($user->account_type == 'vendor' && !request()->month && !request()->year){
            $orders =Order::where('resturant_id', $user->base_resturant->id)->where('type','!=','wallet')->where('status','completed')->whereDay('created_at', now()->day)->get();
            if($orders->count() > 0){
            $vendor = $orders->first()?->resturant?->user;
            view()->share(['vendor' => $vendor,'orders'=>$orders]);
   
            if(request()->has('download')) {
                // pass view file
                $pdf = PDF::loadView('dailyVendorReport');
                // download pdf
                return $pdf->download('order_'.now().'.pdf');
            }
              return view('dailyVendorReport');
            }else{
                return redirect()->back()->with('success',trans('messages.no orders today'));
            }
        }if($user->account_type == 'vendor' && request()->month && !request()->year){
            $orders =Order::where('resturant_id', $user->base_resturant->id)->where('type','!=','wallet')->where('status','completed')->whereMonth('created_at', now()->month)->get();
            if($orders->count() > 0){

            $vendor = $orders->first()?->resturant?->user;
            view()->share(['vendor' => $vendor,'orders'=>$orders]);
            if(request()->has('download')) {
                // pass view file
                $pdf = PDF::loadView('dailyVendorReport');
                // download pdf
                return $pdf->download('order_'.now().'.pdf');
            }
              return view('dailyVendorReport');
            }else{
                return redirect()->back()->with('success',trans('messages.no orders today'));
            }
        }
        if($user->account_type == 'vendor' && request()->year){
            $orders =Order::where('resturant_id', $user->base_resturant->id)->where('type','!=','wallet')->where('status','completed')->whereYear('created_at', now()->year)->get();
            if($orders->count() > 0){

            $vendor = $orders->first()?->resturant?->user;
            view()->share(['vendor' => $vendor,'orders'=>$orders]);
            if(request()->has('download')) {
                // pass view file
                $pdf = PDF::loadView('dailyVendorReport');
                // download pdf
                return $pdf->download('order_'.now().'.pdf');
            }
              return view('dailyVendorReport');
            }else{
                return redirect()->back()->with('success',trans('messages.no orders today'));
            }
        }else{
            $orders =Order::where('delegate_id', $user->id)->where('type','!=','wallet')->where('status','completed')->whereDay('created_at', now()->day)->get();
            if($orders->count() > 0){
            $vendor = $orders->first()?->delegate;
            view()->share(['vendor' => $vendor,'orders'=>$orders]);
            if(request()->has('download')) {
                // pass view file
                $pdf = PDF::loadView('dailyDelegateReport');
                // download pdf
                return $pdf->download('delegate_order_'.now().'.pdf');
            }
            return view('dailyDelegateReport');
            }else{
                return redirect()->back()->with('success',trans('messages.no orders today'));
            }
        }
    
    }
    
    
    public function transferCancelledOrderPrice($id){
        try{
            // $order=Order::where('status','cancelled')->find($id);
             $order=Order::whereIn('status',['cancelled','declined'])->find($id);

            if($order && $order->grand_total>0 && $order->transfer_price_by==null ){
                $user = User::findOrFail($order->user_id);
                    // transfer grand_total to user
                        Wallet::create([
                            'to_user'=>$user->id,
                            'amount'=>$order->grand_total,
                            'payment'=>'wallet',
                            'type'=>'transfer',
                            'order_id'=>$order->id,
                            'status'=>'completed'
                            ]);
                       $user->update(['balance'=>$user->balance+$order->grand_total]);
                       if($order->status=='cancelled'){
                     Notification::send($user,new \App\Notifications\NotifyUserCancelledOrderPriceNotification($order));
                       }else{
                                           Notification::send($order->user,new \App\Notifications\NotifyOrderPriceTransferToWalletNotification($order,$order->grand_total));

                       }
                    $order->update(['transfer_price_by'=>'admin']);
                    // return $this->successResponse(OrderResource::make($order),__('api.successfully transfer')); 
            return redirect()->back()->with('success',__('api.successfully transfer'));
                
            }else{
                 return $this->errorResponse(__('api.error'));
            }
        }catch(\Exception $e){
            //  return $this->errorResponse($e->getMessage());
            return redirect()->back()->with('error',$e->getMessage());
          }
    }
    
    
    public function transferPrice($id){
        try{
            $order=Order::find($id);
            $delegate=$order->delegate;
            if($order && $order->grand_total>0 && $order->transfer_price_by==null ){
                $vendor_price=$order->vendor_percentage;
                    if(!$delegate){
                        $vendor_price=$vendor_price+$order->delivery_price;
                    }
                    $vendor=$order->resturant?->user;
                    if($vendor){
                        // transfer order price for vendor
                        $vendor->update(['balance'=>$vendor->balance+$vendor_price]);
                        Wallet::create([
                            'to_user'=>$vendor->id,
                            'amount'=>$vendor_price,
                            'payment'=>'wallet',
                            'type'=>'transfer',
                            'order_id'=>$order->id,
                            'status'=>'completed'
                            ]);
                        //notify vendor with order percentage
                        Notification::send($vendor,new \App\Notifications\NotifyOrderPercentageNotification($order));

                    }
                    // transfer tax for delivery
                   if($delegate && !$order->reason){
                        Wallet::create([
                            'to_user'=>$delegate->id,
                            'amount'=>$order->delegate_percentage,
                            'payment'=>'wallet',
                            'type'=>'transfer',
                            'order_id'=>$order->id,
                            'status'=>'completed'
                            ]);
                       $delegate->update(['balance'=>$delegate->balance+$order->delegate_percentage]);
                       
                        //notify delegate with order percentage
                        Notification::send($delegate,new \App\Notifications\NotifyOrderPercentageNotification($order));

                   }
                    $order->update(['transfer_price_by'=>'admin']);
                    // return $order->id;
                    // return $this->successResponse(OrderResource::make($order),__('api.successfully transfer')); 
            return redirect()->back()->with('success',__('api.successfully transfer'));
                
            }else{
                 return $this->errorResponse(__('api.error'));
            }
        }catch(\Exception $e){
            //  return $this->errorResponse($e->getMessage());
            return $e;
            return redirect()->back()->with('error',$e->getMessage());
          }
    }
    
    
    
    public function transfer_order_total_from_delegate($id, GeneralSettings $setting){
      
        try{
            $order=Order::find($id);
            $delegate=$order->delegate;
            if($order && $order->grand_total>0 && $order->transfer_price_by==null  && $order->status == 'new_order'){
                $vendor_price=$order->vendor_percentage;
                $app_price=$order->app_percentage + $order->delegate_percentage;
            
                $total=$app_price+$vendor_price;
                // if($delegate->balance>$total){
                    $vendor=$order->resturant?->user;
                    if($vendor){
                        // transfer order price for vendor
                        $vendor->update(['balance'=>$vendor->balance+$vendor_price]);
                        Wallet::create([
                            'from_user'=>$delegate->id,
                            'to_user'=>$vendor->id,
                            'amount'=>$vendor_price,
                            'payment'=>'wallet',
                            'type'=>'transfer',
                            'status' => 'completed',
                            'order_id' => $order->id,
                            ]);
                            

                    }
                    // transfer tax for app
                    $setting->app_balance=$setting->app_balance+$app_price;
                    $setting->save();
                        Wallet::create([
                            'from_user'=>$delegate->id,
                            'amount'=>$app_price,
                            'payment'=>'wallet',
                            'type'=>'transfer',
                            'status' => 'completed',
                            'order_id' => $order->id,
                            ]);
                    $delegate->update(['balance'=>$delegate->balance-$total]);
                    if($delegate->balance<0){
                     $order->update(['expiration_date' => now()->addHours(2),'connected'=>'inactive']);   
                     Notification::send($delegate,new \App\Notifications\NotifyMinWalletBalanceNotification($delegate));
                    }
                    return 'ok'; 
                // }else{
                //       return $this->errorResponse(__('api.charge your wallet first')); 
                // }
                
            }else{
                 return redirect()->back();
            }
        }catch(\Exception $e){
             return redirect()->back();
          }
    }
    
    public function fetchProduct(Request $request)
    {
         if($request->ajax()){
            $product_id = $request->product_id;
            $product = Order::where("id",$product_id)->first();
            $data = view('admin.orders.ajax-modal',compact('product','product_id'))->render();
            return response()->json(['options'=>$data,'product'=> $product,'product_id' => $product_id]);
        }

    }
}