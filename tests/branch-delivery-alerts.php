<?php
// Uses an existing dependency runtime with a disposable in-memory SQLite database.
$runtime = realpath($argv[1] ?? '');
if (!$runtime || !is_file($runtime.'/vendor/autoload.php')) throw new RuntimeException('Pass a Laravel dependency runtime directory.');
require $runtime.'/vendor/autoload.php';
require __DIR__.'/../app/Services/Dashboard/PosBranchPrinting.php';
putenv('APP_ENV=testing');
$app = require $runtime.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array']);
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Database\Schema\Blueprint;
Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->string('name');});
Schema::create('pos_service_tickets',function(Blueprint $t){$t->id();$t->string('branch');$t->string('channel');$t->string('status');$t->string('payment_status');$t->unsignedBigInteger('actor_id');});
Schema::create('pos_branch_print_jobs',function(Blueprint $t){$t->id();$t->string('branch');$t->unsignedBigInteger('ticket_id');$t->string('status');$t->timestamp('claimed_at')->nullable();});
DB::table('users')->insert([
 ['id'=>1,'name'=>'Call center','account_type'=>'admin','owner_resturant_id'=>null],
 ['id'=>4,'name'=>'Branch admin','account_type'=>'admin','owner_resturant_id'=>100],
 ['id'=>10,'name'=>'Branch one','account_type'=>'vendor','owner_resturant_id'=>null],
 ['id'=>11,'name'=>'Branch two','account_type'=>'vendor','owner_resturant_id'=>null],
]);
DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'One'],['id'=>101,'user_id'=>11,'name'=>'Two']]);
foreach([
 [1,'f:100','phone','new','unpaid',1], [2,'f:100','phone','preparing','unpaid',1],
 [3,'f:101','phone','new','unpaid',1], [4,'f:100','phone','cancelled','unpaid',1],
 [5,'f:100','phone','finished','paid',1], [6,'f:100','phone','new','unpaid',10],
 [7,'f:100','dine','new','unpaid',1], [8,'f:100','phone','new','unpaid',4],
 [9,'f:100','phone','out_for_delivery','unpaid',1],
] as [$id,$branch,$channel,$status,$payment,$actor]) DB::table('pos_service_tickets')->insert(['id'=>$id,'branch'=>$branch,'channel'=>$channel,'status'=>$status,'payment_status'=>$payment,'actor_id'=>$actor]);
$printing=app(\App\Services\Dashboard\PosBranchPrinting::class);
$actor=\App\Models\User::withoutGlobalScopes()->findOrFail(10);
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$result=$printing->listing(['branch'=>'f:100'],$actor);
verify($result['incoming_ticket_ids']===[2,1],'only active call-center delivery orders alert branch one');
verify($printing->listing(['branch'=>'f:101'],\App\Models\User::withoutGlobalScopes()->findOrFail(11))['incoming_ticket_ids']===[3],'branch two receives only its own call-center order');
try{$printing->listing(['branch'=>'f:101'],$actor);throw new RuntimeException('Cross-branch listing accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){verify($e->getStatusCode()===404,'branch cannot read another branch alerts');}
try{$printing->listing(['branch'=>'f:100'],\App\Models\User::withoutGlobalScopes()->findOrFail(1));throw new RuntimeException('Call-center receiver accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){verify($e->getStatusCode()===403,'call center cannot act as the branch receiver');}
DB::table('pos_branch_print_jobs')->insert(['branch'=>'f:100','ticket_id'=>1,'status'=>'invoked']);
verify($printing->listing(['branch'=>'f:100'],$actor)['incoming_ticket_ids']===[2,1],'alerts stay independent of print queue completion');
DB::table('pos_service_tickets')->where('id',1)->update(['status'=>'cancelled']);
verify($printing->listing(['branch'=>'f:100'],$actor)['incoming_ticket_ids']===[2],'cancelled order leaves alert feed');
