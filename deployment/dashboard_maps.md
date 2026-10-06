# Dashboard maps and automatic address lookup

The branch location form, delivery-area maps, and restaurant/delegate directories now use locally bundled Leaflet with OpenStreetMap tiles, so selecting and saving a pin does not depend on Google billing. The phone order map uses Google for automatic address lookup when authorized, and falls back to OpenStreetMap pin selection after a load/authorization failure. Fallback never fabricates an address or automatically confirms a suggested location.

`MAP_TILE_URL` can replace the default OpenStreetMap-compatible tile endpoint. Attribution stays visible; browser caching and referrers are preserved, and there is no offline prefetch. Public tile availability is best effort: https://operations.osmfoundation.org/policies/tiles/.

The dashboard reads `services.maps.browser_key` through Laravel configuration, including when `config:cache` is enabled. `MAP_BROWSER_KEY` may hold a dedicated browser key; when unset it falls back to the existing `MAP_KEY`. Keep existing server-side/app keys unchanged.

A dark map marked “For development purposes only” does not identify one exact cause. On the affected branch device open Chrome DevTools → Console and record the `Google Maps JavaScript API error: ...` code. Do not send the complete script URL or key.

In the Google Cloud project owning the browser key:

- Enable Maps JavaScript API and Geocoding API.
- Check the project's active billing account.
- Use website (HTTP referrer) restrictions allowing `https://fasakhaninja.com/*` and, if used, `https://www.fasakhaninja.com/*`. Keep API restrictions limited to the APIs this key needs.
- `MissingKeyMapError` / `InvalidKeyMapError`: check the configured browser key. `RefererNotAllowedMapError`: correct the website restriction. `ApiNotActivatedMapError`: enable the API. `BillingNotEnabledMapError`: resolve billing in the owning project.

After updating an environment value, regenerate configuration **only if the site already uses configuration caching**; otherwise clear the existing configuration cache. The pinned deployment installer preserves the site's cache mode automatically. Reload the restaurant form and verify that a map click updates both displayed and submitted coordinates and that save/reopen preserves them. The map form also supports saving explicit coordinates through Search when map tiles cannot load; it does not silently replace a saved location after an authorization failure.

Reference: https://developers.google.com/maps/documentation/javascript/error-messages

Google Cloud activation, key restrictions, billing, and live map rendering require the production account/device. Code validation cannot establish that they are correctly configured.

Phone delivery fees are computed server-side from the rounded distance in metres between the saved restaurant pin and confirmed customer pin, multiplied by `resturants.km_price`, then rounded to EGP cents. This is straight-line pin distance, not road routing. The quote is checked again on save. A changed pin/rate requires confirmation again; no manually entered fee is trusted.

October 4 delivery follow-up: a successful address lookup now moves the customer marker and fits both the branch and customer into view. An orange straight line joins them, and the server automatically previews pin-distance × configured km price. The operator still confirms the customer pin before saving customer details/creating the order. Editing the address clears the old coordinates, line, fee and confirmation. Late geocoding responses cannot override a newer address or manual selection.

Authorization handling is installed even when a preceding SPA page already loaded Google. `REQUEST_DENIED`, `gm_authFailure`, and Google's development-watermark/error DOM switch to the bundled Leaflet map. This restores **manual** pin selection and automatic distance/fee calculation; it does not activate Google's address search. `ZERO_RESULTS` is distinct from key/billing rejection. Live Google authentication and live tiles must still be checked on the production branch device. The browser tests simulate provider replies, including out-of-order responses and authorization rejection; local PHP quotes and order saves run against the real controllers.


## Address suggestions and optional road routing (October 4 evening)

Typing two address characters requests suggestions after a 250 ms pause. Only the entered address is searched; an unrelated saved area is not appended. Operators select a result using the mouse or arrows + Enter, then review and confirm the customer pin. Typing another address, selecting another customer, changing branch or moving the pin invalidates older results and quotes. No first result is silently accepted. The existing Google mode uses Places API (New) autocomplete and location details on a Google map, and retains the existing pin-distance pricing. Google Places must be enabled alongside Maps JavaScript; this release does not activate Cloud APIs or billing.

