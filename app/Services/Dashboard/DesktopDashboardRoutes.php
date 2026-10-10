<?php
namespace App\Services\Dashboard;

/** Exact original write routes. This list is coverage, not a claim about unregistered modules. */
class DesktopDashboardRoutes
{
    // These original POST actions calculate prices or read addresses without committing a business operation.
    public const READ_POSTS=['takeaway.quote','dining.quote','phone-orders.quote','phone-orders.delivery-quote','phone-orders.address-suggestions'];
    public const READ_POST_ACTIONS=[
        'App\\Http\\Controllers\\Dashboard\\ProductController@fetchSubcategory',
        'App\\Http\\Controllers\\Dashboard\\ProductController@fetchProduct',
        'App\\Http\\Controllers\\Dashboard\\ProductController@fetchFeature',
    ];
    public const WRITES=[
        'resturant_reviews.destroy',
        'userwishlists.destroy',
        'order-board.menu.availability',
        'read_notify','mark_all_as_read',
        'dashboard-inbox.notifications.read',
        'takeaway.checkout','takeaway.movements','takeaway.settings',
        'dining.save','dining.action','dining.settle','dining.table-save','dining.settings',
        'phone-orders.save','phone-orders.action','phone-orders.settle','phone-orders.dispatch-company','phone-orders.finish-batch',
        'branch-stock.receive','branch-stock.recipe-save',
        'branch-expenses.save','branch-expenses.review','branch-expenses.categorySave',
        'customers.save','delivery-companies.save','employees.save','employees.attendance','employees.entry',
        'employees.attendance-rules','employees.wallet','employees.daily-notes','employees.void-entry','employees.close','employees.pay',
        'branch-shifts.close',
    ];
    public static function journaled(?string $route): bool {return in_array($route,self::WRITES,true)||DesktopDashboardLegacy::handles($route);}
}
