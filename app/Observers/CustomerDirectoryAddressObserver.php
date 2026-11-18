<?php

namespace App\Observers;

use App\Models\UserAddress;
use App\Services\Dashboard\CustomerDirectorySync;

class CustomerDirectoryAddressObserver
{
    public function saved(UserAddress $address): void
    {
        app(CustomerDirectorySync::class)->captureAddress($address);
    }
}
