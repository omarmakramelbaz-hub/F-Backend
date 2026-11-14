# مخلي صافي بالوزن — 14 November 2026

User-approved addition, not yet applied to production. This package only inserts missing menu/category/feature/recipe/media rows. It never replaces application files, changes current stock quantities, or replays orders.

| Item | Physical branches, EGP/kg net | Retail stores, EGP/kg net | Existing raw ingredient | Raw kg per net kg |
|---|---:|---:|---|---:|
| فسيخ مخلي صافي | 1000 | 1400 | فسيخ كيلو 3 سمكات | 2 |
| رنجة مخلي صافي | 600 | 800 | رنجة سمينة | 2 |
| سردين مخلي صافي | 600 | 800 | سردين بلدي | 2 |

The visible category is exactly `مخلي صافي بالوزن`. Recipe unit `kg` enables weighted quantities in the existing POS and website. A `kilo` feature supports the current customer-app base portion; no preparation surcharges or additional half/quarter features are added. Selling 0.25 / 0.5 / 1 kg net consumes 0.5 / 1 / 2 kg raw respectively.

Scope is physical branches 94, 301, 307, 363, Shubra's direct retail children, and exact registered retail identities without a conflicting parent. Other restaurants, including wholesale 308, and unrelated GO stores are excluded. All new SKUs share one catalog identity per fish. Retail order mapping to Shubra and all saved recipe snapshots are checked without creating a sale.

The package reuses the current corresponding raw-fish photos from Shubra menu SKUs 732 (three-fish feseekh), 782 (fat herring), and 779 (sardine). These are representative existing whole-fish pictures, not new fillet photography. Original photos are retained. Copies use collection `product_image` and disk `products`, with physical-file and GO ERP transport verification.

## Run

Use the pinned `RUN_ROOT.sh REVISION --check` or `RUN_ROOT.sh REVISION --apply` on `srv1836524` as root. The script runs as the application owner after validating host, production root, database, owner, installed services, table engines and file storage. Source files and test payloads are SHA256 verified before execution.

Before writes, an immutable plan and current records are saved under `/home/fasakha/menu-change-backups/`. All database additions happen in one transaction; conflicts stop the operation instead of changing existing data. Reruns create no duplicate rows. After application, a second read verifies prices, recipes, fractional snapshots, store mapping, weighted mode, saved image bytes and three public image URLs.

Success marker: `NET_FILLET_MENU_APPLIED_AND_VERIFIED`.

Local verification: PHP lint, `test_recipe_contract.php`, `test_menu.php`, and `bash -n RUN_ROOT.sh`. Real application and MySQL verification require the production command. No live orders are generated for tests.
