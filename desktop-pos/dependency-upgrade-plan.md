# Candidate dependency resolution, not a release

This isolated branch prepares runtime constraints for Composer resolution. It
does not update the application, the root composer.json, composer.lock, the
installer, the offline coverage, or any release acceptance gate. The existing
lock intentionally remains the old baseline and must not be used to install
this candidate manifest.

Baseline: 889e16ed6ead38fd4b377d86b746420bf55f54a3. The runtime lock SHA256 is
319ea5f627a46681c45e57c4e780f2d1c19a80f0272711ccf770feb3716d18a7.
Its no-dev audit reported 17 advisories across Guzzle, Protobuf, Laravel,
Flysystem, and MediaLibrary. Five abandoned packages are a separate finding.

The candidate sets lower bounds at Guzzle 7.15.2, Protobuf 4.33.6, Laravel
12.69.0, Flysystem 3.35.3, and MediaLibrary 11.23.0. These are intended to leave
all affected version ranges in that audit. A newly resolved graph still needs
a fresh audit; the constraints are not evidence that it passes.

Related constraints were checked against published Composer metadata:

- Gax 1.31.0 accepts Protobuf 4; 1.30.0 still restricts it to 3. Gax also needs
  grpc-gcp 0.4 or later within its compatible range.
- Guzzle 7.15.2 needs Promises 2.5.1+, which conflicts with Firebase SDK 5's
  Promises 1 restriction. Firebase SDK 7.24.1 and Laravel Firebase 6.2.0 accept
  PHP 8.2, Laravel 12, and Promises 2. JWT Auth 2.3.0 accepts the SDK's JWT 5.
- Viewable stays on 7.1.1+ within major 7. Major 8 requires PHP 8.5/Laravel 13.
- Translatable 11.17.1, Snappy 1.0.5, Debugbar 3.16.5, Permission 6.25.0,
  Settings 3.9.0, and Location 7.7.0 accept the candidate Laravel/PHP versions.
- Framework 12 requires Carbon 3, Symfony 7.2+, and Monolog 3. The resolver
  must select compatible transitive versions; none have been manually patched.

## Current resolver evidence

The local command was:

```text
composer update --dry-run --no-install --no-scripts -W --no-interaction --working-dir=desktop-pos/runtime
```

It exited 2 because the Linux PHP wrapper did not load GD or EXIF. Loading the
already present EXIF module normally removed that problem; the second run
still exited 2 solely because Simple QRCode 4.2.0 requires GD and no local GD
module was available. This was a local probe-platform block, not an
application package conflict. No ignore-platform or security-blocking override
was used.

The published candidate at 842bd0d558b9223ee068f3fba2007fbc8b2fc821 resolved
successfully on real Windows PHP 8.2.34 with all eleven extensions and Composer
2.10.3. Run [38002142197](https://github.com/omarmakramelbaz-hub/F-Backend/actions/runs/38002142197),
job 114062487100, completed the resolver at 2026-10-09 23:00:18 UTC. It proposed
21 package additions, 52 updates, and 18 removals. The unchanged-lock and
absent-vendor assertions both passed. Its selected versions included:

| Package | Selected version |
| --- | --- |
| laravel/framework | 12.69.3 |
| spatie/laravel-medialibrary | 11.23.9 |
| league/flysystem / league/flysystem-local | 3.36.0 / 3.35.3 |
| guzzlehttp/guzzle / guzzlehttp/promises | 7.15.5 / 2.5.3 |
| google/protobuf / google/gax / google/grpc-gcp | 4.33.6 / 1.51.0 / 0.4.2 |
| kreait/firebase-php / kreait/laravel-firebase | 7.24.1 / 6.2.0 |
| tymon/jwt-auth / lcobucci/jwt | 2.3.0 / 5.6.0 |
| nesbot/carbon / monolog/monolog | 3.14.2 / 3.12.1 |
| spatie/image / maennchen/zipstream-php | 3.9.7 / 3.1.2 |
| symfony/http-kernel / symfony/mailer | 7.4.20 / 7.4.19 |

The setup action unexpectedly inherited COMPOSER_NO_AUDIT=1 into this first
Windows run. This suppressed the post-update audit; the run is evidence of
dependency resolution, not a zero-advisory audit. The workflow now explicitly
removes that inherited environment variable and verifies its absence before
Composer starts. The corrected workflow still needs a separate successful run.
No application has been installed or migrated, no new lock has been written,
and no zero-advisory or release-acceptance result has been obtained. An audit
of the unchanged baseline lock would still describe the old dependencies and
cannot validate this candidate graph.

The separate Windows-only probe workflow uses real PHP 8.2.34 and the original
eleven extensions. The manifest retains its conservative platform.php 8.2.33.
It runs only the dry-run resolver, verifies the existing lock stays unchanged,
and rejects any unexpected vendor directory. It does not build an installer,
run an application install, or authorize a release. It is restricted to this
planning branch and its own workflow/runtime-manifest paths.

## Required application migrations before implementation can be accepted

- Replace Fideloper TrustProxies in app/Http/Middleware/TrustProxies.php and
  Fruitcake HandleCors in app/Http/Kernel.php with the framework middleware.
  These old packages have been removed only from the candidate constraints;
  the application code has not been changed.
- Replace Collective Form/Html calls while preserving their rendered forms,
  methods, routes, CSRF, and permissions. There are 44 calls across 19 current
  admin views, including roles, products, contacts, contracts, and restaurants.
  Collective 6.4.1 supports Illuminate only through 10 and cannot resolve
  with Laravel 12. Its removal from the candidate does not fix these views.
- Migrate and exercise MediaLibrary configurations, persisted media records,
  uploads, conversions, URLs, and branch permissions across its major changes.
  Its traits are used by many original application models.
- Review FirebaseServiceProvider, FirebaseNotificationService, and FcmFirebase
  against SDK 7; verify actual messaging contracts and existing notification
  behavior. Updating constraints alone does not migrate the integration.
- Exercise mail delivery after the framework moves from SwiftMailer to Symfony
  Mailer, plus existing Carbon date handling, settings, JWT authentication, and
  permission checks. The source scan found no direct SwiftMailer-specific calls
  and no Spatie Image Manipulations constants; this does not replace tests.
- Regenerate a resolved lock only after real platform resolution succeeds,
  audit it without ignores, then run the original dashboard and Windows native
  recovery checks against that exact source and dependency fingerprint.

Do not merge this preparation into the installer branch or mark dependencies
reviewed until the graph, application migrations, and acceptance tests pass.
