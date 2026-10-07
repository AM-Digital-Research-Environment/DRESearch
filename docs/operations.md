# Operations runbook

## Health and rebuilds

Admin → DRE Search shows connectivity, live document count, generation state,
duration, attempted/imported totals, pending queue counts, and dirty markers. Reindex one corpus for a localized change or all corpora after a
mapping/schema upgrade.

Only one rebuild per profile runs at a time. A second job exits with the active
job identifier. Cancelling a job stops work at the next checkpoint and removes
its unpublished staging collection only after verifying that no alias points to it.
If alias verification is unavailable, cleanup preserves the collection for a later recovery run.

## Failure triage

- `batch_import_failed`: inspect the bounded failed IDs/error summary, correct
  the mapper/schema mismatch, then rebuild.
- `document_count_mismatch`: compare the source query with import responses; the
  previous alias is still live.
- `rebuild_locked`: find the active job in Admin → Jobs before retrying.
- Pending changes: restore connectivity, then select **Retry pending changes**. Failed jobs retain their queue rows.
- Dirty/stale: dependency capture failed or visibility rules changed. Rebuild fully; public search stays paused until a successful rebuild clears the marker.
- Public errors include `X-Request-ID`; correlate it with the server log.

## Backup and rollback

Omeka/MySQL remains the source of truth. Typesense indexes are disposable, but
the immediately previous module-owned generation is kept. To roll back manually,
point the alias at `previous_collection`. Never delete collections by prefix.

## Secrets

Prefer environment variables in production. A blank admin API-key field leaves
the stored value unchanged; the clear checkbox removes it. Rotate the Typesense
key at the server and module together.

## Upgrading to 1.22.x

Upgrade from 1.21.x straight to **1.22.1** or later. The 1.22.0 migration looked
up module services that Omeka does not register while a module awaits its
upgrade, so Omeka's upgrade button failed and left the module inactive. If a
site is stuck on 1.22.0 in "needs upgrade", installing 1.22.1 and pressing
**Upgrade** again completes it; the migration is idempotent.

Install the complete DRESearch.zip (Composer dependencies and compiled assets
together), then run Omeka's module upgrade. The migration adds the durable queue, shared cache and
dirty-revision column and marks all profiles dirty. Select **Reindex all corpora**
before reopening search: older generations can contain private metadata that the
new mapper now excludes. Pending changes are replayed into the new generations.

The background job runner must be operational. Inspect failed jobs under
**Admin → Jobs** and use **Retry pending changes** after fixing connectivity.
A stopped worker or an unavailable Typesense server leaves public search paused
for the affected profiles. Direct SQL imports bypass Omeka events and must be
followed by a full rebuild. A first build is required for every enabled profile.

Guzzle limits connection establishment to 2 seconds and each request to 10
seconds; automatic SDK transport retries are disabled. Queue jobs provide the
retry boundary for writes. Large installations should measure import batch time
before changing these limits.

Rollback collections may predate privacy edits. Rebuild from current Omeka data
before using an older generation after visibility changes. The module does not
rewrite retained historical collections.
