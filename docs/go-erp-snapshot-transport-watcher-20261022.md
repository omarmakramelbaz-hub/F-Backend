# Preserve the reviewed numeric snapshot transport in automatic releases

The Windows repair core is `a0178ead801e8d39185cd0830171804141767995`
(preview.25). Its policy-only seed is
`52f7f5d22e5a2026e5bd5732bbb7893125b6ef9d`. The previous seed remains
`7523ff6888a6e3d0a6f5b7712c553909f12c043b`.

The package retains the exact capacity-watcher utility and adds a seed-only
migration. Only the source producer and worker differ in the new five-file
runtime. They preserve the new seed's independent candidate proof, agree on its
exact policy identity, and retain the original capacity and historical cases.

Run the pinned migration package as root on srv1836524. It requires the exact
previous runtime, service unit, clean checkout, existing watcher lock and
compatible private state. It stages the public seed and validates its immutable
core proof before switching active paths. The exact staged verifier alone may
be normalized from 0600 to 0644 after its identity and Git blob are checked;
the surrounding staging directory stays private.

The service unit changes first and its new runtime is reloaded before any seed
paths change. The new runtime supports the old seed during that transition.
The private checkout and state follow; configuration changes last. The token,
publication history, dependency guard, sequence floor and all existing higher
version/counter values are retained. The minimum version floor becomes .25.
Only capture state tied to the old seed is retired. Application and database
contents are unchanged.

Private backups and an inode-bound intent support exact rollback after failure
or interruption. Unexpected concurrent state is retained and requires review;
a retry never overwrites higher state with an older backup. Normal repeated
invocation verifies the current state without resetting later captures.

The migration receipt is not Windows offline readiness. Install the matching
tested repair over the existing Windows installation, prepare online, then
verify the completed preparation before a disconnected read test.
