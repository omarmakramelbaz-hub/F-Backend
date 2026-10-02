<?php
namespace ErpTests;

class LegacyOwner extends \Illuminate\Foundation\Auth\User
{
    protected $table = 'users';
    protected $guarded = [];
}
