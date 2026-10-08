<?php
namespace App\Services\Dashboard;

/** Exact original write routes. This list is coverage, not a claim about unregistered modules. */
class DesktopDashboardRoutes
{
    // These original POST actions calculate prices; they do not commit a business operation.
    public const READ_POSTS=['takeaway.quote','dining.quote','phone-orders.quote','phone-orders.delivery-quote'];
    public const READ_POST_ACTIONS=[
        'App\\Http\\Controllers\\Dashboard\\ProductController@fetchSubcategory',
        'App\\Http\\Controllers\\Dashboard\\ProductController@fetchProduct',
        'App\\Http\\Controllers\\Dashboard\\ProductController@fetchFeature',
    ];
    public const WRITES=[
        'takeaway.checkout','takeaway.movements','takeaway.settings',
        'dining.save','dining.action','dining.settle','dining.table-save','dining.settings',
        'phone-orders.save','phone-orders.action','phone-orders.settle','phone-orders.dispatch-company','phone-orders.finish-batch',
        'branch-stock.receive','branch-stock.recipe-save',
        'branch-expenses.save','branch-expenses.review','branch-expenses.categorySave',
        'customers.save','delivery-companies.save','employees.save','employees.attendance','employees.entry',
        'employees.wallet','employees.daily-notes','employees.void-entry','employees.close','employees.pay',
        'branch-shifts.close',
    ];
    public static function journaled(?string $route): bool {return in_array($route,self::WRITES,true)||DesktopDashboardLegacy::handles($route);}
}
