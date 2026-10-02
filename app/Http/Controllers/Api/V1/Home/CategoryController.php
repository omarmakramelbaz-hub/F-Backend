<?php

namespace App\Http\Controllers\Api\V1\Home;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\User;
use App\Models\Product;
use App\Models\ResturantProduct;
use App\Models\Resturant;
use App\Models\Area;
use App\Models\Wishlist;
use App\Models\LastSearch;
use Illuminate\Http\Request;
use App\Http\Resources\Api\Home\CategoryResource;
use App\Http\Resources\Api\Home\ProductResource;
use App\Http\Resources\Api\Vendor\ResturantResource;
use App\Http\Resources\Api\Vendor\ResturantSingleResource;
use App\Http\Resources\Api\Vendor\ResturantProductResource;
use App\Http\Resources\Api\Vendor\FeaturedResturantResource;
use App\Http\Resources\Api\Home\ProductCategoryResource;
use App\Http\Traits\ApiResponses;
use Notification;
use JWTAuth;
use Validator;
use Auth;
use \Carbon\Carbon;
use GuzzleHttp\Client;

class CategoryController extends Controller
{

    use ApiResponses;

    public function getCategorys(Request $request)
    {
        $category = Category::query();
        if (!empty($request->parent_id)) {
            $category = $category->where('parent_id', $request->parent_id);
        } else {
            $category = $category->whereNull('parent_id');
        }

        $category = $category->where('status', 'show')->with('parent')->get();
        $categoryData = CategoryResource::collection($category);
        return $this->successResponse($categoryData, __('api.get all Categorys'));
    }


    public function getProducts(Request $request)
    {
        $product = Product::query();
        if (!empty($request->category_id)) {
            $product = $product->where('category_id', $request->category_id);
        }

        if (!empty($request->subcategory_id)) {
            $product = $product->where('subcategory_id', $request->subcategory_id);
        }

        $product = $product->with('subcategory', 'category')->get();
        $productData = ProductResource::collection($product);
        return $this->successResponse($productData, __('api.get all Products'));

    }

    public function getSingleProduct(Product $product)
    {
        $productData = ProductResource::make($product);
        return $this->successResponse($productData, __('api.get single of Product'));
    }


    public function getProduct($id)
    {
        $product = ResturantProduct::find($id);
        if ($product) {
            $productData = new ResturantProductResource($product);
            return $this->successResponse($productData, __('api.get single Product'));
        }
        return $this->errorResponse(__('api.this product not found'));
    }



    public function getResturant(Request $request, Resturant $resturant)
    {
        $resturantData = ResturantSingleResource::make($resturant);
        return $this->successResponse($resturantData, __('api.get single resturant'));

    }
    public function getResturantProduct(Request $request, Resturant $resturant)
    {
        $resturantData = ProductCategoryResource::collection($resturant->resturant_category_products());
        return $this->successResponse($resturantData, __('api.get single resturant'));

    }

    //   public function getResturants(Request $request){
//     $resturant = Resturant::query()->where('status','!=','hide')
//   ;
//     if(! empty($request->most_reviewed)  &&  $request->most_reviewed == 1){
//       $resturant =$resturant->orderBy('avg_rate','DESC');
//     }

    //     // dd($data);
//     if (!empty($request->is_featured) && $request->is_featured == 'yes') {
//     // $resturant = $resturant->where('is_featured', 'yes')
//     //     ->orderByRaw('sortby_is_featured IS NULL, sortby_is_featured ASC');


