<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderActivitySubscription extends Model
{
    protected $table = 'order_activity_subscriptions';
    protected $guarded = [];
    protected $hidden = ['activity_token', 'fcm_token', 'token_hash'];
    protected $casts = [
        'activity_token' => 'encrypted',
        'fcm_token' => 'encrypted',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];
}
