# Isolated Laravel 12 original-suite compatibility pilot

The actual Linux original core and legacy suites completed with158 and722 checks respectively (880 PASS lines, exit0). This pilot fixes three failures observed against the actual candidate dependencies. It keeps the production Composer inputs and the original Laravel 8 fixtures unchanged. Application migration, application validation, accepted release and full dashboard acceptance remain false.

The candidate manifest SHA256 is `c7f7f6ac9b766b9282be10dacfbc25c367600ad550488802b24e75b4dfc122c1`; the unchanged lock SHA256 is `f0fe7a09c3736dac4cddf120f14f9ca3a5c6553a1ff7ecc2e37f4be0e5cbee75`. A strict fresh Composer 2.10.3 installation used independent home/cache directories, no updates, no scripts, no ignored platform requirements and no audit exceptions. All139 actual installed/autoloaded versions match the lock. Linux PHP8.3.6 passes all21 actual platform requirements; the locked audit has zero findings. This is distinct from the earlier Windows PHP8.2.34/MariaDB11.4.13 bounded login-and-roles diagnostic.

## Observed application failures and narrow fixes

| Actual failure | Fix | Evidence |
| --- | --- | --- |
| Laravel12 validation omits the envelope's unlisted `payload.facts`, so shift closing reconciliation rejects its required source facts after101 core checks. | Declare `payload.facts` as `nullable\|array`; preserve typed facts without enabling unrelated unvalidated keys. | Actual old8/new12 validator comparison and the subsequent core158 checks. |
| Spatie6 treats the original checkbox string permission IDs as permission names; original role creation returns500 for permission named `5`. | Both original role controller calls resolve nonempty validated strings to configured Permission objects with the actual role guard. Numeric lookup uses the original weak integer argument coercion; other strings use explicit name lookup. Existing validation and v6 resolve-before-detach behavior remain. | Sixteen actual old8/v6 resolution cases, including raw zero, fractional zero, overflow, numeric-name collisions, UUID/ULID-shaped names, guards and missing identities. Candidate cases invoke the actual controller helper. Actual original authenticated HTTP create/update302, collision identity preserved, invalid identity500 with existing grants retained, unchanged invalid form validation302. |
| An original duplicate Arabic `array` translation overwrites its scalar with an array. Laravel12 `str_contains` throws; malformed drag row returns500. | Remove only the later three-line duplicate, retaining the existing intended Arabic string and every ordering validation rule. | Same real authenticated malformed-row request becomes422 with string Arabic errors and unchanged order; valid original ordering remains200. |

## Separate fixture target

`.github/scripts/laravel12-full-suite-fixtures.cjs` copies frozen original fixtures from `e62516840259cc85ea168b7245bcd5e91909ce78` into a new directory outside the repository/application. It rejects an unfrozen checkout, changed source/dependency bytes, changed original fixtures, missing actual classes or a graph other than the exact139 locked packages before writing the private target.

Only the private copies receive these explicit pinned12 setup changes:

- The existing exact framework assertion expects12.69.3 while retaining the original minimum565 routes. The original source/default8 assertion remains8.83.29.
- Three real login transitions refresh CSRF from the authenticated original rendered page/form because Laravel12 regenerates the login token. Requests still use actual original authentication, cookies and CSRF validation.
- All22 original delete-form DOM comparisons retain their old path/query/style/method/current-token baseline, with exactly the `autocomplete="off"` attribute added by the installed12 `csrf_field` helper. The production renderer remains unchanged.

The remote-owner token setup uses the existing original `/admin/products/create` form. An exploratory added `/admin/categorys` read returned500 because late synthetic collision rows have NULL `created_at` and the original list view calls `diffForHumans()` on null. That original read limitation was retained; neither timestamps nor that view were changed to make this compatibility suite pass.

Example preparation after checking out the frozen pilot source and preparing its isolated original app with the candidate dependencies:

```sh
node .github/scripts/laravel12-full-suite-fixtures.cjs REPOSITORY APPLICATION PRIVATE_FIXTURE_DIRECTORY SOURCE_COMMIT PHP_EXECUTABLE
python3 PRIVATE_FIXTURE_DIRECTORY/tests/desktop_dashboard_runtime/native-run.py MARIADB_EXTRACTED_ROOT PHP_EXECUTABLE APPLICATION
```

The original native runner uses a fresh private loopback MariaDB instance and retains its cleanup/completion guards. Preparation alone does not execute or accept the suites. The accompanying evidence JSON records exact source/fixture/log bindings and actual completed counts.

## Acceptance limits

The pilot uses synthetic fixtures and original real services/controllers. No Firebase/provider credentials, provider fakes or provider bypasses are supplied. Credential-dependent `route:list` remains outside this proof; the old plural `role` and `role_or_permission` aliases are not exercised. No browser module is enabled in this Linux run. Existing Windows bounded-bootstrap proof covers the preceding source, not these three new compatibility patches. A Windows execution of this patched full-suite target, production dependency migration, provider flows, broader routes, browser/installer acceptance and release approval remain separate gates. Original-suite completion does not imply full dashboard coverage.
