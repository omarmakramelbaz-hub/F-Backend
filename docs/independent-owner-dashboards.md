# Independent owner dashboards

Requested behavior:

- The primary owner manages new owner accounts, including create, edit, suspend and delete.
- Each new owner starts with an empty business dashboard. No branches, managers,
  restaurant owners, stores, customers, stock, financial entries or orders from an
  existing dashboard are copied into it.
- An owner has all business permissions in their own dashboard and can create
  managers, restaurant owners, restaurants and branches there.
- Owners and their staff cannot list, search, export, edit, delete or directly
  address accounts or business records belonging to another dashboard.
- Managing global owners is reserved for the primary owner. Giving a tenant owner
  all local business permissions must not grant access to the global owner registry.

## Current application boundary

`users.account_type=admin` currently points at the common application database.
`added_by` records creator provenance, not a complete ownership boundary.
`AdminScope` runs only on selected user queries, and many reports and financial
services use `DB::table` directly. The existing desktop workspace feature narrows a
desktop device's grants within an existing installation; it does not create an
empty independent business. Neither mechanism alone implements this requirement.

## Proposed isolation boundary pending host evidence

Prefer separate application data and database credentials for each dashboard,
including separate media storage, cache, sessions, queued jobs and integration
credentials. A new owner must never inherit a central API key, payment provider,
Facebook/WhatsApp connection, Firebase credential, media path or desktop enrollment.
The primary owner's control plane manages owner identities and provisioning state;
tenant applications cannot connect to that control plane.

Before choosing a provisioner, `deployment/run_inspect_owner_dashboards_root.sh`
reports database provisioning privilege *booleans*, cPanel read access/database
quota, domain availability, database schema inventory and runtime drivers. It does
not create a tenant, install a cron job, change source, reset a password or move a
phone. A database account limited to its current schema requires a host-side
provisioner rather than granting database-user creation privileges to the web app.

Provisioning must use a clean schema plus reviewed framework/catalog prerequisites,
never a full live-data clone. Existing data remains associated with the primary
dashboard. Any existing account moved into a new dashboard needs an explicit,
separate migration decision; a blank dashboard request does not authorize moving
its current records or cascading their deletion.

Required verification before activation: two owners with separate new managers
and restaurants; empty starting metrics; full local business access; cross-owner
list/search/export/direct-ID reads and writes denied; independent caches/media/
sessions/jobs; suspended/deleted owner sessions revoked; primary-owner-only global
management; repeated provisioning/recovery without partial active dashboards.

## Reported owner phone conflict

The screenshot shows the phone layout and logical owner label applied. The current
save refuses a phone already attached to another dashboard-capable account. The
inspection reports only the primary owner and matching dashboard accounts, with
IDs, names, email, status and creator/restaurant/profile links. It does not select
password hashes or remember tokens, print the full mobile, or choose which existing
account should lose its login phone. Do not remove this collision check to make
the form appear to save successfully.
