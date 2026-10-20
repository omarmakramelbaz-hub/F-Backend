# Repair image delivery after the original menu addition

Live observation on 21 October 2026: Mahalla's new tomato image URL `/storage/public/3322/tomato-plate.jpg` returns HTTP 404. The existing lemon image `/storage/products/1007/mediaVmPkQ7` returns HTTP 200 and valid WebP bytes. The new public-disk URL can resolve to an existing local path while Apache still returns 404; a filesystem-only mapping check was insufficient.

The correction copies only the media listed in `storage/app/menu-sides-20261021/receipt.json` to the already-configured local `products` disk using Spatie's current path and URL generation. It retains source files, media IDs, product IDs, prices, category, and unrelated media. Existing destination files with different hashes cause a stop. It refuses generated conversions or responsive images instead of losing them.

Before changing any media disk pointers, every target image must return HTTP 200 and the approved original JPEG SHA256. HTTP requests are limited to the configured site's own origin with redirects disabled. A missing MIME header is accepted when the exact image bytes match. Only then does a transaction update the scoped media `disk` and, where applicable, `conversions_disk` fields. Private backups and a separate correction receipt preserve the previous records and file paths.

This correction supersedes the original installer's public-disk image verification. Do not rerun the old activation script to validate corrected images; use the correction's `--check` or repeat `--apply` verification. No application files, configuration, recipes, stock, or menu prices are changed.

Run `deployment/repair_menu_side_images_root.sh --release <reviewed SHA> --check`, followed by `--apply`. Successful repair prints `MENU_SIDE_IMAGES_READY` after image verification. Actual HTTP serving remains a live-server check; isolated local tests use injected verification responses.
