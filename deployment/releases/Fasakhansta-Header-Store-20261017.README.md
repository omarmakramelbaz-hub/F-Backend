# Store selector beside the saved address

Source: `f4fce216692a0ca0bc4eee7c8f442276a940704d`. Required baseline: `b2ec35c91224af06bf1c8f9c2f0e54402f2291f9`.

Moves the existing store selector into the header beside the saved-address selector; removes its old menu position. Keeps its node ID and event wiring. Readable navy-header colors and responsive adjacent controls. Only home/CSS change on installation, with reviewed source checks, independent SEO merge, backups and rollback.

Validation: existing full DOM interaction suite,18 existing installer tests, compiled Blade/PHP syntax, archive/source checks and preserved SEO through upgrades/rerun. No customer orders submitted.

SHA-256: `4c1e34d5fb160096b6a450beb1e5bf7d08ee8076ec6a45dbbe32b27e885c3a06`. Archive: 39349 bytes.

Extract outside the public root; `bash INSTALL.sh --check` then `bash INSTALL.sh`. Success: `WEB_CUSTOMER_ADDRESSES_READY`. Uploading the package does not install it.
