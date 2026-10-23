# Synthetic PHP numeric transport reproduction

`deployment/diagnose_go_erp_numeric_transport.php` runs constant synthetic values through the actual `DesktopDashboardBootstrap::json` encoder. It never boots Laravel, connects to a database, reads account rows, or changes application code or persistent settings. Its 11,132-row case is synthetic and does not establish the cause in the real account.

Run with the PHP executable being investigated:

```sh
php deployment/diagnose_go_erp_numeric_transport.php
```

The script changes `serialize_precision` only while comparing each synthetic producer/consumer pair and restores every changed INI value. It also compares streamed per-row hashing with whole-table encoding. `--fixture-wire` emits a small constant-only fixture for the real JavaScript raw-download regression.

| Producer `serialize_precision` | Consumer | Observation |
| --- | --- | --- |
| -1 | -1 | All fixture table hashes match |
| 17 | 17 | All fixture table hashes match |
| 14 | 14 | All fixture table hashes match |
| -1 | 17 | Float 0.1, a float32-derived 0.9, exponent and synthetic users hashes differ |
| 17 | -1 | The same four fixture hashes differ |

Decimal strings, negative zero, integral float types and a large integer remain intact on the raw PHP wire. Changing generic `precision` with `serialize_precision=-1` does not change the fixture JSON. Changed row contents are still rejected by the existing hash comparison. No checks are bypassed.

The matrix demonstrates a possible cause of import line 46, not a measurement of web PHP or the desktop's retained PHP configuration. The older JavaScript transport issue is already fixed in Preview 31; the new regression distinguishes it from PHP-to-PHP re-encoding differences.

## Actual request diagnostics

The diagnostic changes add optional `transport_php` metadata at the real snapshot export return: capture scope, SAPI, PHP version, `serialize_precision`, and `precision`. These five strings describe the process executing the export request. No encoding setting, table hash, schema hash, or catalog hash changes. The metadata does not depend on a CLI configuration probe.

On import failure, `desktop/import.php` selects the first trace frame under the trusted application's `app/Services/Dashboard` directory. It reports only the basename, bounded line, exception class/status, and an exact reviewed fixed abort message when present. Raw exception text, arguments, paths, rows, SQL and credentials are omitted.

If export PHP metadata is valid, the same error includes its settings alongside settings read from the running desktop import process. Both PHP and native code reconstruct only approved fields. Unsupported or malformed metadata is omitted. A CLI export is labelled as a data source, never as the website. Extra input fields and private exception messages are tested for suppression.

The private FPM probe is supplemental: its output explicitly says the application entrypoint is unverified. It is not a substitute for actual request metadata. The Windows settings probe reads the pinned retained Preview 31 runtime with the generated import INI and scan directory; it does not start the dashboard, connect to the database, or change a profile.

These are source changes for a coordinated future server/runtime release. They are not installed on the user's server or desktop by committing this branch. Adding the desktop helper and export metadata changes the application source fingerprint; do not replace live files independently of the matching reviewed runtime. Actual website/device readings and the live failing check remain pending a matching diagnostic build and another preparation attempt.

Do not change the shared JSON helper or server-wide PHP settings based solely on this reproduction. The helper is also used outside snapshot transfer, and existing catalog coverage and immutable records can depend on historical encoding. A prospective transport change needs an explicit compatible boundary and real failure evidence first.

Primary references:

- [PHP json_encode](https://www.php.net/manual/en/function.json-encode.php): float encoding depends on `serialize_precision`.
- [PHP core INI directives](https://www.php.net/manual/en/ini.core.php): its default is -1, it is configurable, and loaded INI values can differ from defaults.
- [PHP 8.1 MySQL PDO changes](https://www.php.net/manual/en/migration81.incompatible.php): native integer/float result types may be returned by the driver. The live schema and fetched value types still need inspection.
