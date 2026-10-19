The contract PDF checks use the original Laravel application, login controller,
permission middleware, contract model, Blade view and Snappy implementation.
They create a unique disposable MariaDB database from the 106 inspected table
shapes, then add the existing three journal tables. All rows are synthetic.

Run the portable route/render regression:

```text
php tests/desktop_dashboard_runtime/contract-pdf-http.php application maria-port artifact-directory render source-revision
```

Run the native smoke on Windows against an untouched generated bundle, preferably
under a directory containing spaces:

```text
php tests/desktop_dashboard_runtime/contract-pdf-http.php application maria-port artifact-directory windows source-revision
python tests/desktop_dashboard_runtime/contract-pdf-parser-test.py
python tests/desktop_dashboard_runtime/verify-contract-pdf.py artifact-directory source-revision
```

The artifact directory must exist. The Python parser is a test-only dependency
installed from `requirements-pdf-smoke.txt` with pip's `--require-hashes` and
`--only-binary=:all:` options. It is not included in the application runtime.

`receipt.json` records source/helper hashes, actual HTTP attempts, scoped and
permission denials, template/rendered-text hashes, database cleanup, unchanged
table hashes, an empty journal and zero external-asset canary requests. Windows
mode additionally verifies the bundled executable's policy hash, binary hash and
native version, and downloads the PDF through the original signed-in HTTP route.
It retains `contract_100.html` and `contract_100.pdf`. The separate strict parser
checks the PDF page tree and extracts the saved English, Arabic and literal
placeholder text, then writes `parsed-pdf-receipt.json` bound to the HTTP receipt.
CI binds all helpers to the exact runtime Git revision; local runs explicitly
report their working-tree helper binding.

The first retained Windows PDF (workflow `39319991407`, artifact `12219409268`,
source `6f41acdf348ebbbd89238cc0ca77620b674e0178`) completed all 61 HTTP checks.
Its unchanged PDF SHA256 is
`2f08236da5fd14a0f6b93dfcd3350387bda29f1f7077f3511ae34fd1a3a2a960`.
Poppler rendering showed one readable A4 page with connected Arabic and no
clipping. The embedded Arial font's ToUnicode map describes the initial yeh in
the original Arabic `تجريبي` as U+FBFE, which NFKC decomposes to Farsi yeh U+06CC.
That extraction initially failed the exact Arabic phrase comparison despite the
correct visible glyph.

For this pinned native generator, the parser projects only extracted U+FBFE and
U+FBFF to Arabic yeh presentation forms U+FEF3 and U+FEF4 before NFKC. This is a
glyph comparison rule, not canonical equivalence or a general Farsi character
fold. [Unicode 17 section 9.2.4, Table 9-10](https://www.unicode.org/versions/Unicode17.0.0/core-spec/chapter-9/)
specifies that initial and medial Farsi yeh glyphs match Arabic yeh, while their
isolated and final forms differ. The
[Unicode character database](https://www.unicode.org/Public/17.0.0/ucd/UnicodeData.txt)
retains their distinct base characters. The font subset does not retain the
original cmap; the source-bound Arabic HTML, observed ToUnicode entry, rendered
glyph and Unicode shaping specification establish the narrow comparison rule.
The parser requires both exact Arabic phrases in the hash-bound original HTML
before applying it and records each projected glyph in the parsed receipt.
Raw U+06CC and isolated/final U+FBFC/U+FBFD remain distinct and fail the Arabic
phrase checks. Unit controls cover those exclusions and missing or replaced
Arabic. Disposable copies of the actual PDF with each such content mutation
also failed after rebinding their test receipts; the original PDF was unchanged.
This inspected artifact and local parser correction do not replace a new full
Windows CI run with the corrected source-bound helpers.

Contract records contain saved global templates (`type`, `template`, `added_by`),
not populated vendor/date contract instances. Preview and PDF preserve literal
placeholders, strip stored markup into escaped text, and retain block/line breaks.
No contract data or journal operation is written by either read route.

The route requires `contract-list`, matching `contracts.show`. It retains the
existing model scope: AdminScope restricts JSON/API/chat requests; ordinary HTML
continues to display shared templates as the current show route does. The test
therefore proves foreign-template 404 with JSON reads and separately checks the
existing ordinary-HTML behavior. It does not claim broader owner isolation.

These checks leave `fullDashboard=false`. PDF text extraction does not certify
physical printer behavior or visual Arabic typography on every installed font.
