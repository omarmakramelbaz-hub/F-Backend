<?php
require __DIR__.'/wallet-cash.php';
require __DIR__.'/enrollment-races.php';
// Exercise the existing original native CRM rollback/recovery regression with
// encrypted ready contexts retained, rather than silently weakening its checks.
if(!function_exists('check')){function check($condition,$message){verify($condition,$message);}}
if(!function_exists('denied')){function denied(callable $work,int $status,string $message){rejectWallet($work,$status,$message);}}
require_once $application.'/database/migrations/2026_10_17_000001_create_crm_customer_directory.php';
(new CreateCrmCustomerDirectory)->up();$remoteDatabase=$database;
require __DIR__.'/customer-directory-remote.php';
