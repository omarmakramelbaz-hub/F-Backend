<?php
 
namespace App\Scopes;
 
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
 use Auth;
 use DB;
class AdminScope implements Scope
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
 
        $id_user = (session()->get('id_user')) ?? null;
        $user = DB::table('users')->where('id', $id_user)->first();
        // dd($id_user);
        
         if (request()->wantsJson() || request()->is('api/*')  || request()->is('admin/chat/*')) {
        if($user && $user->account_type == 'resturant_owner'){
             $resturants= DB::table('resturants')->where('id',$user->owner_resturant_id)->orWhere('parent_id', $user->owner_resturant_id)->pluck('user_id')->toArray();
        //   dd($resturants,array_push($resturants,$user->id));
            $builder->where(function ($query) use ($user, $resturants) {
                if (!empty($resturants)) {
                    $query->whereIn('id', $resturants);
                }
                $query->orWhere('id', $user->id)
                      ->orWhere('added_by', $user->id);
            });
            
                       
        }elseif($user && $user->account_type != 'admin'){
            $builder->where('added_by',$user->id)->when($user->added_by!=null,function($q) use($user){
               $q->orWhere('added_by',$user->added_by);
                })->orWhere('id',$user->id)->orWhere('id', 1);
        }
    }
    }
}