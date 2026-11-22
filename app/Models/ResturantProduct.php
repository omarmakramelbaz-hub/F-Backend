<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\HasMedia;
use Illuminate\Support\Facades\App;
use App\Scopes\AdminScope;

class ResturantProduct extends Model implements HasMedia
{
    use InteractsWithMedia;
    use HasFactory;

    /**
     * What ResturantProductResource reads from each item. A menu loads them together (a few queries for the whole
     * menu) instead of the resource reading them item by item (about ten queries per item).
     */
    public const RESOURCE_RELATIONS = ['vendor', 'resturant', 'category', 'product.product_features', 'product.subcategory', 'media'];

    protected $guarded = [];
    //  protected static function booted()
    // {
    //     static::addGlobalScope(new AdminScope);
    // }

    public function vendor() {
       return $this->belongsTo(\App\Models\User::class,'added_by');
    }

    public function resturant() {
       return $this->belongsTo(\App\Models\Resturant::class,'resturant_id');
    }
    
     public function category() {
       return $this->belongsTo(\App\Models\Category::class,'category_id');
    }

    public function product() {
       return $this->belongsTo(\App\Models\Product::class);
    }
    
    public function calculate_price($feature_id,$product_clean){
        $price=$this->product_price;
        $feature=ProductFeature::find($feature_id);
        if($product_clean){
            if($product_clean=='extra_clear'){
               $price=$price+json_decode($this->price)->extra_clear;  
            }elseif($product_clean=='extra_clean'){
               $price=$price+json_decode($this->price)->extra_clean;  
            }elseif($product_clean=='extra_vacuim'){
               $price=$price+json_decode($this->price)->extra_vacuim;  
            }
        }
        if($feature){
            $feature_name=$feature->name;
            if($feature_name=='half'){
                $price=$price/2;
            }elseif($feature_name=='quarter'){
                $price=$price/4;
            }elseif($feature_name=='combo'){
                $price=$price+json_decode($this->price)->extra_combo;
            }elseif($feature_name=='large'){
                $price=$price+json_decode($this->price)->extra_large;
            }elseif($feature_name=='medium'){
                $price=$price+json_decode($this->price)->extra_medium;
            }
        }
        
        return $price;
    }
    
}
