<?php
namespace Tests\Feature;

use App\Http\Requests\Dashboard\User\StoreUserRequest;
use App\Http\Requests\Dashboard\Resturant\StoreResturantRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PartnerCommissionSettingsTest extends TestCase
{
    public function test_dashboard_individual_fee_fields_accept_zero_and_decimal_percentages_only(): void
    {
        foreach ([[new StoreUserRequest(), 'delegate_fees'], [new StoreResturantRequest(), 'service_fees']] as [$request, $field]) {
            $rules = [$field => $request->rules()[$field]];
            foreach (['0', '0.00', '7.5', '12.50', '100', '100.00'] as $value) {
                $this->assertTrue(Validator::make([$field => $value], $rules)->passes(), $field.': '.$value);
            }
            foreach (['-1', '100.01', '101', '1.001', '1e1', '', null, 'ten'] as $value) {
                $this->assertTrue(Validator::make([$field => $value], $rules)->fails(), $field.': '.var_export($value, true));
            }
            // An unrelated dashboard update must not erase an existing rate.
            $this->assertTrue(Validator::make([], $rules)->passes());
        }
    }
}
