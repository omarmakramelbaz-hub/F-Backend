<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\User\ConversationMessagesResource;
use App\Http\Resources\Api\User\ConversationResource;
use Illuminate\Http\Request;
use App\Http\Traits\ApiResponses;
use App\Models\Conversation;
use App\Models\MessageConversation;
use App\Models\User;

class ConversationController extends Controller
{ 
    use ApiResponses;
    
    //

  

    public function conversations(){
        $conversations=Conversation::where('user_id',auth()->guard('api')->user()->id)->orWhere('receiver',auth()->guard('api')->user()->id)->get();
        $data = ConversationResource::collection($conversations);    
        return $this->successResponse($data,trans('messages.success data'));
    }
    /** A conversation the signed-in user takes part in (as its starter or its receiver), or null. */
    private function own($id): ?Conversation
    {
        $me = auth('api')->id();
        return Conversation::where('id', $id)->where(fn ($q) => $q->where('user_id', $me)->orWhere('receiver', $me))->first();
    }

    public function conversation($id){
        // Only the two people in a conversation read its messages.
        $conversation=$this->own($id);
        if(!$conversation){
            return $this->errorResponse(trans('messages.SomeThingWrong'));
        }
        foreach($conversation->msgs->where('reciever',auth('api')->user()->id) as $msg){
            $msg->update(['read'=>1]);
        }
        $data =ConversationMessagesResource::collection($conversation->msgs);    
        return $this->successResponse($data,trans('messages.success data'));
    }
    public function send_message(Request $request){
        $request->validate(['conv_id'=>'required|integer', 'message'=>'required|string|max:5000']);
        // Only into a conversation the sender takes part in, and always to its other person.
        $conversation=$this->own($request->conv_id);
        if(!$conversation){
            return $this->errorResponse(trans('messages.SomeThingWrong'));
        }
        $me=(int) auth('api')->id();
        MessageConversation::create([
            'conv_id'=>$conversation->id,
            'sender'=>$me,
            'reciever'=>(int) $conversation->user_id === $me ? $conversation->receiver : $conversation->user_id,
            'message'=>$request['message'],
        ]);
        $data =ConversationMessagesResource::collection($conversation->fresh()->msgs);    
        return $this->successResponse($data,trans('messages.success data'));
    }
    public function new_conv($user_id){
    
      $conv= Conversation::Where('user_id',auth('api')->user()->id)->where('receiver',$user_id)->first();
    
       if($conv==null){
           $conv=Conversation::Where('receiver',auth('api')->user()->id)->where('user_id',$user_id)->first();
       }
      
          if($conv==null){
                $conv= Conversation::create(['user_id'=>auth('api')->user()->id,'receiver'=>$user_id]);;
          }
       $msgs=$conv->msgs;
       if($msgs){
       foreach($msgs->where('reciever',auth('api')->user()->id) as $msg){
           $msg->update(['read'=>1]);
       }
       }
       $data =new ConversationResource($conv);    
       return $this->successResponse($data,trans('messages.success data'));
    }
    
  
    
 
}
