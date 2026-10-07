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
         $this->middleware('permission:fcm_notification-create', ['only' => ['create','store','campaignStatus','campaignStep','campaignResume']]);
    }
	public function create()
	{
		$type = request()->validate(['account_type' => 'nullable|in:user,vendor,delegate,resturant_owner,admin'])['account_type'] ?? 'user';
		$users = $this->manualRecipients($type)->get();
		$campaigns = app(\App\Services\Dashboard\DashboardPushCampaigns::class)->ready()
            ? \DB::table('dashboard_push_campaigns')->where('actor_id', auth('admin')->id())->where('account_type', $type)->orderByDesc('id')->limit(10)->get(['id', 'title', 'created_at']) : collect();
        return view('admin.fcm_notification', compact('users', 'campaigns'));
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
        $data = $request->validate([
            'account_type' => 'nullable|in:user,vendor,delegate,resturant_owner,admin',
            'title' => 'required|string|max:150', 'body' => 'required|string|max:1500',
            'send_by' => 'required|in:0,1', 'choose_user' => 'required_if:send_by,1|nullable|in:0,1',
            'zone_id' => 'exclude_unless:send_by,0|required|array|min:1', 'zone_id.*' => 'integer|min:1|exists:areas,id',
            'user_id' => 'exclude_unless:send_by,1|exclude_unless:choose_user,1|required|array|min:1',
            'user_id.*' => 'integer|min:1',
            'durable' => 'nullable|boolean', 'request_key' => 'required_if:durable,1|nullable|uuid',
        ]);
        $type = $data['account_type'] ?? 'user';
        $query = $this->manualRecipients($type);
        if ((string) $data['send_by'] === '0') {
            $zones = $data['zone_id'];
            $query->whereHas('addresses', fn ($q) => $q->whereIn('area_id', $zones));
        } elseif ((string) ($data['choose_user'] ?? '') === '1') {
            $ids = array_unique(array_map('intval', $data['user_id']));
            $query->whereIn('id', $ids);
            if ((clone $query)->count() !== count($ids)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['user_id' => trans('dashboard_push.recipients')]);
            }
        }
        $tokens = $query->with('tokens')->get()->flatMap(fn ($user) => $user->tokens->pluck('token'))->all();
        if (!empty($data['durable']) || count($tokens) > 100) {
            $data['request_key'] = $data['request_key'] ?? (string) \Illuminate\Support\Str::uuid();
            $campaign = app(\App\Services\Dashboard\DashboardPushCampaigns::class)->create(auth('admin')->user(), $data, $tokens);
            if ($request->expectsJson() || $request->header('X-Dashboard-SPA') === '1') return response()->json(['success' => true, 'campaign' => $campaign, 'message' => trans('dashboard_push.queued')], 202);
            return redirect()->route('fcm_notifications.create', ['account_type' => $type, 'campaign' => $campaign['id']])->with('success', trans('dashboard_push.queued'));
        }
        $result = app(\App\Services\Dashboard\DashboardPushSender::class)->send($tokens, $data['title'], $data['body'], $type);
        $partial = $result['failed'] || $result['not_sent'] || $result['invalid'];
        $key = $result['accepted'] ? ($partial ? 'partial' : 'sent')
            : ($result['reason'] === 'payload_too_large' ? 'too_large' : (!empty($result['empty']) ? 'empty' : 'failed'));
        $message = trans('dashboard_push.'.$key, array_filter($result, 'is_scalar'));
        $json = $request->expectsJson() || $request->header('X-Dashboard-SPA') === '1';
        $counts = array_intersect_key($result, array_flip(['accepted', 'failed', 'attempted', 'not_sent', 'invalid']));
        if (!$result['accepted']) {
            \Log::warning('Dashboard manual notification unavailable', ['reason' => $result['reason']] + $counts);
            if ($json) return response()->json(['success' => false, 'message' => $message, 'severity' => 'error'] + $counts, 502);
            return redirect()->back()->withInput()->with('error', $message);
        }
        if ($partial) \Log::warning('Dashboard manual notification incomplete', ['reason' => $result['reason']] + $counts);
        if ($json) return response()->json(['success' => true, 'message' => $message, 'severity' => $partial ? 'warning' : 'success'] + $counts);
        return redirect()->back()->with($partial ? 'error' : 'success', $message);
    }

    public function campaignStatus(int $campaign)
    {
        return response()->json(app(\App\Services\Dashboard\DashboardPushCampaigns::class)->status($campaign, auth('admin')->user()));
    }

    public function campaignStep(int $campaign)
    {
        $service = app(\App\Services\Dashboard\DashboardPushCampaigns::class);
        $service->status($campaign, auth('admin')->user()); // Authorize before any send.
        $service->step($campaign);
        return response()->json($service->status($campaign, auth('admin')->user()));
    }

    public function campaignResume(int $campaign)
    {
        return response()->json(app(\App\Services\Dashboard\DashboardPushCampaigns::class)->resume($campaign, auth('admin')->user()));
    }

    private function manualRecipients(string $type)
    {
        $actor = auth('admin')->user();
        abort_unless($actor, 401);
        $query = User::withoutGlobalScopes()->where('account_type', $type)->whereHas('tokens');
        // Keep non-administrator senders within the existing dashboard account family.
        if ($actor->account_type !== 'admin') $query->where(function ($q) use ($actor) {
            $q->where('added_by', $actor->id)->orWhere('id', $actor->id);
            if ($actor->added_by !== null) $q->orWhere('added_by', $actor->added_by);
        });
        return $query;
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
