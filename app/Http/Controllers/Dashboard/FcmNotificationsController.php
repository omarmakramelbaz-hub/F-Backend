<?php

namespace App\Http\Controllers\Dashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Auth;
use App\Http\Traits\FcmFirebase;
use Notification;
class FcmNotificationsController extends Controller
{
    use FcmFirebase;

 function __construct()
    {
         $this->middleware('permission:fcm_notification-create', ['only' => ['create','store']]);
    }
	public function create()
	{
		$users = User::where('account_type',request()->account_type??'user')->whereHas('tokens')->get();
		return view('admin.fcm_notification', compact('users'));
	}
	
	public function testNotification(){
	    $body_data=[
            'title' => 'test test test',
            'text'  => 'te tetst hbjkf',
             "data" => [
                    "notification_type" => 4,
                    "account_type"  => 'admin',
                    ],
            ];
                    // dd($body_data);

        // $tokens = $FcmToken; 
        // foreach($tokens as $token){
        // }
            return $this->sendFcmNotification('d7BHyPLCW0rG9aCcJgJBon:APA91bGGAexfoWP65WP8Kqx6JdYwINeNxm43KIuOlispqgHvuXJJ8jfaq__x5GUwp5j06hzwOXIGA2F__NgOVciQZ6GvC5M3iuHhWlZe-wf61QijxXUXBYM' ,$body_data) ;
            return 1;
	}

	public function store(Request $request)
	{
	    $FcmToken = [];
		$url = 'https://fcm.googleapis.com/fcm/send';
// 		dd($request->all());
		if($request->send_by == 0){
			$zone = $request->zone_id;
		    $users = User::where('account_type',request()->account_type??'user')->whereHas('tokens')->whereHas('addresses', function($q) use($zone){
		            $q->whereIn('area_id', $zone);
		        })->get();
            foreach($users as $value){
				$FcmToken = array_merge($FcmToken, $value->my_tokens);
			}
		}
		if($request->send_by == 1 && $request->choose_user == 0){
		    $users = User::where('account_type',request()->account_type??'user')->whereHas('tokens')->get();
		      foreach($users as $value){

				$FcmToken = array_merge($FcmToken, $value->my_tokens);

			}
		}
		if($request->send_by == 1 && $request->choose_user == 1){
			foreach($request->user_id as $value){
		        $user = User::where('account_type',request()->account_type??'user')->whereHas('tokens')->where('id', $value)->first();
				$FcmToken = array_merge($FcmToken, $user->my_tokens);
			}
        }  
        
        $body_data=[
            'is_topic' => true,
            'topic' => 'notify-users',
            'title' => $request->title,
            'text'  => $request->body,
             "data" => [
                    "notification_type" => 4,
                    "account_type"  => request()->account_type??'user',
                    ],
            ];
                    // dd($body_data);

        // $tokens = $FcmToken; 
        // foreach($tokens as $token){
        // }
        // dd($FcmToken);
            $this->sendFcmNotificationTobic($FcmToken ,$body_data) ;
        return redirect()->back()->with('success',trans('messages.AddSuccessfully'));
	}
	
	  public function SaveToken(Request $request){
        $user=User::find($request->user_id);
        $user->newOrExistingToken($request['token']);
        $user->device_token=$request['token'];
        $user->save();
          $body_data=[
            'title' => "hello",
            'text'  => "firebase",
             "data" => [
                    "notification_type" => 5,
                    "account_type"  => 'admin',
                    ],
            ];
          // dd($body_data);
          

        // $this->sendFcmNotification($user->device_token ,$body_data) ;
        
        return response()->json([
            'success'=>true,
            'message'=>'user token updated successfully',
        ]);


    }
    
     public function send_chat_notification(Request $request){
        $values = $request->validate(['user2' => 'required|integer|min:1', 'message' => 'required|string|max:5000', 'inbox_id' => 'nullable|integer|min:1']);
        $actor = auth('admin')->user();
        abort_unless($actor, 401);
        $inbox = app(\App\Services\Dashboard\SupportInbox::class);
        $scope = isset($values['inbox_id']) ? (int) $values['inbox_id'] : null;
        $user = $inbox->partner($actor, (int) $request->user2, $scope);
        $senderId = $inbox->inboxId($actor, $scope);
          $body_data=[
            'title' => "new message from ".$actor->name,
            'text'  => $request->message,
             "data" => [
                    "notification_type" => 10,
                    "account_type"  => $user->account_type,
                    "reciever_id"  => $user->id,
                    'sender_id'=>$senderId,
                    'sender_account_type'=>auth('admin')->user()->account_type,
                    'sender_fcm_id'=>auth('admin')->user()->fcm_id,
                    'sender_device_token'=>auth('admin')->user()->device_token,
                     'click_action'=>env('APP_URL')."/admin/chat/?user_id=".$senderId
                    ],
            ];
        //   dd($body_data,$user);
          
        if($user->device_token ){
         $this->sendFcmNotification($user->device_token ,$body_data) ;
        }
        // if($user->fcm_id ){
        //  $this->sendFcmNotification($user->fcm_id ,$body_data) ;
        // }
        if($user->my_tokens ){
            $this->sendFcmNotification($user->my_tokens ,$body_data) ;
        }
        // dd($this->sendFcmNotification($user->fcm_id ,$body_data) );
        return response()->json([
            'success'=>true,
            'message'=>'send fcm  successfully',
        ]);


    }
    
    public function chat(){
        $actor = auth('admin')->user();
        $inbox = app(\App\Services\Dashboard\SupportInbox::class);
        abort_unless($inbox->canAccess($actor), 403);
        $staff = $inbox->isStaff($actor);
        $selected = request()->validate(['user_id' => 'nullable|integer|min:1', 'inbox_id' => 'nullable|integer|min:1']);
        $inboxIds = $inbox->inboxIds($actor);
        $inboxId = $inbox->inboxId($actor, isset($selected['inbox_id']) ? (int) $selected['inbox_id'] : null);
        $user = isset($selected['user_id']) ? $inbox->partner($actor, (int) $selected['user_id'], $inboxId)
            : ($staff ? null : $inbox->partner($actor, $inbox->centralId(), $inboxId));
        $users = $staff ? User::withoutGlobalScopes()->where('account_type', '!=', 'admin')->orderBy('name')->get(['id', 'name', 'mobile', 'account_type']) : collect([$user]);
        return view('admin.chat', compact('user', 'users', 'staff', 'inboxId', 'inboxIds'));
    }
    
    
    
}
