<?php
namespace App\Services\OwnerDashboards;

final class AccountPolicy
{
    public static function enforce($request,$actor): void
    {
        if(!Runtime::tenant())return;
        $route=$request->route();if(!$route)return;
        $action=$route->getActionName();
        if(!str_contains($action,'Dashboard\\UserController@'))return;
        $method=$route->getActionMethod();
        $parameter=$route->parameter('user');$id=is_object($parameter)?(int)$parameter->id:(int)$parameter;
        $bulk=$request->input('ids',[]);$ids=is_array($bulk)?$bulk:explode(',',(string)$bulk);
        if($id===1){
            abort_unless((int)$actor->id===1,403,'حساب أونر اللوحة يديره الأونر الرئيسي أو صاحب الحساب.');
            abort_if(in_array($method,['destroy','changeStatus'],true),403,'إيقاف أو حذف حساب الأونر يتم من الأونر الرئيسي.');
        }
        if(in_array($method,['deleteAll','delete_all'],true))abort_if(in_array(1,array_map('intval',$ids),true),403);
    }
}
