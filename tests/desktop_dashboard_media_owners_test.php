<?php
// Compare the unchanged per-ticket authorization with batched manifest membership on exact scoped queries.
namespace Illuminate\Support\Facades {
    class Schema {public static array $missing=[];static function hasColumn($table,$column){return !in_array($table.'.'.$column,self::$missing,true);}}
}
namespace {
    function abort_if($value,$status,$message=''){if($value)throw new RuntimeException('Bounded query rejected');}
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardMedia.php';
    class ScopedMediaQuery {
        public static int $cursors=0,$existence=0;private array $where=[],$projection=[];
        function __construct(private array $rows){}
        function where($column,$value){$this->where[$column]=$value;return $this;}
        function select($columns){$this->projection=$columns;return $this;}
        private function rows(){return array_filter($this->rows,function($row){foreach($this->where as $column=>$value)if(!array_key_exists($column,$row)||$row[$column]===null||$row[$column]!=$value)return false;return true;});}
        function exists(){self::$existence++;return count($this->rows())>0;}
        function cursor(){self::$cursors++;foreach($this->rows() as $row)yield(object)($this->projection?array_intersect_key($row,array_flip($this->projection)):$row);}
    }
    $class=new ReflectionClass(\App\Services\Dashboard\DesktopDashboardMedia::class);$media=$class->newInstanceWithoutConstructor();
    $owner=$class->getMethod('owner');$batch=$class->getMethod('ownerMembership');$checks=0;
    function verify($value,$label){global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
    function row($model,$id){return ['model_type'=>'App\\Models\\'.$model,'model_id'=>$id];}
    function queries($all=false){return [
        'users'=>new ScopedMediaQuery($all?[['id'=>1,'pending_vendor_id'=>11],['id'=>8,'pending_vendor_id'=>12],['id'=>99,'pending_vendor_id'=>99]]:[['id'=>1,'pending_vendor_id'=>11]]),
        'orders'=>new ScopedMediaQuery([['id'=>100,'user_id'=>2]]),'go_store_orders'=>new ScopedMediaQuery([['id'=>101,'customer_id'=>3]]),
        'products'=>new ScopedMediaQuery([['id'=>102,'added_by'=>4]]),'categories'=>new ScopedMediaQuery([['id'=>103,'added_by'=>5]]),
        'resturants'=>new ScopedMediaQuery([['id'=>104,'added_by'=>6]]),'pending_vendors'=>new ScopedMediaQuery([['id'=>10]]),
        'resturant_products'=>new ScopedMediaQuery([['id'=>105]]),'areas'=>new ScopedMediaQuery([['id'=>106]])];}
    $rows=[];foreach(['User','Admin'] as $model)foreach([1,2,3,4,5,6,8,99,100,999] as $id)$rows[]=row($model,$id);
    foreach([10,11,12,99,999] as $id)$rows[]=row('PendingVendor',$id);
    foreach(['Resturant'=>[104,999],'Product'=>[102,999],'Category'=>[103,999],'ResturantProduct'=>[105,999],'Area'=>[106,999],'Unknown'=>[1]] as $model=>$ids)foreach($ids as $id)$rows[]=row($model,$id);
    $rows[]=['model_type'=>'Other\\Models\\User','model_id'=>1];
    foreach([false,true] as $all){
        $queries=queries($all);ScopedMediaQuery::$cursors=0;$membership=$batch->invoke($media,$rows,$queries);
        verify(ScopedMediaQuery::$cursors===count($queries),'Each exact scoped source is projected only once');
        foreach($rows as $row)verify($owner->invoke($media,$row,$queries)===$owner->invoke($media,$row,$queries,$membership),'Batched owners preserve the original administrator/scoped decision');
        if(!$all){verify(!$owner->invoke($media,row('User',99),$queries,$membership),'An extra FK-closure user is not a media grant');verify(!$owner->invoke($media,row('PendingVendor',99),$queries,$membership),'An extra FK-closure partner is not a media grant');}
    }
    $queries=queries();unset($queries['users']);$membership=$batch->invoke($media,$rows,$queries);
    verify(!$owner->invoke($media,row('User',2),$queries,$membership)&&!$owner->invoke($media,row('User',2),$queries),'Missing users scope does not enable parent-only profiles');
    $queries=queries();unset($queries['pending_vendors']);$membership=$batch->invoke($media,$rows,$queries);
    verify(!$owner->invoke($media,row('PendingVendor',11),$queries,$membership)&&!$owner->invoke($media,row('PendingVendor',11),$queries),'Missing partner scope does not enable user pending references');
    \Illuminate\Support\Facades\Schema::$missing=['orders.user_id','users.pending_vendor_id'];$queries=queries();$membership=$batch->invoke($media,$rows,$queries);
    foreach($rows as $row)verify($owner->invoke($media,$row,$queries)===$owner->invoke($media,$row,$queries,$membership),'Missing optional columns preserve exact original authorization');
    verify(!$owner->invoke($media,row('User',2),$queries,$membership)&&!$owner->invoke($media,row('PendingVendor',11),$queries,$membership),'Unavailable reference columns never broaden membership');
    \Illuminate\Support\Facades\Schema::$missing=[];$queries=queries();$many=array_fill(0,3000,row('User',2));ScopedMediaQuery::$cursors=0;ScopedMediaQuery::$existence=0;
    $membership=$batch->invoke($media,$many,$queries);foreach($many as $row)verify($owner->invoke($media,$row,$queries,$membership),'Repeated media keeps the same parent grant');
    verify(ScopedMediaQuery::$cursors===6&&ScopedMediaQuery::$existence===0,'Three thousand profile images use six scoped projections and no per-image owner query');
    echo 'PASS '.$checks.' exact scoped media owner checks'.PHP_EOL;
}
