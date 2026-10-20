<?php
/** Exercise the actual repair with isolated DB/Spatie/Storage/HTTP doubles and real image files. */
namespace MenuImageRepairTest {
final class State {
    public static string $root; public static array $tables=[],$columns=[],$config=[],$verified=[];
    public static ?array $snapshot=null; public static int $writes=0; public static string $commitFailure='',$probeFailure='';
    public static function fixture(): string {
        self::$root=sys_get_temp_dir().'/menu-image-repair-test-'.bin2hex(random_bytes(6));
        foreach(['storage/app/public','storage/app/menu-sides-20261021','backup'] as $dir)mkdir(self::$root.'/'.$dir,0700,true);
        self::$config=['app.url'=>'https://fasakhaninja.com','filesystems.disks.products.driver'=>'local','filesystems.disks.products.root'=>self::$root.'/storage/app/public/products','filesystems.disks.products.url'=>'https://fasakhaninja.com/storage/products','filesystems.disks.public.driver'=>'local','filesystems.disks.public.root'=>self::$root.'/storage/app/public','filesystems.disks.public.url'=>'https://fasakhaninja.com/storage/public'];
        mkdir(self::$config['filesystems.disks.products.root'],0700);
        self::$tables=['resturants'=>[],'products'=>[],'categories'=>[['id'=>7,'name_ar'=>'الأطباق الجانبية','status'=>'show']],'resturant_products'=>[],'media'=>[],'go_store_products'=>[['id'=>99,'name'=>'unrelated']]];
        self::$columns=['resturants'=>['id','name'],'products'=>['id','category_id','name_ar','status','has_clean'],'resturant_products'=>['id','product_id','resturant_id','category_id','product_name','product_price','price','status'],'media'=>['id','model_type','model_id','collection_name','disk','file_name','custom_properties','conversions_disk','generated_conversions','responsive_images','updated_at']];
        $receipt=['release'=>'menu-sides-20261021','phase'=>'committed','category_id'=>7,'branch_ids'=>[],'products'=>[],'menu'=>[]];
        foreach(range(1,32) as $branch){self::$tables['resturants'][]=['id'=>$branch,'name'=>'فرع '.$branch];$receipt['branch_ids'][]=$branch;}
        $id=3322;
        foreach(['tomato'=>['طبق طماطم',15,'tomato-plate.jpg'],'tahini'=>['طبق طحينة',30,'tahini-plate.jpg']] as $key=>$item){
            $pid=$key==='tomato'?55:56;self::$tables['products'][]=['id'=>$pid,'category_id'=>7,'name_ar'=>$item[0],'status'=>'show','has_clean'=>0];$receipt['products'][]=['id'=>$pid,'key'=>$key];
            foreach(range(1,32) as $branch){$rid=5000+$id;$bytes=file_get_contents(dirname(__DIR__,2).'/deployment/menu-sides-20261021/assets/'.$item[2]);
                self::$tables['resturant_products'][]=['id'=>$rid,'product_id'=>$pid,'resturant_id'=>$branch,'category_id'=>7,'product_name'=>$item[0],'product_price'=>$item[1],'price'=>json_encode(array_fill_keys(['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'],null)),'status'=>'show'];
                self::$tables['media'][]=['id'=>$id,'model_type'=>'App\\Models\\ResturantProduct','model_id'=>$rid,'collection_name'=>'product_image','disk'=>'public','conversions_disk'=>'public','file_name'=>$item[2],'custom_properties'=>json_encode(['menu_release'=>'menu-sides-20261021','menu_item'=>$key,'sha256'=>hash('sha256',$bytes)]),'generated_conversions'=>'[]','responsive_images'=>'[]','updated_at'=>'2026-10-21 01:00:00'];
                mkdir(self::$root.'/storage/app/public/'.$id,0700);file_put_contents(self::$root.'/storage/app/public/'.$id.'/'.$item[2],$bytes);
                $receipt['menu'][]=['id'=>$rid,'branch_id'=>$branch,'key'=>$key,'media_id'=>$id,'image_path'=>self::$root.'/storage/app/public/'.$id.'/'.$item[2]];$id++;
            }
        }
        self::$tables['media'][]=['id'=>1007,'model_type'=>'App\\Models\\ResturantProduct','model_id'=>999,'collection_name'=>'product_image','disk'=>'products','conversions_disk'=>'products','file_name'=>'existing.jpg','custom_properties'=>'{}','generated_conversions'=>'[]','responsive_images'=>'[]','updated_at'=>'old'];
        mkdir(self::$root.'/storage/app/public/products/1007',0700);file_put_contents(self::$root.'/storage/app/public/products/1007/existing.jpg','existing image sentinel');
        file_put_contents(self::$root.'/storage/app/menu-sides-20261021/receipt.json',json_encode($receipt));
        self::$writes=0;self::$snapshot=null;self::$verified=[];self::$commitFailure='';self::$probeFailure='';return self::$root;
    }
}
final class Collection {public function __construct(private array $rows){}public function all():array{return $this->rows;}public function count():int{return count($this->rows);}public function first():?object{return $this->rows[0]??null;}}
final class Query {
    private array $filters=[];public function __construct(private string $table){}
    public function where(string $key,$op,$value=null):self{$value=func_num_args()===2?$op:$value;$this->filters[]=fn($r)=>($r[$key]??null)==$value;return $this;}
    public function whereIn(string $key,array $values):self{$this->filters[]=fn($r)=>in_array($r[$key]??null,$values);return $this;}
    public function orderBy(string $key):self{return $this;}public function lockForUpdate():self{return $this;}
    private function selected(array $r):bool{foreach($this->filters as $f)if(!$f($r))return false;return true;}
    public function get():Collection{$rows=array_values(array_filter(State::$tables[$this->table]??[],fn($r)=>$this->selected($r)));usort($rows,fn($a,$b)=>$a['id']<=>$b['id']);return new Collection(array_map(fn($r)=>(object)$r,$rows));}
    public function first():?object{return $this->get()->first();}public function count():int{return $this->get()->count();}public function exists():bool{return $this->count()>0;}
    public function update(array $values):int{if(count(State::$verified)!==64)throw new \RuntimeException('Database update preceded verification of all 64 HTTP targets');$count=0;foreach(State::$tables[$this->table] as &$row)if($this->selected($row)){$row=array_replace($row,$values);$count++;State::$writes++;}unset($row);return $count;}
}
final class Media {
    public string $disk;public ?string $conversions_disk;public function __construct(private array $row){$this->disk=$row['disk'];$this->conversions_disk=$row['conversions_disk']??null;}
    public function __get(string $key){$v=$this->row[$key]??null;return in_array($key,['generated_conversions','responsive_images','custom_properties'],true)&&is_string($v)?json_decode($v,true):$v;}
    public function getCustomProperty(string $key){return (json_decode($this->row['custom_properties'],true)??[])[$key]??null;}
    public function getPath():string{return State::$config['filesystems.disks.'.$this->disk.'.root'].'/'.$this->row['id'].'/'.$this->row['file_name'];}
    public function getUrl():string{return State::$config['filesystems.disks.'.$this->disk.'.url'].'/'.$this->row['id'].'/'.$this->row['file_name'];}
    public function getAttributes():array{return array_replace($this->row,['disk'=>$this->disk,'conversions_disk'=>$this->conversions_disk]);}
}
final class Disk {
    public function __construct(private string $name){}public function put(string $relative,string $bytes):bool{$path=$this->path($relative);if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);return file_put_contents($path,$bytes)!==false;}
    public function setVisibility(string $relative,string $visibility):bool{return chmod($this->path($relative),0644);}public function path(string $relative=''):string{return rtrim(State::$config['filesystems.disks.'.$this->name.'.root'],'/').'/'.$relative;}
    public function exists(string $relative):bool{return is_file($this->path($relative));}public function get(string $relative):string{return file_get_contents($this->path($relative));}
}
}
namespace Illuminate\Support\Facades {
final class DB {public static function table(string $table):\MenuImageRepairTest\Query{return new \MenuImageRepairTest\Query($table);}public static function beginTransaction():void{\MenuImageRepairTest\State::$snapshot=\MenuImageRepairTest\State::$tables;}public static function commit():void{if(\MenuImageRepairTest\State::$commitFailure==='before')throw new \RuntimeException('Injected commit uncertainty');\MenuImageRepairTest\State::$snapshot=null;if(\MenuImageRepairTest\State::$commitFailure==='after')throw new \RuntimeException('Injected commit uncertainty');}public static function rollBack():void{\MenuImageRepairTest\State::$tables=\MenuImageRepairTest\State::$snapshot;\MenuImageRepairTest\State::$snapshot=null;}public static function transactionLevel():int{return \MenuImageRepairTest\State::$snapshot===null?0:1;}}
final class Schema {public static function hasTable(string $table):bool{return isset(\MenuImageRepairTest\State::$tables[$table]);}public static function getColumnListing(string $table):array{return \MenuImageRepairTest\State::$columns[$table]??[];}public static function hasColumns(string $table,array $columns):bool{return !array_diff($columns,self::getColumnListing($table));}public static function hasColumn(string $table,string $column):bool{return in_array($column,self::getColumnListing($table),true);}}
final class Storage {public static function disk(string $name):\MenuImageRepairTest\Disk{return new \MenuImageRepairTest\Disk($name);}}
}
namespace App\Models {
final class ResturantProduct {public function __construct(private int $id=0){}public static function withoutGlobalScopes():self{return new self;}public function findOrFail(int $id):self{if(!\Illuminate\Support\Facades\DB::table('resturant_products')->where('id',$id)->exists())throw new \RuntimeException('missing model');return new self($id);}public function getMorphClass():string{return self::class;}public function getMedia(string $collection):\MenuImageRepairTest\Collection{$rows=[];foreach(\MenuImageRepairTest\State::$tables['media'] as $r)if($r['model_id']===$this->id&&$r['collection_name']===$collection)$rows[]=new \MenuImageRepairTest\Media($r);return new \MenuImageRepairTest\Collection($rows);}}
}
namespace {
use MenuImageRepairTest\State;
function config(string $key){return State::$config[$key]??null;}
function now($timezone=null):string{return '2026-10-21 01:30:00';}
$checks=0;$roots=[];
function check(bool $test,string $name):void{global $checks;$checks++;if(!$test)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name.PHP_EOL;}
function reject(callable $fn,string $part):void{try{$fn();}catch(Throwable $e){check(stripos($e->getMessage(),$part)!==false,'rejects '.$part);return;}throw new RuntimeException('Expected rejection '.$part);}
function files(string $root):array{$out=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile())$out[substr($f->getPathname(),strlen($root))]=hash_file('sha256',$f->getPathname());ksort($out);return $out;}
function cleanup(string $root):void{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)($f->isDir()&&!$f->isLink())?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}
function probe(array $targets):void{if(State::$writes!==0)throw new RuntimeException('HTTP check after DB update');foreach($targets as $i=>$target){if(State::$probeFailure==='404'&&$i===10)throw new RuntimeException('Injected HTTP404');if(State::$probeFailure==='hash'&&$i===10)throw new RuntimeException('Injected HTTP body hash mismatch');$parts=parse_url($target['url']);$path=State::$root.'/storage/app/public/products/'.$target['media_id'].'/'.basename($parts['path']);if(!is_file($path)||hash_file('sha256',$path)!==$target['sha256'])throw new RuntimeException('HTTP target bytes are not ready');State::$verified[$target['media_id']]=true;}}
require dirname(__DIR__,2).'/deployment/repair_menu_side_images_20261021.php';
function fixture():FasakhanstaMenuSideImageRepair20261021{global $roots;$root=State::fixture();$roots[]=$root;return new FasakhanstaMenuSideImageRepair20261021($root,'probe');}
try{
    check(!method_exists(MenuImageRepairTest\Media::class,'getPathRelativeToRoot'),'media double excludes methods absent from Spatie Media 9.6');
    $runner=fixture();$before=State::$tables;$beforeFiles=files(State::$root);$result=$runner->run(false,null);check(State::$tables===$before&&State::$writes===0&&files(State::$root)===$beforeFiles,'check reads exact 64-image release without database or file changes');
    $runner->run(true,State::$root.'/backup');check(count(State::$verified)===64&&State::$writes===64,'all 64 image destinations and HTTP hashes verify before any media update');
    foreach(State::$tables['media'] as $row)if($row['id']>=3322)check($row['disk']==='products'&&$row['conversions_disk']==='products','each release media now uses products disk');
    check(State::$tables['products']===$before['products']&&State::$tables['resturant_products']===$before['resturant_products']&&State::$tables['go_store_products']===$before['go_store_products']&&State::$tables['media'][64]===$before['media'][64],'all prices, menu IDs, unrelated GO rows and existing media remain unchanged');
    $sourceFiles=array_filter($beforeFiles,fn($k)=>preg_match('~^/storage/app/public/[0-9]+/~',$k),ARRAY_FILTER_USE_KEY);$afterFiles=files(State::$root);check(count($sourceFiles)===64&&!array_diff_assoc($sourceFiles,$afterFiles),'all original public image source files are retained byte-for-byte');
    $state=State::$tables;State::$writes=0;State::$verified=[];$runner->run(true,State::$root.'/backup');check(State::$tables===$state&&State::$writes===0,'repeat repair is idempotent after validating installed image mapping');
    $runner=fixture();$before=State::$tables;file_put_contents(State::$root.'/storage/app/public/3322/tomato-plate.jpg','corrupt');reject(fn()=>$runner->run(true,State::$root.'/backup'),'SHA check');check(State::$tables===$before&&State::$writes===0,'wrong source hash changes no database rows');
    $runner=fixture();$before=State::$tables;mkdir(State::$root.'/storage/app/public/products/3322',0700);file_put_contents(State::$root.'/storage/app/public/products/3322/tomato-plate.jpg','unrelated file');reject(fn()=>$runner->run(true,State::$root.'/backup'),'destination image conflicts');check(State::$tables===$before&&State::$writes===0,'destination collision never overwrites media rows or existing file');check(file_get_contents(State::$root.'/storage/app/public/products/3322/tomato-plate.jpg')==='unrelated file','destination collision bytes are preserved');
    $runner=fixture();$before=State::$tables;State::$probeFailure='404';reject(fn()=>$runner->run(true,State::$root.'/backup'),'HTTP404');check(State::$tables===$before&&State::$writes===0,'HTTP404 probe failure leaves all media references unchanged');
    $runner=fixture();$before=State::$tables;State::$probeFailure='hash';reject(fn()=>$runner->run(true,State::$root.'/backup'),'body hash');check(State::$tables===$before&&State::$writes===0,'wrong HTTP body prevents all media reference updates');
    $runner=fixture();$before=State::$tables;State::$tables['media'][0]['generated_conversions']='{"thumb":true}';$before=State::$tables;reject(fn()=>$runner->run(true,State::$root.'/backup'),'derivatives');check(State::$tables===$before&&State::$writes===0,'unexpected derivatives are rejected without destructive regeneration');
    $runner=fixture();$before=State::$tables;State::$commitFailure='after';reject(fn()=>$runner->run(true,State::$root.'/backup'),'commit uncertainty');check(count(array_filter(State::$tables['media'],fn($r)=>$r['id']>=3322&&$r['disk']==='products'))===64,'successful commit with lost acknowledgement keeps repaired media references');State::$commitFailure='';State::$writes=0;State::$verified=[];$runner->run(true,State::$root.'/backup');check(State::$writes===0,'retry after unknown successful commit introduces no additional database writes');
    $runner=fixture();$before=State::$tables;State::$commitFailure='before';reject(fn()=>$runner->run(true,State::$root.'/backup'),'commit uncertainty');check(State::$tables===$before,'uncommitted media transaction rolls back every reference together');State::$commitFailure='';State::$writes=0;State::$verified=[];$runner->run(true,State::$root.'/backup');check(State::$writes===64,'retry reuses approved image copies after an uncommitted failure');
    $runner=fixture();$before=State::$tables;$path=State::$root.'/storage/app/menu-sides-20261021/receipt.json';$receipt=json_decode(file_get_contents($path),true);array_pop($receipt['menu']);file_put_contents($path,json_encode($receipt));reject(fn()=>$runner->run(true,State::$root.'/backup'),'32-branch/64-image scope');check(State::$tables===$before&&State::$writes===0,'incomplete original receipt cannot widen or narrow the reviewed repair scope');
    $runner=fixture();State::$tables['resturant_products'][0]['product_price']=16;$before=State::$tables;reject(fn()=>$runner->run(true,State::$root.'/backup'),'branch side item changed');check(State::$tables===$before&&State::$writes===0,'unexpected price edits are preserved and require review');
    echo 'MENU IMAGE REPAIR CHECKS PASSED: '.$checks.PHP_EOL;
}finally{foreach($roots as $root)cleanup($root);}
}
