<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Local union rows are storage coverage, never the signed-in account's read grant. */
final class DesktopDashboardWorkspaceReadAuthority
{
    public function selected(): bool { return app(DesktopDashboardWorkspaceLocalAuthority::class)->selected(); }

    public function scope(): ?DesktopDashboardWorkspaceScope
    {
        if (!$this->selected()) return null;
        $local=app(DesktopDashboardWorkspaceLocalAuthority::class); $local->active();
        $id=auth('admin')->id(); abort_unless($id!==null,403);
        $scope=DesktopDashboardWorkspaceExecutionContext::current();
        if ($scope!==null) {
            abort_unless($scope->isLocal() && $scope->actor_id===(int)$id,403);
            return $scope->revalidated();
        }
        return $local->scopeForActor((int)$id);
    }

    /** The exact resolved action, rather than Accept/SPA/query fields, selects the read policy. */
    public function run(Request $request, callable $work)
    {
        if (!$this->selected() || !$this->isRead($request)) return $work();
        $scope=$this->scope();
        return DesktopDashboardWorkspaceExecutionContext::run($scope,function() use($request,$work) {
            $this->authorizeRequest($request); return $work();
        });
    }

    public function isRead(Request $request): bool
    {
        if ($request->route()?->getActionName()==='App\\Http\\Controllers\\Dashboard\\HomeController@adminLogout') return false;
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) return true;
        return $request->isMethod('POST') && in_array($request->route()?->getActionName(),[
            'App\\Http\\Controllers\\Dashboard\\ProductController@fetchFeature',
            'App\\Http\\Controllers\\Dashboard\\ProductController@fetchProduct',
            'App\\Http\\Controllers\\Dashboard\\ProductController@fetchSubcategory',
        ],true);
    }

    public function central(array $permissions): void
    {
        if (($scope=$this->scope())===null) return;
        $actor=$scope->actor();
        abort_unless($actor->account_type==='admin' && empty($actor->owner_resturant_id),403,
            'هذه البيانات متاحة للإدارة المركزية فقط.');
        $this->permission($permissions,$scope);
    }

    public function permission(array $permissions, ?DesktopDashboardWorkspaceScope $scope=null): void
    {
        $scope??=$this->scope(); if ($scope===null) return;
        $facts=$scope->permissionFacts();
        abort_unless($facts['super_admin'] || count(array_intersect($permissions,$facts['names']))>0,403);
    }

    public function restaurantIds(): ?array { return $this->branchIds('f:'); }
    public function storeIds(): ?array { return $this->branchIds('gs:'); }
    private function branchIds(string $prefix): ?array
    {
        if (($scope=$this->scope())===null) return null;
        $branches=$prefix==='gs:'?app(DesktopDashboardWorkspaceCreatedScopes::class)->readable($scope,$scope->actor()):$scope->branchGrants();
        return array_values(array_map(fn($branch)=>(int)substr($branch,strlen($prefix)),
            array_filter($branches,fn($branch)=>str_starts_with($branch,$prefix))));
    }
    public function restaurant(int $id): void
    {
        if (($ids=$this->restaurantIds())!==null) abort_unless(in_array($id,$ids,true),404);
    }
    public function store(int $id): void
    {
        if (($ids=$this->storeIds())!==null) abort_unless(in_array($id,$ids,true),404);
    }
    public function userIds(): ?array
    {
        if (($scope=$this->scope())===null) return null;
        $actor=$scope->actor();
        return $actor->account_type==='admin' && empty($actor->owner_resturant_id) ? null : [$scope->actor_id];
    }
    public function userType(string $type,string $operation='list'): void
    {
        if (!$this->selected()) return;
        abort_unless(in_array($type,['admin','vendor','resturant_owner','delegate','user'],true),403);
        $this->permission([$type.'-'.$operation]);
    }
    public function user(User $target,string $operation='list'): void
    {
        if (!$this->selected()) return;
        $this->userType((string)$target->account_type,$operation);
        if (($ids=$this->userIds())!==null) abort_unless(in_array((int)$target->id,$ids,true),404);
    }
    public function users($query)
    {
        if (($ids=$this->userIds())!==null) $query->whereIn('users.id',$ids);
        return $query;
    }
    public function restaurants($query,string $column='resturants.id')
    {
        if (($ids=$this->restaurantIds())!==null) $query->whereIn($column,$ids);
        return $query;
    }
    public function stores($query,string $column='users.id')
    {
        if (($ids=$this->storeIds())!==null) $query->whereIn($column,$ids);
        return $query;
    }
    public function advertising(object $row): void { if ($this->selected()) $this->restaurant((int)$row->resturant_id); }

    public function authorizeRequest(Request $request): void
    {
        if (!$this->selected()) return;
        $action=$request->route()?->getActionName()??'';
        $prefix='App\\Http\\Controllers\\Dashboard\\';
        if (!str_starts_with($action,$prefix)) return;
        [$controller,$method]=array_pad(explode('@',substr($action,strlen($prefix)),2),2,'');
        if ($controller==='OrderController' && $method==='download_daily_report') {
            $id=filter_var($request->query('vendor'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);abort_unless($id,404);
            $ids=$this->restaurantIds();
            if($ids!==null)abort_unless(DB::table('resturants')->whereIn('id',$ids)->where('user_id',$id)->exists(),404);
        }
        if ($controller==='HomeController' && $method==='adminWallet') $this->central(['wallet-list']);
        if ($controller==='WalletController') $this->central(['wallet-list']);
        if ($controller==='ReportController') {
            if (in_array($method,['getCustomers','getDelegates','getVendors'],true)) $this->central(['report-list']);
            else $this->permission(['report-list']);
        }
        if ($controller==='UserController') {
            if ($method==='index') $this->userType((string)$request->query('account_type'));
            if (in_array($method,['show','edit'],true)) {
                $target=$request->route('user'); abort_unless($target instanceof User,404);
                $this->user($target,$method==='edit'?'edit':'list');
            }
            if ($method==='create') $this->central([(string)$request->query('account_type').'-create']);
            if ($method==='resturantMap') $this->permission(['resturant-list']);
            if ($method==='delegateMap') $this->central(['delegate-list']);
        }
        if ($controller==='ResturantController') {
            if ($method==='create') $this->central(['resturant-create']);
            else $this->permission([$method==='edit'?'resturant-edit':'resturant-list']);
            $target=$request->route('resturant'); if (is_object($target)) $this->restaurant((int)$target->id);
        }
        if ($controller==='GoStores\\StoreController') {
            $this->permission(['resturant-list']);
            if ($method==='create') $this->central(['resturant-create']);
            if (($owner=$request->route('owner'))!==null) $this->store((int)$owner);
        }
        if ($controller==='PendingVendorController') $this->central(in_array($method,['edit','addVendor'],true)
            ?['pending_vendor-edit']:['pending_vendor-list','pending_vendor-delete']);
        if ($controller==='ContractController') $this->central(in_array($method,['edit','create'],true)
            ?['contract-edit']:($method==='index'||$method==='contracts'?['contract-list','contract-edit']:['contract-list']));
        if ($controller==='ContactController') $this->central(['contact-list','contact-delete']);
        if ($controller==='CouponWheelController') $this->central(['coupon_wheel-'.match($method){'create'=>'create','edit'=>'edit',default=>'list'}]);
        if ($controller==='AdvertisingController') {
            $this->permission([$method==='edit'?'slidear-edit':($method==='create'?'slidear-create':'slidear-list')]);
            if (($row=$request->route('advertising')) && is_object($row)) $this->advertising($row);
        }
        if ($controller==='ProductController' && $method==='fetchFeature' && $request->filled('id')) {
            $id=filter_var($request->input('id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); abort_unless($id,404);
            $restaurant=DB::table('resturant_products')->where('id',$id)->value('resturant_id');
            abort_unless($restaurant!==null,404); $this->restaurant((int)$restaurant);
        }
    }
}
