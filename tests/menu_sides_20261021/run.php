<?php
/** Isolated deployment behavior with in-memory facade/media doubles; no Laravel or production access. */
namespace MenuSidesTest {
    final class State {
        public static array $tables = [];
        public static array $columns = [];
        public static ?array $snapshot = null;
        public static string $root;
        public static int $mediaCalls = 0;
        public static int $failMediaAt = 0;
        public static int $writes = 0;
        public static string $commitFailure = '';
        public static array $config = [];
        public static string $mediaUrlOverride = '';
        public static function fixture(): string {
            self::$root = sys_get_temp_dir().'/menu-sides-test-'.bin2hex(random_bytes(6));
            mkdir(self::$root.'/storage/app/public', 0700, true); mkdir(self::$root.'/backup', 0700); mkdir(self::$root.'/public', 0700);
            symlink(self::$root.'/storage/app/public', self::$root.'/public/storage');
            self::$config=['app.url'=>'https://menu.test', 'filesystems.disks.public.driver'=>'local', 'filesystems.disks.public.root'=>self::$root.'/storage/app/public', 'filesystems.disks.public.url'=>'https://menu.test/storage', 'filesystems.disks.products.driver'=>'local', 'filesystems.disks.products.root'=>self::$root.'/storage/app/public/products', 'filesystems.disks.products.url'=>'https://menu.test/storage'];
            self::$tables = [
                'users'=>[['id'=>1],['id'=>10]],
                'resturants'=>[['id'=>82,'name'=>'المنصورة','added_by'=>10],['id'=>94,'name'=>'المحلة','added_by'=>10],['id'=>309,'name'=>'المقطم ستور','added_by'=>10]],
                'categories'=>[['id'=>4,'added_by'=>1,'name_ar'=>'الأطباق الجانبية','status'=>'show','parent_id'=>null],['id'=>9,'added_by'=>1,'name_ar'=>'الأسماك','status'=>'show','parent_id'=>null]],
                'products'=>[['id'=>55,'added_by'=>1,'category_id'=>9,'name_ar'=>'رنجة','status'=>'show']],
                'resturant_products'=>[['id'=>88,'added_by'=>1,'resturant_id'=>82,'product_id'=>55,'category_id'=>9,'product_name'=>'رنجة','product_price'=>200,'price'=>'{}','status'=>'show']],
                'media'=>[], 'product_features'=>[],
                'go_store_products'=>[['id'=>1,'name'=>'sentinel','price_cents'=>999]],
            ];
            self::$columns = [
                'users'=>['id'], 'resturants'=>['id','name','added_by'],
                'categories'=>['id','added_by','name_ar','status','parent_id'],
                'products'=>['id','added_by','category_id','subcategory_id','name_ar','name_en','status','has_clean','created_at','updated_at'],
                'resturant_products'=>['id','added_by','resturant_id','product_id','category_id','product_name','product_description','product_price','price','status','highest_rated','created_at','updated_at'],
                'media'=>['id','model_type','model_id','collection_name','disk','file_name','custom_properties'],
                'product_features'=>['id','product_id','name'], 'go_store_products'=>['id','name','price_cents'],
            ];
            self::$snapshot = null; self::$mediaUrlOverride=''; self::$commitFailure = ''; self::$mediaCalls = 0; self::$failMediaAt = 0; self::$writes = 0;
            return self::$root;
        }
    }
    final class Collection {
        public function __construct(private array $rows) {}
        public function all(): array { return $this->rows; }
        public function first(): ?object { return $this->rows[0] ?? null; }
        public function count(): int { return count($this->rows); }
    }
    final class Query {
        private array $filters=[];
        public function __construct(private string $table) { if(!isset(State::$tables[$table])) throw new \RuntimeException('Unknown table '.$table); }
        public function orderBy(string $column): self { return $this; }
        public function lockForUpdate(): self { return $this; }
        public function where(string $column, $value): self { $this->filters[]=fn($r)=>($r[$column]??null)==$value;return $this; }
        public function whereIn(string $column,array $values): self { $this->filters[]=fn($r)=>in_array($r[$column]??null,$values);return $this; }
        private function rows(): array { $rows=State::$tables[$this->table];foreach($this->filters as $f)$rows=array_values(array_filter($rows,$f));usort($rows,fn($a,$b)=>$a['id']<=>$b['id']);return $rows; }
        public function get(): Collection { return new Collection(array_map(fn($row)=>(object)$row,$this->rows())); }
        public function first(): ?object { return $this->get()->first(); }
        public function exists(): bool { return count($this->rows())>0; }
        public function count(): int { return count($this->rows()); }
        public function insertGetId(array $row): int { $max=0;foreach(State::$tables[$this->table] as $current)$max=max($max,$current['id']);$row['id']=$max+1;State::$tables[$this->table][]=$row;State::$writes++;return $row['id']; }
    }
    final class Media {
        public int $id; public string $disk;
        public function __construct(private array $row) { $this->id=$row['id'];$this->disk=$row['disk']; }
        public function getCustomProperty(string $key) { return $this->row['custom_properties'][$key]??null; }
        public function getPath(): string { return State::$root.'/storage/app/public/'.$this->id.'/'.$this->row['file_name']; }
    }
    final class MediaAdder {
        private string $name=''; private string $file=''; private array $props=[];
        public function __construct(private int $modelId,private string $bytes) {}
        public function usingName(string $name): self {$this->name=$name;return $this;}
        public function usingFileName(string $file): self {$this->file=$file;return $this;}
        public function withCustomProperties(array $props): self {$this->props=$props;return $this;}
        public function toMediaCollection(string $collection,string $disk): Media {
            State::$mediaCalls++;
            if(State::$mediaCalls===State::$failMediaAt)throw new \RuntimeException('Injected media failure');
            $row=['model_type'=>'App\\Models\\ResturantProduct','model_id'=>$this->modelId,'collection_name'=>$collection,'disk'=>$disk,'file_name'=>$this->file,'custom_properties'=>$this->props];
            $id=(new Query('media'))->insertGetId($row);$row['id']=$id;$media=new Media($row);
            mkdir(dirname($media->getPath()),0700,true);file_put_contents($media->getPath(),$this->bytes);return $media;
        }
    }
}
namespace Illuminate\Support\Facades {
    final class DB {
        public static function table(string $name): \MenuSidesTest\Query {return new \MenuSidesTest\Query($name);}
        public static function beginTransaction(): void {if(\MenuSidesTest\State::$snapshot!==null)throw new \RuntimeException('nested');\MenuSidesTest\State::$snapshot=\MenuSidesTest\State::$tables;}
        public static function commit(): void {if(\MenuSidesTest\State::$commitFailure==='before')throw new \RuntimeException('Injected commit uncertainty');\MenuSidesTest\State::$snapshot=null;if(\MenuSidesTest\State::$commitFailure==='after')throw new \RuntimeException('Injected commit uncertainty');}
        public static function rollBack(): void {\MenuSidesTest\State::$tables=\MenuSidesTest\State::$snapshot;\MenuSidesTest\State::$snapshot=null;}
        public static function transactionLevel(): int {return \MenuSidesTest\State::$snapshot===null?0:1;}
    }
    final class Schema {
        public static function hasTable(string $table): bool {return isset(\MenuSidesTest\State::$tables[$table]);}
        public static function getColumnListing(string $table): array {return \MenuSidesTest\State::$columns[$table];}
    }
}
namespace App\Models {
    final class ResturantProduct {
        public function __construct(private int $id=0){}
        public static function withoutGlobalScopes(): self {return new self;}
        public function findOrFail(int $id): self {if(!\Illuminate\Support\Facades\DB::table('resturant_products')->where('id',$id)->exists())throw new \RuntimeException('Missing product');return new self($id);}
        public function addMediaFromString(string $bytes): \MenuSidesTest\MediaAdder {return new \MenuSidesTest\MediaAdder($this->id,$bytes);}
        public function getMedia(string $collection): \MenuSidesTest\Collection {$rows=[];foreach(\MenuSidesTest\State::$tables['media'] as $row)if($row['model_id']===$this->id&&$row['collection_name']===$collection)$rows[]=new \MenuSidesTest\Media($row);return new \MenuSidesTest\Collection($rows);}
        public function getFirstMediaUrl(string $collection): string {if(\MenuSidesTest\State::$mediaUrlOverride!=='')return \MenuSidesTest\State::$mediaUrlOverride;$media=$this->getMedia($collection)->first();return $media?'https://menu.test/storage/'.$media->id.'/'.basename($media->getPath()):'';}
    }
}
namespace {
    use MenuSidesTest\State;
    function config(string $key) {return State::$config[$key]??null;}
    require dirname(__DIR__,2).'/deployment/menu_sides_20261021.php';
    $checks=0;$roots=[];$assets=dirname(__DIR__,2).'/deployment/menu-sides-20261021/assets';
    function check(bool $condition,string $message): void {global $checks;$checks++;if(!$condition)throw new RuntimeException('FAIL '.$message);echo 'PASS '.$message.PHP_EOL;}
    function fixture(): FasakhanstaMenuSides20261021 {global $roots,$assets;$root=State::fixture();$roots[]=$root;return new FasakhanstaMenuSides20261021($root,$assets);}
    function reject(callable $fn,string $contains): void {try{$fn();}catch(RuntimeException $e){check(str_contains($e->getMessage(),$contains),'rejects '.$contains);return;}throw new RuntimeException('Expected rejection '.$contains);}
    function treeFiles(string $root): array {$result=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile())$result[]=substr($f->getPathname(),strlen($root));sort($result);return $result;}
    function cleanup(string $root): void {if(!is_dir($root))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)($f->isDir()&&!$f->isLink())?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}
    try {
        $runner=fixture();$before=State::$tables;$files=treeFiles(State::$root);$result=$runner->run(false,null);
        check($result['status']==='CHECK_READY'&&$result['branch_count']===3&&$result['menu_row_count']===6,'preview includes physical branches and Fasakhansta storefronts');
        check(State::$tables===$before&&State::$writes===0&&treeFiles(State::$root)===$files,'check changes neither database nor private filesystem');
        $result=$runner->run(true,State::$root.'/backup');
        check($result['status']==='MENU_SIDES_READY'&&count(State::$tables['products'])===3&&count(State::$tables['resturant_products'])===7&&count(State::$tables['media'])===6,'apply adds exactly two products and one of each per branch with public-disk images');
        check(count(array_filter(State::$tables['media'],fn($m)=>$m['disk']==='public'))===6,'new images use mapped public disk while legacy products disk configuration remains untouched');
        $pairs=[];foreach(State::$tables['resturant_products'] as $row){if($row['id']===88)continue;$pairs[$row['resturant_id']][$row['product_name']]=$row['product_price'];check($row['category_id']===4&&$row['status']==='show'&&array_values(array_unique(json_decode($row['price'],true)))===[null],'new plate uses side category, visibility and no optional surcharges');}
        foreach([82,94,309] as $branch)check(($pairs[$branch]['طبق طماطم']??null)===15&&($pairs[$branch]['طبق طحينة']??null)===30,'both approved prices cover branch '.$branch);
        check(State::$tables['go_store_products']===$before['go_store_products']&&State::$tables['resturant_products'][0]===$before['resturant_products'][0]&&State::$tables['products'][0]===$before['products'][0],'unrelated fish and GO products remain byte-for-byte unchanged');
        foreach(State::$tables['resturant_products'] as &$row)if($row['id']!==88)$row['price']=json_encode(array_reverse(json_decode($row['price'],true),true));unset($row);
        $applied=State::$tables;$result=$runner->run(true,State::$root.'/backup');check($result['status']==='ALREADY_APPLIED'&&State::$tables===$applied,'repeat apply accepts MySQL-reordered JSON keys and creates no duplicates');
        $result=$runner->run(false,null);check($result['status']==='ALREADY_APPLIED'&&State::$tables===$applied,'check validates installed release without database changes');
        $receipt=State::$root.'/storage/app/'.FasakhanstaMenuSides20261021::RELEASE.'/receipt.json';rename($receipt,dirname($receipt).'/receipt.pending.json');$result=$runner->run(true,State::$root.'/backup');check($result['status']==='ALREADY_APPLIED'&&is_file($receipt)&&State::$tables===$applied,'interrupted post-commit receipt finalization recovers without inserts');
        $first=State::$tables['media'][0];$path=State::$root.'/storage/app/public/'.$first['id'].'/'.$first['file_name'];file_put_contents($path,'corrupt');reject(fn()=>$runner->run(false,null),'image verification failed');
        $runner=fixture();State::$tables['products'][]=['id'=>90,'name_ar'=>'طبق طحينه','category_id'=>4,'status'=>'show'];$before=State::$tables;reject(fn()=>$runner->run(true,State::$root.'/backup'),'Pre-existing matching product');check(State::$tables===$before,'Arabic spelling collision leaves database unchanged');
        $runner=fixture();State::$tables['resturant_products'][]=['id'=>90,'product_name'=>'طماطم'];$before=State::$tables;reject(fn()=>$runner->run(false,null),'Pre-existing matching branch item');check(State::$tables===$before&&State::$writes===0,'short name collision is rejected during read-only preview');
        $runner=fixture();State::$tables['categories'][0]['name_ar']='سلطات';reject(fn()=>$runner->run(true,State::$root.'/backup'),'exactly one existing side-dishes category');check(State::$writes===0,'missing category prevents inserts');
        $runner=fixture();State::$tables['categories'][]=['id'=>100,'added_by'=>1,'name_ar'=>'اطباق جانبيه','status'=>'show','parent_id'=>null];reject(fn()=>$runner->run(false,null),'found 2');check(State::$writes===0,'ambiguous side category prevents inserts');
        $runner=fixture();State::$tables['categories'][0]['status']='hide';reject(fn()=>$runner->run(true,State::$root.'/backup'),'category is hidden');check(State::$writes===0,'hidden category prevents inserts');
        $runner=fixture();State::$failMediaAt=4;$before=State::$tables;reject(fn()=>$runner->run(true,State::$root.'/backup'),'Injected media failure');check(State::$tables===$before&&\Illuminate\Support\Facades\DB::transactionLevel()===0,'media failure halfway through installation rolls all menu records back');check(count(array_filter(treeFiles(State::$root),fn($p)=>str_contains($p,'.jpg')))===0,'rollback removes only images created by the failed attempt');
        $runner=fixture();State::$commitFailure='after';reject(fn()=>$runner->run(true,State::$root.'/backup'),'Injected commit uncertainty');$pending=State::$root.'/storage/app/'.FasakhanstaMenuSides20261021::RELEASE.'/receipt.pending.json';check(is_file($pending)&&count(State::$tables['media'])===6,'uncertain successful commit preserves receipt and all verified image files');State::$commitFailure='';$result=$runner->run(true,State::$root.'/backup');check($result['status']==='ALREADY_APPLIED'&&count(State::$tables['resturant_products'])===7,'verified commit uncertainty recovers without duplicate menu rows');
        $runner=fixture();$before=State::$tables;State::$commitFailure='before';reject(fn()=>$runner->run(true,State::$root.'/backup'),'Injected commit uncertainty');check(State::$tables===$before,'uncommitted transaction rolls back after commit exception');State::$commitFailure='';reject(fn()=>$runner->run(false,null),'interrupted installation receipt exists');
        $runner=fixture();$runner->run(true,State::$root.'/backup');State::$tables['resturants'][]=['id'=>999,'name'=>'New branch'];reject(fn()=>$runner->run(true,State::$root.'/backup'),'Branch coverage changed');
        $runner=fixture();$runner->run(true,State::$root.'/backup');State::$tables['resturant_products'][1]['product_price']=16;reject(fn()=>$runner->run(false,null),'Installed menu values changed');
        $runner=fixture();$runner->run(true,State::$root.'/backup');$extras=json_decode(State::$tables['resturant_products'][1]['price'],true);$extras['extra_clean']=0;State::$tables['resturant_products'][1]['price']=json_encode($extras);reject(fn()=>$runner->run(false,null),'Installed menu values changed');
        $runner=fixture();unlink(State::$root.'/public/storage');$before=State::$tables;reject(fn()=>$runner->run(true,State::$root.'/backup'),'public');check(State::$writes===0&&State::$tables===$before,'missing public URL mapping fails before all menu inserts');
        $runner=fixture();State::$config['filesystems.disks.public.url']='https://elsewhere.test/storage';reject(fn()=>$runner->run(true,State::$root.'/backup'),'same origin');check(State::$writes===0,'cross-origin media configuration fails before inserts');
        $runner=fixture();State::$mediaUrlOverride='https://menu.test/storage/not-the-image.jpg';$before=State::$tables;reject(fn()=>$runner->run(true,State::$root.'/backup'),'URL does not resolve');check(State::$tables===$before,'wrong generated media URL rolls back all menu rows');
        $runner=fixture();State::$columns['resturant_products']=array_diff(State::$columns['resturant_products'],['product_price']);reject(fn()=>$runner->run(true,State::$root.'/backup'),'runtime menu columns are missing');check(State::$writes===0,'obsolete live schema cannot be modified');
        check(FasakhanstaMenuSides20261021::normalize('  طَحينة  ')==='طحينه','matching normalizes diacritics, whitespace and ة without changing display names');
        echo 'MENU SIDES BEHAVIOR CHECKS PASSED: '.$checks.PHP_EOL;
    } finally {foreach($roots as $root)cleanup($root);}
}
