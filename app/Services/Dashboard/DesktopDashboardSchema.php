<?php
namespace App\Services\Dashboard;

/** Explicitly reviewed schemas from the deployed read-only 2026-10-08 inspection. */
class DesktopDashboardSchema
{
    public const TABLES=[
        'advertisings','areas','banners','branch_customers','branch_delivery_companies',
        'branch_employees','branch_employee_days','branch_employee_entries','branch_employee_salaries','branch_expenses',
        'branch_expense_categories','branch_expense_category_commands','branch_expense_category_settings','branch_expense_commands','branch_inventory',
        'branch_inventory_movements','branch_operation_commands','branch_payrolls','branch_recipe_sales','branch_shift_closings',
        'branch_shift_sources','branch_stock','branch_stock_movements','branch_stock_recipes','carts',
        'categories','commissions','contacts','contracts','conversations',
        'coupon_subscripes','coupon_wheels','coupon_wheel_resturants','dashboard_push_campaigns','dashboard_push_devices',
        'delegate_notifications','desktop_pos_devices','desktop_pos_operations','desktop_pos_orders','desktop_pos_snapshots',
        'failed_jobs','features','go_order_payments','go_order_payment_receipts','go_service_assignments',
        'go_service_jobs','go_service_ledger','go_service_offers','go_service_outbox','go_service_payments',
        'go_service_payment_receipts','go_service_recipients','go_stores','go_store_orders','go_store_payment_receipts',
        'go_store_products','jobs','last_searches','media','menu_price_release_20260923',
        'message_conversations','migrations','model_has_permissions','model_has_roles','notifications',
        'orders','order_board_clocks','partner_service_requests','password_resets','payments',
        'pending_vendors','permissions','phone_delivery_batches','phone_delivery_batch_items','phone_delivery_dispatches',
        'pos_branch_print_jobs','pos_service_commands','pos_service_kitchen_tickets','pos_service_settings','pos_service_tables',
        'pos_service_tickets','products','product_features','question_answers','resturants',
        'resturant_areas','resturant_products','reviews','roles','role_has_permissions',
        'settings','shippings','slidears','social_accounts','stock_ingredients',
        'store_delivery_release_20260923','takeaway_orders','takeaway_order_items','takeaway_tills','takeaway_till_entries',
        'users','user_address','user_tokens','wallets','wishlists',
        'zayed_price_release_20260923',
    ];
    public const EMPTY=['failed_jobs','jobs','password_resets','social_accounts','user_tokens','dashboard_push_devices',
        'desktop_pos_devices','desktop_pos_operations','desktop_pos_orders','desktop_pos_snapshots',
        'go_service_outbox','menu_price_release_20260923','store_delivery_release_20260923','zayed_price_release_20260923','migrations'];
}
