<?php
/** Real SQLite migrations, directory, listing and operational receipts; no external sends. */
namespace {
    class CrmDirectoryDenied extends \RuntimeException {}
    function abort_unless($condition,$status,$message='DENIED'){if(!$condition)throw new CrmDirectoryDenied($message,$status);}
    function abort_if($condition,$status,$message='DENIED'){if($condition)throw new CrmDirectoryDenied($message,$status);}
    function app($abstract=null){$c=\Illuminate\Container\Container::getInstance();return $abstract===null?$c:$c->make($abstract);}
    function now($zone=null){return \Carbon\Carbon::now($zone);}
    $vendor=getenv('WA_INBOX_TEST_VENDOR');if(is_string($vendor)&&is_dir($vendor))$vendor.='/autoload.php';
    if(!is_string($vendor)||!is_file($vendor))throw new \RuntimeException('ISOLATED_TEST_VENDOR_REQUIRED');
    require $vendor;
}
namespace App\Services\Dashboard {
    /** Actor/branch fixture isolates account authentication; actual BranchOperations performs each write gate. */
    class TakeawayAccess {
        public function actor($actor){\abort_unless(is_object($actor)&&isset($actor->id),403);return $actor;}
        public function permissions($actor){return ['can_checkout'=>true];}
        public function branch($branch,$actor,$lock=false){\abort_unless(in_array($branch,$actor->branches,true),403);return ['value'=>$branch,'id'=>(int)substr($branch,2),'kind'=>'f','name'=>$branch];}
        public function branches($actor){return array_map(fn($branch)=>$this->branch($branch,$actor),$actor->branches);}
    }
    class PosServicePhone {
        public static function key(string $phone): string {$phone=strtr($phone,['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);return preg_replace('/[^0-9+]/','',$phone);}
    }
    class PosServiceTicket { public static function fingerprint(array $input): string {return hash('sha256',json_encode($input));} }
}
namespace {
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\DB;
    use App\Services\Dashboard\CustomerDirectory;
    use App\Services\Dashboard\BranchCustomers;
    $c=new Container;Container::setInstance($c);$db=new Capsule($c);$db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);$db->setAsGlobal();$db->bootEloquent();
    $c->instance('db',$db->getDatabaseManager());$c->instance('db.schema',$db->getConnection()->getSchemaBuilder());
    $translator=new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'en');$c->instance('validator',new \Illuminate\Validation\Factory($translator,$c));Facade::setFacadeApplication($c);
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-17 10:00:00','UTC'));
    require __DIR__.'/../app/Services/Dashboard/CustomerDirectory.php';require __DIR__.'/../app/Services/Dashboard/BranchOperations.php';require __DIR__.'/../app/Services/Dashboard/BranchCustomers.php';
    Schema::create('pos_service_tickets',fn(Blueprint $t)=>$t->id());
    require __DIR__.'/../database/migrations/2026_10_04_080000_create_branch_operations.php';(new \CreateBranchOperations)->up();
    require __DIR__.'/../database/migrations/2026_10_17_000001_create_crm_customer_directory.php';(new \CreateCrmCustomerDirectory)->up();
    $admin=(object)['id'=>1,'account_type'=>'admin','owner_resturant_id'=>null,'branches'=>['f:100','f:101']];
    $branch=(object)['id'=>2,'account_type'=>'vendor','owner_resturant_id'=>100,'branches'=>['f:100']];
    $checks=0;function expectCrm($condition,string $label): void {$GLOBALS['checks']++;if(!$condition)throw new \RuntimeException('CRM_DIRECTORY_FAILED:'.$label);}
    function deniedCrm(callable $work,int $status): void {try{$work();throw new \RuntimeException('Expected denial');}catch(CrmDirectoryDenied $error){expectCrm($error->getCode()===$status,'scope/consent denial '.$status);}}
    function crmKey(int $n): string{return sprintf('00000000-0000-4000-8000-%012d',$n);}
    function invalidCrm(callable $work,string $field): void {try{$work();throw new \RuntimeException('Expected validation failure');}catch(\Illuminate\Validation\ValidationException $error){expectCrm(isset($error->errors()[$field]),'invalid consent field '.$field);}}
    $directory=app(CustomerDirectory::class);$service=app(BranchCustomers::class);
    $customer=$directory->capture(['phone'=>'٠١٠٦٤٤٦٤٤٩٩','name'=>'أحمد'],'takeaway','f:100');
    $same=$directory->capture(['phone'=>'+201064464499','address'=>'عنوان التوصيل'],'delivery','f:100');
    $directory->capture(['phone'=>'00201064464499'],'dine','f:101');
    expectCrm($customer->id===$same->id&&DB::table('crm_customers')->count()===1,'canonical identity across channels');
    $foreign=$directory->capture(['phone'=>'01000000002','name'=>'فرع آخر'],'dine','f:101');
    $unassigned=$directory->capture(['phone'=>'01000000003','name'=>'عميل تطبيق قبل الشراء'],'app','');
    $unclassified=$directory->capture(['phone'=>'01000000004','name'=>'عميل محادثة'],'','');
    $list=$service->listing(['branch'=>'f:100'],$branch);
    expectCrm(count($list['items'])===1&&$list['items'][0]['channels']===['delivery','takeaway'],'branch sees own source badges without duplicates');
    expectCrm($list['summary']['marketing_opted_in']===0&&$list['summary']['service_opted_in']===0,'no implicit messaging consent');
    expectCrm($service->listing(['branch'=>'f:100','search'=>'٠١٠٦'],$branch)['pagination']['total']===1,'partial Arabic phone search');
    deniedCrm(fn()=>$service->listing(['branch'=>'all'],$branch),403);
    deniedCrm(fn()=>$service->listing(['branch'=>'f:101'],$branch),403);
    $global=$service->listing(['branch'=>'all'],$admin);expectCrm($global['pagination']['total']===4,'central sees unassigned app and unclassified inbound');
    expectCrm(count(array_values(array_filter($global['items'],fn($row)=>$row['id']===$customer->id))[0]['channels'])===3,'central sees every real source');
    expectCrm($service->listing(['branch'=>'all','source'=>'app'],$admin)['pagination']['total']===1,'unclassified never gets invented app badge');
    $v=['branch'=>'f:100','directory_id'=>$customer->id,'service_consent'=>true,'marketing_consent'=>true,'idempotency_key'=>crmKey(1)];
    deniedCrm(fn()=>$service->consent($v,$branch),422);
    $v['evidence']='رسالة موافقة صريحة من العميل بتاريخ 17 أكتوبر';$first=$service->consent($v,$branch);$replay=$service->consent($v,$branch);
    expectCrm($first['success']&&$replay['replayed'],'consent retry uses immutable receipt');
    expectCrm(DB::table('crm_customers')->where('id',$customer->id)->value('marketing_consent_evidence')===$v['evidence'],'actual opt-in evidence persisted');
    expectCrm($service->listing(['branch'=>'f:100'],$branch)['summary']['marketing_opted_in']===1,'eligible summary updated');
    $stop=array_replace($v,['idempotency_key'=>crmKey(2),'service_consent'=>false,'marketing_consent'=>false,'evidence'=>'']);$service->consent($stop,$branch);
    $state=DB::table('crm_customers')->find($customer->id);expectCrm(!$state->service_consent&&!$state->marketing_consent&&$state->opted_out_at!==null,'revocation needs no fabricated approval proof');
    deniedCrm(fn()=>$service->consent(array_replace($v,['directory_id'=>$foreign->id,'idempotency_key'=>crmKey(3)]),$branch),404);
    deniedCrm(fn()=>$service->consent(array_replace($v,['directory_id'=>$unclassified->id,'idempotency_key'=>crmKey(4)]),$branch),404);
    $service->consent(array_replace($stop,['directory_id'=>$unclassified->id,'idempotency_key'=>crmKey(5)]),$admin);
    expectCrm(!DB::table('crm_customer_sources')->where('customer_id',$unclassified->id)->exists(),'unclassified admin consent does not invent source');
    $save=['branch'=>'f:100','directory_id'=>$unassigned->id,'idempotency_key'=>crmKey(6),'channel'=>'app','phone'=>$unassigned->phone,'name'=>'الاسم المحدث','address'=>''];
    $service->directorySave($save,$admin);expectCrm(DB::table('crm_customers')->find($unassigned->id)->name==='الاسم المحدث','central edits app profile before first purchase');
    expectCrm(DB::table('crm_customer_sources')->where('customer_id',$unassigned->id)->value('branch')==='','editing app profile preserves unassigned source');
    $minimal=['branch'=>'f:100','phone'=>'01000000005','channel'=>'dine','idempotency_key'=>crmKey(7)];$new=$service->directorySave($minimal,$branch);
    expectCrm($new['customer']['phone']==='+201000000005'&&$new['customer']['name']===null,'minimal valid phone can be recorded');
    $saved=$service->save(['branch'=>'f:100','name'=>'عميل محفوظ','phone'=>'01000000006','address'=>'شارع الجيش','idempotency_key'=>crmKey(8)],$branch);
    expectCrm($saved['customer']['id']>0&&DB::table('crm_customers')->where('phone','+201000000006')->exists(),'legacy delivery save captures registry and retains local id');
    $verbal=['branch'=>'f:100','directory_id'=>$customer->id,'service_consent'=>true,'marketing_consent'=>false,'consent_method'=>'verbal','consent_scope'=>'service','idempotency_key'=>crmKey(9)];
    $approval=$service->consent($verbal,$branch);$state=DB::table('crm_customers')->find($customer->id);
    expectCrm($state->service_consent&&!$state->marketing_consent&&$state->opted_out_at===null,'verbal choice grants only the selected purpose');
    expectCrm(str_contains($state->service_consent_evidence,'أقر الموظف')&&str_contains($state->service_consent_evidence,'شفهيًا')&&str_contains($state->service_consent_evidence,'متابعة الطلب والخدمة')&&!str_contains($state->service_consent_evidence,'العروض الترويجية'),'server records truthful verbal attestation without free text');
    expectCrm($state->service_consented_by===2&&$state->service_consented_at==='2026-10-17 10:00:00'&&$approval['recorded_by']===2&&$approval['recorded_at']==='2026-10-17T10:00:00+00:00'&&$approval['consent_method']==='verbal','server stamps verbal approval actor and UTC time');
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-17 10:05:00','UTC'));
    $approvalReplay=$service->consent($verbal,$branch);
    expectCrm($approvalReplay['replayed']&&$approvalReplay['recorded_at']===$approval['recorded_at']&&DB::table('crm_customers')->find($customer->id)->service_consented_at===$state->service_consented_at,'verbal retry replays the original receipt and timestamp');
    deniedCrm(fn()=>$service->consent(array_replace($verbal,['marketing_consent'=>true]),$branch),409);
    deniedCrm(fn()=>$service->consent(array_replace($verbal,['directory_id'=>$foreign->id,'idempotency_key'=>crmKey(10)]),$branch),404);
    invalidCrm(fn()=>$service->consent(array_replace($verbal,['consent_method'=>'profile','idempotency_key'=>crmKey(11)]),$branch),'consent_method');
    deniedCrm(fn()=>$service->consent(array_replace($verbal,['consent_method'=>'withdrawal','idempotency_key'=>crmKey(12)]),$branch),422);
    $withdrawal=array_replace($verbal,['service_consent'=>false,'consent_method'=>'withdrawal','consent_scope'=>'all','idempotency_key'=>crmKey(13)]);$stopped=$service->consent($withdrawal,$branch);$state=DB::table('crm_customers')->find($customer->id);
    expectCrm(!$state->service_consent&&!$state->marketing_consent&&$state->opted_out_at==='2026-10-17 10:05:00'&&$stopped['consent_method']==='withdrawal','one-click withdrawal stops both purposes without evidence entry');
    expectCrm($state->service_consented_by===2&&str_contains($state->service_consent_evidence,'إيقاف')&&!str_contains($state->service_consent_evidence,'وافق'),'withdrawal attribution never invents approval');
    $verbalBoth=array_replace($verbal,['marketing_consent'=>true,'consent_scope'=>'all','idempotency_key'=>crmKey(14)]);$service->consent($verbalBoth,$branch);$state=DB::table('crm_customers')->find($customer->id);
    expectCrm($state->service_consent&&$state->marketing_consent&&str_contains($state->marketing_consent_evidence,'العروض الترويجية'),'cashier can explicitly record both customer choices together');
    $marketingBefore=$state->marketing_consented_at;$marketingEvidence=$state->marketing_consent_evidence;
    $scopeStop=array_replace($verbal,['service_consent'=>false,'marketing_consent'=>false,'consent_scope'=>'service','consent_method'=>'withdrawal','idempotency_key'=>crmKey(15)]);
    $scoped=$service->consent($scopeStop,$branch);$state=DB::table('crm_customers')->find($customer->id);
    expectCrm(!$state->service_consent&&$state->marketing_consent&&$state->opted_out_at===null&&$state->marketing_consented_at===$marketingBefore&&$state->marketing_consent_evidence===$marketingEvidence,'scoped withdrawal preserves the current other-purpose approval despite stale false UI flag');
    expectCrm($scoped['consent_scope']==='service'&&str_contains($scoped['consent_evidence'],'متابعة الطلب والخدمة')&&!str_contains($scoped['consent_evidence'],'العروض الترويجية'),'scoped evidence covers only the selected purpose');
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-17 10:10:00','UTC'));
    $directory->consent($customer->id,false,false,'إلغاء واتساب من رسالة العميل');
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-17 10:11:00','UTC'));
    $staleAfterStop=array_replace($scopeStop,['marketing_consent'=>true,'idempotency_key'=>crmKey(16)]);$service->consent($staleAfterStop,$branch);$state=DB::table('crm_customers')->find($customer->id);
    expectCrm(!$state->service_consent&&!$state->marketing_consent&&$state->opted_out_at!==null,'scoped withdrawal after incoming STOP never resurrects a stale other-purpose opt-in');
    $service->consent(array_replace($verbal,['marketing_consent'=>true,'idempotency_key'=>crmKey(17)]),$branch);$state=DB::table('crm_customers')->find($customer->id);
    expectCrm($state->service_consent&&!$state->marketing_consent&&$state->opted_out_at===null,'scoped approval after STOP grants only its target despite stale true other flag');
    $serviceBefore=$state->service_consented_at;$serviceEvidence=$state->service_consent_evidence;
    $marketingOnly=array_replace($verbal,['service_consent'=>false,'marketing_consent'=>true,'consent_scope'=>'marketing','idempotency_key'=>crmKey(18)]);$service->consent($marketingOnly,$branch);$state=DB::table('crm_customers')->find($customer->id);
    expectCrm($state->service_consent&&$state->marketing_consent&&$state->service_consented_at===$serviceBefore&&$state->service_consent_evidence===$serviceEvidence,'marketing scoped action ignores stale false service value');
    expectCrm(str_contains($state->marketing_consent_evidence,'العروض الترويجية')&&!str_contains($state->marketing_consent_evidence,'متابعة الطلب والخدمة'),'marketing attestation does not claim service permission');
    $directory->consent($customer->id,false,false,'STOP');
    $service->consent(array_replace($marketingOnly,['service_consent'=>true,'idempotency_key'=>crmKey(19)]),$branch);$state=DB::table('crm_customers')->find($customer->id);
    expectCrm(!$state->service_consent&&$state->marketing_consent,'marketing scoped approval ignores stale true service value after STOP');
    invalidCrm(fn()=>$service->consent(array_replace($verbal,['consent_scope'=>'profile','idempotency_key'=>crmKey(20)]),$branch),'consent_scope');
    echo 'CRM customer registry: '.$checks." checks passed.\n";
}
