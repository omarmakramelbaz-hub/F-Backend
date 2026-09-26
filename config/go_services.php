<?php
return [
 // Deploy migrations and test in staging before explicitly enabling. No legacy checkout changes.
 'enabled'=>env('GO_SERVICES_ENABLED',false),
 'batch_size'=>5,'batch_seconds'=>120,'search_minutes'=>60,'offer_minutes'=>30,'payment_minutes'=>10,'max_recipients'=>100,'max_open_jobs'=>5,
 'paymob'=>[
  'enabled'=>env('GO_SERVICES_PAYMOB_ENABLED',false),
  'secret_key'=>env('PAYMOB_SECRET_KEY'),'public_key'=>env('PAYMOB_PUBLIC_KEY'),'hmac_secret'=>env('PAYMOB_HMAC_SECRET'),
  'is_live'=>env('GO_SERVICES_PAYMOB_LIVE',false),
  'methods'=>['card'=>env('GO_SERVICES_PAYMOB_CARD_ID'),'mobile_wallet'=>env('GO_SERVICES_PAYMOB_WALLET_ID'),'apple_pay'=>env('GO_SERVICES_PAYMOB_APPLE_PAY_ID'),'google_pay'=>env('GO_SERVICES_PAYMOB_GOOGLE_PAY_ID')],
 ],
];
