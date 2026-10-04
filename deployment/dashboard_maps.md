# Dashboard Google Maps setup

The dashboard reads `services.maps.browser_key` through Laravel configuration, including when `config:cache` is enabled. `MAP_BROWSER_KEY` may hold a dedicated browser key; when unset it falls back to the existing `MAP_KEY`. Keep existing server-side/app keys unchanged.

A dark map marked “For development purposes only” does not identify one exact cause. On the affected branch device open Chrome DevTools → Console and record the `Google Maps JavaScript API error: ...` code. Do not send the complete script URL or key.

In the Google Cloud project owning the browser key:

- Enable Maps JavaScript API.
- Check the project's active billing account.
- Use website (HTTP referrer) restrictions allowing `https://fasakhaninja.com/*` and, if used, `https://www.fasakhaninja.com/*`. Keep API restrictions limited to the APIs this key needs.
- `MissingKeyMapError` / `InvalidKeyMapError`: check the configured browser key. `RefererNotAllowedMapError`: correct the website restriction. `ApiNotActivatedMapError`: enable the API. `BillingNotEnabledMapError`: resolve billing in the owning project.

After updating an environment value, regenerate configuration **only if the site already uses configuration caching**; otherwise clear the existing configuration cache. The pinned deployment installer preserves the site's cache mode automatically. Reload the restaurant form and verify that a map click updates both displayed and submitted coordinates and that save/reopen preserves them. The map form also supports saving explicit coordinates through Search when Google's map cannot load; it does not silently replace a saved location after an authorization failure.

Reference: https://developers.google.com/maps/documentation/javascript/error-messages

Google Cloud activation, key restrictions, billing, and live map rendering require the production account/device. Code validation cannot establish that they are correctly configured.
