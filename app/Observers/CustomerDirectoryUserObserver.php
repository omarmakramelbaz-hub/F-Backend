<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Dashboard\CustomerDirectorySync;

class CustomerDirectoryUserObserver
{
    public function saved(User $user): void
    {
        app(CustomerDirectorySync::class)->captureUser($user);
    }
}
