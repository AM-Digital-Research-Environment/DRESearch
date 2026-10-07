# Operations runbook

## Health and rebuilds

Admin → DRE Search shows connectivity, live document count, generation state,
duration, attempted/imported totals, pending queue counts, and dirty markers. Reindex one corpus for a localized change or all corpora after a
mapping/schema upgrade.

Only one rebuild per profile runs at a time. A second job exits with the active
job identifier. Cancelling a job stops work at the next checkpoint and removes
its unpublished staging collection only after verifying that no alias points to it.
If alias verification is unavailable, cleanup preserves the collection for a later recovery run.

## Monitoring and the command line

`GET /dre-search/api/health` reports, without secrets or record ids, whether
Typesense is configured and reachable, each corpus' public state (`live`,
`hiding` pending records, or `paused`), the age of the oldest queued change and
whether the incremental worker is alive. It answers `200` while Typesense is
reachable and `503` otherwise, so an uptime monitor can poll it.

`bin/dre-search` operates the index from a shell, through Omeka's own bootstrap:

```sh
php modules/DRESearch/bin/dre-search status
php modules/DRESearch/bin/dre-search drain
php modules/DRESearch/bin/dre-search reindex research_items
php modules/DRESearch/bin/dre-search reindex --all [--allow-shrink]
php modules/DRESearch/bin/dre-search sync-stopwords
```

The web job runner normally applies queued changes within seconds, and search
traffic restarts a stranded worker. Where that runner is unreliable, a cron line
guarantees it (a no-op when nothing is queued):

```cron
* * * * * www-data php /var/www/html/modules/DRESearch/bin/dre-search drain --quiet
```

## Synonyms and popular searches

`data/synonyms.json` holds a Typesense synonym set (`dre_synonyms`) of place
names spelled differently across languages and periods. Every search refers to
it by name, so an edit takes effect once the set is uploaded — **Sync stopwords
and synonyms** on the maintenance page, `bin/dre-search sync-stopwords`, or any
full reindex — without a rebuild. Until it is uploaded, searches run without it.

Popular searches in the empty search box are off by default. To offer them, set
in `config/local.config.php`:

```php
'dre_search' => ['popular_searches' => ['enabled' => true, 'min_count' => 5, 'limit' => 5]],
```

They come from the popular-query analytics, which need Typesense started with
`--enable-search-analytics=true` and a persistent `--analytics-dir`, then
**Provision analytics** on the maintenance page. Only queries run at least
`min_count` times that found something are shown, and nothing shaped like
personal data (an e-mail address, a URL, five or more consecutive digits).
Raise `min_count` on a quiet site, where a handful of visitors' queries would
otherwise be on display.

## Failure triage

- `batch_import_failed`: inspect the bounded failed IDs/error summary, correct
  the mapper/schema mismatch, then rebuild.
- `document_count_mismatch`: compare the source query with import responses; the
  previous alias is still live.
- `rebuild_locked`: find the active job in Admin → Jobs before retrying.
- **Records hidden from public search**: their changes are queued and the worker
  applies them within seconds. If the oldest pending change keeps ageing, check
  **Admin → Jobs** for a failed `DrainSearchChanges` job; search requests restart
  a stranded worker after two minutes, and **Retry pending changes** does so now.
- **Public search paused** with pending changes: more than
  `pending_exclusion_limit` records are waiting (a bulk sync); it resumes as the
  worker catches up. With a dirty marker: dependency capture failed or visibility
  rules changed — rebuild fully.
- `documents_rejected`: Typesense refused the listed records (a schema violation
  in the source data). They are out of search until fixed and saved again.
- `document_count_mismatch` with "Refusing to promote": the rebuild would keep
  less than half of the live documents. Check the profile scope; if the drop is
  intended, rebuild with **Allow a smaller corpus**.
- Public errors include `X-Request-ID`; correlate it with the server log.

## Backup and rollback

Omeka/MySQL remains the source of truth. Typesense indexes are disposable, but
the immediately previous module-owned generation is kept. To roll back manually,
point the alias at `previous_collection`. Never delete collections by prefix.

## Secrets

A key saved in **Modules → DRE Search → Configure** takes precedence over the
`TYPESENSE_API_KEY` environment variable; the form lists where each connection
value in effect comes from. Prefer environment variables in production and keep
the saved field empty. A blank admin API-key field leaves the stored value
unchanged; the clear checkbox removes it. Rotate the Typesense key at the server
and module together.

## Translations

`language/template.pot` holds every interface string, the server's and the
search client's. Copy it to `language/<locale>.po`, translate, and compile it to
`language/<locale>.mo` (msgfmt or Poedit); Omeka then serves both the PHP
strings and, through `window.dreSearchTranslations`, the client's. After
changing strings, run `npm run i18n` (lint fails while the template is stale).

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
While the worker is stopped or Typesense is unavailable, the changed records are
hidden from public search (the whole corpus pauses beyond
`pending_exclusion_limit`). Direct SQL imports bypass Omeka events and must be
followed by a full rebuild. A first build is required for every enabled profile.

## Upgrading to 1.23

The migration adds the worker lease table and the rejected-records column; no
rebuild is needed. Retired generations older than the rollback target are now
deleted at the next promotion (`retention_days` 0); set it in local.config.php to
keep them longer.

Guzzle limits connection establishment to 2 seconds and each request to 10
seconds; automatic SDK transport retries are disabled. Queue jobs provide the
retry boundary for writes. Large installations should measure import batch time
before changing these limits.

Rollback collections may predate privacy edits. Rebuild from current Omeka data
before using an older generation after visibility changes. The module does not
rewrite retained historical collections.