    //         return $this->getFeaturedResturants($request);
//     }
//     if(! empty($request->lat)  && ! empty($request->lng) ){
//       $latitude = $request->lat;
//       $longitude = $request->lng;
//       $city_name = getCityName($latitude,$longitude);
//     $area = Area::where('title_ar', 'LIKE', '%' . $city_name . '%')->orWhere('title_en', 'LIKE', '%' . $city_name . '%')->first();
//     // dd($area);
//     //   $resturant =$resturant->select(\DB::raw('*, ( 6367 * acos( cos( radians('.$latitude.') ) * cos( radians( lat ) ) *
//     //                   cos( radians( lng ) - radians('.$longitude.') ) + sin( radians('.$latitude.') ) * sin( radians( lat ) ) ) ) AS distance'))
//     //                 ->having('distance', '<', 10000000)
//     //                 ->orderBy('distance','asc')->orWhereHas('resturant_areas',function($q) use ($area){
//     //                     $q->where('area_id' ,$area->id);
//     //                 })->orWhere('under_contract','yes');
//      $resturant =$resturant->select(\DB::raw('*, ( 6367 * acos( cos( radians('.$latitude.') ) * cos( radians( lat ) ) *
//                       cos( radians( lng ) - radians('.$longitude.') ) + sin( radians('.$latitude.') ) * sin( radians( lat ) ) ) ) AS distance'))
//                     ->having('distance', '<', 10000000)
//                     ->orderByRaw("
//                         CASE 
//                             WHEN under_contract = 'no' THEN 1
//                             WHEN under_contract = 'yes' THEN 2
//                             ELSE 3
//                         END
//                     ")->orderByRaw("
//                         CASE 
//                             WHEN status = 'opened' THEN 1
//                             WHEN status = 'busy' THEN 2
//                             WHEN status = 'closed' THEN 3
//                             ELSE 4
//                         END
//                     ")
//                     ->orderBy('distance','asc')
//                     ->WhereHas('resturant_areas',function($q) use ($area){
//                         $q->where('area_id' ,$area->id);
//                     })->when(function($query) {
//                         $query->where('under_contract', '!=', 'yes');
//                     }, function($query) {
//                         $query->has('resturant_products');
//                     })->orWhere('under_contract','yes');

    //     }

    //     if(! empty($request->city_name)){
//       $resturant =$resturant->where('city_name', 'LIKE', '%' . $request->city_name . '%');
//     }

    //     if(! empty($request->most_researched)  &&  $request->most_researched == 1){
//       $resturant =$resturant->withCount('last_searches')
//         ->orderBy('last_searches_count', 'desc');
//     }

    //     if(! empty($request->search)){
//     //  $searchQuery = trim($request->query('search'));
//         $searchKeywords = explode(' ', $request->input('search'));


    //         $resturant =$resturant->with('resturant_products')->with('resturant_areas')->where(function($query) use ($searchKeywords)
//         {
//                 foreach($searchKeywords as $searchQuery){
//                     $query->orWhere('name', 'LIKE', '%' . $searchQuery . '%');

    //                     $columns = ['address', 'delivery_time'];

    //                     foreach ($columns as $column ) {
//                         $query->orWhere($column, 'LIKE', '%' . $searchQuery . '%');
//                     }

    //                     $query->orWhereHas('resturant_products', function($q) use ($searchQuery) {
//                         $q->where(function($q) use ($searchQuery) {
//                             $q->orWhere('product_name', 'LIKE', '%' . $searchQuery . '%');
//                             $q->orWhere('product_description', 'LIKE', '%' . $searchQuery . '%');
//                         });
//                     });

    //                 }
//         });


    //     //   $resturant =$resturant->where('name', 'like', '%' . $request->search . '%')->orWhere('address', 'like', '%' . $request->search . '%');

    //      $searchQuery = trim($request->query('search'));
//         if(auth('api')->check()){
//             $search=LastSearch::where('user_id',auth('api')->user()->id)->where('search',request()->search)->where('searchable_type','Resturant')->first();
//             $search_rest = Resturant::where('name',request()->search)->first();
//             // dd(auth('api')->user()->id);

    //             if($search){
//                 $search->update(['updated_at'=>Carbon::now()]);
//             }else{
//                 $search= LastSearch::create(['user_id'=>auth('api')->user()->id,'search'=>request()->search,'searchable_type'=>'Resturant']);
//             }
//             if($search_rest){
//                 $search->update(['resturant_id' => $search_rest->id]);
//             }
//         }
//     }
//   // Ordering by status and under_contract
// $resturant->orderByRaw("
//     CASE 
//         WHEN under_contract = 'no' THEN 1
//         WHEN under_contract = 'yes' THEN 2
//         ELSE 3
//     END
// ")->orderByRaw("
//     CASE 
//         WHEN status = 'opened' THEN 1
//         WHEN status = 'busy' THEN 2
//         WHEN status = 'closed' THEN 3
//         ELSE 4
//     END
// ");

    // // Fetch data
// $resturant = $resturant->get();
// $productData = ResturantResource::collection($resturant);
//     // $resturant = $resturant->whereNotNull(['lat','lng'])->has('resturant_products')->groupBy('name')->get();
//     // $resturant = $resturant->has('resturant_products')->groupBy('name')->get();
//     if($resturant->isEmpty()){
//     //     $resturant = Resturant::whereNotNull(['lat','lng'])->where('under_contract','yes')->WhereHas('resturant_products',function($q){
//     //     $q->whereHas('resturant',function($w){
//     //         $w->where('under_contract','no');
//     //     });
//     // })->groupBy('name')->get();
//     $productData=null;
//     }

