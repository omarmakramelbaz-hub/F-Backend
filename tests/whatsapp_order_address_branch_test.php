<?php

// Pure resolver fixtures: synthetic customer facts, no framework, database, network or writes.
namespace Illuminate\Support\Facades {
    class Schema {
        public static function hasTable($table): bool { return false; }
    }
    class DB {
        public static function __callStatic($name, $arguments) { throw new \RuntimeException('UNEXPECTED_DATABASE_CALL'); }
    }
}
namespace App\Services\Dashboard {
    class TakeawayAccess {
        public function branches($actor): array {
            return array_values(array_filter($GLOBALS['waAddressBranches'],
                static fn ($branch) => in_array($branch['value'], $actor->branches, true)));
        }
    }
    class FasakhanstaDeliveryOrigin {
        public static function isConfiguredOrigin(string $value): bool { return $value === 'f:309'; }
    }
}
namespace {
    function config($name, $default = null) { return $GLOBALS['waAddressConfig'][$name] ?? $default; }
    function app($class) { return new $class; }
    require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppOrderWorkflow.php';

    $checks=0;
    function addressExpect($actual,$expected,string $label): void {
        $GLOBALS['checks']++;
        if ($actual!==$expected) throw new \RuntimeException($label);
    }
    function addressDefaults(): void {
        $GLOBALS['waAddressBranches']=[];
        foreach ([94=>'فرع المحلة',301=>'فرع شبرا الخيمة',307=>'فرع نبروه',363=>'فرع المنصورة'] as $id=>$name)
            $GLOBALS['waAddressBranches'][]=['id'=>$id,'kind'=>'f','value'=>'f:'.$id,'name'=>$name];
        $GLOBALS['waAddressConfig']=['whatsapp_orders.allowed_branch_ids'=>['94','301','307','363'],
            'whatsapp_cart.branch_aliases'=>[], 'whatsapp_orders.enabled'=>true, 'whatsapp_orders.mode'=>'auto',
            'whatsapp_orders.activated_at'=>'2026-10-10 00:00:00','whatsapp_cart.activated_at'=>'2026-10-10 00:00:00'];
    }
    function addressData(?string $area=null,string $address='',?string $hint=null): array {
        return ['branch_hint'=>$hint,'customer'=>['name'=>'عميل مثال','phone'=>'201000000001','address'=>$address,
            'area'=>$area,'notes'=>null], 'decision'=>'CONFIRMED','fulfillment'=>'DELIVERY',
            'evidence'=>['request_ids'=>['m1'],'confirmation_ids'=>['m2'],'customer_acceptance_ids'=>[],'location_id'=>null]];
    }
    function addressResolve(array $data,?array $access=null): ?array {
        $original=$data; $config=$GLOBALS['waAddressConfig']; $branches=$GLOBALS['waAddressBranches'];
        $method=new \ReflectionMethod(\App\Services\Dashboard\WhatsAppOrderWorkflow::class,'automaticBranch');
        $result=$method->invoke(new \App\Services\Dashboard\WhatsAppOrderWorkflow,$data,
            (object)['branches'=>$access??['f:94','f:301','f:307','f:363','f:500','f:501','f:309']]);
        addressExpect($data,$original,'routing does not rewrite extracted facts/citations');
        addressExpect($GLOBALS['waAddressConfig'],$config,'routing preserves configuration and activation');
        addressExpect($GLOBALS['waAddressBranches'],$branches,'routing never edits branch inventory/access');
        return $result;
    }
    function addressRoutes(array $data,?string $value,string $label,?array $access=null): void {
        addressExpect(addressResolve($data,$access)['value']??null,$value,$label);
    }
    try {
        addressDefaults();
        foreach (['مدينة مبارك المنصوره','مدينة مبارك المنصورة','المنصوره','فرع المنصوره','مدينه مبارك بالمنصوره'] as $area)
            addressRoutes(addressData($area,'شارع المثال'),'f:363','recognized city inside supplied area '.$area);
        foreach (['مدينة مبارك المنصوره شارع المثال','عنواني بالمنصورة','شارع الجمهورية في المنصورة',
            'شارع المثال، المنصوره','في المنصورة شارع المثال','مدينه مبارك بالمنصوره',
            'مدينة مبارك للمنصوره','مدينة مبارك فالمنصوره'] as $address)
            addressRoutes(addressData(null,$address),'f:363','address city without explicit branch '.$address);
        addressRoutes(addressData(null,'نبروه شارع المثال'),'f:307','literal branch prefix removed without city guessing');
        addressRoutes(addressData(null,'شبرا الخيمه شارع المثال'),'f:301','Arabic orthography recognized for compound city');
        $GLOBALS['waAddressConfig']['whatsapp_cart.branch_aliases']=['363'=>['حي امل']];
        addressRoutes(addressData('حى أمل'),'f:363','alif and maqsura normalization is routing comparison only');
        addressDefaults();
        foreach (['المنصورية','المنصوريه','منصوره','شبراوية','نبروهية','فوق نبروهية','لالمنصوره',
            'المنصوره الجديدة','المنصورة الجديده','مدينة مجهولة','الفرع','فرع','Store','FS'] as $place)
            addressRoutes(addressData($place,$place),null,'no prefix/subword/generic/unknown place inference '.$place);
        foreach (['شارع المنصورة','طريق المنصوره','شارع: المنصورة','ش. المنصورة','road المنصورة','شارع المنصوره، طنطا'] as $street)
            addressRoutes(addressData(null,$street),null,'street name alone is not a destination city '.$street);
        addressRoutes(addressData('شارع المنصورة','شارع المنصورة'),null,'area containing only a street name is not stronger city evidence');
        foreach (['مش في المنصورة','غير المنصورة','مش المنصورة','ليس من المنصورة','لا في المنصورة'] as $denied)
            addressRoutes(addressData($denied,$denied),null,'explicit denied place is not customer destination '.$denied);
        addressRoutes(addressData(null,'مش في المنصورة، نبروه'),'f:307','denied place does not hide separate affirmative destination');
        addressRoutes(addressData('المنصوره','شارع المنصورة'),'f:363','area signal independently establishes destination');
        addressRoutes(addressData('المنصوره','شارع المنصورة، المحلة'),null,'other known city conflicts with supplied area');
        addressRoutes(addressData('المنصوره','نبروه شارع المثال'),null,'area/address known cities conflict');
        addressRoutes(addressData(null,'المنصورة ثم المحلة'),null,'multiple distinct address places cannot choose first');
        addressRoutes(addressData('مدينة مبارك المنصوره','شارع المثال','فرع شبرا الخيمة'),null,'explicit other branch conflicts with supplied city');
        addressRoutes(addressData(null,'المنصورة','فرع مجهول'),null,'unrecognized explicit hint cannot be ignored');
        addressRoutes(addressData(null,'شارع المثال','فرع المنصوره'),'f:363','explicit branch hint supports orthographic comparison');
        addressRoutes(addressData('مدينة مبارك المنصوره','شارع المثال','المنصورة'),'f:363','matching hint and area agree');
        $GLOBALS['waAddressConfig']['whatsapp_cart.branch_aliases']=['94'=>['المنصورة']];
        addressRoutes(addressData(null,'المنصورة'),null,'duplicate configured place alias retains both candidate branches');
        addressRoutes(addressData('المنصوره','شارع المثال','المنصورة'),null,'duplicate hint alias cannot silently select first');
        addressDefaults(); $GLOBALS['waAddressConfig']['whatsapp_orders.allowed_branch_ids']=['94','301','307'];
        addressRoutes(addressData('المنصوره','شارع المثال'),null,'city never bypasses automation branch allowlist');
        addressRoutes(addressData(null,'المنصورة، نبروه'),null,'allowlist cannot erase another recognized place and choose remaining branch');
        addressRoutes(addressData('المنصورة','نبروه'),null,'denied area city still conflicts with allowed address city');
        addressDefaults();
        addressRoutes(addressData('المنصوره','شارع المثال'),null,'city never bypasses actor branch access',['f:94','f:301','f:307']);
        $GLOBALS['waAddressBranches'][]=['id'=>363,'kind'=>'g','value'=>'g:363','name'=>'فرع المنصورة'];
        addressRoutes(addressData('المنصوره'),null,'other-kind store is not a Fasakhansta destination',['g:363']);
        addressDefaults(); $GLOBALS['waAddressBranches'][]=['id'=>309,'kind'=>'f','value'=>'f:309','name'=>'المقطم ستور'];
        addressRoutes(addressData('المقطم ستور'),null,'registry origin with missing live parent/name proof remains blocked');
        addressDefaults(); $GLOBALS['waAddressBranches'][]=['id'=>500,'kind'=>'f','value'=>'f:500','name'=>'فرع المحلة'];
        addressRoutes(addressData('المحلة'),'f:94','unapproved denied legacy duplicate does not invent another reviewed destination');
        $GLOBALS['waAddressConfig']['whatsapp_orders.allowed_branch_ids'][]='500';
        addressRoutes(addressData('المحلة'),null,'allowed duplicate legacy label remains a genuine ambiguity');
        addressDefaults();
        $GLOBALS['waAddressBranches'][]=['id'=>500,'kind'=>'f','value'=>'f:500','name'=>'فرع مدينة نصر'];
        $GLOBALS['waAddressBranches'][]=['id'=>501,'kind'=>'f','value'=>'f:501','name'=>'فرع زهراء مدينة نصر'];
        $GLOBALS['waAddressConfig']['whatsapp_orders.allowed_branch_ids']=['94','301','307','363','500','501'];
        addressRoutes(addressData(null,'زهراء مدينة نصر شارع المثال'),'f:501','longest registered label wins only overlapping occurrence');
        addressRoutes(addressData(null,'مدينة نصر شارع المثال'),'f:500','shorter approved place remains its own destination');
        addressRoutes(addressData(null,'زهراء مدينة نصر ثم مدينة نصر'),null,'separate second recognized occurrence still conflicts');
        addressRoutes(addressData('مدينة نصر','زهراء مدينة نصر'),null,'longest label never hides separate area/address conflict');
        $GLOBALS['waAddressBranches'][]=['id'=>502,'kind'=>'f','value'=>'f:502','name'=>'فرع المنصورة الجديدة'];
        $GLOBALS['waAddressConfig']['whatsapp_orders.allowed_branch_ids'][]='502';
        addressRoutes(addressData(null,'المنصوره الجديده'), 'f:502', 'full explicitly approved compound place is eligible',
            ['f:94','f:301','f:307','f:363','f:502']);
        $GLOBALS['waAddressConfig']['whatsapp_cart.branch_aliases']=['363'=>['محل','فرع','مدينة','فسخانستا','Go']];
        addressRoutes(addressData(null,'محل فرع مدينة فسخانستا Go'),null,'generic and tiny aliases cannot become place evidence');
        echo 'WHATSAPP_ORDER_ADDRESS_BRANCH_TESTS='.$checks." PASS\n";
    } catch (\Throwable $error) {
        fwrite(STDERR,'WHATSAPP_ORDER_ADDRESS_BRANCH_TESTS_FAILED: '.$error->getMessage()."\n"); exit(1);
    }
}
