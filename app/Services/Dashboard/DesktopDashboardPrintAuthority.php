<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\{DB,Schema};

/** Current locking authorization for irreversible prints, independent of RR snapshots/Spatie caches. */
final class DesktopDashboardPrintAuthority
{
    private ?\WeakMap $validated=null;
    public function actor(int $id): User
    {
        abort_unless(DB::transactionLevel()>0,503);
        $actor=User::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->first();
        abort_unless($actor&&in_array($actor->account_type,['admin','vendor','resturant_owner','delegate'],true)
            &&!in_array($actor->status??null,['disabled','declined'],true),403);
        $tables=config('permission.table_names');$roleIds=[];$roles=collect();$direct=[];$grants=[];$permissions=collect();
        // Lock pivots explicitly: a cached relation or an ordinary SELECT can retain a
        // pre-wait REPEATABLE READ view even after the fixed print scope is acquired.
        if(Schema::hasTable($tables['model_has_roles']))$roleIds=DB::table($tables['model_has_roles'])->where('model_type',$actor->getMorphClass())
            ->where('model_id',$id)->orderBy('role_id')->lockForUpdate()->pluck('role_id')->all();
        if($roleIds&&Schema::hasTable($tables['roles']))$roles=DB::table($tables['roles'])->whereIn('id',$roleIds)->orderBy('id')->lockForUpdate()->get();
        if(Schema::hasTable($tables['model_has_permissions']))$direct=DB::table($tables['model_has_permissions'])->where('model_type',$actor->getMorphClass())
            ->where('model_id',$id)->orderBy('permission_id')->lockForUpdate()->pluck('permission_id')->all();
        if($roleIds&&Schema::hasTable($tables['role_has_permissions']))$grants=DB::table($tables['role_has_permissions'])->whereIn('role_id',$roles->where('guard_name','admin')->pluck('id'))
            ->orderBy('role_id')->orderBy('permission_id')->lockForUpdate()->pluck('permission_id')->all();
        if(Schema::hasTable($tables['permissions']))$permissions=DB::table($tables['permissions'])->where('guard_name','admin')->whereIn('name',['order-list','order-create','order-edit'])
            ->orderBy('id')->lockForUpdate()->get();
        $super=$id===1||$roles->where('guard_name','admin')->where('name','Super Admin')->isNotEmpty();
        $list=$permissions->where('name','order-list')->contains(fn($p)=>in_array($p->id,array_merge($direct,$grants)));
        if($actor->account_type==='admin'&&empty($actor->owner_resturant_id))abort_unless($super||$list,403);
        $this->validated??=new \WeakMap();
        $this->validated[$actor]=['admin_write'=>$super||$permissions->contains(fn($p)=>in_array($p->id,array_merge($direct,$grants)))];
        return $actor;
    }

    public function branch(string $value,User $actor): void
    {
        $this->facts($actor);
        abort_unless(preg_match('/^(f|gs):([1-9][0-9]{0,18})$/D',$value,$m),404);$id=(int)$m[2];
        if($m[1]==='f'){
            abort_unless($actor->account_type!=='delegate'&&Schema::hasColumn('resturants','user_id'),404);
            $row=DB::table('resturants')->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);
            if($actor->account_type==='admin')abort_unless(empty($actor->owner_resturant_id)||(int)$actor->owner_resturant_id===$id,404);
            elseif($actor->account_type==='resturant_owner')abort_unless((int)$actor->owner_resturant_id>0&&($id===(int)$actor->owner_resturant_id||(int)($row->parent_id??0)===(int)$actor->owner_resturant_id),404);
            else abort_unless((int)$row->user_id===(int)$actor->id,404);
            return;
        }
        abort_unless(empty($actor->owner_resturant_id)&&$actor->account_type!=='resturant_owner'&&$this->goReady(),404);
        if($actor->account_type!=='admin')abort_unless((int)$actor->id===$id,404);
        $owner=DB::table('users')->where('id',$id)->lockForUpdate()->first();$row=DB::table('go_stores')->where('user_id',$id)->lockForUpdate()->first();
        abort_unless($owner&&$row&&$owner->app_scope==='go_partner'&&$owner->status==='accepted'&&$this->storeOwner($owner),404);
    }
    /** Exact original checkout conditions, using only this instance's locked actor facts. */
    public function canCheckout(User $actor): bool
    {
        $facts=$this->facts($actor);
        return in_array($actor->account_type,['vendor','resturant_owner','delegate'],true)
            ||($actor->account_type==='admin'&&(!empty($actor->owner_resturant_id)||$facts['admin_write']));
    }
    public function canReprint(User $actor): bool {$this->facts($actor);return $actor->account_type==='resturant_owner'||$this->canClose($actor);}
    public function canClose(User $actor): bool {$this->facts($actor);return $actor->account_type==='admin'&&empty($actor->owner_resturant_id);}
    private function facts(User $actor): array {abort_unless($this->validated&&isset($this->validated[$actor]),503);return $this->validated[$actor];}
    public function receiverBranch(User $actor): ?string
    {
        if($this->canClose($actor))return null;$branches=[];
        if($actor->account_type!=='delegate'&&Schema::hasColumn('resturants','user_id')){
            $query=DB::table('resturants');
            if($actor->account_type==='admin')$query->where('id',$actor->owner_resturant_id);
            elseif($actor->account_type==='resturant_owner'){
                $parent=(int)$actor->owner_resturant_id;$query->where(function($q)use($parent){$q->where('id',$parent);if(Schema::hasColumn('resturants','parent_id'))$q->orWhere('parent_id',$parent);});
                if($parent<1)$query->whereRaw('1=0');
            }else $query->where('user_id',$actor->id);
            foreach($query->orderBy('id')->lockForUpdate()->get(['id']) as $row)$branches[]='f:'.$row->id;
        }
        if(empty($actor->owner_resturant_id)&&$actor->account_type!=='resturant_owner'&&$this->goReady()){
            $owner=DB::table('users')->where('id',$actor->id)->lockForUpdate()->first();
            if($owner&&$owner->app_scope==='go_partner'&&$owner->status==='accepted'&&$this->storeOwner($owner)
                &&DB::table('go_stores')->where('user_id',$actor->id)->lockForUpdate()->first())$branches[]='gs:'.$actor->id;
        }
        return count($branches)===1?$branches[0]:null;
    }
    private function goReady(): bool
    {
        return Schema::hasColumn('go_stores','user_id')&&Schema::hasColumn('users','account_type')
            &&Schema::hasColumn('users','app_scope')&&Schema::hasColumn('users','status');
    }
    private function storeOwner(object $owner): bool
    {
        if($owner->account_type==='vendor')return true;
        if($owner->account_type!=='delegate'||empty($owner->pending_vendor_id)||!Schema::hasColumn('pending_vendors','profession_key'))return false;
        $pending=DB::table('pending_vendors')->where('id',$owner->pending_vendor_id)->lockForUpdate()->first();return $pending&&$pending->profession_key==='store_owner';
    }
}
