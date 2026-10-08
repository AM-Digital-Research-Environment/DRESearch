# Changelog

All notable changes to DRE Search are documented here. The project follows
[Semantic Versioning](https://semver.org/).

## [1.26.0] - 2026-10-08

### Fixed

- **The map always has a basemap.** The shared `window.RV_MAP_CONFIG` was read with `??`, so the empty `lightStyle` / `darkStyle` that DRE-Visualizations used to emit became the style URL and the map drew points on a blank canvas. Every step now uses `||` and falls back to the next style.
- **The facet value list is a list again.** It carried `role="group"` on the `<ul>` itself, which stripped the list semantics from its items (axe "listitem"); the group now wraps the list.

### Changed

This release brings the client onto DRE-theme's shared interaction contract (`docs/DESIGN-INTEGRATION.md`), so search behaves like the theme and DRE Visualizations rather than beside them.

- **Errors can be retried, and stay readable.** A failed search, federated search or map request shows a translated message and a **Try again** button that reruns it. The HTTP status, request id and raw server message, which used to be printed on the page, now go to the browser console.
- **One status node per surface.** The results, the federated panel and the map each keep exactly one persistent `role="status"` node, which announces "Loading…", the result count, the empty message or the failure. The skeleton is `aria-hidden`, and `aria-busy` is set on the results only while they load.
- **Shared wording.** "Loading…", "Try again", "Copied", "Clear all filters", "No records match that search.", "Search is temporarily unavailable.", "The map could not be loaded.", and "View as" for the layout switch, whose gallery option is now called **Grid** (`?view=gallery` links still work).
- **Translated map controls.** The map's zoom buttons, attribution toggle and "Use Ctrl + scroll to zoom" hints follow the site's language. The navigation control drops the compass, matching DRE Visualizations.
- **Focus that survives high-contrast mode.** Text fields no longer combine their focus ring with `outline: none`, which left no focus indicator at all under Windows High Contrast. The sort chevron and the transcript badge stay visible there too, and the focusable federated tab panel now shows focus.
- **Controls drawn like the theme's.** Fields and selects use the theme's field border. The pager, Export, Copy link, Cite, the mobile Filters toggle and the empty-state actions are the theme's secondary button. Chips are fully rounded, and an organisation's or term's type tag takes the colour of its entity type (location, subject, genre, language, organisation), as in the charts and maps.
- **Cards fit their column, not the screen.** A thumbnail sits beside the text only while the card itself is at least 28rem wide, so cards in a narrow column on a wide screen stack instead of squeezing their titles. People and organisations keep their small portrait beside the name.
- **Menus close like menus.** Escape closes the Cite menu, the Export menu and the mobile "Share and export" panel and returns focus to the button that opened it; a click elsewhere closes them.
- **Copy feedback.** "Copy link" and the Cite buttons read "Copied" for two seconds and announce it through the theme's shared status region, or the results' own status node without the theme.
- **Headings nest under a block title.** A Search block with a title (an `h2`) renders "Search results" and "Filters" as `h3` and its card titles as `h4`. Untitled blocks and the federated page keep `h2` and `h3`.
- **Printing.** A printed search keeps its results and drops the facets, sort, view, share, export, paging and map controls; the federated page prints which corpus the results come from. Project, role, keyword and series chips are `<button>`s, so they carry `data-print` to stay visible under the theme's print sheet, which hides every other button. A search block now prints under these rules instead of being dropped by the theme.
- The federated tab panel is named by its tab, the skeleton shimmer follows the theme's surface colour and timing, and the export menu uses the theme's shadow. Spacing, radii, letter-spacing and breakpoints are taken from the theme's scales.

### Packaging and tooling

- **Real-browser tests.** `npm run test:browser` drives the built bundle in Chromium with Playwright and axe: mounting, autocomplete, facets, corpus tabs, the empty and error states, heading levels, dark mode, no serious or critical axe violations, and no horizontal scroll at 320px. CI runs it in a new `browser` job.
- GitHub Actions are pinned to commit SHAs; Dependabot runs weekly with a five-day cooldown, like the theme's; `ci.yml` can be started by hand.
- The version check also reads `package-lock.json`. ESLint also lints `scripts/` and declares `svelte-eslint-parser` directly; jsdom moves to 30 and ESLint to 10.12. `.nvmrc` and `engines.node` pin Node 24, and an `.editorconfig` follows the theme's.
- `module.ini` declares `php_version_constraint = ">=8.3"` and requires Omeka S `^4.2.1`.

**Upgrade:** requires Omeka S 4.2.1 or newer. No reindex is needed.

## [1.25.0] - 2026-10-07

### Security

- **Popular searches are moderated.** Anyone could put any text in the public "Popular searches" list. A `search` request with `record_query: true` and a fresh `analytics_id` counts once, so a query sent `min_count` times within the rate limit was enough. A query that matches one of its words still finds something, so it passed the no-hit check too. The automatic checks now only pick candidates: a query is shown to visitors once an editor approves it. The new **Popular searches** section of the maintenance page lists each corpus's candidates with how often they ran, and offers **Approve**, **Hide** and **Revoke** (a CSRF-protected POST, open to editors and above). Case and spacing variants share one decision. Every decision clears the server's ten-minute cache of the list. Without the moderation table nothing is shown. The list now reads every recorded query (up to 1,000 per corpus), not just the top few, so repeated spam cannot push an approved query out of reach.
- `SECURITY.md` and `docs/public-api.md` now say that the analytics counts can be inflated by anyone, and that `is_public:=true` is defense in depth: search keeps private data out by indexing only public records and hiding records with pending changes.

### Changed

- **PHP 8.3 is the minimum** (was 8.2, whose security support ends on 2026-12-31). Dependencies are resolved for PHP 8.3 (`config.platform`) and CI tests 8.3–8.5. On an older PHP the module no longer loads its `vendor/` autoloader, whose Composer platform check would otherwise fail every request on the site: a fresh install is refused with a message naming the required version, an existing install reports search unavailable (the maintenance page says why), and `bin/dre-search` exits with an error. The code still parses on PHP 8.2 so that this degrade works; syntax newer than 8.2 waits for the next raise.

### Fixed

- A research item dated with a bare year ("1950") or a decade ("1890s") was indexed with today's date and time in its `date` field, because PHP's `strtotime()` reads a four-digit number as a clock time. Only a complete, valid date is now parsed as such; a year, decade, range or approximate date counts as 1 January of its first year. The `year` facet and sorting were not affected. Rebuild the research items corpus to correct existing documents.

### Packaging and tooling

- PHPStan also analyses `tests/` at level 8, ESLint uses type-aware rules (`no-floating-promises`, `no-misused-promises`, `await-thenable`), and CI checks that `Psr\Log\LoggerInterface` resolves from Omeka core rather than the module's `vendor/`. The integration tests build their database from Omeka's own install schema, and new tests cover media and item-set deletion, the authority resolver, and the indexers' dates, section phases and episode numbers.

**Upgrade:** requires PHP 8.3 or newer. Run Omeka's module upgrade. It creates the empty `dre_search_popular_moderation` table, so popular searches stay empty until an editor approves some. Then rebuild the research items corpus once, to correct the stored dates of items dated with a bare year or a decade (see Fixed).

## [1.24.0] - 2026-10-07

### Changed

- **The search client loads in one round trip instead of three.** The page used to find the bundle one request at a time: the entry, then the shared runtime chunk it imports, then — once the entry had run — the page chunk and its stylesheet. The build now writes `asset/dist/manifest.json`, and the head announces those files up front: a `modulepreload` for the shared chunk on every page, plus the search block's or federated page's own chunk and a stylesheet `preload` on those pages (`View\BundleAssets`, shared by the block, the header bar and the federated page). Measured on the dev site with a cold cache at 150 ms RTT and 1.6 Mbps, median of five interleaved runs, from the entry request: the research-items block's chunk is ready after 1.17 s instead of 2.32 s and the first card renders after 1.61 s instead of 2.35 s; on the federated page the chunks are ready after 1.27 s instead of 2.24 s and the search request leaves 0.53 s sooner; on a page with only the header bar, the shared chunk arrives 1.16 s sooner. Deploy `manifest.json` with the rest of `asset/dist`; without it the bundle loads as before, minus the hints. The release check now fails if the archive lacks a file the manifest names.
- **The result cards share one stylesheet.** The nine card components repeated the same frame, thumbnail, title, byline, chip and footer rules; they now live once in `styles/card.css` (`dre-shell__*` classes) and each card keeps only its differences. The search-block stylesheet shrinks from 67.7 kB to 42.8 kB (8.0 to 6.7 kB gzipped). A computed-style comparison of 3,822 rendered elements — all twelve corpora, the gallery and the federated page, light and dark, desktop and phone, hovered and keyboard-focused — found no difference.
- **One pager.** The search block and the federated "All" list drew pagers from two implementations; both now use `Pagination.svelte`, with 44 px targets, the 250-page clamp and a hover that a host theme's button fill cannot override. The "All" list's pager gains the focus ring and matches the block's.
- **Thumbnails come from one helper.** Every card takes its image through `CardThumb` and `thumbnailFor`. People and organisations use Omeka's `square` derivative, a centre crop that fills their round or square box at full resolution; gallery cards add `srcset`/`sizes` over the medium (200 px) and large (800 px) derivatives, and the first gallery row still loads eagerly. At today's gallery widths (about 310 px) the browser still picks the 800 px file: Omeka makes no size in between.

### Fixed

- **The map loads without DRE-Visualizations again.** MapLibre 6 ships only ES modules, so the jsDelivr fallback's `dist/maplibre-gl.js` was a 404, and a vendored 6.x copy published through `RV_LIBS` was loaded as a classic script and failed. Every copy is now imported as a module. The CDN copy moves to **MapLibre 6.13.0** (from 6.1.0, matching DRE-Visualizations) and is pinned with Subresource Integrity: the stylesheet carries `integrity`, and the entry and worker modules are fetched with their sha384 hashes and run from `blob:` URLs, because an `import()` cannot carry a hash. A build that splits its modules again is refused with a clear error. A host whose Content Security Policy forbids `blob:` scripts should serve the vendored copy.

## [1.23.0] - 2026-10-07

### Added

- **Place-name synonyms.** "Ivory Coast" finds "Côte d'Ivoire", "Swaziland" finds "Eswatini", and so on for twelve countries named differently in English, French, German and older usage (`data/synonyms.json`, a Typesense 30 synonym set). Searches reference the set per query, so editing it needs no rebuild; a Typesense without the set answers as before. "Sync stopwords and synonyms" on the maintenance page, `bin/dre-search sync-stopwords` and every full reindex upload it.
- **Cite a single publication.** Publication cards have a "Cite" disclosure: copy BibTeX, copy RIS or download a `.ris` file for that record, using the export serializers.
- **Open the linked record from a filter value.** A principal investigator on a project card, a podcast's series and a video's playlist filter the results when clicked; a small arrow beside them now opens that person's, series' or playlist's own page.
- **Typed years.** The year facet has two number fields beside the slider: an exact year no longer means dragging across five centuries one step at a time. Entries are clamped to the corpus's range and kept in order.
- **Shareable "All results" pages.** The federated page's merged tab keeps its page number in the address bar (`?page=3`), so a shared or reloaded link opens the same page; a link past the last page lands on the last one.
- **Popular searches (opt-in).** With `popular_searches.enabled`, the empty search box lists the corpus's most-run searches below the visitor's recent ones, read from the popular-query analytics (Maintenance → Provision analytics). Off by default, because it shows visitors what others typed: a query must have been run at least `min_count` times (5) and have found something, and anything shaped like personal data — an e-mail address, a URL, a run of five or more digits, control characters — is never shown. Cached ten minutes; `GET /dre-search/api/popular?profile=…`.

### Changed

- **One edit no longer takes all search offline.** Every Omeka write used to queue work for all twelve corpora, and any queued row made that corpus — and the header autocomplete and the federated page — answer "unavailable" until a background job finished; a MongoDB2OmekaS sync kept search down for its whole run. Now work is queued only for the corpora whose scope holds each item, and public search hides just the records whose changes are pending (`id:!=[…]`), pausing a corpus only beyond 250 pending records (`pending_exclusion_limit`) or when it needs a full rebuild. Federated search, union results and autocomplete leave a paused corpus out instead of failing.
- **One background worker instead of one job per request.** A single `dre_search_worker` lease coordinates draining: a write dispatches a job only when no worker is alive, and the worker loops while new writes arrive. A sync of N items no longer spawns N processes. Long-running imports wake the worker every 30 seconds. A failed pass backs off for two minutes instead of retrying on every save.
- **Stranded queues heal themselves.** When the oldest pending change is more than two minutes old and no worker is alive, the next search request starts one.
- **A rejected document cannot block a corpus.** A record Typesense refuses during an incremental update is removed from the live index, acknowledged and listed on the maintenance page with a link to the item; the rest of the queue continues. An integer field outside int32 (e.g. an episode number typo) is now treated as absent rather than rejecting the document.
- **Rebuilds refuse to empty a corpus by mistake.** Promotion is refused when the new generation keeps less than half of the live documents (`min_retained_ratio`); the maintenance page offers "Allow a smaller corpus" for an intended drop. Rebuilds wait up to 60 seconds for a running drain and up to two minutes for a Typesense that is still loading, instead of failing at once.
- Retired generations are deleted at the next promotion (`retention_days` now defaults to 0); the live and rollback generations are always kept.
- The maintenance page distinguishes live, "records hidden" and "public search paused" states, shows the oldest pending change and the worker's state, and its menu entry is visible to editors and site admins.

### Fixed

- Typesense rejects GET query strings over 4,000 bytes, so a long non-Latin query (450 Amharic characters) returned "unavailable". All searches now travel as POST.
- A block's saved locked filter could escape its parentheses (Typesense gives `&&` and `||` equal precedence) and must now balance; it may not mention `is_public`. Unknown and mismatched block scopes share one error code.
- Item-set edits, which cannot change any document, no longer re-queue every member synchronously; batch edits no longer process each item twice; resource-template changes now refresh the items using the template.
- A "not found" check matched any message containing "404", which a timeout on a generated collection name could; cleanup now relies on Typesense's typed not-found error.
- Incremental batches resolve only the authorities they link to instead of reloading every tracked authority per 100 items.
- The internal `is_public` flag is no longer sent with every search hit, and the merged "All" results translate their corpus badges.
- A selected facet value outside the top 100 recounted values showed a false "(0)"; its count is now unknown (`null`) and the sidebar shows no number. While typing in a facet's search box, only matching values are listed.
- Filter values Typesense would reinterpret inside a quoted list (a trailing `*` or `\`, or a value wrapped in double quotes) are refused instead of silently matching something else. Invalid UTF-8 in a request is rejected, and a malformed `?q=` no longer empties the federated page's bootstrap.
- Unexpected server errors return `500 internal_error` instead of `503`, so monitoring no longer mistakes a bug for an outage.

### Search interface

- **Ticking a value in a searched facet list no longer empties the list.** The facet search was rebuilt on every filter change, so the list turned into "Loading results" and focus fell to the page; it now re-counts in place, keeping the value focused and checked.
- **Back works after changing view.** Returning from gallery or map restored the old view and wrote it back into the address bar; the automatic gallery switch no longer adds a history step of its own; a hand-edited `?sort=` is ignored instead of failing the search; a `#footnote` link no longer refetches every block.
- **Paging keeps the results on screen.** The previous page stays visible, dimmed, while the next one loads, focus moves to the results heading instead of the page body, and the facet counts are not recomputed for a page-only change. Clearing filters or removing a chip returns focus there too.
- **Screen readers hear "45 results".** One atomic status line per block replaces a live region that covered the sort, export and copy-link controls.
- **Autocomplete follows the combobox pattern.** Recent searches and "See all results" join the arrow-key options, the highlighted option scrolls into view, Enter waits for an IME composition, Escape on the mobile header returns focus to its toggle, and clearing the box keeps focus in it.
- **The federated page sends fewer requests.** A deep link with corpus filters, or a card chip's hand-off to a corpus, used to search twice; arrow keys across the corpus tabs now move focus and Enter selects, instead of pushing a history step and a search per key press.
- **Visible keyboard focus.** Buttons, chips, links and tabs draw a solid primary outline (about 6:1 in dark mode) in addition to the soft halo, which alone was about 1.6:1.
- Facet checkbox lists are named after their facet, each block is a landmark named after its title or corpus, the search fields are `search` landmarks, and the export panel is a plain disclosure that returns focus to its button.
- The map shows how many matching locations have coordinates and offers the mapped places as a list for keyboard and screen-reader users; it waits for typing to pause, asks the list endpoint for a single hit, and its errors no longer linger after switching back to the list.
- The pager reaches the 250th page the server serves (it stopped at 100); recent searches keep "colonial" instead of also "colon" and "col"; affiliations on person cards are filter links, listed once; the first gallery row loads eagerly; a failed bundle load shows a reload link instead of a silent skeleton.
- BibTeX keeps DOIs and URLs verbatim, and a line break in a title can no longer start a forged record in the plain-text export.

### Omeka integration

- **Translatable.** A gettext template (`language/template.pot`, generated by `npm run i18n` and checked by lint) covers the PHP strings, every corpus and facet label, and the search client's interface, which now receives its strings in the site's language from Omeka's translator (`window.dreSearchTranslations`, emitted only where a translation exists).
- **Search blocks are validated on save** — through the page editor and the REST API. An unsafe locked filter is refused there, results per page, default sort and facets are normalised; a block with a bad saved scope renders its "unavailable" state instead of failing the page.
- Search blocks support Omeka 4.2 **block templates** (`common/block-template/<name>` from a theme's `block_templates`), load their assets in `prepareRender()`, and contribute their title and intro to Omeka's own site-page search.
- A **"DRE Search: all results"** link type for site navigation; the federated results page is `noindex,follow`.
- **Monitoring and CLI:** `GET /dre-search/api/health` (Typesense reachability, corpus states, queue age, worker liveness; no secrets) and `bin/dre-search status | drain | reindex | sync-stopwords`, which runs through Omeka's bootstrap and suits a cron fallback.
- The configuration form lists where each connection value in effect comes from (saved setting, environment variable or default) — a saved key silently shadowed a rotated `TYPESENSE_API_KEY`.
- Event listeners are built on the first write instead of on every page view, and a failure to build them is logged instead of silently disabling incremental indexing. Uninstalling logs the Typesense collections the module created before it drops the table that recorded them.
- The public ACL allow-list is a constant checked by a test against the controller's actions and routes.

### Robustness

- **Rate limits per visitor behind a proxy.** `X-Forwarded-For` is honoured when the direct peer is in `rate_limits.trusted_proxies` (read from the right, skipping trusted hops); IPv6 clients are bucketed by /64; a `429` carries `Retry-After`; federated searches with counts and cross-corpus suggestions weigh more than one request. Limits are configurable under `dre_search.rate_limits`.
- **Time budgets.** Public searches use a 5-second transport deadline and a 2-second Typesense `search_cutoff_ms`; rebuilds, drains and provisioning use a separate 60-second client (`typesense.search_timeout` / `index_timeout`).
- **A dead Typesense costs one timeout, not one per block.** After a connection failure the proxy stops calling Typesense for the rest of the request, and for 30 seconds across requests when APCu is available.
- Count-only and facet-recount queries ask for zero hits.

### Packaging and tooling

- **Guzzle 8.** The Typesense transport is a plain PSR-18 Guzzle 8 client; `php-http/guzzle7-adapter`, `http-interop/http-factory-guzzle` and `ralouphie/getallheaders` — leftovers that held Guzzle at 7 — are gone. No other module in the AMIRA deployment bundles Guzzle.
- **Reproducible releases.** `composer.lock` is committed and resolved for PHP 8.2 (`config.platform`), so CI tests on PHP 8.2–8.5 exactly the dependency set the archive ships; `composer audit` runs in CI and before packaging.
- **Releases wait for CI.** A tag runs the full suite before `DRESearch.zip` is built, the archive must contain its page chunks, translations and CLI, and it carries a build-provenance attestation.
- **`asset/dist/` is no longer tracked.** It is built by CI and by the release workflow, so Svelte, Vite and other frontend dependency updates no longer fail CI for want of a hand-rebuilt bundle. Dependabot groups routine updates and never proposes Monolog 3 or a new `psr/*` major, which would collide with Omeka core's PSR Log 1.
- CI cancels superseded PR runs, caches Composer, verifies the Omeka download's checksum, waits for Typesense to be healthy, fails if any test is skipped, and runs `npm audit`; CodeQL scans the TypeScript and the workflows.
- PHPStan runs at level 8 (from 5) and TypeScript checks indexed access; the findings were fixed, among them possible null dereferences in the block renderer and the readiness gate.

**Upgrade:** run Omeka's module upgrade. No reindex is needed; press "Sync stopwords and synonyms" once (or run `bin/dre-search sync-stopwords`) to load the synonym set.

## [1.22.1] - 2026-10-07

### Fixed

- **Omeka's module upgrade from 1.21.x failed.** The 1.22.0 migration asked the service manager for this module's own services, which Omeka does not register while a module is awaiting its upgrade. The upgrade aborted after its table changes, the module stayed inactive and every retry failed the same way. The migration now builds what it needs from core services and still marks every profile, including `local.config.php` additions, for rebuild.
- **A missing `vendor/` directory no longer takes down the whole site.** Omeka loads every active module's `Module.php` on each request; an unguarded autoloader include turned a source checkout without `composer install` into a site-wide fatal error. The module's own classes now load regardless, search reports itself unavailable, installation is refused with an explanation, and the admin page shows the cause.
- **Matches in list fields were never highlighted.** Typesense returns a `string[]` field's highlight as one entry per element, which the proxy ignored, so author, editor, subject, tag, PI and keyword matches produced no "Matched in" line or highlighted chip. Title and abstract highlights were unaffected.

### Internal

- Tests now load Omeka's `bootstrap.php`, as production does, so PHP 8.5 runs Omeka's patched Laminas classes; this removes the two `SplPriorityQueue` deprecations the PHP 8.5 CI job reported. PHPUnit now fails on deprecations, notices and warnings raised by module code.
- Integration tests accept `DRE_TEST_MYSQL_PORT`. The upgrade test runs with core services only.

**Upgrade:** install the complete DRESearch.zip, run Omeka's module upgrade, then **Reindex all corpora** (required when coming from 1.21.x, as described for 1.22.0). No reindex is needed when coming from a working 1.22.0.

## [1.22.0] - 2026-10-05

- Enforce public resource, value, linked authority and media visibility throughout indexing.
- Queue and batch incremental updates durably, capture former dependencies, replay changes across rebuilds and protect live aliases after ambiguous promotion responses.
- Fix contributor filters, full-text snippets, stale federated tab responses and long-tail facet search.
- Align stopwords across result modes; disable internal analytics, add anonymous deliberate-query capture, shared cache invalidation, bounded HTTP timeouts and recovery controls.
- Expand lifecycle and Typesense regression coverage and enforce PHP style in CI.
- Preserve Omeka's PSR Log compatibility with Monolog 2, append the module autoloader after core, and update PHP_CodeSniffer to its patched release.
- Update Vitest and affected build/test transitive dependencies within their existing major versions; the npm dependency audit is clean.

**Upgrade:** run Omeka's module upgrade, then **Reindex all corpora**. Search pauses
until the new visibility rules have been applied. See [the operations runbook](docs/operations.md#upgrading-to-1220).

## [1.21.3] - 2026-09-07

### Fixed

- Show available languages on publication result cards below the bibliographic reference. Each language applies the existing Language filter, including keyboard activation. Records without language metadata omit the row.
- Uses the already indexed language field; no reindex or visualization regeneration is needed.

## [1.21.2] - 2026-09-07

### Fixed

- Keep result view toggles at least 44 by 44 pixels when mobile layouts hide their text labels. Centre the existing icons without enlarging them.
- Verified keyboard corpus selection, filtered and empty-result recovery, secondary-action disclosure, light/dark layouts at 320/390/1280 pixels, and 200% text reflow with long labels against real Omeka markup using local asset overrides.

## [1.21.1] - 2026-09-07

### Fixed

- Fix blank search results under Omeka's versioned script URL. Page chunks imported shared Svelte state from the unversioned entry, while Omeka loaded that entry with a version query. Browsers treated these as separate modules and raised effect_orphan during mounting. Shared state now lives in a separate hashed chunk, with a build regression contract preventing imports back into the entry.
- Preserve the smaller header payload and page-specific lazy loading.

### Upgrade

Replace 1.21.0 with the complete **DRESearch.zip** asset, including all asset/dist/chunks files and vendor/. This patch is required for the new split build on Omeka.

## [1.21.0] - 2026-09-07

### Changed

- Load the full faceted search interface only on pages that use it. Header JavaScript drops from about 161 KB to 59 KB and its component stylesheet from 72 KB to 4.4 KB before compression.
- Use a labelled corpus chooser on mobile, retaining result counts and desktop keyboard navigation. Group sharing and export actions in a mobile disclosure.
- Expose public corpus counts using the same membership rules as indexing, with optional site scope. The updated DRE theme and Visualizations module consume these definitions.

### Fixed

- Discard obsolete autocomplete responses after edits, clearing, submission or external query changes.
- Preserve server-rendered search fallbacks when a page chunk cannot load.

### Upgrade

Install the complete **DRESearch.zip** release asset, including vendor/ and every asset/dist/chunks file. Do not copy just the entry JavaScript/CSS or use GitHub's source archive as the installable module. Keep older hashed chunks available while replacing assets during rolling deployments. Upgrade the theme to 2.30.2 and Visualizations to 2.28.3, then regenerate visualization data. The release includes Composer's refreshed class map for the new count services.

## [1.20.3] - 2026-09-04

### Fixed

- **Typing in a search block's main field dropped characters.** The box keeps a
  local copy of the query and hands it to the parent only after a 250 ms
  debounce, so the `value` prop is a debounce behind the field the whole time
  someone is typing. The prop-to-local sync effect also read that local copy, so
  every keystroke re-ran it and rewound the field to the parent's stale value:
  typing `religion` on a publications block left `n`. The sync now depends on
  `value` alone, and ignores the parent echoing back a query the box itself just
  committed. The facet type-to-filter boxes were never affected — they own their
  state outright, which is why they always felt responsive.

### Changed

- **A search no longer buries the page it started from in browser history.** A
  block committed its query to the URL with `pushState`, so every 250 ms typing
  pause left a back-button step behind and escaping a search took as many Back
  presses as the visitor had hesitations. Typing and paging now replace the
  current entry; only a change of scope — a facet, a sort, a year window, a view
  — pushes a step worth going back to. The URL still carries the whole state, so
  links stay as shareable as before, and this is what the federated "Search all"
  page already did.

## [1.20.2] - 2026-09-04

### Documentation

- The publications corpus description said publications span "~10 type-specific
  resource templates". The cluster bibliography now spans **19**, on a
  non-contiguous id range (11-20, 24-32) that grows whenever a new upstream EP3
  type appears in ERef/EPub. No code change: the profile already scopes by
  `item_set_id` 29918 with `template_id: null`, which is exactly why nine new
  publication templates and 285 new publications needed no work here.

> Reindex `research_publications` after upgrading — the upstream harvest grew
> from 277 to 562 publications, and the Typesense collection is only as current
> as its last reindex.

## [1.20.1] - 2026-08-28

### Changed

- High-frequency search controls now meet the theme's 44px touch-target contract:
  both search fields and their clear buttons, the collapsed header search toggle,
  sort and view controls, export actions, copy-link action, federated-search clear
  action, and both pagination implementations.
- Compact corpus tabs remain deliberately exempt because expanding thirteen tabs
  would make the mobile selector substantially harder to scan.

### Internal

- Added a source-level regression test for the shared `--size-control-lg` contract.
- Corrected the package-lock root version, which had remained on 1.18.1.

## [1.20.0] - 2026-08-14

### Fixed

- **Dark mode is the theme's, not the operating system's.** Three components —
  `ResultItem`, `Sparkline` and `MapView` — branched on
  `@media (prefers-color-scheme: dark)` and `matchMedia` instead of the
  `[data-theme]` attribute the theme writes to `<html>` and `<body>` before first
  paint. A visitor on a system-dark machine who chose light got a light page
  carrying a dark-matter basemap, dark-tuned thumbnail filters and dark
  opacities; choosing dark on a system-light machine inverted the same three.
  They now follow the same switch as the rest of the client.
- **The map is part of the site again.** `MapView` painted clusters `#007a50`,
  points `#d57912` and every stroke and label `#fff` — raw literals no theme
  token could reach. Three consequences, all fixed: changing the brand colour in
  theme settings re-tinted the whole site except this map; the point colour was
  the raw Braun pigment rather than `--accent` (`#ca7210`); and the white stroke
  was the exact literal DRE-theme retired `--white` to prevent. Colour now
  resolves through a token bridge at paint time and re-resolves when the theme
  toggles, so the map follows both the toggle and the admin's brand colour.
- `FederatedApp` read `var(--danger, …)`, a token the theme has never defined, so
  its error border always painted the hard-coded `#b42318`. It reads `--error`.
- `Sparkline` read `var(--type-entity-term, …)`, which nothing anywhere defined.
  DRE-theme now publishes the entity-type colour family and this resolves.

### Changed

- **One rhythm.** Result text was set at `1.5` where the theme's `--leading-normal`
  is `1.6`, so a result list and a browse list on the same page, in the same
  family at the same size, had visibly different rhythm. All 34 hand-set
  line-heights now come off the `--leading-*` scale. (`line-height: 1` on the
  clear-button glyph stays — that is a reset, not rhythm.)
- **540 `var(--token, literal)` fallbacks now carry the theme's own values.**
  They had been written by hand and drifted into a second design system: `--muted`
  appeared as four different greys (none of them `#716a66`), `--text-xs` as four
  sizes all smaller than the 13px the token carries, and `--ink` resolved to two
  different inks depending on whether it was nested. None of it was visible in
  production — the token always wins when the theme is loaded — which is exactly
  why it drifted. The values are generated from DRE-theme's OKLCH source now, and
  the lint checks every one of them.
- **MapLibre comes from the vendored copy.** The client injected
  `cdn.jsdelivr.net` script and stylesheet tags at runtime and loaded Carto
  basemap tiles — two third-party origins on an EU-hosted site whose theme
  self-hosts its fonts specifically to avoid them, and a duplicate renderer
  whenever DRE Visualizations rendered on the same page. The loader now prefers a
  copy already on the page, then the same-origin copy DRE Visualizations vendors,
  and reaches the CDN only as a floor.
- Off-scale type is on the scale: `font-size: 1.25rem` in the search box (and six
  others) now read from `--text-*`. The two `em` sizes stay — those are
  deliberately relative to their parent, a different mechanism from the rem scale.
- `999px` pill radii read `--radius-full`; the export menu's raw `z-index: 30`
  reads `--z-dropdown`; its hand-set `80ms` transition reads `--transition-fast`.

### Internal

- `scripts/check-design-tokens.mjs` is now a thin config over
  `scripts/lib/token-rules.mjs`, vendored verbatim from DRE-theme so all three
  repositories run one rule set. The old script had four rules and no rem check,
  which is why `font-size: 1.25rem` sat in the search box, and it stripped
  `var(--x, …)` before applying any rule, which is why no fallback was ever
  checked. Four rules are new: fallback literals, `--leading-*`, the z-index
  scale, and `@media` widths against the shared breakpoint ladder.
- `scripts/design-token-allowlist.txt` records what is not yet converted, per
  rule per file. It is a backlog, not a set of exemptions: lines should only ever
  be removed. 50 lines today — 24 off-scale spacing, 16 px geometry, 8 `@media`
  widths off the ladder, 2 radii.
- New `src/svelte/lib/tokenBridge.ts`: prefers DRE-theme's `window.DRETokens` and
  falls back to an equivalent local probe, so the client still resolves tokens
  when mounted in a host without the theme.

## [1.19.2] - 2026-08-07

### Fixed

- Result cards broke apart on narrow screens wherever a click-to-filter value was
  long enough to wrap — worst on a publication's reference line, where the venue
  title was centred against the left-aligned text around it and stranded
  ` (eds.),` and `, pp. 25–48.` on lines of their own. The cause was the element,
  not the CSS: those values were `<button>`s, and a button is an atomic
  inline-block, so it cannot break across the line boxes of the sentence it sits
  in — a wide one claims the full column width, and the UA's `text-align: center`
  for buttons centres whatever it wraps internally. They are now `FilterLink`
  spans (`role="button"`, `tabindex="0"`, Enter/Space), which fragment like the
  text around them. A 375px-wide reference went from seven ragged lines to five
  flush ones. One shared component replaces six identical copies of the style, so
  every corpus is covered: research items (authors, place of origin, current
  location, language), publications (authors, editors, venue, publisher),
  projects (PIs), research sections (leaders), podcasts and videos (language).
  Frontend only — no reindex, no config.

## [1.19.1] - 2026-08-04

### Fixed

- Short queries under-reported their matches by up to 10×. Prefix search is on,
  so a one- or two-character query has to stand in for every token starting with
  it, and Typesense's default cap of four prefix expansions per token truncated
  the result set: on the research items corpus `k` found 137 of 1299 matches and
  `ke` 102 of 165. The truncation also interacted with `filter_by` — a narrower
  filter reaches deeper into the same candidate space — so a filtered search could
  report more hits than the unfiltered facet count claimed for that value. The
  pool is now 512 (`QueryBuilder::MAX_CANDIDATES`), applied to search,
  autocomplete, and every query derived from them (federated tab counts, export,
  map, union, facet recounts) — enough for `k` to reach all 1299. Longer queries
  are unaffected: `kenya`, `africa`, `islam` and `music` return exactly the counts
  they did before. It is also faster, because the default's escalating retry
  passes cost more than one wider pass — `k` went 415ms → 92ms and `africa` 37ms →
  3ms on the dev corpus. No reindex.

### Packaging

- First tagged release, so the module is now installable from a release asset
  (`DRESearch.zip` + its SHA-256) instead of a `git clone` plus a
  `composer install`. The release workflow additionally asserts the tag matches
  `config/module.ini`, and that the archive contains `Module.php`,
  `config/module.ini`, the built bundle and `vendor/autoload.php` while
  containing no dev tooling.
- Development files no longer leak into the archives. `.gitattributes` gained
  `export-ignore` rules — which is what governs GitHub's auto-generated "Source
  code" tarballs — and the release workflow's own exclude list was widened to
  match: `tests/`, `scripts/`, `.github/`, and the Node/PHPUnit/PHPStan/ESLint/
  Prettier/TypeScript config files are all out. `docs/` and `src/svelte` (the
  GPL sources for the compiled bundle) deliberately stay in.
- `LICENSE` now carries the verbatim GPL-3.0 text rather than a short notice, so
  the licence is machine-detectable; the copyright notice moved to the README.
- Added `CITATION.cff` (ORCID, affiliation, SPDX licence), wired into the
  existing CI version-consistency check so it cannot drift from
  `config/module.ini`.

## [1.19.0] - 2026-08-04

### Fixed

- Facets are multi-select again. Picking one Type emptied the Type list of every
  other option, so a checkbox group behaved like a radio group: a facet's own
  selection is part of the filter, leaving Typesense nothing else to count. Each
  refined facet is now recounted alongside the main search with its own clause
  lifted — every other filter still applies, so the numbers stay honest — and a
  selected value that no longer matches stays listed at zero rather than
  disappearing from the list it was ticked in. This lives in the shared query
  layer, so it holds for every corpus, every page block, and the federated page,
  with no configuration and no reindex. Unfiltered searches are unchanged: the
  extra pass rides along in the existing round-trip only when a facet is refined,
  and falls back to the plain search if it fails.

### Changed

- The federated page's corpus tabs wrap instead of scrolling sideways. Thirteen
  tabs measure ~1885px against a ~1236px column, so five of them — Genres,
  Languages, Locations, Subjects & tags and half of Organisations — sat behind a
  horizontal scrollbar. They now wrap to two rows on a desktop column (five on a
  phone, where the chips tighten), and read as pills so the active one is legible
  on any row. The count badge on the active pill lost its fill: on the filled pill
  any tint pushed the number under WCAG AA (4.0:1 dark, 3.3:1 light); outlined, it
  keeps the label's own 6.5:1 / 5.2:1.

## [1.18.2] - 2026-08-03

### Fixed

- Reindexing the Locations corpus failed outright: Typesense builds its geo
  index from the sort index, so it rejects a `geopoint` field declared with
  `sort: false` — the default for every config-declared display field. The
  generated schema now forces geopoint fields sortable, which is a property of
  Typesense rather than of any one profile's configuration.
- A failing corpus no longer hides its cause. The reindex-all summary reported
  only which corpora failed, leaving the actual Typesense message reachable only
  by digging through the per-corpus log lines; it now carries each reason.

### Added

- An integration test that creates every shipped profile's schema against a live
  Typesense, so server-side field-combination rules are caught in CI instead of
  mid-reindex, and a profile-schema guard rule for unsortable geopoints.

## [1.18.1] - 2026-08-01

### Changed

- Updated the supported Svelte 5, Vite 8, ESLint 10, TypeScript ESLint,
  Svelte Check, Prettier, and browser-global development toolchain releases.
- Regenerated the committed production bundle with Svelte 5.56.8 and Vite
  8.2.0.

### Security

- Refreshed transitive build dependencies to remove the reported
  `brace-expansion` denial-of-service and PostCSS source-map path-traversal
  advisories; `npm audit` now reports no known vulnerabilities.

## [1.18.0] - 2026-08-01

### Added

- Publication full-text search and a compact availability filter without sending
  full texts to browsers.
- Shareable list/gallery preferences, larger derivative-aware thumbnails, and a
  lazy clustered map for geocoded locations.
- A persistent result summary with shared removable scope chips, layout-matched
  reduced-motion skeletons, compact association sparklines, recent searches,
  slash-to-focus, copy-link, and zero-result suggestions.
- A server-side Typesense 30 union endpoint and federated All tab with mixed-card
  corpus handoff; the server-held API key remains private.
- Optional per-profile popular/no-hit analytics provisioning and an admin digest.
- A standalone profile/schema/client drift guard wired into lint, plus map, union,
  mapper, URL-state, thumbnail, and chip-model regression tests.

### Changed

- One shared reindex orchestrator now owns stopword provisioning, one/all profile
  rebuild wiring, and the non-fatal analytics follow-up.
- CI now runs PHP syntax, PHPUnit and PHPStan on PHP 8.2–8.5, plus frontend
  lint/type/tests/build, a committed-bundle check, and a live Typesense 30 union
  integration test.

### Deployment

- Reindex all corpora to populate union source markers plus the new publication
  full-text and location coordinate fields. Analytics additionally requires
  Typesense search analytics and a persistent analytics directory.

## [1.17.2] - 2026-07-30

### Changed

- Match highlights follow the DRE theme's renamed highlight token. The theme
  renamed `--dre-hl-bg` to `--highlight-bg` in v2.22.0 — it was the only token
  carrying a product prefix and an abbreviation, on the one token whose whole
  purpose is to be shared across theme, search and visualizations.
  `Highlight.svelte` now reads
  `var(--highlight-bg, var(--dre-hl-bg, <literal>))`, so a matched term keeps its
  wash against both the new theme and any instance still on 2.21.x. The
  `--dre-hl-bg` step can be dropped once every deployment is on ≥ 2.23, when the
  theme retires its deprecated alias.

## [1.17.1] - 2026-07-26

### Fixed

- Both reindex jobs crashed immediately with an undefined-method fatal on
  `getJob()`. Omeka's `AbstractJob` exposes the Job entity as the protected
  `$job` property and has no `getJob()` accessor, so "Reindex all corpora" and
  the per-corpus reindex both aborted before indexing anything.

## [1.17.0] - 2026-07-17

### Added

- Transactional rebuild state, per-profile advisory locks, unique staging
  collections, rollback generation tracking, safe retention, and cancellation.
- Strict per-document import gates and final count verification before alias
  promotion.
- Complete-or-fail exports with explicit cap metadata.
- Validated public request objects, stable error codes/request IDs, rate limits,
  caches, and server-owned block scopes.
- Incremental scope-exit deletion, dependency refreshes, batch/media/item-set
  events, and dirty-index reporting for bounded sync failures.
- Typed profile definitions, shared mapper normalization, strict URL/DOI
  handling, and operator-visible index status.
- Frontend cancellation, bounded paging, lossless URL state, accessible tabs and
  combobox IDs, runtime locale overrides, and safer downloads.
- PHPUnit, Vitest, accessibility, Typesense integration, CI, Dependabot, and
  reproducible release-package workflows.

### Changed

- Blank API-key submissions preserve the secret; clearing requires an explicit
  checkbox.
- Editor-authored introduction HTML is sanitized.
- Unknown profile names are rejected instead of falling back to the default.

### Security

- Browsers can no longer submit raw locked filters.
- Public endpoints enforce bounded schemas and never expose backend exceptions.
- Indexed external links are restricted to HTTP(S), with client-side defense in
  depth for legacy index documents.

## [1.16.0] - 2026-02-01

- Previous production release.
