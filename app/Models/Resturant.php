<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\HasMedia;
use Illuminate\Support\Facades\App;
use App\Scopes\ResturantScope;

class Resturant extends Model implements HasMedia
{
    use InteractsWithMedia;
    use HasFactory;

    protected $guarded = [];
    protected static function booted()
    {
        static::addGlobalScope(new ResturantScope);
    }

    /**
     * Check the branch schedule using the application timezone.
     * Supports normal schedules (09:00 -> 23:00) and overnight
     * schedules (18:00 -> 02:00). Missing/equal times are treated
     * as unrestricted so existing branches are not closed by mistake.
     */
    public function isWithinBusinessHours(): bool
    {
        if (empty($this->open_at) || empty($this->close_at)) {
            return true;
        }

        $timezone = config('app.timezone', 'Africa/Cairo');

        try {
            $now = Carbon::now($timezone)->format('H:i:s');
            $open = Carbon::parse($this->open_at, $timezone)->format('H:i:s');
            $close = Carbon::parse($this->close_at, $timezone)->format('H:i:s');
        } catch (\Throwable $e) {
            // Do not unexpectedly hide a branch if an old record has
            // an invalid time value; the stored manual status still applies.
            return true;
        }

        // Same opening/closing time is treated as 24 hours.
        if ($open === $close) {
            return true;
        }

        // Same-day schedule, e.g. 09:00 -> 23:00.
        if ($open < $close) {
            return $now >= $open && $now < $close;
        }

        // Overnight schedule, e.g. 18:00 -> 02:00.
        return $now >= $open || $now < $close;
    }

    /**
     * Status exposed to customer/vendor APIs.
     * Manual hide/closed states remain authoritative. Opened/busy branches
     * are automatically exposed as closed outside their configured hours.
     */
    public function getEffectiveStatusAttribute()
    {
        $status = $this->attributes['status'] ?? null;

        if (in_array($status, ['opened', 'busy'], true) && !$this->isWithinBusinessHours()) {
            return 'closed';
        }

        return $status;
    }

    public function admin() {
       return $this->belongsTo(User::class,'added_by');
    }
  public function area() {
       return $this->belongsTo(Area::class,'area_id');
    }
    public function user() {
       return $this->belongsTo(User::class,'user_id');
    }

    public function parent() {
       return $this->belongsTo(Resturant::class);
    }
    
    public function last_searches() {
       return $this->hasMany(LastSearch::class);
    }
    public function wishlists() {
       return $this->hasMany(Wishlist::class,'resturant_id');
    }
    public function resturant_products() {
       return $this->hasMany(ResturantProduct::class,'resturant_id')->orderBy('category_id','ASC');
    }
    public function resturant_highest_rated_products() {
       return $this->hasMany(ResturantProduct::class,'resturant_id')->where('highest_rated','yes')->orderBy('category_id','ASC');
    }
    public function resturant_areas() {
       return $this->hasMany(ResturantArea::class,'resturant_id');
    }
    
    public function owner() {
       return $this->hasOne(User::class,'owner_resturant_id');
    }
    
    
    public static function findNearby($latitude, $longitude, $radius = 10)
    {
        $earthRadius = 6371; // Earth's radius in km

        return self::select('*')
            ->selectRaw(
                "(
                    $earthRadius * acos(
                        cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(latitude))
                    )
                ) AS distance",
                [$latitude, $longitude, $latitude]
            )
            ->having('distance', '<=', $radius)
            ->orderBy('distance')
            ->get();
    }
    public function resturant_category_products() {
       return ResturantProduct::where('resturant_id',$this->id)
                ->whereHas('product',function($m){
                    $m->when(request()->has('category_id'),function($q){
                        $q->where('category_id',request()->category_id);
                    })->when(request()->has('subcategory_id'),function($q){
                        $q->where('subcategory_id',request()->subcategory_id);
                    });
                })
                // ->where('status','show')
                ->with(ResturantProduct::RESOURCE_RELATIONS)
                ->get()
                ->groupBy('category_id');
    }
    
    
    public function resturant_categorys() {
       $cats = Category::whereHas('resturant_products',function($q){
           $q->where('resturant_id', $this->id);
           
       })->withCount('resturant_products')->orderBy('order','ASC')->get();
       
       return $cats;
    }
    
    public function is_fav(){
        if(auth('api')->check()){
        $f=Wishlist::where('user_id', auth('api')->user()->id)->where('resturant_id', $this->id)->first();
        return $f?1:0;
        }return 0;
    }
    
    public function reviews(){
        return $this->hasMany(Review::class,'resturant_id');
    }
     public function calcualte_star_rate(){
        $rate=$this->reviews()->avg('rate');
        $this->update(['avg_rate' => round($rate, 2)]);
        return $this->avg_rate;
    }
     public function orders(){
        return $this->hasMany(Order::class,'resturant_id');
    }
}
