<?php

use App\Http\Controllers\Api\V1\User\CustomerConsentController;
use Illuminate\Support\Facades\Route;

// Included by api.php; every write uses the authenticated customer's stored phone.
Route::post('user/whatsapp-consent', [CustomerConsentController::class, 'update'])
    ->middleware(['CheckLang', 'auth:api', 'app.scope', 'custom.jwt'])
    ->name('customer-crm.app-consent');
