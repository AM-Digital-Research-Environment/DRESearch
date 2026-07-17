# Operations runbook

## Health and rebuilds

Admin → DRE Search shows connectivity, live document count, generation state,
duration, attempted/imported totals, and whether incremental activity marked a
profile stale. Reindex one corpus for a localized change or all corpora after a
mapping/schema upgrade.

Only one rebuild per profile runs at a time. A second job exits with the active
job identifier. Cancelling a job stops work at the next checkpoint and removes
its unpublished staging collection.

## Failure triage

- `batch_import_failed`: inspect the bounded failed IDs/error summary, correct
  the mapper/schema mismatch, then rebuild.
- `document_count_mismatch`: compare the source query with import responses; the
  previous alias is still live.
- `rebuild_locked`: find the active job in Admin → Jobs before retrying.
- Dirty/stale: incremental refresh exceeded its cap or failed. Rebuild fully.
- Public errors include `X-Request-ID`; correlate it with the server log.

## Backup and rollback

Omeka/MySQL remains the source of truth. Typesense indexes are disposable, but
the immediately previous module-owned generation is kept. To roll back manually,
point the alias at `previous_collection`. Never delete collections by prefix.

## Secrets

Prefer environment variables in production. A blank admin API-key field leaves
the stored value unchanged; the clear checkbox removes it. Rotate the Typesense
key at the server and module together.
