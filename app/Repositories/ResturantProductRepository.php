<?php

namespace App\Repositories;

use App\Interfaces\ResturantProductRepositoryInterface;
use App\Models\ResturantProduct;
use App\Models\ResturantProductPrice;
use App\Http\Traits\UploadImageTrait;
use App\Events\ResturantProductUpdated;

class ResturantProductRepository implements ResturantProductRepositoryInterface 
{
    use UploadImageTrait;

    public function getAllResturantProducts($request) 
    {
        $searchQuery = trim($request->query('search'));
       if(auth('admin')->check()){
        return ResturantProduct::where('added_by',auth('api')->user()->id)->orderBy('id', 'desc')->get();
       }else{
           if(!request()->parent_id){
          return ResturantProduct::whereHas('resturant',function($q){
              $q->where('user_id',auth('api')->user()->id);
          })->orderBy('id', 'desc')->get(); 
           }else{
                 return ResturantProduct::whereHas('resturant',function($q){
              $q->where('id',request()->parent_id);
          })->orderBy('id', 'desc')->get(); 
           }
       }
    }

    public function getResturantProductById($ResturantProductId) 
    {
        return ResturantProduct::findOrFail($ResturantProductId);
    }

    public function deleteResturantProduct($ResturantProductId) 
    {
        $get_user = ResturantProduct::whereId($ResturantProductId)->delete();
    }

    public function createResturantProduct(array $ResturantProductDetails) 
    {
        // dd($ResturantProductDetails);
        unset($ResturantProductDetails['subcategory_id']);
        unset($ResturantProductDetails['extra_medium']);
                unset($ResturantProductDetails['extra_combo']);
                unset($ResturantProductDetails['extra_large']);
                unset($ResturantProductDetails['extra_clean']);
                unset($ResturantProductDetails['extra_clear']);
                unset($ResturantProductDetails['extra_vacuim']);
                unset($ResturantProductDetails['product_image']);

        $ResturantProduct = ResturantProduct::create($ResturantProductDetails);       
        $ResturantProduct->price= ['extra_combo' => request('extra_combo') ,
                                    'extra_large' => request('extra_large') ,
                                    'extra_medium' => request('extra_medium') ,
                                    'extra_clean' => request('extra_clean') ,
                                    'extra_clear' => request('extra_clear') ,
                                    'extra_vacuim' => request('extra_vacuim') ,
            ];
        $ResturantProduct->save();
        if(request()->hasFile('product_image') && request()->file('product_image')->isValid()){
                $ResturantProduct->addMediaFromRequest('product_image')->toMediaCollection('product_image','products');
            }
        return $ResturantProduct;
    }

    public function updateResturantProduct($ResturantProductId, array $newDetails) 
    {
        // dd($newDetails);
        unset($newDetails['extra_medium']);
                unset($newDetails['extra_combo']);
                unset($newDetails['extra_large']);
                unset($newDetails['extra_clean']);
                unset($newDetails['extra_clear']);
                unset($newDetails['extra_vacuim']);
                unset($newDetails['product_image']);
                unset($newDetails['subcategory_id'],$newDetails['subcatid']);

        $ResturantProduct = ResturantProduct::whereId($ResturantProductId)->first();
        if(request()->hasFile('product_image') && request()->file('product_image')->isValid()){
                $ResturantProduct->clearMediaCollection('product_image');
                $ResturantProduct->addMediaFromRequest('product_image')->toMediaCollection('product_image','products');
            }
        $updated  =$ResturantProduct->update($newDetails);
        // dd(request('extra_clear'),request()->extra_clear);
        $prices= ['extra_combo' => request('extra_combo') ,
                'extra_large' => request('extra_large') ,
                'extra_medium' => request('extra_medium') ,
                'extra_clean' => request('extra_clean') ,
                'extra_clear' => request('extra_clear') ,
                'extra_vacuim' => request('extra_vacuim') ,
            ];
        $ResturantProduct->update(['price' => $prices ]);
                    // dd($ResturantProduct->price);
                    $ResturantProduct= ResturantProduct::find($ResturantProductId);
        app(\App\Services\Dashboard\DesktopDashboardProductEffects::class)->updated($ResturantProduct,$ResturantProductId);

// dd($ResturantProduct);
        return $updated;
    }
    
    public function deleteAllResturantProducts($ids) 
    {
        $ResturantProducts= ResturantProduct::whereIn('id',explode(",",$ids))->delete();
        return $ResturantProducts;
    }
}
