<?php

namespace App\Models\Erp;

use Illuminate\Foundation\Auth\User as Authenticatable;

class StaffUser extends Authenticatable
{
    protected $table = 'erp_users';
    protected $guarded = ['id'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['active' => 'boolean', 'permissions' => 'array'];
}
