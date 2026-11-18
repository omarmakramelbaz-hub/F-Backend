<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\{DB,Schema};

/** Explicit initial read scopes. Foreign-key closure adds required parents, never child ledgers. */
class DesktopDashboardData
{
    public const PUBLIC_TABLES=['areas','categories','products','product_features','features','stock_ingredients','branch_expense_categories','branch_expense_category_settings','branch_basic_recipes','permissions','roles','role_has_permissions'];
    private const REFERENCE_TABLES=['users','resturants','pending_vendors','products','product_features','categories','areas','coupon_wheels','user_address'];
    private const GO_ELIGIBILITY_FIELDS=['id','profession_key','type','status','source_app'];
    public function allAdministration(User $actor): bool
    {
        if($actor->account_type!=='admin'||!empty($actor->owner_resturant_id))return false;
        if((int)$actor->id===1)return true;
        return Schema::hasTable('roles')&&Schema::hasTable('model_has_roles')&&$actor->roles()->where('guard_name','admin')->where('name','Super Admin')->exists();
    }
    public function queries(object $device,User $actor,array $branches): array
    {
        $queries=[];$restaurantIds=[];$storeIds=[];$all=$this->allAdministration($actor);
        foreach($branches as $branch){[$kind,$id]=explode(':',$branch);if($kind==='f')$restaurantIds[]=(int)$id;else $storeIds[]=(int)$id;}
        foreach(DesktopDashboardSchema::TABLES as $table)if(Schema::hasTable($table)){
            $query=DB::table($table)->whereRaw('1=0');
            if(!in_array($table,DesktopDashboardSchema::EMPTY,true)&&($all||in_array($table,self::PUBLIC_TABLES,true)))$query=DB::table($table);
            $queries[$table]=$query;
        }
        // The original shared template listing allows central admins with either
        // contract-list or contract-edit, without requiring the Super Admin role.
        if(!$all&&$actor->account_type==='admin'&&empty($actor->owner_resturant_id)&&isset($queries['contracts'])
            &&($actor->checkPermissionTo('contract-list','admin')||$actor->checkPermissionTo('contract-edit','admin')))
            $queries['contracts']=DB::table('contracts');
        foreach(DesktopDashboardBootstrap::BRANCH_TABLES as $table)if(isset($queries[$table])){
            abort_unless(Schema::hasColumn($table,'branch'),409,'مخطط بيانات الفرع يحتاج مراجعة.');$queries[$table]=DB::table($table)->whereIn('branch',$branches);
        }
        // Historical categories are shared; new categories belong only to their origin branch.
        if(isset($queries['branch_expense_categories'])&&Schema::hasColumn('branch_expense_categories','origin_branch'))
            $queries['branch_expense_categories']=DB::table('branch_expense_categories')->where(function($q)use($branches){
                $q->whereNull('origin_branch')->orWhereIn('origin_branch',$branches);
            });
        // CRM identities are shared globally, but a device receives only customers linked
        // to its enrolled branches. Never import the entire directory for an admin.
        if(isset($queries['crm_customers'],$queries['crm_customer_sources'],$queries['crm_purchases'])){
            $queries['crm_customers']=DB::table('crm_customers')->where(function($q)use($queries){
                $q->whereIn('id',(clone $queries['crm_customer_sources'])->select('customer_id'))
                    ->orWhereIn('id',(clone $queries['crm_purchases'])->select('customer_id'));
            });
        }
        foreach(['resturants'=>'id','resturant_products'=>'resturant_id','resturant_areas'=>'resturant_id','orders'=>'resturant_id','advertisings'=>'resturant_id','reviews'=>'resturant_id','wishlists'=>'resturant_id','coupon_wheel_resturants'=>'resturant_id'] as $table=>$column)
            if(isset($queries[$table])&&!$all)$queries[$table]=DB::table($table)->whereIn($column,$restaurantIds);
        foreach(['go_stores'=>'user_id','go_store_products'=>'user_id','go_store_orders'=>'store_id'] as $table=>$column)
            if(isset($queries[$table])&&!$all)$queries[$table]=DB::table($table)->whereIn($column,$storeIds);
        $children=[
            'takeaway_order_items'=>['order_id','takeaway_orders','id'],'branch_shift_sources'=>['closing_id','branch_shift_closings','id'],
            'phone_delivery_batch_items'=>['batch_id','phone_delivery_batches','id'],'carts'=>['order_id','orders','id'],
            'commissions'=>['order_id','orders','id'],'payments'=>['order_id','orders','id'],'shippings'=>['order_id','orders','id'],
            'go_order_payments'=>['order_id','orders','id'],
            'go_order_payment_receipts'=>['payment_id','go_order_payments','id'],'go_store_payment_receipts'=>['order_id','go_store_orders','id'],
        ];
        foreach($children as $table=>[$column,$parent,$id])if(isset($queries[$table],$queries[$parent])&&!$all){
            abort_unless(Schema::hasColumn($table,$column),409,'علاقة بيانات الفرع تحتاج مراجعة.');
            $queries[$table]=DB::table($table)->whereIn($column,(clone $queries[$parent])->select($id));
        }
        if(isset($queries['order_board_clocks'],$queries['orders'],$queries['go_store_orders'])&&!$all)$queries['order_board_clocks']=DB::table('order_board_clocks')->where(function($q)use($queries){
            $q->where(fn($part)=>$part->where('source','legacy')->whereIn('order_id',(clone $queries['orders'])->select('id')))
                ->orWhere(fn($part)=>$part->where('source','store')->whereIn('order_id',(clone $queries['go_store_orders'])->select('id')));
        });
        if(!$all){
            $ids=[(int)$actor->id,...$storeIds];
            if(isset($queries['resturants']))$ids=array_merge($ids,(clone $queries['resturants'])->pluck('user_id')->all());
            $queries['users']=DB::table('users')->whereIn('id',array_unique($ids));
            // users.pending_vendor_id has a live FK. Import the eligibility of
            // every application referenced by an imported user, never application PII.
            if(isset($queries['pending_vendors'],$queries['go_stores']))$queries['pending_vendors']=DB::table('pending_vendors')
                ->whereIn('id',(clone $queries['users'])->whereNotNull('pending_vendor_id')->select('pending_vendor_id'))
                ->select(self::GO_ELIGIBILITY_FIELDS)->orderBy('id');
        }
        foreach(['model_has_roles','model_has_permissions'] as $table)if(isset($queries[$table])&&!$all)$queries[$table]=DB::table($table)->where('model_type',User::class)->where('model_id',$actor->id);
        if(isset($queries['notifications']))$queries['notifications']=DB::table('notifications')->where('notifiable_type',User::class)->where('notifiable_id',$actor->id);
        if(isset($queries['branch_expense_category_commands'])&&!$all)$queries['branch_expense_category_commands']=DB::table('branch_expense_category_commands')->where('actor_id',$actor->id);
        if(isset($queries['settings'])){
            $names=array_map(fn($p)=>$p->getName(),(new \ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(\ReflectionProperty::IS_PUBLIC));
            $queries['settings']=DB::table('settings')->where('group','general')->whereIn('name',$names);
        }
        return $queries;
    }
    public function rows(array $queries): array
    {
        $data=[];$total=0;$eligibilityOnly=isset($queries['pending_vendors'])&&$queries['pending_vendors']->columns===self::GO_ELIGIBILITY_FIELDS;
        foreach($queries as $table=>$query){
            $data[$table]=[];
            // Avoid retaining a Collection of stdClass rows beside the full
            // array dataset. Reject the first excess row before appending it.
            foreach($query->cursor() as $row){abort_if($total>=500000,413);$data[$table][]=(array)$row;$total++;}
        }
        if($eligibilityOnly)$data['pending_vendors']=array_map(fn($row)=>$row+['full_name'=>'','mobile'=>''],$data['pending_vendors']);
        $references=DB::select('SELECT TABLE_NAME AS source_table,COLUMN_NAME AS source_column,REFERENCED_TABLE_NAME AS target_table,REFERENCED_COLUMN_NAME AS target_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL',[DB::connection()->getDatabaseName()]);
        // MySQL legacy schemas contain cycles (users -> restaurant -> user). Resolve parents to a fixed point.
        for($round=0;$round<32;$round++){
            $changed=false;
            foreach($references as $ref){
                if(!array_key_exists($ref->source_table,$data))continue;
                abort_unless(array_key_exists($ref->target_table,$data),409,'جدول مرتبط لم يُجهّز للنقل.');
                $known=array_column($data[$ref->target_table],$ref->target_column);$requested=[];
                foreach($data[$ref->source_table] as $row)if(isset($row[$ref->source_column]))$requested[]=$row[$ref->source_column];
                $missing=array_values(array_diff(array_unique($requested),$known));if(!$missing)continue;
                if($eligibilityOnly&&$ref->target_table==='pending_vendors'){
                    // Users added as parents (for example customers on branch orders) may
                    // reference their own application. Import only its eligibility, never PII.
                    foreach(array_chunk($missing,1000) as $ids){
                        foreach(DB::table('pending_vendors')->whereIn('id',$ids)->select(self::GO_ELIGIBILITY_FIELDS)->orderBy('id')->cursor() as $row){
                            abort_if($total>=500000,413);$data['pending_vendors'][]=(array)$row+['full_name'=>'','mobile'=>''];$changed=true;$total++;
                        }
                    }
                    continue;
                }
                abort_unless(in_array($ref->target_table,self::REFERENCE_TABLES,true),409,'مرجع مالي خارج نسخة الفرع؛ لم تُفعّل قاعدة محلية ناقصة.');
                foreach(array_chunk($missing,1000) as $ids){
                    foreach(DB::table($ref->target_table)->whereIn($ref->target_column,$ids)->cursor() as $row){abort_if($total>=500000,413);$data[$ref->target_table][]=(array)$row;$changed=true;$total++;}
                }
                abort_if($total>500000,413);
            }
            if(!$changed){$data=$this->media($data,$eligibilityOnly);abort_if(array_sum(array_map('count',$data))>500000,413);return $data;}
        }
        abort(409,'روابط البيانات تجاوزت حد التجهيز.');
    }
    public function pendingEligibility(array $queries,array $data): ?array
    {
        if(!isset($queries['pending_vendors'])||$queries['pending_vendors']->columns!==self::GO_ELIGIBILITY_FIELDS)return null;
        $ids=array_map('intval',array_column($data['pending_vendors']??[],'id'));sort($ids);
        return ['kind'=>'go-store-owner-only','projected_ids'=>$ids,'source_fields'=>self::GO_ELIGIBILITY_FIELDS,
            'redacted_required_fields'=>['full_name','mobile'],'application_review'=>false];
    }
    private function media(array $data,bool $eligibilityOnly): array
    {
        if(!array_key_exists('media',$data))return $data;
        $models=['User'=>'users','Admin'=>'users','Resturant'=>'resturants','ResturantProduct'=>'resturant_products','Product'=>'products','Category'=>'categories',
            'Advertising'=>'advertisings','Banner'=>'banners','Slidear'=>'slidears','Feature'=>'features','PendingVendor'=>'pending_vendors','Area'=>'areas','Review'=>'reviews'];
        $query=DB::table('media')->where(function($q)use($models,$data,$eligibilityOnly){
            $q->whereRaw('1=0');foreach($models as $model=>$table){if($eligibilityOnly&&$model==='PendingVendor')continue;$ids=array_column($data[$table]??[],'id');if($ids)$q->orWhere(fn($sub)=>$sub->where('model_type','App\\Models\\'.$model)->whereIn('model_id',$ids));}
        });
        $remaining=500000-(array_sum(array_map('count',$data))-count($data['media']));$data['media']=[];
        foreach($query->cursor() as $row){abort_if(count($data['media'])>=$remaining,413);$data['media'][]=(array)$row;}return $data;
    }
}
