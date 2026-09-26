<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
class CreateGoServiceMarketplace extends Migration {
 public function up() {
  Schema::create('go_service_jobs',function(Blueprint $t){
   $t->bigIncrements('id');$t->unsignedBigInteger('customer_id')->index();$t->string('request_key',64);$t->string('payload_hash',64);$t->string('profession_key',80)->index();
   $t->text('description');$t->string('area',150);$t->string('address',500);$t->string('phone',30);$t->decimal('lat',10,7);$t->decimal('lng',11,7);$t->text('photos')->nullable();$t->timestamp('scheduled_at')->nullable();
   $t->string('status',32)->default('searching')->index();$t->unsignedBigInteger('partner_id')->nullable()->index();$t->unsignedBigInteger('accepted_offer_id')->nullable();
   $t->unsignedBigInteger('price_cents')->default(0);$t->unsignedInteger('commission_bps')->default(0);$t->unsignedBigInteger('commission_cents')->default(0);
   $t->string('payment_method',30)->nullable();$t->string('payment_status',30)->default('unpaid');$t->unsignedBigInteger('held_cents')->default(0);$t->timestamp('payment_due_at')->nullable();
   $t->timestamp('search_until')->index();$t->timestamp('next_dispatch_at')->index();$t->unsignedInteger('dispatch_round')->default(0);$t->timestamp('accepted_at')->nullable();$t->timestamp('started_at')->nullable();$t->timestamp('completed_at')->nullable();$t->string('close_reason',500)->nullable();$t->timestamps();$t->unique(['customer_id','request_key']);
  });
  Schema::create('go_service_recipients',function(Blueprint $t){
   $t->bigIncrements('id');$t->unsignedBigInteger('job_id');$t->unsignedBigInteger('partner_id');$t->string('status',24)->default('invited');$t->unsignedInteger('round');$t->timestamps();$t->unique(['job_id','partner_id']);$t->index(['partner_id','status']);$t->foreign('job_id')->references('id')->on('go_service_jobs');
  });
  Schema::create('go_service_offers',function(Blueprint $t){
   $t->bigIncrements('id');$t->unsignedBigInteger('job_id');$t->unsignedBigInteger('partner_id');$t->unsignedBigInteger('price_cents');$t->unsignedInteger('commission_bps');$t->text('scope');$t->boolean('materials_included')->default(false);$t->unsignedInteger('arrival_minutes');$t->unsignedInteger('duration_minutes');$t->string('status',24)->default('offered');$t->timestamp('expires_at');$t->timestamps();$t->unique(['job_id','partner_id']);$t->foreign('job_id')->references('id')->on('go_service_jobs');
  });
  Schema::create('go_service_assignments',function(Blueprint $t){
   // One active booking per partner; enforced even across concurrent jobs.
   $t->unsignedBigInteger('partner_id')->primary();$t->unsignedBigInteger('job_id')->unique();$t->timestamp('created_at');$t->foreign('job_id')->references('id')->on('go_service_jobs');
  });
  Schema::create('go_service_ledger',function(Blueprint $t){
   $t->bigIncrements('id');$t->unsignedBigInteger('job_id')->index();$t->string('event_key',100)->unique();$t->string('kind',32);$t->unsignedBigInteger('from_user')->nullable();$t->unsignedBigInteger('to_user')->nullable();$t->unsignedBigInteger('amount_cents');$t->unsignedBigInteger('wallet_id')->nullable();$t->timestamp('created_at');$t->foreign('job_id')->references('id')->on('go_service_jobs');
  });
  Schema::create('go_service_payments',function(Blueprint $t){
   $t->bigIncrements('id');$t->unsignedBigInteger('job_id')->unique();$t->uuid('reference')->unique();$t->unsignedBigInteger('integration_id');$t->unsignedBigInteger('amount_cents');$t->string('gateway_order_id',80)->nullable()->unique();$t->text('checkout_secret')->nullable();$t->string('status',24)->default('creating');$t->timestamp('expires_at');$t->timestamps();$t->foreign('job_id')->references('id')->on('go_service_jobs');
  });
  Schema::create('go_service_payment_receipts',function(Blueprint $t){
   $t->bigIncrements('id');$t->string('transaction_id',80)->unique();$t->unsignedBigInteger('job_id')->index();$t->unsignedBigInteger('amount_cents');$t->string('status',24);$t->timestamp('created_at');$t->foreign('job_id')->references('id')->on('go_service_jobs');
  });
  Schema::create('go_service_outbox',function(Blueprint $t){
   $t->bigIncrements('id');$t->string('event_key',140)->unique();$t->unsignedBigInteger('user_id')->index();$t->unsignedBigInteger('job_id');$t->string('message',500);$t->unsignedInteger('attempts')->default(0);$t->timestamp('available_at')->index();$t->timestamp('sent_at')->nullable();$t->timestamp('created_at');
  });
 }
 public function down() {
  if(Schema::hasTable('go_service_ledger')&&DB::table('go_service_ledger')->exists())throw new RuntimeException('Refusing to erase service financial history. Disable GO_SERVICES_ENABLED instead.');
  foreach(['outbox','payment_receipts','payments','ledger','assignments','offers','recipients','jobs'] as $name)Schema::dropIfExists('go_service_'.$name);
 }
}
