The connected-loopback certificate and its deliberately public test private key
belong only to the disposable Windows Electron HTTPS acceptance fixture. That
isolated session pins the exact certificate fingerprint and loopback hostname.
These test files are excluded from the packaged application's `src` file set.
