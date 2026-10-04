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
