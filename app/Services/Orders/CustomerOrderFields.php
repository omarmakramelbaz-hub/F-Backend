<?php

namespace App\Services\Orders;

/** What a customer's app never sets on an order: the server decides these (price, parties, state, settlement). */
final class CustomerOrderFields
{
    public const SERVER_DECIDES = ['id', 'user_id', 'resturant_id', 'status', 'type', 'order_no', 'shift_sequence',
        'shift_order_number', 'total', 'updated_total', 'grand_total', 'service_fees', 'user_tax', 'tax', 'vendor_tax', 'delegate_id',
        'transfer_price_by', 'shipping_id', 'shipping_cancelled_block', 'expiration_date', 'connected', 'paymob_order_id',
        'accepted_notify', 'declined_by', 'reason', 'wallet_id', 'created_at', 'updated_at', 'deleted_at'];

    /** A delivery request's price is proposed when it is made and agreed with a courier; paying never changes it. */
    public const SHIPPING_PAYMENT_DECIDES = ['delivery_price', 'delegate_from_out', 'coupon_wheel_id'];

    public const PAYMENT_TYPES = ['online', 'v_cash', 'wallet', 'cash'];
}
