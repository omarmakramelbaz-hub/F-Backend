<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderLiveActivity extends Model
{
    protected $guarded = [];
    protected $hidden = ['activity_id_hash', 'activity_token', 'fcm_token', 'token_hash', 'fcm_token_hash'];
    protected $casts = ['expires_at' => 'datetime', 'ended_at' => 'datetime', 'next_attempt_at' => 'datetime'];
}
