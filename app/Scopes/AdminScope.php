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
        // Only JSON, API and chat requests are scoped, so the account is read for them alone (every other request used
        // to read it too, then never use it). With no dashboard account signed in there is nothing to read: users.id is
        // never null, so that lookup always found nothing.
        if (!(request()->wantsJson() || request()->is('api/*') || request()->is('admin/chat/*'))) {
            return;
        }
        $id_user = (session()->get('id_user')) ?? null;
        $user = $id_user === null ? null : DB::table('users')->where('id', $id_user)->first();

        if ($user && $user->account_type == 'resturant_owner') {
            $resturants = DB::table('resturants')->where('id', $user->owner_resturant_id)->orWhere('parent_id', $user->owner_resturant_id)->pluck('user_id')->toArray();
            $builder->where(function ($query) use ($user, $resturants) {
                if (!empty($resturants)) {
                    $query->whereIn('id', $resturants);
                }
                $query->orWhere('id', $user->id)
                      ->orWhere('added_by', $user->id);
            });
        } elseif ($user && $user->account_type != 'admin') {
            $builder->where('added_by', $user->id)->when($user->added_by != null, function ($q) use ($user) {
                $q->orWhere('added_by', $user->added_by);
            })->orWhere('id', $user->id)->orWhere('id', 1);
        }
    }
}