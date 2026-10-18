<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\AddressRequest;
use App\Http\Resources\Api\Auth\UserAddressResource;
use Illuminate\Http\Request;
use Notification;
use App\Http\Traits\ApiResponses;
use App\Models\UserAddress;
use App\Models\Area;
use App\Models\ResturantArea;
use App\Models\User;
use App\Http\Traits\UploadImageTrait;
use Carbon\Carbon;
use Exception;

class AddressController extends Controller
{
    //
    use ApiResponses;
    use UploadImageTrait;

    public function index()
    {
        try {
            // if(! empty(request('area_id')) ){
            //     $addresses= auth('api')->user()->addresses->whereIn('area_id',request('area_id'));
            // }
            if (!empty(request('area_id'))) {
                // Get the user's current restaurant from their latest cart
                $latestCart = auth('api')->user()->carts()->orderBy('id', 'desc')->first();

                if ($latestCart && $latestCart->resturant_id) {
                    // Get all restaurant areas for this restaurant with lat/lng
                    $restaurantAreas = ResturantArea::where('resturant_id', $latestCart->resturant_id)
                        ->whereNotNull('lat')
                        ->whereNotNull('lng')
                        ->whereNotNull('expected_delivery')
                        ->get();

                    // Get all user addresses with lat/lng
                    $userAddresses = auth('api')->user()->addresses()
                        ->whereNotNull('lat')
                        ->whereNotNull('lng')
                        ->get();

                    // Filter addresses that are within range of any restaurant area
                    $addresses = $userAddresses->filter(function ($address) use ($restaurantAreas) {
                        foreach ($restaurantAreas as $restaurantArea) {
                            // Calculate distance using Haversine formula
                            $distance = $this->calculateDistance(
                                $address->lat,
                                $address->lng,
                                $restaurantArea->lat,
                                $restaurantArea->lng
                            );
                            // If address is within expected_delivery range (in km), include it
                            if ($distance <= $restaurantArea->expected_delivery) {
                                return true;
                            }
                        }
                        return false;
                    });
                } else {
                    $addresses = collect();
                }
            }


            if (empty(request('area_id')) || $addresses->count() == 0) {
                $addresses = (auth('api')->user()->addresses);
            }
            $user = UserAddressResource::collection($addresses);
            return $this->successResponse($user, trans('messages.success data'));

        } catch (\Exception $e) {
            return $this->errorResponse(__('messages.SomeThingWrong'));
        }
    }

    public function store(AddressRequest $request)
    {
        try {
            $address = auth('api')->user()->addresses()->create($request->validated());

            $city_name = getCityName($address->lat, $address->lng);

            $area = Area::where('title_ar', 'like', '%' . $city_name . '%')->orWhere('title_en', 'like', '%' . $city_name . '%')->first();
            if ($area) {
                $address->area_id = $area->id;
                $address->save();
            }
            $user = new UserAddressResource($address);
            return $this->successResponse($user, trans('messages.AddSuccessfully'));
        } catch (\Exception $e) {
            return $this->errorResponse(__('messages.SomeThingWrong'));
        }

    }

    public function show($id)
    {
        try {
            $address = auth('api')->user()->addresses()->where('id', $id)->first();
            if ($address) {
                $user = new UserAddressResource($address);

                return $this->successResponse($user, trans('messages.success data'));
            } else {
                return $this->errorResponse(__('messages.SomeThingWrong'));
            }
        } catch (\Exception $e) {
            return $this->errorResponse(__('messages.SomeThingWrong'));
        }

    }

    public function update($id, AddressRequest $request)
    {
        try {
            $address = auth('api')->user()->addresses()->where('id', $id)->first();
            if ($address) {
                $address->update($request->validated());
                $user = UserAddressResource::collection(auth('api')->user()->addresses);

                $city_name = getCityName($address->lat, $address->lng);

                $area = Area::where('title_ar', 'like', '%' . $city_name . '%')->orWhere('title_en', 'like', '%' . $city_name . '%')->first();
                if ($area) {
                    $address->area_id = $area->id;
                    $address->save();
                }
                return $this->successResponse($user, trans('messages.UpdateSuccessfully'));
            } else {
                return $this->errorResponse(__('messages.SomeThingWrong'));
            }
        } catch (\Exception $e) {
            return $this->errorResponse(__('messages.SomeThingWrong'));
        }

    }
    public function destroy($id)
    {
        try {
            $address = auth('api')->user()->addresses()->where('id', $id)->first();
            if ($address) {
                $address->delete();
                $user = UserAddressResource::collection(auth('api')->user()->addresses);

                return $this->successResponse($user, trans('messages.DeleteSuccessfully'));
            } else {
                return $this->errorResponse(__('messages.SomeThingWrong'));
            }
        } catch (\Exception $e) {
            return $this->errorResponse(__('messages.SomeThingWrong'));
        }

    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     * Returns distance in kilometers
     */
    private function calculateDistance($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371; // Earth's radius in kilometers

        $lat1 = deg2rad($lat1);
        $lng1 = deg2rad($lng1);
        $lat2 = deg2rad($lat2);
        $lng2 = deg2rad($lng2);

        $latDiff = $lat2 - $lat1;
        $lngDiff = $lng2 - $lng1;

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos($lat1) * cos($lat2) *
            sin($lngDiff / 2) * sin($lngDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = $earthRadius * $c;

        return $distance;
    }
}
