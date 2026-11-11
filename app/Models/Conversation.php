<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Mime\MessageConverter;

class Conversation extends Model
{
    use HasFactory;

    protected $guarded=[];
    public function UserSend(){
        return $this->belongsTo(User::class,'user_id');
    } public function Userreciev(){
        return $this->belongsTo(User::class,'receiver');
    }
    public function msgs(){
        return $this->hasMany(MessageConversation::class,'conv_id');
    }
}
