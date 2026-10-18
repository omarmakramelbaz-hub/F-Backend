<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use App\Scopes\OrderScope;
use App\Models\GeneralSettings;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;
    protected $guarded = []; 
    protected static function booted()
    {
        static::addGlobalScope(new OrderScope);
        static::deleted(function ($order) {
            // Delete all related notifications
             DB::table('notifications')
              ->where('notifiable_type', self::class)
              ->where('notifiable_id', $order->id)
              ->delete();
        });
    }
 
    protected static function boot()
    {
        parent::boot();
        // static::created(function ($cart) {
        //   $max = Order::count();
        //   if($max == 0){
        //       $max = 16800;
        //   }else{
        //       $max = Order::whereNotNull('order_no')->latest()->first()->order_no + 1;
        //   }
        //   $cart->order_no = $max;
        //   $cart->save();
        // });
        static::creating(function ($model) {
            if (empty($model->order_no) && !empty($model->resturant_id)) {
                $numbers = app(\App\Services\Dashboard\BranchOrderSequence::class)->next('f:'.$model->resturant_id, 'app');
                if ($numbers) {
                    $model->shift_sequence = $numbers['shift_sequence'];
                    $model->shift_order_number = $numbers['shift_order_number'];
                    $model->order_no = \App\Services\Dashboard\BranchOrderSequence::display($model, 'f:'.$model->resturant_id, 'app', '');
                } else $model->order_no = self::generateUniqueOrderNumber($model->resturant_id);
            }
        });

        
        // static::creating(function ($model) {
        //     $model->order_no = self::generateUniqueOrderNumber();
        // });
        static::deleting(function ($order) {
            // Delete notifications related to this order
            DB::table('notifications')
              ->where('notifiable_type', self::class)
              ->where('notifiable_id', $order->id)
              ->delete();
        });
        
        static::updating(function ($model) {
            // Check if the status column is being updated
            //   dd("d");
            //   \Log::info("update_order_".$model->id."$model->status".$model->status."dirty".$model->isDirty('status'));
            if ($model->isDirty('status') &&  $model->status == 'completed' && $model->coupon_wheel_id ==null) {
            //   \Log::info("update_order_".$model->id);
                $CouponWheel=CouponWheel::whereDate('start_date','<=',now())->whereDate('end_date','>=',now())->first();
                // Update another column when status changes
                if($CouponWheel){
                    // \Log::info("update_order_".$CouponWheel->id."-".$model->updated_total."-".$CouponWheel->price);
                $coupon_subscripe=CouponSubscripe::where('user_id',$model->user_id)->where('coupon_wheel_id',$CouponWheel->id)->first();
                // \Log::info("update_order_".$coupon_subscripe->id.'user-id'.$model->user_id);
                if($coupon_subscripe  && $model->updated_total >= $CouponWheel->price){
                  $model->coupon_wheel_id = $CouponWheel->id;
                  // or set it based on some logic
                //   \Log::info("update_order_".$CouponWheel->id);
                  $coupon_subscripe->update(['price'=>$coupon_subscripe->price+$model->updated_total]);
                }
                }
            }
        });
    }
    
  
    // public static function generateUniqueOrderNumber()
    // {
    //     do {
    //         // $orderNumber = strtoupper(rand(10000, 99999)); // Generates a random string of 10 characters
    //         $order = Order::whereNotNull('order_no')->orderBy('order_no','desc')->first();
    //         $orderNumber = $order?$order->order_no + 1:16800;
    //     } while (self::where('order_no', $orderNumber)->exists());

    //     return $orderNumber;
    // }
    
    // public static function generateUniqueOrderNumber($restaurantId)
    // {
    //     $startFrom = 16800;
    
    //     $latestOrder = self::where('resturant_id', $restaurantId)
    //         ->whereNotNull('order_no')
    //         ->orderByDesc('id')
    //         ->first();
    
    //     $nextNumber = $latestOrder
    //         ? (int) filter_var($latestOrder->order_no, FILTER_SANITIZE_NUMBER_INT) + 1
    //         : $startFrom;
    
    //     $prefix = 'R' . $restaurantId . '-';
    
    //     // تأكد أنه مش موجود مسبقاً
    //     $fullOrderNumber = $prefix . $nextNumber;
    //     while (self::where('order_no', $fullOrderNumber)->exists()) {
    //         $nextNumber++;
    //         $fullOrderNumber = $prefix . $nextNumber;
    //     }
    
    //     return $fullOrderNumber;
    // }
