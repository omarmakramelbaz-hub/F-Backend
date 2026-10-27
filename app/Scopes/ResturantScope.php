<?php
 
namespace App\Scopes;
 
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
 
class ResturantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $workspace=app(\App\Services\Dashboard\DesktopDashboardWorkspaceReadAuthority::class);
        if($workspace->selected()){
            $workspace->restaurants($builder,$model->qualifyColumn('id'));return;
        }
        $id_returant = (session()->get('id_returant')) ?? null;
        if(auth('admin')->check() && auth('admin')->user()->account_type =='resturant_owner' && auth('admin')->user()->owner_resturant_id){
            
            $builder->where('id',auth('admin')->user()->owner_resturant_id)->orWhere('parent_id', auth('admin')->user()->owner_resturant_id);

        }
         elseif(auth('admin')->check() && auth('admin')->user()->account_type !='admin'){
            $builder->where('user_id',auth('admin')->user()->id)->orWhere('parent_id', $id_returant);

        }
        
    }
}
