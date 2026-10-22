# Account image capacity for offline preparation

The owner's read-only server report measured 1,073,721,364 accepted bytes against
the former 1,073,741,824-byte ceiling. All 118 rejected paths were readable files
with supported MIME and individual size; each exceeded the remaining aggregate
budget. They must retain their original bytes, rather than receive substitutes.

Preview 0.4.4-preview.22 raises the aggregate budget to 2 GiB consistently in
server export, native manifest validation and the packaged PHP import receipt.
The 16 MiB per-file ceiling, 20,000-file ceiling, scoped media authorization,
private attachment namespace, image MIME, Windows path/collision guards, exact
download hashes and generation activation checks remain in force. Downloads
stream one image at a time; the change does not allocate a 2 GiB image buffer.

Server export now reports `media-capacity-exceeded` for aggregate overflow.
The updated native preparation window explains capacity failures explicitly.
An incomplete media set still cannot activate an offline generation.

Install the matching tested Windows version under the existing Windows account
and application directory. Apply the matching PHP source with an exact original
hash check and private backup. A new preparation must complete before claiming
offline readiness. Actual account contents may change between checks.

The focused native and PHP receipt tests admit a complete set just above the
former one-GiB ceiling and exactly at two GiB, reject the first extra byte,
share the budget between public images and private attachments, and preserve
individual validation and no-fetch/no-write refusal. The existing full Windows
workflow also exercises the actual PHP receipt inside the original Laravel
application. It builds a fresh matching runtime and verifies installed Windows
operation and retained unsynced data before creating an update release receipt.
