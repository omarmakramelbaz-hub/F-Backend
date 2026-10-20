# Menu side image repair checks

Run `php tests/menu_side_image_repair_20261021/run.php` with PHP 8.0+.

The harness requires the actual repair runner and exercises its public `run()` method. Its fixture reproduces 32 branches, 64 release media records, the original private installation receipt, and real approved JPEG source files. Database, Spatie media models, Storage, and the HTTP verifier are isolated doubles; the Media double exposes the Spatie 9.6 `getPath()`/`getUrl()` contract and deliberately omits `getPathRelativeToRoot()`; copy operations and backup files use a real temporary filesystem.

Coverage includes read-only preflight; every destination copied and HTTP-verified before the first media update; unchanged source files, prices, menu identities and unrelated media; idempotence; invalid source hashes; destination collisions; HTTP 404/body mismatches; unexpected image derivatives; incomplete original scope; changed menu prices; and both committed/uncommitted transactions whose acknowledgement is lost.

These tests make no network calls, load no production `.env`, and do not replace live Laravel/Spatie/MariaDB integration or actual HTTP checks. The deployed runner performs the real HTTP verification before changing media references.
