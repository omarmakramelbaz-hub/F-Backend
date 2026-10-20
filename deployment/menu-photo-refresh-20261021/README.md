# Professional product photographs for all Fasakhansta branches

User request, 21 October 2026: make the menu photographs professional and clear, following the existing clean side-dish presentation, for every branch.

The release contains **42 logical product images for 1,473 visible menu entries across all 32 branches and stores**, including the 20 wholesale entries. It keeps the existing 219 side-dish images as the visual reference. The 152 hidden entries retain their existing photographs. The manifest records each original menu identity and media ID; names, prices, quantities, recipes and visibility are not edited.

The new photographs use white backgrounds, simple white plates, natural lighting, and visible product details. Whole feseekh has separate 1/2/3/4-fish photographs. Matching Desouk and Nabrouh grades share count-specific imagery. Meal families and tiers have separate images; photograph portions are visual illustrations rather than measured serving weights. Sandwiches preserve the actual bread and cured-fish filling. Packaged products and drinks follow their existing photographed containers and brands. Tiny package microprint was unreadable in the source and is not represented as verified label copy.

Wholesale photographs continue the existing representative-product convention: the current catalog already shows individual products rather than actual barrels, cartons or twenty-unit packaging. The original bulk quantities remain in each product name. No bulk package design or label is invented.

The assets were generated or edited using the built-in image-generation tool, with actual catalog photographs as product references. Shared retail packaging photographs are reused across corresponding logical image keys. Full generation prompts and source references are recorded in `generation-notes.json`.

## Publication

The explicit root wrapper extracts only this release package and its standalone PHP runner. It boots the deployed Laravel application as `fasakha`; no application source checkout, migrations, configuration replacement, messages, or cache rebuilds are required. A separate reversible framing helper appends `object-fit: contain` and a white background to the existing takeaway and dining product-image styles, with private copies of both original stylesheets. This keeps whole bottles, fish and plates visible inside the existing card dimensions. Other live stylesheet content is preserved, and existing file-mtime asset versions refresh the styles when the page reloads.

The runner checks all 42 JPEG hashes, byte lengths and dimensions. It validates the current 32-branch catalog against the manifest and the expected first media IDs. New filenames are versioned inside the existing media-ID directories on the established `products` disk. Existing originals and conversion files are retained.

Every one of the 1,473 new URLs must return HTTP 200 and the exact approved JPEG bytes **before** database image references change. Same-origin requests run with redirects disabled. The only database updates are to the scoped media rows: filename, image MIME/size, disk, cleared conversion flags, a release marker, and a timestamp for media synchronization. Media IDs and all menu data are preserved.

Private before-records and receipts are kept outside the public directories. A repeat run verifies the release and creates no duplicate media or menu entries. Changed menu identities, scope, destination collisions or unexpected edited photos stop publication for review.

Run `deployment/apply_menu_photo_refresh_root.sh --release <reviewed SHA> --check`, then use `--apply` for activation. Progress counters report file publication and HTTP verification. Success is `MENU_PHOTOS_READY` with `http_verified_images: 1473`.

Local behavior tests use actual JPEG files and isolated Laravel/Spatie/database/HTTP doubles. Live HTTP and installed-runtime compatibility are verified by the server runner. The workspace cannot activate the deployed menu directly.

## Gallery

See [GALLERY.md](GALLERY.md) for the complete product-photo set.
