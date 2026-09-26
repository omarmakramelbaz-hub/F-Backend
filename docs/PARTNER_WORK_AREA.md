# Partner work-area map

F-admin is a WebView shell. The add/edit delegate form is in F-Backend. This branch is based on the unmerged GO marketplace branch and must remain GitHub-only until deployment is explicitly approved. Do not merge to main to preview: main automatically deploys to the VPS.

The fixed pin and radius are saved on the linked pending_vendors record (lat, lng, work_radius_km); users.lat/lng remain live GPS. The existing September 20 partner-fields migration supplies these columns. No new migration or change to payment settings is needed. The numeric radius is an integer from 1 to 255 km (the existing column is unsignedTinyInteger), and means straight-line radius, not diameter or road distance. All supported integer radii also work in GO marketplace matching; legacy null retains the existing 5 km fallback.

The map reuses MAP_KEY through config/partner_work_area.php. Click to place a pin, drag to move it, enter a radius to see the circle immediately. Blank create forms do not save the default viewport as a location. Existing profile values and validation old-input values are restored. Map load/auth failures are explicit and prevent accidental submission. Auth/media/mail are not contacted by tests.

Tests: php tests/partner_work_area/unit.php; node tests/partner_work_area/map.test.js; composer install --working-dir=tests/partner_work_area followed by php tests/partner_work_area/runtime.php. Runtime tests use isolated SQLite/Eloquent, real Validator and Blade, with auth/mail/model-role test doubles. They cover creation and editing, Arabic digits, bounds, mail failure, preservation of registration fields and GPS, and a custom 7 km marketplace radius. Google Maps tiles and physical iOS WebView behavior still need staging/device acceptance testing.
