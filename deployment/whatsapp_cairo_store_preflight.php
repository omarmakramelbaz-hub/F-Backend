<?php
// Public store inventory only. No customer queries, AI, external calls or mutations.
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
    if(PHP_SAPI!=='cli'||$argc!==1||!function_exists('posix_getpwnam')||!function_exists('posix_geteuid'))throw new \RuntimeException();
    $owner=posix_getpwnam('fasakha');if(!is_array($owner)||$owner['uid']!==posix_geteuid())throw new \RuntimeException();
    if(!chdir('/home/fasakha/public_html'))throw new \RuntimeException();
    require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $schema=\Illuminate\Support\Facades\Schema::class;$db=\Illuminate\Support\Facades\DB::class;
    $id=static function($v){$s=(string)$v;return(is_int($v)||is_string($v))&&preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/',$s)?$s:null;};
    $text=static function($v){
        if(!is_string($v))return null;$v=trim($v);
        if(strlen($v)>800||preg_match('/[\x00-\x1f\x7f]|EAA[A-Za-z0-9_-]{15,}|sk-[A-Za-z0-9_-]{16,}|[^\s]+@[^\s]+/',$v))return null;
        return $v;
    };
    $time=static fn($v)=>is_string($v)&&preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/',$v)?$v:null;
    $safeTime=static fn($v)=>is_string($v)&&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/',$v)?$v:null;
    $report=['diagnostic'=>'WHATSAPP_CAIRO_STORE_READ_ONLY','database_writes'=>0,'configuration_writes'=>0,
        'network_requests'=>0,'ai_calls'=>0,'orders_created'=>0,'customer_queries'=>0,'classification_applied'=>false,
        'runtime_files'=>[],'public_schema'=>[],'stores'=>[],'stores_truncated'=>false];
    foreach([
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php','app/Services/Dashboard/PhoneDelivery.php',
        'app/Services/Dashboard/PosServiceTicket.php','app/Services/Dashboard/TakeawayAccess.php',
        'app/Services/Dashboard/TakeawayCatalog.php','app/Services/Dashboard/PhoneMapProvider.php',
        'app/Services/Dashboard/PhoneDeliveryBoard.php','app/Services/Dashboard/PosBranchPrinting.php',
        'app/Http/Controllers/Dashboard/PhoneOrdersController.php','app/Http/Controllers/Dashboard/PosServiceController.php',
        'public/dashboard/js/dashboard-location-picker.js','public/dashboard/js/phone-orders.js',
        'public/dashboard/js/phone-address-search.js','resources/views/admin/phone_orders/index.blade.php',
        'resources/views/admin/phone_orders/batch_receipt.blade.php',
        'resources/views/admin/maps/assets.blade.php','app/Models/Resturant.php','app/Models/Area.php',
    ]as$path){
        $s=@lstat($path);$item=['exists'=>$s!==false];
        if($s){$regular=($s['mode']&0170000)===0100000;$item+=['regular'=>$regular,'links'=>$s['nlink'],
            'mode'=>sprintf('%04o',$s['mode']&0777),'owner_matches'=>$s['uid']===posix_geteuid()];
            if($regular&&$s['size']<=2097152){$bytes=@file_get_contents($path);if(is_string($bytes))$item['blob']=sha1('blob '.strlen($bytes)."\0".$bytes);}
        }
        $report['runtime_files'][$path]=$item;
    }
    $orders=(array)config('whatsapp_orders',[]);$cart=(array)config('whatsapp_cart',[]);
    $allowed=$orders['allowed_branch_ids']??[];$allowed=is_array($allowed)?array_values(array_filter(array_map($id,$allowed),static fn($v)=>$v!==null)):[];
    $report['configuration']=['mode'=>in_array($orders['mode']??null,['review','auto'],true)?$orders['mode']:'UNKNOWN',
        'orders_enabled'=>in_array($orders['enabled']??false,[true,1,'1'],true),
        'automation_actor_id'=>$id($orders['automation_actor_id']??null),'allowed_branch_ids'=>array_slice($allowed,0,100),
        'allowed_branch_ids_truncated'=>count($allowed)>100,'activation_message_id'=>$id($orders['activation_message_id']??null),
        'activation_event_id'=>$id($orders['activation_event_id']??null),'activated_at_utc'=>$safeTime($cart['activated_at']??null)];
    $providerConfigured=static function($key){$v=config($key);return is_string($v)&&filter_var($v,FILTER_VALIDATE_URL)!==false
        &&parse_url($v,PHP_URL_SCHEME)==='https'&&!parse_url($v,PHP_URL_USER)&&!parse_url($v,PHP_URL_PASS);};
    $browserKey=config('services.maps.browser_key');
    $report['maps']=['phone_open_enabled'=>(bool)config('services.maps.phone_open_enabled',false),
        'road_provider_configured'=>$providerConfigured('services.maps.osrm_url'),
        'address_provider_configured'=>$providerConfigured('services.maps.photon_url'),
        'browser_key_configured'=>is_string($browserKey)&&trim($browserKey)!==''];
    $actor=\App\Models\User::withoutGlobalScopes()->find(1);
    $actor=app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->actor($actor);
    $report['actor']=['id'=>1,'central_access'=>true,
        'can_checkout'=>(app(\App\Services\Dashboard\TakeawayAccess::class)->permissions($actor)['can_checkout']??false)===true];
    if(!$schema::hasTable('resturants'))throw new \RuntimeException();
    $columns=$schema::getColumnListing('resturants');
    $public=['id','name','name_ar','name_en','parent_id','type','resturant_type','is_store','is_branch','status',
        'area_id','city_id','open_at','close_at','lat','lng','latitude','longitude','km_price',
        'default_0_1','default_1_2','default_2_3'];
    $select=array_values(array_intersect($public,$columns));
    if(!in_array('id',$select,true))throw new \RuntimeException();
    $report['public_schema']['resturants']=array_values(array_diff($select,['lat','lng','latitude','longitude','km_price']));
    $report['public_schema']['pin_fields_present']=array_values(array_intersect($columns,['lat','lng','latitude','longitude']));
    $report['public_schema']['rate_field_present']=in_array('km_price',$columns,true);
    $asArray=static fn($v)=>is_array($v)?$v:$v->all();
    $report['stores_total']=(int)$db::table('resturants')->count();
    $rows=$asArray($db::table('resturants')->select($select)->orderBy('id')->limit(151)->get());
    // Read actual parent_id values; priority does not classify a store or apply routing.
    $priority=$asArray($db::table('resturants')->select($select)->where('id',301)->limit(1)->get());
    $hasParent=in_array('parent_id',$select,true);
    $children=$hasParent?$asArray($db::table('resturants')->select($select)->where('parent_id',301)->orderBy('id')->limit(151)->get()):[];
    $report['parent_301']=['parent_field_present'=>$hasParent,
        'children_total'=>$hasParent?(int)$db::table('resturants')->where('parent_id',301)->count():null,
        'children_truncated'=>count($children)>149];
    $unique=[];foreach(array_merge($priority,$children,$rows)as$row){$key=$id($row->id??null);if($key!==null&&!isset($unique[$key]))$unique[$key]=$row;}
    $rows=array_slice(array_values($unique),0,150);
    $report['stores_truncated']=$report['stores_total']>count($rows);
    $report['inventory_order']='ID_301_THEN_DIRECT_CHILDREN_THEN_OTHER_IDS';
    // Names are joined only for the live model's explicit area_id->Area relation.
    $relation=static function($class,$method,$pattern){
        try{$r=new \ReflectionMethod($class,$method);$path=$r->getFileName();
            $parts=explode('\\',$class);$expected=getcwd().'/app/Models/'.end($parts).'.php';
            if(!is_string($path)||$path!==$expected||is_link($path)||filesize($path)>2097152)return false;
            $lines=file($path);$body=implode('',array_slice($lines,$r->getStartLine()-1,$r->getEndLine()-$r->getStartLine()+1));
            return preg_match($pattern,$body)===1;
        }catch(\Throwable$e){return false;}
    };
    $areaReady=in_array('area_id',$columns,true)&&$schema::hasTable('areas')
        &&$relation(\App\Models\Resturant::class,'area','/belongsTo\s*\(\s*Area::class\s*,\s*[\x22\x27]area_id[\x22\x27]\s*\)/');
    $areas=[];$areaColumns=$areaReady?$schema::getColumnListing('areas'):[];
    $areaReady=$areaReady&&in_array('id',$areaColumns,true);
    $parentReady=$areaReady&&in_array('parent_id',$areaColumns,true)
        &&$relation(\App\Models\Area::class,'parent','/belongsTo\s*\(\s*Area::class\s*,\s*[\x22\x27]parent_id[\x22\x27]\s*\)/');
    $report['public_schema']['area_relation_verified']=$areaReady;
    $report['public_schema']['area_parent_relation_verified']=$parentReady;
    $report['public_schema']['city_relation_verified']=false;
    if($areaReady){
        $areaSelect=array_values(array_intersect(['id','title_ar','title_en','title','parent_id'],$areaColumns));
        $ids=array_values(array_unique(array_filter(array_map(static fn($r)=>(int)($r->area_id??0),$rows),static fn($v)=>$v>0)));
        if($ids!==[])foreach($db::table('areas')->select($areaSelect)->whereIn('id',$ids)->limit(150)->get()as$area)$areas[(string)$area->id]=$area;
        if($parentReady){$parents=array_values(array_unique(array_filter(array_map(static fn($a)=>(int)($a->parent_id??0),$areas),static fn($v)=>$v>0)));
            if($parents!==[])foreach($db::table('areas')->select($areaSelect)->whereIn('id',$parents)->limit(150)->get()as$area)$areas[(string)$area->id]=$area;
        }
    }
    foreach($rows as$row){
        $entry=['id'=>$id($row->id??null),'name'=>$text($row->name??($row->name_ar??($row->name_en??null))),
            'parent_id'=>$id($row->parent_id??null),'manual_status'=>in_array($row->status??null,['opened','busy','closed','hide'],true)?$row->status:'UNKNOWN'];
        foreach(['type','resturant_type','is_store','is_branch']as$field)if(in_array($field,$select,true)){
            $v=$row->$field??null;$entry[$field]=is_bool($v)||is_int($v)?$v:$text($v);
        }
        $open=$time($row->open_at??null);$close=$time($row->close_at??null);
        $entry['working_hours']=['configured'=>$open!==null&&$close!==null,'open_at'=>$open,'close_at'=>$close,
            'overnight'=>$open!==null&&$close!==null&&$open>$close,'equal_times'=>$open!==null&&$close!==null&&$open===$close];
        $lat=$row->lat??($row->latitude??null);$lng=$row->lng??($row->longitude??null);$rate=$row->km_price??null;
        $entry['pin_ready']=is_numeric($lat)&&is_numeric($lng)&&(float)$lat>=-90&&(float)$lat<=90&&(float)$lng>=-180&&(float)$lng<=180
            &&!((float)$lat===0.0&&(float)$lng===0.0);
        $entry['rate_configured']=is_numeric($rate)&&is_finite((float)$rate)&&(float)$rate>=0&&(float)$rate<=1000000;
        $entry['delivery_tiers']=[];
        foreach(['default_0_1','default_1_2','default_2_3']as$field)if(in_array($field,$select,true)){
            $value=$row->$field??null;$entry['delivery_tiers'][$field]=is_numeric($value)&&is_finite((float)$value)
                &&(float)$value>=0&&(float)$value<=1000000?(float)$value:null;
        }
        $entry['area_id']=in_array('area_id',$select,true)?$id($row->area_id??null):null;
        $entry['city_id']=in_array('city_id',$select,true)?$id($row->city_id??null):null;
        if($areaReady&&isset($areas[$entry['area_id']??''])){
            $area=$areas[$entry['area_id']];$entry['area_name']=$text($area->title_ar??($area->title??($area->title_en??null)));
            if($parentReady){$parent=$areas[(string)($area->parent_id??'')]??null;
                $entry['parent_area_id']=$id($area->parent_id??null);
                $entry['parent_area_name']=$parent?$text($parent->title_ar??($parent->title??($parent->title_en??null))):null;
            }
        }
        $report['stores'][]=$entry;
    }
    $report['stores_reported']=count($report['stores']);
    $report['parent_301']['children_reported']=count(array_filter($report['stores'],static fn($v)=>$v['parent_id']==='301'));
    $report['parent_301']['children_truncated']=$hasParent&&$report['parent_301']['children_total']>$report['parent_301']['children_reported'];
    while(ob_get_level()>0)ob_end_clean();
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}catch(\Throwable$e){while(ob_get_level()>0)ob_end_clean();echo"CAIRO_STORE_PREFLIGHT_FAILED_WITHOUT_SECRET_OUTPUT\n";exit(1);}
