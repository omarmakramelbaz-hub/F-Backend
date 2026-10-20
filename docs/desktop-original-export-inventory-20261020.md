# Original export inventory and engine compatibility

This inventory concerns the captured dashboard source, not features added for the
desktop port. The Windows dependency graph remains unchanged and has a genuine
zero-advisory Composer audit. No Excel package or parking-domain schema is added.

## Inactive inherited XLSX actions

The following three GET routes are registered in `routes/admin.php` and call
`ReportController`, but their captured implementations cannot run on either
reviewed engine:

| Route name | URL after `/admin/` | Export class | Missing model |
| --- | --- | --- | --- |
| `exportPackage.excel` | `reports/packages/export/excel` | `ExportPackages` | `App\Models\UserPackage` |
| `exportTicket.excel` | `reports/tickets/export/excel` | `ExportTickets` | `App\Models\Ticket` |
| `exportTrackers.excel` | `reports/valet-trackers/export/excel` | `ExportValetTrackers` | `App\Models\ValetTracker` |

All three export classes import `Maatwebsite\Excel` concerns. Neither the original
server Composer configuration nor the desktop runtime requires that package.
The actual bundled autoloader has neither its facade nor `FromCollection`.
The three models are also absent from the captured source, so installing Excel
would not make those actions functional. No current rendered report template
links to these three route names.

The orders report's `exportorder.excel` link is inside a Blade comment and no such
route exists. FAQ and restaurant export links are likewise commented out; their
named routes and `ExportQuestionAnswer` / `ExportResturant` classes are absent.
The common button group's Excel/PDF/CSV/print controls are one Blade comment;
only its bulk-delete control is rendered. These inherited actions are excluded
from usable active-feature coverage. This is an original-source limitation, not
a regression introduced by the Windows engine upgrade.

`ExportPackages::map()` still uses Carbon 2's default absolute day difference,
but is behind the absent model/package path above. It is deliberately left
unchanged rather than claiming a reconstructed parking export is the captured
restaurant feature.

Official package metadata was reviewed: Laravel Excel 3.1.70 supports Laravel
8 through 13 / PHP 8 and receives CVE fixes; 4.x requires PHP 8.3. PhpSpreadsheet
5.10 supports PHP 8.2. Those facts establish available options, but no package
was installed because the current active dashboard has no working server-side
XLSX action to port.

## Active original reports and downloads

| Original action | Format / implementation | Engine compatibility and proof |
| --- | --- | --- |
| `reports.index`, `reports.orders`, `reports.customers`, `reports.vendors`, `reports.delegates`, `reports.resturants` | Original HTML views and queries | Orders use explicit Sunday/Saturday period boundaries and `OperatingDay` cutoffs; no signed Carbon difference. Other report queries use `whereYear`/SQL month grouping and unchanged money arithmetic. |
| `branch-expenses.export` | UTF-8 BOM CSV via `streamDownload` / `fputcsv` | Actual unchanged expenses Feature suite passes on both 8.83.29 and 12.70.0, including Arabic category output and formula-prefix escaping. The period day count explicitly preserves integer absolute Carbon 2 semantics. |
| `employees.export` | UTF-8 BOM CSV via `streamDownload` / `fputcsv` | Uses original BranchPayroll statements, current salary/hours/net/wallet columns and formula-prefix escaping. Payroll's inclusive day count explicitly preserves integer absolute Carbon 2 semantics; actual core financial checks pass on both engines. |
| `download-pdf`, `print-pdf` | Original order invoice/receipt Blade and Snappy PDF | Uses the bundled Barryvdh/Knp implementation and pinned native Windows wkhtmltox; no Carbon signed-difference call. Combined final Windows acceptance remains mandatory. |
| `download-daily-report-pdf` | Original vendor/delegate daily/month/year HTML or Snappy PDF | Existing `whereDay`/`whereMonth`/`whereYear` filters use ordinary current time helpers; no Carbon signed-difference call. No date-filter business rule is changed by the engine bridge. |
| `pdfview-contract` | Original authenticated contract HTML / Snappy PDF | Existing source-bound HTTP fixture checks actual models, permission revocation and installed Snappy classes. The Windows workflow requires an actual generated PDF plus independent parsed Arabic/content/font proof. It must run on the final combined revision. |

The original date-selection rules (including existing day/month query scope) are
preserved. No speculative correction to historical business filters is included
in the runtime security change. A complete release claim still requires the
final combined Windows workflow and the parent project's active-feature review.

Primary references:

- <https://packagist.org/packages/maatwebsite/excel>
- <https://docs.laravel-excel.com/3.1/getting-started/installation.html>
- <https://phpspreadsheet.readthedocs.io/en/latest/>
