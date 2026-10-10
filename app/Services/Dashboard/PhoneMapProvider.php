<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Contracts\Cache\LockTimeoutException;

/** Optional open-data services. Explicitly enabled only after operator approval. */
class PhoneMapProvider
{
    public function enabled(): bool {return (bool)config('services.maps.phone_open_enabled',false);}
    public function suggestions(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:30','query'=>'required|string|min:2|max:240'])->validate();
        $policy=app(PhoneDelivery::class)->settings($v['branch'],$actor);
        $query=trim(preg_replace('/\s+/u',' ',$v['query']));
        if(config('desktop_dashboard.local')){
            $this->authorizeSavedSuggestions($actor);
            return $this->savedSuggestions($policy['branch']['value'],$query);
        }
        abort_unless($this->enabled(),503,'البحث بالعناوين من الخدمة البديلة لم يُفعّل بعد.');
        $params=['q'=>$query,'limit'=>6,'countrycode'=>'EG','bbox'=>'24,22,37,32'];
        if($policy['latitude']!==null){$params['lat']=$policy['latitude'];$params['lon']=$policy['longitude'];}
        $json=$this->request('search',(string)config('services.maps.photon_url'),$params,86400);
        abort_unless(isset($json['features'])&&is_array($json['features']),503,'تعذر قراءة اقتراحات العناوين.');
        $items=[];$seen=[];
        foreach(array_slice($json['features'],0,12) as $feature){
            $p=$feature['properties']??[];$coords=$feature['geometry']['coordinates']??[];
            if(($feature['geometry']['type']??'')!=='Point'||count($coords)!==2||!$this->point($coords)||strtoupper($p['countrycode']??'')!=='EG')continue;
            $parts=[];foreach(['name','housenumber','street','district','city','county','state','country'] as $key)if(isset($p[$key])&&is_string($p[$key])&&trim($p[$key])!=='')$parts[]=trim($p[$key]);
            $label=mb_substr(implode('، ',array_unique($parts)),0,400);if($label==='')continue;$id=hash('sha256',json_encode([$label,$coords]));if(isset($seen[$id]))continue;$seen[$id]=true;
            $items[]=['id'=>$id,'label'=>$label,'latitude'=>(float)$coords[1],'longitude'=>(float)$coords[0]];
        }
        return ['success'=>true,'provider'=>'open','items'=>array_slice($items,0,6)];
    }
    /** Spatie's shared permission/role cache cannot prove a current local admin grant. */
    private function authorizeSavedSuggestions($actor): void
    {
        $fresh=app(TakeawayAccess::class)->actor($actor);
        if($fresh->account_type!=='admin'||!empty($fresh->owner_resturant_id)||(int)$fresh->id===1)return;
        $tables=config('permission.table_names');$roles=Schema::hasTable($tables['roles'])&&Schema::hasTable($tables['model_has_roles']);
        if($roles&&$fresh->roles()->where('guard_name','admin')->where('name','Super Admin')->exists())return;
        abort_unless(Schema::hasTable($tables['permissions']),403);
        $permission=DB::table($tables['permissions'])->where('guard_name','admin')->where('name','order-list')->first();abort_unless($permission,403);
        $modelKey=config('permission.column_names.model_morph_key','model_id');
        $permissionKey=config('permission.column_names.permission_pivot_key')?:'permission_id';$roleKey=config('permission.column_names.role_pivot_key')?:'role_id';
        $direct=Schema::hasTable($tables['model_has_permissions'])&&DB::table($tables['model_has_permissions'])
            ->where('model_type',$fresh->getMorphClass())->where($modelKey,$fresh->id)->where($permissionKey,$permission->id)->exists();
        $grant=$roles&&Schema::hasTable($tables['role_has_permissions'])&&DB::table($tables['role_has_permissions'])
            ->where($permissionKey,$permission->id)->whereIn($roleKey,$fresh->roles()->where('guard_name','admin')->select($tables['roles'].'.id'))->exists();
        abort_unless($direct||$grant,403);
    }
    /** Local reads use only the selected branch's stored address and pin, never customer identity. */
    private function savedSuggestions(string $branch,string $query): array
    {
        abort_unless(mb_strlen($query)>=2,422,'اكتب حرفين على الأقل للبحث في العناوين المحفوظة.');
        $result=['success'=>true,'provider'=>'saved','items'=>[]];$access=app(TakeawayAccess::class);
        foreach(['branch','address','area','latitude','longitude'] as $column)if(!$access->has('branch_customers',$column))return $result;
        $rows=DB::table('branch_customers')->where('branch',$branch)->whereNotNull('latitude')->whereNotNull('longitude')
            ->select('address','area','latitude','longitude')->orderBy('id')->cursor();$seen=[];
        foreach($rows as $row){
            if(!is_numeric($row->latitude)||!is_numeric($row->longitude))continue;
            $lat=(float)$row->latitude;$lng=(float)$row->longitude;
            if(!is_finite($lat)||!is_finite($lng)||abs($lat)>90||abs($lng)>180||($lat===0.0&&$lng===0.0))continue;
            $address=trim(preg_replace('/\s+/u',' ',(string)$row->address));$area=trim(preg_replace('/\s+/u',' ',(string)$row->area));
            if($address==='')continue;
            // A literal substring keeps percent, underscore and backslash from becoming SQL wildcards.
            if(mb_stripos($address,$query)===false&&mb_stripos($area,$query)===false)continue;
            $fullLabel=implode('، ',array_unique(array_filter([$address,$area],fn($part)=>$part!=='')));$label=mb_substr($fullLabel,0,400);
            $id=hash('sha256',json_encode([$fullLabel,[$lng,$lat]]));if(isset($seen[$id]))continue;$seen[$id]=true;
            $result['items'][]=['id'=>$id,'label'=>$label,'latitude'=>$lat,'longitude'=>$lng];
            if(count($result['items'])===6)break;
        }
        return $result;
    }
    public function road(array $policy,float $lat,float $lng): array
    {
        abort_unless($this->enabled(),503,'حساب الطريق من الخدمة البديلة لم يُفعّل بعد.');
        $points=[$policy['longitude'],$policy['latitude'],$lng,$lat];
        $pair=implode(',',array_map(fn($n)=>sprintf('%.7F',$n),array_slice($points,0,2))).';'.implode(',',array_map(fn($n)=>sprintf('%.7F',$n),array_slice($points,2)));
        $url=rtrim((string)config('services.maps.osrm_url'),'/').'/route/v1/driving/'.$pair;
        $json=$this->request('route',$url,['overview'=>'simplified','geometries'=>'geojson','steps'=>'false','alternatives'=>'false','radiuses'=>'200;200'],1800);
        $route=$json['routes'][0]??[];$coords=$route['geometry']['coordinates']??[];
        abort_unless(($json['code']??'')==='Ok'&&($route['geometry']['type']??'')==='LineString'&&is_numeric($route['distance']??null)&&$route['distance']>=0&&$route['distance']<=2000000&&count($coords)>=2&&count($coords)<=20000,422,'لم نجد طريق توصيل بين الموقعين. حرّك الدبوس إلى مدخل شارع متاح ثم أعد المحاولة.');
        foreach($coords as $p)abort_unless($this->point($p),503,'تعذر قراءة مسار الطريق.');
        return ['meters'=>(int)round($route['distance']),'path'=>array_map(fn($p)=>[(float)$p[1],(float)$p[0]],$coords),'method'=>'road_osrm'];
    }
    /** Reserve a short rate slot, then release the lock before network I/O.
     * Slow queries must not block all branch devices for their entire timeout. */
    private function searchRequest($cache,string $key,string $url,array $params,int $ttl): array
    {
        $provider='phone-search-slot:'.hash('sha256',(string)parse_url($url,PHP_URL_HOST));
        try{$allowed=$cache->lock($provider,2)->block(1,function()use($cache,$provider){
            if((float)$cache->get($provider.':next',0)>microtime(true))return false;
            $cache->put($provider.':next',microtime(true)+1.0,5);return true;
        });}catch(LockTimeoutException $e){$allowed=false;}
        abort_unless($allowed,429,'جارٍ تحديث اقتراحات العناوين.');
        try{$response=Http::acceptJson()->withHeaders(['User-Agent'=>'FasakhanstaDashboard/1.0 (+https://fasakhaninja.com)'])->withOptions(['allow_redirects'=>false,'connect_timeout'=>2])->timeout(5)->get($url,$params);}
        catch(\Illuminate\Http\Client\ConnectionException $e){abort(503,'تأخر مزود البحث بالعناوين. أعد المحاولة.');}
        if($response->status()===429){$cache->put($provider.':next',microtime(true)+5,6);abort(429,'مزود العناوين مشغول للحظات.');}
        abort_unless($response->successful(),503,'خدمة البحث بالعناوين غير متاحة حاليًا.');$data=$response->json();
        abort_unless(is_array($data)&&isset($data['features'])&&is_array($data['features']),503,'تعذر قراءة اقتراحات العناوين.');
        // Successful lookups can be reused across devices; empty results expire sooner.
        $cache->put($key,$data,count($data['features'])?$ttl:60);return $data;
    }
    private function point($p): bool{return is_array($p)&&count($p)>=2&&is_numeric($p[0])&&is_numeric($p[1])&&abs((float)$p[0])<=180&&abs((float)$p[1])<=90;}
    private function request(string $kind,string $url,array $params,int $ttl): array
    {
        abort_unless(filter_var($url,FILTER_VALIDATE_URL)&&parse_url($url,PHP_URL_SCHEME)==='https'&&!parse_url($url,PHP_URL_USER)&&!parse_url($url,PHP_URL_PASS),503,'إعدادات خدمة الخرائط غير صالحة.');
        $key='phone-map:'.hash('sha256',$url.json_encode($params));$cache=Cache::store();if(is_array($hit=$cache->get($key)))return $hit;
        if($kind==='search')return $this->searchRequest($cache,$key,$url,$params,$ttl);
        // One request per second per provider across branch devices; cached reuse is free.
        $provider='phone-map-provider:'.hash('sha256',(string)parse_url($url,PHP_URL_HOST));
        try{return $cache->lock($provider,15)->block(2,function()use($cache,$provider,$key,$url,$params,$ttl,$kind){
            if(is_array($hit=$cache->get($key)))return $hit;
            $wait=(float)$cache->get($provider.':next',0)-microtime(true);if($wait>0)usleep((int)(min(1.1,$wait)*1000000));$cache->put($provider.':next',microtime(true)+1.05,5);
            try{$response=Http::acceptJson()->withHeaders(['User-Agent'=>'FasakhanstaDashboard/1.0 (+https://fasakhaninja.com)'])->withOptions(['allow_redirects'=>false,'connect_timeout'=>3])->timeout(8)->get($url,$params);}
            catch(\Illuminate\Http\Client\ConnectionException $e){abort(503,'تعذر الاتصال بخدمة الخرائط. أعد المحاولة بعد لحظات.');}
            abort_unless($response->successful(),503,'خدمة الخرائط غير متاحة حاليًا. أعد المحاولة بعد لحظات.');$data=$response->json();abort_unless(is_array($data),503,'استجابة خدمة الخرائط غير صالحة.');
            if(($kind==='search'&&isset($data['features']))||($kind==='route'&&($data['code']??'')==='Ok'))$cache->put($key,$data,$ttl);
            return $data;
        });}catch(LockTimeoutException $e){abort(429,'خدمة الخرائط مشغولة للحظات. أعد المحاولة.');}
    }
}
