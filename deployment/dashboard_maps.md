# Delivery addresses and dashboard maps — October 6

The delivery address field now always uses Google Places API (New) Autocomplete Data on a Google map. The former `PHONE_OPEN_MAPS_ENABLED` switch no longer replaces address search with Photon. It continues to control **server-side OSRM road routing/pricing**; keep it enabled on the approved installation for road distance × the restaurant’s saved kilometre price.

Typing two characters starts a search after a 250 ms pause. Suggestions are restricted to Egypt with a 30 km location bias around the chosen branch, rather than a hard city boundary. Only the typed address is searched. Selecting a result with mouse/arrows + Enter fetches its location and formatted address together, updates the customer pin, and previews the road and fee. The employee must confirm the pin before saving. Editing the address, changing branch/customer, moving the pin, or leaving the page invalidates obsolete results and fee confirmations. No first result is selected silently.

Google session tokens are shared by suggestions and the selected place details, then renewed. Google result lists are not cached; this also prevents expired prediction IDs from breaking selection after editing back to an earlier query. The compact list identifies Google Maps and the map retains Google attribution. Saved confirmation does not trust browser-provided fees or route geometry: the server recalculates against branch settings.

## Google Cloud setup

The browser key is `MAP_BROWSER_KEY`, falling back to the existing `MAP_KEY`, read through Laravel configuration. In the project owning that key:

- Enable **Maps JavaScript API** and **Places API (New)**; keep Geocoding API enabled if used elsewhere.
- Enable billing and restrict the browser key to the APIs it needs and the website referrers `https://fasakhaninja.com/*` and, if used, `https://www.fasakhaninja.com/*`.
- Check the browser console’s error **code**, without copying the full script URL/key. Missing/invalid key, rejected referrer, disabled API and billing errors require their corresponding Cloud setting to be fixed.

The installer preserves configuration-cache mode. After changing environment values, rebuild a cache only if the installation already uses it; otherwise clear the cache. Reload the delivery form after updates.

Google failure presents an explicit setup message. The bundled Leaflet map remains available for manual pin selection; Google-derived results are not silently displayed on a non-Google map. A failed road request leaves the quote unconfirmed and never substitutes a guessed distance. Restaurant/location directory maps retain their local Leaflet integration and SPA stylesheet handling.

## Approved route provider

The owner approved disclosure of addresses/coordinates to Photon and OSRM on October 4 and explicitly requested Google address search on October 6. Address suggestions now go to Google, and confirmed branch/customer coordinates go to the configured OSRM endpoint. Names, phone numbers and order contents are not included. `PHONE_OSRM_URL` defaults to `https://routing.openstreetmap.de/routed-car`; approved deployments may use a compatible managed service. Public service availability is best effort.

The route provider retains bounded requests, validation, short locks, per-provider throttling and a 30-minute route cache. Route distance is rounded to metres and multiplied by the saved price with integer-cent rounding. The legacy Photon endpoint remains for compatibility but is not used by the delivery field.

`deployment/enable_phone_maps.sh` is retained for the already-approved OSRM setup; its historical preflight also tests Photon. It does not activate Google Cloud APIs, billing or browser-key permissions. `deployment/check_dashboard_runtime.php` reports browser-key presence, not live Google authorization.

## Verification

Browser fixtures exercise Google suggestions, mouse/keyboard selection, repeated queries, obsolete responses, pin/route/fee updates, explicit confirmation, actual local order saving and auth-failure fallback. Provider responses are synthetic and external network calls are blocked. Live address relevance and Firebase/Google credentials cannot be established without the production account/device.

Primary references:
- https://developers.google.com/maps/documentation/javascript/place-autocomplete-data
- https://developers.google.com/maps/documentation/javascript/reference/autocomplete-data
- https://developers.google.com/maps/documentation/places/web-service/policies
- https://developers.google.com/maps/documentation/javascript/error-messages
- https://project-osrm.org/docs/v5.24.0/api/
- https://routing.openstreetmap.de/about.html