public static function generateUniqueOrderNumber($restaurantId)
{
    $startFrom = 16800;

    $prefix = 'R' . $restaurantId . '-';

    $latestOrder = self::where('resturant_id', $restaurantId)
        ->whereNotNull('order_no')
        ->where('order_no', 'like', $prefix . '%')
        ->orderByDesc('id')
        ->first();

    if ($latestOrder && str_starts_with($latestOrder->order_no, $prefix)) {
        $numberPart = (int) str_replace($prefix, '', $latestOrder->order_no);
    } else {
        $numberPart = $startFrom;
    }

    // توليد الرقم الجديد مع البادئة
    $fullOrderNumber = $prefix . $numberPart;

    while (self::where('resturant_id', $restaurantId)->where('order_no', $fullOrderNumber)->exists()) {
        $numberPart++;
        $fullOrderNumber = $prefix . $numberPart;
    }

    return $fullOrderNumber;
}

    public function user() {
       return $this->belongsTo(User::class);
    }
    public function wallet() {
       return $this->belongsTo(Wallet::class);
    }
    public function user_address() {
       return $this->belongsTo(UserAddress::class,'user_address_id');
    }
 public function resturant() {
       return $this->belongsTo(Resturant::class,'resturant_id');
    }
     public function paid() {
       return $this->hasOne(Payment::class,'order_id')->where('status',true);
    }
    
    public function delegate_notifications() {
       return $this->hasMany(DelegateNotification::class);
    }
    public function carts() {
       return $this->hasMany(Cart::class);
    }
    public function rated_before() {
            //   $cats = \App\Models\Review::where('order_id', $this->id)->where('user_id',auth('api')->check()?auth('api')->user()->id:auth('admin')->user()->id)->count();

        if(auth('api')->check()){
                   $cats = \App\Models\Review::where('order_id', $this->id)->where('user_id',auth('api')->user()->id)->count();

        }elseif(auth('admin')->check()){
                   $cats = \App\Models\Review::where('order_id', $this->id)->where('user_id',auth('admin')->user()->id)->count();
        }else{
            $cats = null;
        }
        if($cats > 0){
            return 1;
        }else{
            return 0;
        }
    }
    
    public function commissioned_before() {
     if(auth('api')->check()){
               $cats = \App\Models\Commission::where('order_id', $this->id)->where('user_id',auth('api')->user()->id)->count();
     }elseif(auth('admin')->check()){
                $cats = \App\Models\Commission::where('order_id', $this->id)->where('user_id',auth('admin')->user()->id)->count();
     }else{
         $cats = null;
     }
    //   $cats = \App\Models\Commission::where('order_id', $this->id)->where('user_id',auth('api')->check()?auth('api')->user()->id:auth('admin')->user()->id)->count();
        if($cats > 0){
            return 1;
        }else{
            return 0;
        }
    }
    public function transfered_before() {
        if(auth('api')->check()){
       $transfer = \App\Models\Wallet::where('type','transfer')->where('order_id', $this->id)->where('from_user',auth('api')->user()->id)->where('status','completed')->count();
        }elseif(auth('admin')->check()){
            
       $transfer = \App\Models\Wallet::where('type','transfer')->where('order_id', $this->id)->where('from_user',auth('admin')->user()->id)->where('status','completed')->count();
        }else{
            $transfer = null;
        }
    //   $transfer = \App\Models\Wallet::where('type','transfer')->where('order_id', $this->id)->where('from_user',auth('api')->check()?auth('api')->user()->id:auth('admin')->user()->id)->where('status','completed')->count();
        if($transfer > 0){
            return 1;
        }else{
            return 0;
        }
    }
    
    public function getTotalAttribute(){
        if($this->type == 'wallet'){
            $total = $this->wallet?->amount;
        }elseif($this->type == 'shipping'){
            $total = 0;
        }else{
        $total=0;
        foreach($this->carts as $cart){
            $total=$total+($cart->price*$cart->qty);
        }}
        // dd($this->carts->count());
        return round($total,2);
    }
    public function getUpdatedTotalAttribute(){
        if($this->type == 'wallet'){
            $total = $this->wallet?->amount;
        }elseif($this->type == 'shipping'){
            $total =0;
        }else{
        $total=0;
        foreach($this->carts as $cart){
            $cart_total=$cart->updated_total!==null?$cart->updated_total:$cart->price*$cart->qty;
            $total=$total+($cart_total);
        }}
        // dd($this->carts->count());
        return round($total,2);
    }
    
    // رسوم الخدمة الزياده من المستخدم
    public function getServiceFeesAttribute(){
        if( $this->type!='shipping'){
        $setting = \DB::table('settings')->where('name','service_fees')->pluck('payload')->first();
        $setting_service_fees =( filter_var($setting, FILTER_SANITIZE_NUMBER_INT));
        $service_fees=($this->updated_total * (int)$setting_service_fees) / 100;
        
        return round($service_fees,2);
        }else{
            return 0;
        }
    }
    //الضريبه 14% زياده من المستخدم
    public function getTaxAttribute(){
        $setting = \DB::table('settings')->where('name','tax')->pluck('payload')->first();
        $setting_tax =( filter_var($setting, FILTER_SANITIZE_NUMBER_INT));
        $tax=($this->updated_total * (int)$setting_tax) / 100;
        
        return round($tax,2);
    }
    
    //الفلوس الصافي للتاجر
    public function getVendorPercentageAttribute(){
        // $app_percentage=($this->total) - ($this->app_percentage);
        $app_percentage=($this->updated_total) - ($this->vendor_tax);
        return round($app_percentage,2);
    }
    
    
    //الفلوس الصافي للمندوب
    public function getDelegatePercentageAttribute(){
        $delegate_percentage=($this->delivery_price) - ($this->delivery_price * $this->delegate?->delegate_fees / 100);
        return round($delegate_percentage,2);
    }
    
    //الفلوس الصافي من المندوب للادمن
    public function getDelegateToAppPercentageAttribute(){
        $app_percentage=($this->delivery_price * $this->delegate?->delegate_fees / 100);
        return round($app_percentage,2);
    }
    
    
    // 10% + (14% from 10%)
    public function getAppPercentageAttribute(){
        if($this->delegate_from_out == 'in_resturant'){
        // $app_percentage=($this->vendor_tax) + $this->tax +$this->user_tax;
        $app_percentage=($this->vendor_tax) + $this->service_fees +$this->user_tax;
        }elseif($this->delegate_from_out == 'out_resturant' && $this->type!='shipping'){
        $app_percentage=(($this->vendor_tax) + $this->service_fees +$this->user_tax) + $this->delegate_to_app_percentage;
            
        }elseif($this->delegate_from_out == 'out_resturant' && $this->type=='shipping'){
                    $app_percentage= $this->delegate_to_app_percentage;
        }
        else{
            $app_percentage = 0;
        }
        return round($app_percentage,2);
    }
    
    //نسبة التطبيق من الطلب
      public function getAppToVendorPercentageAttribute(){
        // $app_percentage=($this->vendor_tax) + $this->tax +$this->user_tax;
        $app_percentage=($this->vendor_tax);
        
        return round($app_percentage,2);
    }
    public function getGrandTotalAttribute(){
        if($this->updated_total!==null){
            $total=$this->updated_total;
        }else{
            $total=$this->total;
        }
        if($this->delegate_from_out == 'out_resturant' && $this->type=='shipping'){
                    $grand_total=$total+ $this->delivery_price ;

        }else{
            if($this->type=='current'){
                    $grand_total=$total + $this->delivery_price + $this->user_tax + $this->service_fees;
            }elseif($this->type=='shipping'){
                $grand_total=$this->shipping?->actual_price;
            }elseif($this->type == 'wallet'){
                 $grand_total = $this->wallet?->amount;
            }

        }
        return round($grand_total,2);
    }
    
    public function delegate() {
       return $this->belongsTo(User::class,'delegate_id');
    }
    
     public function shipping() {
       return $this->hasOne(Shipping::class,'order_id');
    }
   
}
