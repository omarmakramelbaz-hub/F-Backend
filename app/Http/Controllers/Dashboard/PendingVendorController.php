<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\PendingVendor;
use Illuminate\Http\Request;
use App\Models\Resturant;
use App\Models\ResturantArea;
use App\Models\User;
use Mail;
use App\Http\Requests\Dashboard\User\TransferVendorRequest;
use App\Http\Requests\Dashboard\User\PendingVendorRequest;
class PendingVendorController extends Controller
{
    function __construct()
    {
        $this->middleware('permission:pending_vendor-list|pending_vendor-delete', ['only' => ['index']]);
        $this->middleware('permission:pending_vendor-delete', ['only' => ['destroy']]);
        $this->middleware('permission:pending_vendor-edit', ['only' => ['edit','update']]);
        
    }

    public function index()
    {
        $request = request();

        $fields = ['full_name', 'mobile', 'profession_key'];
        $searchQuery = trim($request->query('search'));

        $pending_vendors = PendingVendor::whereIn('type',['vendor','delegate'])->where(function($query) use($searchQuery, $fields) {
            foreach ($fields as $field)
                $query->orWhere($field, 'like',  '%' . $searchQuery .'%');
            })->when($request->query('type'), function($query, $type) {
                $query->where('type',$type);
            })->when($request->query('from_date'), function($query, $from_date) {
                $query->where('created_at', '>=',$from_date);
            })->when($request->query('to_date'), function($query, $to_date) {
                $query->where('created_at', '<=', $to_date);
            })
            ->orderBy('id', 'desc')
            ->paginate(30);

        return view('admin.pending_vendors.index', compact('pending_vendors'));
    }
    public function show(PendingVendor $pending_vendor)
    {
        return view('admin.pending_vendors.show', compact('pending_vendor'));
    }
    public function destroy(PendingVendor $pending_vendor)
    {
        $pending_vendor -> delete();
        return redirect()->route('pending_vendors.index')->with('success',trans('messages.DeleteSuccessfully'));
    }


    public function deleteAll(Request $request)
    {
        $ids = $request->ids;
        PendingVendor::whereIn('id',explode(",",$ids))->delete();
        return response()->json(['success'=> trans('messages.RecordsDeleteSuccessfully')]);
    }

    public function addVendor(PendingVendor $pending){
        return view('admin.pending_vendors.add-vendor', compact('pending'));        
    }
    public function transferVendor(TransferVendorRequest $request){
        $pending_vendor = PendingVendor::where('id', $request->id)->first();
        if(! $pending_vendor){
            return back();
        }
        
        if(count($request->resturant_name) >0){
            for ($i=0; $i <=count($request->resturant_name) ; $i++) { 

                // if(count($request->name) >0){
                    // for ($i=0; $i <count($request->name) ; $i++) { 
                        // $is_exist = User::where('mobile',$request->mobile[$i])->first();
                        // if($is_exist){
                        //     return redirect()->back()->with(['error'=> trans('messages.mobile is already existed')]);
                        // }
                        $user = User::create([
                            'added_by'      => $request->added_by[$i],
                            'email'         => $request->email[$i],
                            'name'          => $request->name[$i],
                            'mobile'        => $request->mobile[$i],
                            'password'      => $request->password[$i],
                            'account_type'  =>$request->account_type[$i],
                            'status'        => 'accepted',
                            'pending_vendor_id' => $pending_vendor->id,
                            'roles_name'    => $request->roles_name[0][$i],
                        ]);
                        if($user->account_type=='vendor'){
                            $user->assignRole(2);

                        }else{
                            $user->assignRole(13);
                            $owner=$user;
                        }
                    
                    // }
                // }

                if($i!=0){
                    
                $resturant = Resturant::create([
                    'added_by'      => $request->added_by[$i],
                    'name'          => $request->resturant_name[$i],
                    'user_id'       => $user->id,
                    'status'        => 'closed',
                ]);
                
                if($i == 1){
                    $parent = $resturant->id;
                }
                else{
                    $resturant['parent_id'] = $parent;
                    $resturant->save();
                }
                
                ResturantArea::create([
                    'added_by'      => $request->added_by[$i],
                    'resturant_id'  => $resturant->id,
                    'area_id'       => $request->area_id[$i],
                    'type'          => 'kilo',
                ]);
                }
            }
            if(isset($parent)){
            $owner->owner_resturant_id=$parent;
            $owner->save();
            }
        }

        
        //send emails by number of branches
        $to_email = $request->email[0];
        if($to_email){
            $mail=Mail::send('emails.send_pending_vendor_acceptance_email', ['user' => $request->name[0], 'email' => $to_email, 'mobile' => $request->mobile, 'password' => $request->password,'account_type'=>$user->account_type], function($message) use ($request, $to_email) {
                $message->to($to_email);
                $message->subject('Send Notification');
            });
        }
        $pending_vendor->update(['status' => 'accepted']);
        return view('admin.resturants.show', compact('pending_vendor','resturant'));
    }
    
    public function sendingDeclineMail(PendingVendor $pending_vendor, Request $request){
        $data = $request->except('_token');
        // dd($data);
            $to_email = $pending_vendor->email;
            $mail=Mail::send('emails.send_pending_vendor_decline_email', ['order' => $pending_vendor, 'data' => $data], function($message) use ($request, $to_email) {
                 $message->to($to_email);
                 $message->subject('Send Notification');
            });
        
        $pending_vendor->update(['status' => 'declined', 'decline_reason' => $request->decline_reason]);
        return redirect()->back()->with('success',trans('messages.EmailSentSuccessfully'));
    }
    
    public function edit(PendingVendor $pending_vendor){
        return view('admin.pending_vendors.edit',compact('pending_vendor'));
    }
    
    public function update(PendingVendor $pending_vendor,PendingVendorRequest $request)
    {
        $Details= $request->validated();
      unset($Details["national_id_image"],$Details["commercial_registration_no_image"],$Details["driving_license_image"],$Details["tax_no_image"]);
        
         $pending_vendor->update($Details);
        if(request()->hasFile('national_id_image') && request()->file('national_id_image')->isValid())
        {
            $pending_vendor->clearMediaCollection('national_id_image');
            $pending_vendor->addMediaFromRequest('national_id_image')->toMediaCollection('national_id_image','pending_vendor');
        }
        if(request()->hasFile('commercial_registration_no_image') && request()->file('commercial_registration_no_image')->isValid())
        {
            $pending_vendor->clearMediaCollection('commercial_registration_no_image');
            $pending_vendor->addMediaFromRequest('commercial_registration_no_image')->toMediaCollection('commercial_registration_no_image','pending_vendor');
        }
        
         if(request()->hasFile('driving_license_image') && request()->file('driving_license_image')->isValid())
        {
            $pending_vendor->clearMediaCollection('driving_license_image');
            $pending_vendor->addMediaFromRequest('driving_license_image')->toMediaCollection('driving_license_image','pending_vendor');
        }
        if(request()->hasFile('tax_no_image') && request()->file('tax_no_image')->isValid())
        {
            $pending_vendor->clearMediaCollection('tax_no_image');
            $pending_vendor->addMediaFromRequest('tax_no_image')->toMediaCollection('tax_no_image','pending_vendor');
        }
           return redirect()->back()->with('success',trans('messages.UpdateSuccessfully'));
          
}
}