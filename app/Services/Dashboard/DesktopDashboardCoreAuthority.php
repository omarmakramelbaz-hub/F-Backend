<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Database\Events\{TransactionCommitted,TransactionRolledBack};
use Illuminate\Support\Facades\{DB,Schema};

/** Current permission and immutable BASE scope checks for the original operational routes. */
final class DesktopDashboardCoreAuthority
{
    private static ?\WeakMap $verified=null;
    private static ?array $controllerFacts=null;
    private static ?\WeakMap $epochs=null;
    private static ?\WeakMap $dispatchers=null;
    public static function handles(?string $route): bool
    {
        return isset(DesktopDashboardRemoteAttempts::CORE[$route??''])&&!DesktopDashboardCustomerDirectory::handles($route);
    }
    public function actor(int $id): User
    {
        return $this->identity($id)[0];
    }
    /** The only authorization inputs retained in encrypted native recovery receipts. */
    public function context(string $route,array $values): array
    {
        abort_unless(self::handles($route),503);
        $branch=$values['branch']??'';abort_unless(is_string($branch),422);
        $discount=$values['discount']??'';
        return ['format'=>1,'route'=>$route,'branch'=>$branch,
            'bonus'=>$route==='employees.entry'&&($values['kind']??null)==='bonus',
            'approval'=>$route==='branch-expenses.save'&&in_array($values['approve']??null,[true,1,'1'],true),
            'discount'=>in_array($route,['takeaway.checkout','dining.save','phone-orders.save'],true)
                &&$discount!==null&&$discount!==''&&!(is_scalar($discount)&&preg_match('/^0+(?:\.0{1,2})?$/D',(string)$discount))];
    }
    public function authorize(string $route,array $values,object $device): User
    {
        return $this->authorizeContext($this->context($route,$values),$device);
    }
    public function authorizeContext(array $context,object $device): User
    {
        abort_unless(array_keys($context)===['format','route','branch','bonus','approval','discount']
            &&$context['format']===1&&is_string($context['route'])&&self::handles($context['route'])
            &&is_string($context['branch'])&&is_bool($context['bonus'])&&is_bool($context['approval'])&&is_bool($context['discount']),503,
            'صلاحيات نتيجة العملية المحفوظة غير مكتملة؛ راجعها على السيرفر قبل استكمال المزامنة.');
        $route=$context['route'];[$actor,$permissions]=$this->identity((int)$device->actor_id);
        $central=$actor->account_type==='admin'&&empty($actor->owner_resturant_id);
        $primary=(int)$actor->id===1&&$central;
        $policy=DesktopDashboardRemoteAttempts::CORE[$route];
        if(str_starts_with($route,'employees.'))abort_unless(in_array($actor->account_type,['admin','vendor','resturant_owner'],true),403);
        $allowed=match($policy){
            'can_read_own_notifications'=>true,
            'can_manage_inventory'=>$permissions['can_checkout']&&in_array($actor->account_type,['admin','resturant_owner'],true),
            'can_approve_expense'=>$permissions['can_checkout']&&($central||$actor->account_type==='resturant_owner'),
            'can_manage_expense_categories'=>$primary,
            'can_payroll_owner'=>$permissions['can_checkout']&&$primary,
            default=>$permissions[$policy]??false,
        };
        if(in_array($route,['employees.save','branch-stock.minimum-save','branch-shifts.close'],true))$allowed=$allowed&&$central;
        if($context['bonus'])$allowed=$allowed&&$primary;
        if($context['approval'])$allowed=$allowed&&$permissions['can_checkout']&&($central||$actor->account_type==='resturant_owner');
        if($context['discount'])$allowed=$allowed&&$permissions['can_manage'];
        abort_unless($allowed,403);
        $branch=null;
        // Own notifications have no branch policy. Legacy category receipts can
        // remain branchless; the original service still permits only read-only retries.
        if($policy!=='can_read_own_notifications'&&($policy!=='can_manage_expense_categories'||$context['branch']!=='')){
            try{$base=json_decode($device->branches,true,512,JSON_THROW_ON_ERROR);}catch(\Throwable $error){abort(503);}
            abort_unless(is_array($base)&&in_array($context['branch'],$base,true),403,'الفرع خارج نطاق ربط الجهاز.');
            $branch=$this->branch($context['branch'],$actor);
            if(in_array($route,['branch-stock.minimum-save','branch-stock.basic-apply','branch-stock.recipe-save','branch-shifts.close'],true))
                abort_unless(str_starts_with($context['branch'],'f:'),$route==='branch-shifts.close'?404:403);
        }
        self::$verified??=new \WeakMap();self::$verified[$actor]=['permissions'=>$permissions,'branches'=>$branch===null?null:[$branch]]+$this->stamp();
        return $actor;
    }
    /** Run original middleware with exactly the current facts locked by authorizeContext(). */
    public function withControllerFacts(User $actor,callable $work)
    {
        abort_unless(DB::transactionLevel()>0&&self::$verified&&isset(self::$verified[$actor]),503);
        abort_unless($this->currentStamp(self::$verified[$actor]),503,'صلاحيات العملية تحتاج قراءة جديدة داخل المعاملة الحالية.');
        $previous=self::$controllerFacts;self::$controllerFacts=['actor'=>$actor]+self::$verified[$actor];unset(self::$verified[$actor]);
        try{return $work();}finally{self::$controllerFacts=$previous;}
    }
    public function currentControllerFacts($actor): ?array
    {
        if(!self::$controllerFacts||DB::transactionLevel()<1||!$actor||(int)($actor->id??0)!==(int)self::$controllerFacts['actor']->id)return null;
        abort_unless($this->currentStamp(self::$controllerFacts),503);
        return self::$controllerFacts;
    }
    private function stamp(): array
    {
        self::$epochs??=new \WeakMap();self::$dispatchers??=new \WeakMap();$connection=DB::connection();$events=$connection->getEventDispatcher();abort_unless($events,503);
        if(!isset(self::$dispatchers[$events])){
            foreach([TransactionCommitted::class,TransactionRolledBack::class] as $event)$events->listen($event,static function($event){
                self::$epochs[$event->connection]=(self::$epochs[$event->connection]??0)+1;
            });
            self::$dispatchers[$events]=true;
        }
        return ['connection'=>$connection,'dispatcher'=>$events,'epoch'=>self::$epochs[$connection]??0];
    }
    private function currentStamp(array $facts): bool
    {
        return $facts['connection']===DB::connection()&&$facts['dispatcher']===$facts['connection']->getEventDispatcher()&&$facts['epoch']===(self::$epochs[$facts['connection']]??0);
    }
    private function identity(int $id): array
    {
        abort_unless(DB::transactionLevel()>0,503);
        $actor=User::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->first();
        abort_unless($actor&&in_array($actor->account_type,['admin','vendor','resturant_owner','delegate'],true)
            &&!in_array($actor->status??null,['disabled','declined'],true),403);
        $tables=config('permission.table_names');$roles=collect();$roleIds=[];$direct=[];$grants=[];$catalog=collect();
        if(Schema::hasTable($tables['model_has_roles']))$roleIds=DB::table($tables['model_has_roles'])->where('model_type',$actor->getMorphClass())->where('model_id',$id)
            ->orderBy('role_id')->lockForUpdate()->pluck('role_id')->all();
        if($roleIds&&Schema::hasTable($tables['roles']))$roles=DB::table($tables['roles'])->whereIn('id',$roleIds)->orderBy('id')->lockForUpdate()->get();
        if(Schema::hasTable($tables['model_has_permissions']))$direct=DB::table($tables['model_has_permissions'])->where('model_type',$actor->getMorphClass())->where('model_id',$id)
            ->orderBy('permission_id')->lockForUpdate()->pluck('permission_id')->all();
        if($roleIds&&Schema::hasTable($tables['role_has_permissions']))$grants=DB::table($tables['role_has_permissions'])->whereIn('role_id',$roles->where('guard_name','admin')->pluck('id'))
            ->orderBy('role_id')->orderBy('permission_id')->lockForUpdate()->pluck('permission_id')->all();
        if(Schema::hasTable($tables['permissions']))$catalog=DB::table($tables['permissions'])->where('guard_name','admin')->whereIn('name',['order-list','order-create','order-edit'])
            ->orderBy('id')->lockForUpdate()->get();
        $super=$id===1||$roles->where('guard_name','admin')->where('name','Super Admin')->isNotEmpty();$ids=array_merge($direct,$grants);
        $has=fn(string $name)=>$catalog->where('name',$name)->contains(fn($p)=>in_array($p->id,$ids));
        if($actor->account_type==='admin'&&empty($actor->owner_resturant_id))abort_unless($super||$has('order-list'),403);
        $manage=$actor->account_type==='admin'&&($super||$has('order-edit')||$has('order-create'));
        $write=$actor->account_type!=='admin'||!empty($actor->owner_resturant_id)||$manage||$has('order-list');
        return [$actor,['can_checkout'=>$write,'can_manage_tables'=>$write,'can_manage'=>$actor->account_type==='resturant_owner'||$manage]];
    }
    private function branch(string $value,User $actor): array
    {
        abort_unless(preg_match('/^(f|gs):([1-9][0-9]{0,18})$/D',$value,$m),404);$id=(int)$m[2];
        if($m[1]==='f'){
            abort_unless($actor->account_type!=='delegate'&&Schema::hasColumn('resturants','user_id'),404);
            $row=DB::table('resturants')->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);
            if($actor->account_type==='admin')abort_unless(empty($actor->owner_resturant_id)||(int)$actor->owner_resturant_id===$id,404);
            elseif($actor->account_type==='resturant_owner')abort_unless((int)$actor->owner_resturant_id>0&&($id===(int)$actor->owner_resturant_id||(int)($row->parent_id??0)===(int)$actor->owner_resturant_id),404);
            else abort_unless((int)$row->user_id===(int)$actor->id,404);
            return $this->present('f',$row);
        }
        abort_unless(empty($actor->owner_resturant_id)&&$actor->account_type!=='resturant_owner'&&Schema::hasColumn('go_stores','user_id')
            &&Schema::hasColumns('users',['account_type','app_scope','status']),404);
        if($actor->account_type!=='admin')abort_unless((int)$actor->id===$id,404);
        $owner=DB::table('users')->where('id',$id)->lockForUpdate()->first();$store=DB::table('go_stores')->where('user_id',$id)->lockForUpdate()->first();
        abort_unless($owner&&$store&&$owner->app_scope==='go_partner'&&$owner->status==='accepted',404);
        if($owner->account_type==='vendor')return $this->present('gs',$store);
        abort_unless($owner->account_type==='delegate'&&!empty($owner->pending_vendor_id)&&Schema::hasColumn('pending_vendors','profession_key'),404);
        $pending=DB::table('pending_vendors')->where('id',$owner->pending_vendor_id)->lockForUpdate()->first();abort_unless($pending&&$pending->profession_key==='store_owner',404);
        return $this->present('gs',$store);
    }
    private function present(string $kind,object $row): array
    {
        $id=$kind==='f'?(int)$row->id:(int)$row->user_id;
        return ['value'=>$kind.':'.$id,'id'=>$id,'kind'=>$kind,'name'=>(string)($row->name??''),'label'=>(string)($row->name??''),'address'=>(string)($row->address??''),'phone'=>(string)($row->phone??'')];
    }
}
