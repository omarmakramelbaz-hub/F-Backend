<?php

namespace App\Http\Controllers\Dashboard;
use App\Interfaces\UserRepositoryInterface;
use App\Models\User;
use App\Http\Requests\Dashboard\User\StoreUserRequest;
use App\Http\Traits\UploadImageTrait;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Role;
use App\Models\UserAddress;
use App\Models\Resturant;
use App\Models\Wishlist;

use App\Http\Traits\HomeTraits;
class UserController extends Controller
{
    use UploadImageTrait;use HomeTraits;
    private UserRepositoryInterface $userRepository;
    public function __construct(UserRepositoryInterface $userRepository) 
    {     
        $this->middleware('permission:'.request()->account_type.'-list', ['only' => ['index','show']]);
        $this->middleware('permission:'.request()->account_type.'-create', ['only' => ['create','store']]);
        $this->middleware('permission:'.request()->account_type.'-edit', ['only' => ['update','edit']]);
        $this->middleware('permission:'.request()->account_type.'-delete', ['only' => ['destroy','delete_all']]);
        $this->userRepository = $userRepository;
    }


    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
    
        $users = $this->userRepository->getAllUsers($request);
        return view('admin.users.index', compact('users'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $user = new User() ;
        $areas=$this->areas_data();
        $roles=Role::select('id','guard_name','name')->get();
        return view('admin.users.create', compact('user','roles','areas'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreUserRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreUserRequest $request)
    {
        $userDetails = $request->except('_token');
        $user = $this->userRepository->createUser($userDetails);
        if($user == false){
            return redirect()->back()->with('info',trans('messages.done but email will not sent'));
        }
        if(!is_object($user) && $user == 2){
            return redirect()->back()->with('error',trans('messages.account exist'));
        }
        return redirect()->back()->with('success',trans('messages.AddSuccessfully'));
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\user  $user
     * @return \Illuminate\Http\Response
     */
    public function show(User $user)
    {   
        app(\App\Services\Dashboard\DesktopDashboardWorkspaceReadAuthority::class)->user($user);
        return view('admin.users.show', compact('user'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\user  $user
     * @return \Illuminate\Http\Response
     */
    public function edit(User $user)
    {   
        app(\App\Services\Dashboard\DesktopDashboardWorkspaceReadAuthority::class)->user($user,'edit');

        if(auth('web')->check() && auth('web')->user()->id == $user->id || auth('admin')->check()){
                $roles=Role::select('id','guard_name','name')->get();
                $userRole = $user->roles?->pluck('name')->first();
                $areas=$this->areas_data();
                return view('admin.users.edit', compact('user','roles','userRole','areas'));
        }
        else{
            return back();
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateUserRequest  $request
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\Response
     */
    public function update(StoreUserRequest $request, User $user)
    {
        $userDetails = $request->except('_token','_method');
        $this->userRepository->updateUser($user->id, $userDetails);
    
        return redirect()->back()->with('success',trans('messages.UpdateSuccessfully'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\Response
     */
    public function destroy(User $user)
    {
        $this->userRepository->deleteUser($user->id);
        return redirect()->back()->with('success',trans('messages.DeleteSuccessfully'));
    }
    public function userWishlistsDelete($id)
    {
        Wishlist::where('id',$id)->delete();
        return redirect()->back()->with('success',trans('messages.DeleteSuccessfully'));
    }
    public function deleteAll(Request $request)
    {
        $ids = $request->ids;
        $this->userRepository->deleteAllUsers($ids);
        return response()->json(['success'=> trans('messages.RecordsDeleteSuccessfully')]);

    }
    public function userAddressDelete($id){
        $car = UserAddress::find($id);
        $car->delete();
        return redirect()->back()->with('success',trans('messages.DeleteSuccessfully'));
    }
    
    public function resturantMap(){
        app(\App\Services\Dashboard\DesktopDashboardWorkspaceReadAuthority::class)->permission(['resturant-list']);
        $vendors=Resturant::whereNotNull(['lat','lng'])->get();
        $arr= [];
        foreach($vendors as $vendor){
            $img = $vendor->getFirstMediaUrl('logo','thumb');
            $arr[] = [$vendor['name'],$vendor['lat'], $vendor['lng'],url('/admin/resturants/'.$vendor['id']), $img,__('main.'.$vendor['status'])];
        }
        // dd($arr);
        return view('admin.users.resturant_map', compact('arr'));

    }
    
    
    public function delegateMap(){
        app(\App\Services\Dashboard\DesktopDashboardWorkspaceReadAuthority::class)->central(['delegate-list']);
        $vendors=User::where('account_type','delegate')->whereNotNull(['lat','lng'])->get();
        $arr= [];
        foreach($vendors as $vendor){
            $arr[] = [$vendor['name'],$vendor['lat'], $vendor['lng'],url('/admin/users/'.$vendor['id'].'?account_type=delegate'), null];
        }
        // dd($arr);
        return view('admin.users.delegate_map', compact('arr'));

    }
    
    public function changeStatus(User $user){
        $user->update(['status' => request('status')]);
        if($user->status=='accepted'){
            $user->update(['expiration_date'=>null]);
        }
        return redirect()->back()->with('success',trans('messages.UpdateSuccessfully'));

    }
    
    public function go_drive_activation($id){
        $user=User::find($id);
        if($user){
            $orders=$user->orders()->whereNotNull('shipping_cancelled_block')->get();
            foreach($orders as $order){
                $order->update(['shipping_cancelled_block'=>null]);
            }
        }
        return redirect()->back()->with('success',trans('messages.UpdateSuccessfully'));
    }
    

}
