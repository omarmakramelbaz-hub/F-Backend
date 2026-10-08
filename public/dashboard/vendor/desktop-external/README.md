# Original dashboard offline layout assets

These files are public third-party resources already referenced by the original dashboard. Only desktop local mode uses this mirror; ordinary server pages retain their original resource URLs. No account data or application credentials are included.

`manifest.json` records each original URL, resolved public source, original and cached SHA-256 hashes and byte size. Google Fonts CSS changes only its font URLs to local relative paths. Other stylesheet contents keep their original relative paths, including the original Font Awesome 6.5.2 integrity hash. The unversioned SweetAlert URL resolved to 2.1.2 and is pinned by its recorded bytes.

CKEditor is the original 4.14.0 standard distribution, including its skin, plugins, dialogs and Arabic/English translations. Its complete upstream license is retained at `cdn.ckeditor.com/4.14.0/standard/LICENSE.md`. The archive source and hash are recorded. All other upstream licenses and their sources/hashes are in `licenses/` and the manifest. Original file copyright notices are retained. Toastr 2.1.0's original notice credits John Papa, Hans Fjällemark and Tim Ferrell (2012–2014); its MIT license text is also retained from the pinned upstream repository.

Regenerate deliberately with `python desktop-pos/fetch-dashboard-assets.py`. Existing hashed assets are reused, and a changed pinned CKEditor archive is rejected. Packaging verifies all cached files and licenses and rejects an incomplete mirror.

This cache covers static resources in the original admin layouts, catalog, subscribers, application order cards and reports. It does not provide customer-uploaded media, remote map tiles, or an offline replacement for Firebase/Pusher delivery, external payment services or spell-check providers. The full offline dashboard remains under development.
