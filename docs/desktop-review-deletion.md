# Original restaurant Review deletion

The bounded `resturant_reviews.destroy` increment journals the original admin DELETE form and calls the original `ResturantController::resturantReviewsDelete` action. The action deletes only its Review row and preserves its original redirect and success flash. Orders, customers, restaurant rating fields and polymorphic media rows/files retain the original behavior.

The command records all seven Review columns, including both timestamps, and the actual restaurant/order/user parent identities and relationships. Replay checks these facts before the original delete. A missing or changed server Review is a409 conflict retained for review; an unknown local ID remains404. Named typed references map only those identities and reject a mismatched entity.

The original route has `IsAdmin` but no `resturant-delete` controller permission. This adapter preserves that guard and adds no restaurant/menu/POS grant or actor app-scope condition. It checks the existing enrolled account/status, enabled server device, enrolled F restaurant and its current account relationship before both execution and cached deleted-ID responses. Offline state cannot observe a later server device revocation; server reconciliation and native outcome recovery recheck it.

The existing original form wrapper keeps an immutable command UUID. Native outcome reservation accepts only the original positive-ID Review path through its POST form envelope or DELETE method. A saved native outcome retains the approved Review facts for recovery after its row disappears.

This scope does not enable Review creation/update/bulk deletion, restaurant profile administration or external/media writes. `full_dashboard` remains false and all preview/release/source gates remain unchanged. Real Edge execution for this increment is pending integration; Node contracts and original Laravel/MariaDB fixtures are separate evidence.