    //     return $this->successResponse($productData,__('api.get all resturants'));

    //   }


    public function getResturants(Request $request)
    {
        $resturant = Resturant::query()->where('status', '!=', 'hide');
        if (!empty($request->most_reviewed) && $request->most_reviewed == 1) {
            $resturant = $resturant->orderBy('avg_rate', 'DESC');
        }

        if (!empty($request->is_featured) && $request->is_featured == 'yes') {
            return $this->getFeaturedResturants($request);
        }

        if (!empty($request->lat) && !empty($request->lng)) {
            $latitude = $request->lat;
            $longitude = $request->lng;

            // Get all restaurants with their areas that have lat/lng and expected_delivery
            $allRestaurants = clone $resturant;
            $allRestaurants = $allRestaurants->with([
                'resturant_areas' => function ($q) {
                    $q->whereNotNull('lat')
                        ->whereNotNull('lng')
                        ->whereNotNull('expected_delivery');
                }
            ])->get();

            // Filter restaurants where user's location is within expected_delivery range of any area
            $filteredRestaurantIds = [];
            foreach ($allRestaurants as $restaurant) {
                if ($this->restaurantMatchesCustomerLocation($restaurant, $latitude, $longitude)) {
                    $filteredRestaurantIds[] = $restaurant->id;
                }
            }


            // Apply the filter to the query
            if (!empty($filteredRestaurantIds)) {
                $resturant = $resturant->whereIn('id', $filteredRestaurantIds)
                    ->orWhere('under_contract', 'yes');
            } else {
                // If no restaurants found in range, only show under_contract restaurants
                $resturant = $resturant->where('under_contract', 'yes');
            }
        }
        if (!empty($request->city_name)) {
            $resturant = $resturant->where('city_name', 'LIKE', "%{$request->city_name}%");
        }
        if (!empty($request->most_researched) && $request->most_researched == 1) {
            $resturant = $resturant->withCount('last_searches')
                ->orderBy('last_searches_count', 'desc');
        }
        if (!empty($request->search)) {
            $searchKeywords = explode(' ', $request->input('search'));

            $resturant = $resturant->with(['resturant_products', 'resturant_areas'])
                ->where(function ($query) use ($searchKeywords) {
                    foreach ($searchKeywords as $searchQuery) {
                        $query->orWhere('name', 'LIKE', "%$searchQuery%")
                            ->orWhere('address', 'LIKE', "%$searchQuery%")
                            ->orWhere('delivery_time', 'LIKE', "%$searchQuery%")
                            ->orWhereHas('resturant_products', function ($q) use ($searchQuery) {
                                $q->where('product_name', 'LIKE', "%$searchQuery%")
                                    ->orWhere('product_description', 'LIKE', "%$searchQuery%");
                            });
                    }
                });
            if (auth('api')->check()) {
                $search = LastSearch::updateOrCreate(
                    [
                        'user_id' => auth('api')->user()->id,
                        'search' => request()->search,
                        'searchable_type' => 'Resturant'
                    ],
                    ['updated_at' => Carbon::now()]
                );

                $search_rest = Resturant::where('name', request()->search)->first();
                if ($search_rest) {
                    $search->update(['resturant_id' => $search_rest->id]);
                }
            }
        }
        $resturant->orderByRaw("
        CASE 
            WHEN under_contract = 'yes' THEN 5  -- المطاعم تحت التعاقد في النهاية
            WHEN status = 'opened' THEN 1       -- المفتوح أولًا
            WHEN status = 'busy' THEN 2         -- المشغول ثانيًا
            WHEN status = 'closed' THEN 3       -- المغلق ثالثًا
            ELSE 4
        END
    ");

        $resturant = $resturant->get();
        $productData = ResturantResource::collection($resturant);

        if ($resturant->isEmpty()) {
            $productData = null;
        }

        return $this->successResponse($productData, __('api.get all resturants'));
    }


    private function restaurantMatchesCustomerLocation($restaurant, float $latitude, float $longitude): bool
    {
        $usableAreaFound = false;

        foreach ($restaurant->resturant_areas as $restaurantArea) {
            if (
                $restaurantArea->lat === null ||
                $restaurantArea->lng === null ||
                $restaurantArea->expected_delivery === null
            ) {
                continue;
            }

            $usableAreaFound = true;
            $distance = $this->distanceKm(
                $latitude,
                $longitude,
                (float) $restaurantArea->lat,
                (float) $restaurantArea->lng
            );

            if ($distance <= (float) $restaurantArea->expected_delivery) {
                return true;
            }
        }

        // Some Fasakhansta branches have a valid branch pin but no delivery-area
        // rows yet. Keep them discoverable near the customer instead of hiding
        // them completely from the customer app.
        if (!$usableAreaFound && !empty($restaurant->lat) && !empty($restaurant->lng)) {
            return $this->distanceKm(
                $latitude,
                $longitude,
                (float) $restaurant->lat,
                (float) $restaurant->lng
            ) <= 25;
        }

        return false;
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $lat1 = deg2rad($lat1);
        $lng1 = deg2rad($lng1);
        $lat2 = deg2rad($lat2);
        $lng2 = deg2rad($lng2);

        $latDiff = $lat2 - $lat1;
        $lngDiff = $lng2 - $lng1;

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos($lat1) * cos($lat2) *
            sin($lngDiff / 2) * sin($lngDiff / 2);

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function getFeaturedResturants(Request $request)
    {
        $resturant = Resturant::query();
        if (!empty($request->lat) && !empty($request->lng)) {

            $latitude = $request->lat;
            $longitude = $request->lng;

            // Get all restaurants with their areas that have lat/lng and expected_delivery
            $allRestaurants = clone $resturant;
            $allRestaurants = $allRestaurants->with([
                'resturant_areas' => function ($q) {
                    $q->whereNotNull('lat')
                        ->whereNotNull('lng')
                        ->whereNotNull('expected_delivery');
                }
            ])->get();

            // Filter restaurants where user's location is within expected_delivery range of any area
            $filteredRestaurantIds = [];
            foreach ($allRestaurants as $restaurant) {
                if ($this->restaurantMatchesCustomerLocation($restaurant, $latitude, $longitude)) {
                    $filteredRestaurantIds[] = $restaurant->id;
                }
            }

            // Apply the filter to the query
            if (!empty($filteredRestaurantIds)) {
                $resturant = $resturant->whereIn('id', $filteredRestaurantIds)
                    ->orWhere('under_contract', 'yes');
            } else {
                // If no restaurants found in range, only show under_contract restaurants
                $resturant = $resturant->where('under_contract', 'yes');
            }

            $resturant->orderByRaw("
        CASE 
            WHEN under_contract = 'yes' THEN 5  -- المطاعم تحت التعاقد في النهاية
            WHEN status = 'opened' THEN 1       -- المفتوح أولًا
            WHEN status = 'busy' THEN 2         -- المشغول ثانيًا
            WHEN status = 'closed' THEN 3       -- المغلق ثالثًا
            ELSE 4
        END
    ");

        }


        $resturant = $resturant->where('status', '!=', 'hide')->where('is_featured', 'yes')->orderByRaw('sortby_is_featured IS NULL, sortby_is_featured ASC')

            ->get();
        $productData = ResturantResource::collection($resturant);
        return $this->successResponse($productData, __('api.get all resturants'));

    }

    public function getLastSearch()
    {
        try {
            if (auth('api')->check()) {
                $searches = LastSearch::where('user_id', auth('api')->user()->id)->when(request()->has('type'), function ($q) {
                    $q->where('searchable_type', request()->type);
                })->take(10)->get();
                return $this->successResponse($searches, trans('api.success data'));
            }
        } catch (\Exception $e) {
            return $e;
            return $this->errorResponse(__('messages.SomeThingWrong'));
        }
    }

    public function deleteSearch($id)
    {
        try {
            $search = LastSearch::find($id);
            if ($search) {
                $search->delete();
            }
            $searches = LastSearch::where('user_id', auth('api')->user()->id)->when(request()->has('type'), function ($q) {
                $q->where('searchable_type', request()->type);
            })->take(10)->get();
            return $this->successResponse($searches, trans('api.success data'));

        } catch (\Exception $e) {
            return $e;
            return $this->errorResponse(__('messages.SomeThingWrong'));
        }
    }


    public function wishlistResturant(Request $request, Resturant $resturant)
    {
        if ($resturant->is_fav()) {
            Wishlist::where('user_id', auth('api')->user()->id)->where('resturant_id', $resturant->id)->delete();
            return $this->successResponse(null, __('api.delete wishlist done'));

        } else {
            Wishlist::create([
                'user_id' => auth('api')->user()->id,
                'resturant_id' => $resturant->id,
            ]);
            $resturantData = ResturantResource::make($resturant);
            return $this->successResponse($resturantData, __('api.add resturant to wishlist'));
        }
    }
}