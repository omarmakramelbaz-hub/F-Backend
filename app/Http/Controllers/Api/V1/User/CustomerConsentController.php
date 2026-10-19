<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponses;
use App\Services\Dashboard\AppCustomerConsent;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerConsentController extends Controller
{
    use ApiResponses;

    public function update(Request $request, AppCustomerConsent $consent)
    {
        try {
            $data = $consent->update($request->all(), $request->user('api'), (string)$request->header('X-App-Scope', ''));
        } catch (ValidationException $error) {
            return $this->errorResponse($error->validator->errors()->first(), 422);
        }
        return $this->successResponse($data);
    }
}
