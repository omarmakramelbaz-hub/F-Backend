# Independent owner dashboards

The primary owner (persisted users.id=1, admin, accepted, central installation)
manages /admin/owners. Creating an owner queues an empty, independent business
dashboard. Other central admins and every tenant account receive 403 from this
control plane regardless of role grants.

Each dashboard has its own MySQL database/user, HTTPS origin
(go-<random slug>.<primary domain>), document root, application/JWT signing keys,
config/routes/provider caches, sessions, media, browser storage, jobs, settings
and integration credentials. Its local owner is user ID 1 with all local business
abilities, and can create managers, vendors, restaurant owners, restaurants,
branches and business records. Global owner management uses a separate check
against the persisted primary owner.

Only schema is cloned. The only copied rows are migration bookkeeping and the
permission vocabulary/canonical role templates. General settings use neutral
defaults. No existing business account, branch, customer, financial row, stock,
media, enrollment, OAuth connection, payment credential, email transport or
Firebase credential is inherited. Shared links target application code and an
allowlist of static assets. Primary-business stock/delivery mappings and one-off
data release schedules do not execute in tenants.

## Deployment and lifecycle

The pinned root launcher applies unique bounded fragments and new files,
preserving unrelated live changes. It lints candidates and retains code backups
outside public_html. Root worker code and state live under
/usr/local/lib/go-owner-dashboards and /var/lib/go-owner-dashboards. The website's
SQL account receives no global CREATE DATABASE/USER privileges.

The worker creates a database-specific SQL account, maps permanent databases and
SQL users into cPanel, and provisions a separate subdomain/document root. It checks
real HTTPS with certificate validation and a random static document-root proof
before publishing a login link. DNS/AutoSSL delays retain a blocked pending
dashboard. There is no central-database or shared-browser-origin fallback.

Primary edits, suspension and deletion write an immediate revision marker. An
older worker cannot clear a future marker even before the primary transaction
commits. SQL acknowledgement is conditional on revision. Interrupted publication
can resume. An activated database is never rebuilt during retries.

Deletion blocks access and archives business data, as described in the UI.
Suspension revokes remembered tokens and sessions; JWT credentials rotate before
reactivation. Application encryption keys stay stable to preserve encrypted
provider secrets. New passwords remain hashed. Existing central admin 635 keeps
its phone; this release does not move that identity to the primary owner.

The worker runs every 15 seconds. Queues and schedules execute in fresh isolated
PHP processes. New tenants do not inherit native desktop enrollment; independently
scoped native desktop integration is a separate change.

## Verification

Local tests use real Laravel 8 database, auth, sessions, validation and Blade with
separate SQLite databases and keys. They cover primary-only management, empty
starts, credential/branch/file isolation, encrypted settings, rendered forms,
source drift, owner protection and revision races. Existing owner phone/login
regressions also run.

Before enabling its timer, installation provisions two explicitly marked
throwaway dashboards outside the real owner registry. On the actual server it
checks empty home rendering, creates a manager/vendor/restaurant owner/branch
through real repositories, authenticates staff, verifies SQL reads and writes to
the other tenant and primary database are denied, checks separate cPanel virtual
hosts and genuine CSRF login forms, and tests suspend/resume without data loss.
Fixture subdomains, databases, SQL users and files are removed. A failure rolls
back source and worker/service payloads.

## Requirements and operations

Root MySQL CLI authentication, cPanel subdomain APIs and the primary domain are
required. Real owner activation also requires public DNS and valid HTTPS for its
new subdomain. The primary owner page reports a pending domain/SSL state until
ready; certificate verification must never be bypassed.

Server backups must include /var/lib/go-owner-dashboards,
/home/fasakha/go-owner-dashboards, the cPanel account and its mapped databases.
Control records and runtime environments contain secrets and are not support
report attachments.

Application source is shared. Later schema/code releases must include compatible,
reviewed tenant migrations before publishing dependent code. Do not replay
primary-business data migrations across tenants. This release validates the live
schema and refuses unsupported required user columns, cross-schema foreign keys,
views and triggers.
