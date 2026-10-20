# Original restaurant profile and operating status

The desktop adapter invokes `ResturantController::update` / `updateStatus`, the original `StoreResturantRequest`, `ResturantRepository` and pinned Spatie FileAdder. Exact routes are `resturants.update` (PUT/PATCH, including the original multipart POST override) and `resturants.updateStatus` (POST). Each submitted form keeps one UUID and its original redirect and success flash.

Authority is checked from current SQL rows before new work, cached replies and native settlement. It requires the enrolled restaurant branch, unchanged restaurant creation identity, unchanged recorded owner creation identity, and the current original account relationship. Profile updates also require the original `resturant-edit` grant from current role/direct permission rows. The original status action has no such controller permission. Missing creation timestamps require connected review. Changing the owner or parent through the profile form requires connected review.

Before facts include the complete29-column restaurant row and its owner/creator/parent relations, full delivery-area pivots, complete selected geographic records, and full18-column media rows with byte proofs for their primary/conversion/responsive files. Reconciliation compares all captured state before invoking the original write; unrelated concurrent changes produce a retained conflict.

The original repository creates delivery-area rows and uploads logo/background images. Named references carry generated pivot/media IDs through real local/server ID drift and subsequent dependent edits. Only declared IDs are translated. Immutable field/relationship checks and complete after-state comparison prevent extra local result changes from being accepted.

The scoped Spatie filesystem preserves old media bytes when the original controller clears a collection. Newly created rows use an operation-derived UUID per collection, validated source-generated ordering/times and verified original bytes. New files are appended without overwriting different bytes. An outer transaction failure removes only new files that this operation proved absent before writing; an uncertain COMMIT acknowledgment retains files for recovery. Provider broadcasts are suppressed locally and deferred until the outer central transaction commits.

No branch is appended to enrollment by this adapter. Restaurant creation/deletion and bulk deletion remain separate contracts. Provider configuration and credentials continue to require the connected dashboard.