A complete alternative flow is prepared but **disabled by default**: `PHONE_OPEN_MAPS_ENABLED=false`. Do not enable it without the owner's approval to disclose address searches to Photon and branch/customer coordinates to FOSSGIS OSRM. An automatic approval review blocked the attempted external smoke check for this reason. It was not retried. All alternative-provider tests use synthetic replies with HTTP fakes; live availability and local address coverage are not verified.

Approval update, 2026-10-04: the owner explicitly approved sending addresses and branch/customer coordinates to Photon and OSRM. Subsequent HTTPS checks returned Photon suggestions for the public city query “شبرا الخيمة” and a successful OSRM route between two public Cairo coordinates. This does not establish reachability from the production server or the coverage of every customer address. The earlier blocked request preceded this approval.

After the reviewed code release is installed, run `bash deployment/enable_phone_maps.sh` **as the application owner**. It checks both approved services through the server's PHP runtime using public example locations before changing settings. It saves the existing environment/configuration outside the web checkout in a private directory, changes only `PHONE_OPEN_MAPS_ENABLED`, preserves whether configuration caching was enabled, and verifies the effective setting in a fresh process. A configuration failure restores both saved files. It does not restart the VPS or change Google/app settings. Refresh the dashboard after it reports `PHONE MAPS ENABLED` and reconfirm any open order's location because road pricing replaces its previous straight-line quote.

After explicit approval, `PHONE_OPEN_MAPS_ENABLED=true` selects the bundled Leaflet map, Photon address choices and server-side OSRM road routing. `PHONE_PHOTON_URL` and `PHONE_OSRM_URL` may point to an operator-managed compatible HTTPS service. Defaults are `https://photon.komoot.io/api/` and `https://routing.openstreetmap.de/routed-car`. Searches are limited to Egypt and biased near the saved branch pin. Customer names, phone numbers and order contents are not sent; the entered address query and coordinates are sent as needed. Providers may retain access logs.

The optional mode changes new delivery quotes to **road distance in metres × the saved branch km price**, with integer-cent rounding. The server obtains and validates the route; browser-supplied prices, distances and geometry are not trusted. A missing route or provider failure leaves the location unconfirmed and does not silently substitute direct distance. Saved bill snapshots and completed orders remain unchanged. Changing the provider invalidates outstanding delivery quotes, which must be confirmed again.

Requests use identifying User-Agent headers, no redirects, connection/request timeouts, per-provider locks and at least 1.05 seconds between uncached requests. Search replies are cached for two minutes and routes for thirty minutes; repeated preview/confirmation/save reuses the same route. These public services have no availability guarantee and must remain low-volume; use an operator-hosted or contracted endpoint as usage grows. Attribution and a fix-the-map link remain visible.

Primary references: https://github.com/komoot/photon and https://github.com/komoot/photon/blob/master/docs/api-v1.md ; https://routing.openstreetmap.de/about.html ; https://project-osrm.org/docs/v5.24.0/api/ ; https://developers.google.com/maps/documentation/javascript/place-autocomplete-data .

## October 6 autocomplete responsiveness

Autocomplete keeps one network request in flight per field, remembers the latest typed query, discards obsolete replies, caches exact successful lookups in memory, and retries brief HTTP 429 contention automatically up to three times. Editing invalidates the selected pin/quote as before; branch/customer changes clear the field cache. The server releases the shared provider-rate lock **before** HTTP I/O, so one slow search cannot hold all devices behind an eight-second lock. Public Photon traffic remains capped at one new request/second across devices; positive results are cached for a day and empty results for a minute. Search connection/overall timeouts are 2/5 seconds. OSRM routing/authoritative pricing remain unchanged.

Photon supports search-as-you-type but its public demo has no availability guarantee and its address coverage differs from Google Places (https://github.com/komoot/photon#demo-server). No Google billing or new provider is activated by this change. Local browser checks used synthetic Photon/OSRM replies and actual Laravel quotes/order saves; direct live provider access from the development workspace was blocked by its network proxy. Run `php deployment/check_phone_maps.php --preflight` from the production PHP runtime to verify public search/routing reachability without using customer data.
