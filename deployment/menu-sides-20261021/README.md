# Tomato and tahini side plates

Owner request, 21 October 2026: add **طبق طماطم, EGP 15** and **طبق طحينة, EGP 30** to the existing side-dishes category for every Fasakhansta branch.

This is an explicit, data-only release. The installer uses the deployed Laravel app and its current schema; it does not replace application source, switch the server's branch, run migrations, send messages, or change other menu prices. All `resturants` entries are included, matching the dashboard's Fasakhansta branch catalog. Independent `go_store_products` are outside this scope.

Images are AI-created original food photographs made for these two products. PNG originals were converted to JPEG without resizing. New images use the existing `public` media disk and `product_image` collection. Preflight checks the site's existing public URL mapping, and each resulting URL must map to its actual verified image file. Prompts: plain ripe tomato slices and wedges on a small white plate; plain creamy Egyptian tahini sauce in a shallow white bowl; centered whole dishes, white backgrounds, consistent natural light and slightly overhead catalog photography, no text, logos, or extra ingredients.

| File | SHA256 |
| --- | --- |
| assets/tomato-plate.jpg | 3130d500075a2329504a8dab4c5313e4dc721b88a8c1611138c89ac5eab59be6 |
| assets/tahini-plate.jpg | 1fce3105f48d8518739301f11a403e61e677ef52a6bf0b659f4a5f74ce72053e |

Run `deployment/apply_menu_sides_root.sh --release <reviewed commit> --check` first, then use `--apply` to add the records. The wrapper requires root on `srv1836524`, runs Laravel as `fasakha`, extracts only the release files into a temporary directory, and keeps private database snapshots and receipts outside the web root.

The runner requires one unambiguous existing visible side-dishes category. It refuses pre-existing matching products from another source instead of silently overwriting them. It verifies exactly one of each requested product per branch, category, price, visibility, image file, and lack of extra preparation/size charges. Re-running a successful release validates its receipt and makes no duplicate records. A changed set of branches or edited installed items requires review.

No ingredient recipe or portion weight was supplied. Both are sold per plate; no guessed recipe, stock quantity, or inventory receipt is added. The existing recipe editor can bind their ingredients later.

The live database cannot be verified from the coding workspace. Activation is confirmed only by the server runner's successful result.
