<?php

namespace App\Repositories;

use App\Interfaces\ResturantRepositoryInterface;
use App\Models\Resturant;
use App\Models\Order;
use App\Models\ResturantPrice;
use App\Http\Traits\UploadImageTrait;
use App\Models\ResturantArea;
use Mail;
use App\Events\ResturantUpdated;

class ResturantRepository implements ResturantRepositoryInterface 
{
    use UploadImageTrait;

    public function getAllResturants($request) 
    {
        $workspace=app(\App\Services\Dashboard\DesktopDashboardWorkspaceReadAuthority::class);
        if($workspace->selected()){
            $resturants=$workspace->restaurants(Resturant::query());
            if($request->filled('user_id'))$resturants->where('user_id',$request->query('user_id'));
            if($request->filled('resturant_id'))$resturants->where(function($query)use($request){
                $query->where('id',$request->query('resturant_id'))->orWhere('parent_id',$request->query('resturant_id'));
            });
            $resturants->where('name','like','%'.(string)$request->query('search').'%');
            if($request->filled('from_date'))$resturants->where('created_at','>=',$request->query('from_date'));
            if($request->filled('to_date'))$resturants->where('created_at','<=',$request->query('to_date'));
            return $resturants->orderByDesc('id')->paginate(30);
        }
        $searchQuery = trim($request->query('search'));
       
        $resturants= Resturant::when($request->query('user_id'), function($query, $user_id) {
            
                $query->where('user_id', $user_id);
            })->when($request->query('resturant_id'), function($query, $resturant_id) {
                $query->where('id', $resturant_id)->orWhere('parent_id', $resturant_id);
            })->where('name', 'like', '%' . $request->search . '%')->when($request->query('from_date'), function($query, $from_date) {
                $query->where('created_at', '>=', $from_date);
            })->when($request->query('to_date'), function($query, $to_date) {
                $query->where('created_at', '<=', $to_date);
            });
            if(auth('admin')->user()->account_type=='vendor'){
              $resturants=  $resturants->where('user_id',auth('admin')->user()->id);
            }
            
            $resturants=$resturants->orderBy('id', 'desc')
            ->paginate(30);
            return $resturants;
    }

    public function getResturantById($ResturantId) 
    {
        return Resturant::findOrFail($ResturantId);
    }

    public function deleteResturant($ResturantId) 
    {
        $get_user = Resturant::whereId($ResturantId)->delete();
    }

    public function createResturant(array $ResturantDetails) 
    {
        unset($ResturantDetails['logo'],$ResturantDetails['bg_image']);
        unset($ResturantDetails['area_id'], $ResturantDetails['expected_delivery']);
        unset($ResturantDetails['area_lat'], $ResturantDetails['area_lng']);
        $Resturant = Resturant::create($ResturantDetails);       
        if(request()->hasFile('logo') && request()->file('logo')->isValid())
        {
            $Resturant->clearMediaCollection('logo');
            $Resturant->addMediaFromRequest('logo')->toMediaCollection('logo','resturants');
        }
        if(request()->hasFile('bg_image') && request()->file('bg_image')->isValid())
        {
            $Resturant->clearMediaCollection('bg_image');
            $Resturant->addMediaFromRequest('bg_image')->toMediaCollection('bg_image','resturants');
        }
        $Resturant->resturant_areas()->delete();
        if(request('area_id') && !empty(request('area_id')[0])){
            $Resturant->area_id = request('area_id')[0];
            $Resturant->save();
        }
        if(request('area_id') && count(request('area_id'))>0){
        for($i=0;$i<count(request('area_id'));$i++){
            ResturantArea::create([
                    'added_by' => request('added_by'),
                    'resturant_id' => $Resturant->id,
                    'area_id' => !empty(request('area_id')[$i]) ? request('area_id')[$i] : null,
                    'expected_delivery' => request('expected_delivery')[$i] ?? null,
                    'type' => 'kilo',
                    'lat' => request('area_lat')[$i] ?? null,
                    'lng' => request('area_lng')[$i] ?? null,
                ]);
        }
        }
        return $Resturant;
    }

    public function changeStatus($ResturantId,$request) 
    {
        $get_user = Resturant::with('user')->where('id',$ResturantId)->first();

        $get_updated =$get_user->update(['status' => $request]);
        
        app(\App\Services\Dashboard\DesktopDashboardRestaurantEffects::class)->updated($get_user,$ResturantId);

        // if($request == 'closed'){
        //     $to_email = $get_user->user?->email;
        //     if($to_email){
        //         $orders =Order::where('resturant_id',$get_user->id)->where('type','!=','wallet')->where('status','completed')->whereDay('created_at', now()->day)->get();
        //         if($orders->count() > 0){
        //         try{
        //             $mail=Mail::send('emails.send_daily_vendor_orders', ['orders' => $orders, 'vendor' => $get_user->user], function($message) use ( $to_email) {
        //                  $message->to($to_email);
        //                  $message->subject('today report');
        //             });
        //         } catch (\Exception $e) {

        //             return $e->getMessage();
        //         }
        //         }
        //     }
        // }
    }
    public function updateResturant($ResturantId, array $newDetails) 
    {
        unset($newDetails['logo'],$newDetails['bg_image'], $newDetails['email']);
        unset($newDetails['area_id'], $newDetails['expected_delivery']);
        unset($newDetails['area_lat'], $newDetails['area_lng']);
        $Resturant = Resturant::whereId($ResturantId)->first();
        if(request()->hasFile('logo') && request()->file('logo')->isValid())
        {
            $Resturant->clearMediaCollection('logo');
            $Resturant->addMediaFromRequest('logo')->toMediaCollection('logo','resturants');
        }
        if(request()->hasFile('bg_image') && request()->file('bg_image')->isValid())
        {
            $Resturant->clearMediaCollection('bg_image');
            $Resturant->addMediaFromRequest('bg_image')->toMediaCollection('bg_image','resturants');
        }
        if ($Resturant->status != $newDetails['status']){
            app(\App\Services\Dashboard\DesktopDashboardRestaurantEffects::class)->updated($Resturant,$ResturantId);
        }
        if(auth('admin')->check()){
        if(request('area_id')){
        $Resturant->resturant_areas()->delete();

            if(!empty(request('area_id')[0])) { $Resturant->area_id = request('area_id')[0]; }
            $Resturant->save();
        
        
        for($i=0;$i<count(request('area_id'));$i++){
            ResturantArea::create([
                    'added_by' => request('added_by'),
                    'resturant_id' => $Resturant->id,
                    'area_id' => !empty(request('area_id')[$i]) ? request('area_id')[$i] : null,
                    'expected_delivery' => request('expected_delivery')[$i] ?? null,
                    'type' => 'kilo',
                    'lat' => request('area_lat')[$i] ?? null,
                    'lng' => request('area_lng')[$i] ?? null,
                ]);
        }
        }
        }
        return $Resturant->update($newDetails);
    }
    
    public function deleteAllResturants($ids) 
    {
        $Resturants= Resturant::whereIn('id',explode(",",$ids))->delete();
        return $Resturants;
    }
}
