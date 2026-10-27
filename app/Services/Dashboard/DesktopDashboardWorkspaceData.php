<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** One physical dataset, selected from the union of freshly validated member scopes. */
final class DesktopDashboardWorkspaceData
{
    public function __construct(private DesktopDashboardData $legacy, private DesktopDashboardWorkspaceAuthority $authority) {}

    public function queries(DesktopDashboardWorkspaceSnapshotAudience $audience): array
    {
        $this->authority->assertSnapshotAudience($audience);
        $manifest=$audience->manifest();$actors=$audience->actors();$members=$manifest['members'];
        abort_unless($members!==[],409);
        $branches=[];$ids=[];$chosen=null;$all=false;$contracts=false;
        foreach($members as $member){
            $id=$member['actor_id'];$actor=$actors[$id]??null;
            abort_unless($actor instanceof User&&(int)$actor->id===$id,409);
            $ids[]=$id;$branches=array_merge($branches,$member['branches']);
            // Select a real validated administrator if one is present. The
            // enrolling custodian is audit information, never an authority proxy.
            if($chosen===null||(!$all&&$member['permissions']['super_admin']))$chosen=$actor;
            $all=$all||$member['permissions']['super_admin'];
            if($actor->account_type==='admin'&&empty($actor->owner_resturant_id)
                &&array_intersect(['contract-list','contract-edit'],$member['permissions']['names']))$contracts=true;
        }
        $branches=array_values(array_unique($branches));sort($branches,SORT_STRING);
        // Existing public/catalog/child policies and the FK closure remain one
        // implementation. Only actor-specific selections become member unions.
        $queries=$this->legacy->queries($audience->root(),$chosen,$branches);
        if(!$all){
            $stores=[];foreach($branches as $branch)if(str_starts_with($branch,'gs:'))$stores[]=(int)substr($branch,3);
            // Keep the stable custody row for the physical ledger's audit FK;
            // it does not receive a verifier unless it is also a current member.
            $userIds=array_merge($ids,$stores,[(int)$audience->root()->custodian_actor_id]);
            if(isset($queries['resturants']))$userIds=array_merge($userIds,(clone $queries['resturants'])->pluck('user_id')->all());
            $queries['users']=DB::table('users')->whereIn('id',array_values(array_unique($userIds)));
            if(isset($queries['pending_vendors']))$queries['pending_vendors']=DB::table('pending_vendors')
                ->whereIn('id',(clone $queries['users'])->whereNotNull('pending_vendor_id')->select('pending_vendor_id'))
                ->select(['id','profession_key','type','status','source_app'])->orderBy('id');
            foreach(['model_has_roles','model_has_permissions'] as $table)if(isset($queries[$table]))
                $queries[$table]=DB::table($table)->where('model_type',User::class)->whereIn('model_id',$ids);
            if(isset($queries['branch_expense_category_commands']))$queries['branch_expense_category_commands']=DB::table('branch_expense_category_commands')->whereIn('actor_id',$ids);
            if($contracts&&isset($queries['contracts']))$queries['contracts']=DB::table('contracts');
        }
        // Even a full administrator snapshot must include the notifications of
        // every member. Child histories are never inherited from the custodian.
        if(isset($queries['notifications']))$queries['notifications']=DB::table('notifications')
            ->where('notifiable_type',User::class)->whereIn('notifiable_id',$ids);
        return $queries;
    }

    public function rows(array $queries): array { return $this->legacy->rows($queries); }
    public function pendingEligibility(array $queries,array $rows): ?array { return $this->legacy->pendingEligibility($queries,$rows); }
}
