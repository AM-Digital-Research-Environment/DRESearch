# Code map

Annotated tree of the module: where each concern lives. The README's
Architecture section is the short orientation and
[architecture.md](architecture.md) explains the lifecycles; this is the map.
Where a class's own docblock says more, it wins.

```
DRESearch/
├── Module.php                       # Lifecycle: install/upgrade/uninstall (the dre_search_* tables;
│                                    #   uninstall also revokes the search-only keys it minted),
│                                    #   the public-endpoint ACL allow-list (PUBLIC_ACTIONS), the lazily
│                                    #   resolved write listeners, the Typesense config form (admin key,
│                                    #   optional search-only key; SECRETS are never echoed back)
├── bin/dre-search                   # CLI: status · drain · reindex <profile>|--all · sync-stopwords
├── config/
│   ├── module.ini                   # Omeka manifest; the version is written by `npm run bump` only
│   └── module.config.php            # Services, routes, blocks, helpers — and dre_search.profiles,
│                                    #   the twelve corpora (see docs/data-model.md)
├── data/
│   ├── stopwords.json               # English stopword set → Typesense `dre_default`
│   ├── synonyms.json                # synonym set → Typesense `dre_synonyms`
│   └── client-strings.json          # GENERATED (npm run i18n): the client's English strings
├── language/template.pot            # GENERATED (npm run i18n): every PHP + client string
├── view/
│   ├── common/block-layout/dre-search-block.phtml                    # a search block: mount root,
│   │                                                                 #   skeleton, bootstrap JSON
│   ├── common/resource-page-block-layout/dre-search-project-records.phtml
│   ├── common/dre-search-noscript.phtml                              # no-JavaScript link to Omeka's
│   │                                                                 #   item browse, on every surface
│   ├── common/navigation-link-form/dre-search-results.phtml          # nav link to the results page
│   └── dre-search/
│       ├── federated.phtml          # /s/{site}/dre-search, the federated results page
│       └── admin/maintenance/index.phtml   # Admin → DRE Search (rebuilds, queue, analytics,
│                                           #   popular searches, the public search key)
├── asset/
│   ├── css/dre-search.css           # HAND-WRITTEN: skeletons + header-bar shell, render-blocking
│   └── dist/                        # BUILT by Vite, untracked: entry, hashed chunks, manifest.json
├── src/                             # PHP, namespace DRESearch\ (and the Svelte client, below)
│   ├── Settings/                    # The profile contract everything else is parameterised by
│   │   ├── SearchProfile.php        #   one corpus, from a dre_search.profiles.<name> block (incl.
│   │   │                            #   each kind's suggestion subtitle, SUBTITLE_DEFAULTS)
│   │   ├── ProfileRegistry.php      #   all of them; the first is the default
│   │   ├── Definition/              #   Field / Date / Sort definitions, SourceScope
│   │   ├── SourcePredicate.php      #   the SQL membership rule (template, item set, extra sources)
│   │   │                            #   shared by indexing, ScopeMatcher and CorpusCounts
│   │   ├── SortOptions.php          #   a corpus's sort choices, labels translated
│   │   └── BlockProfiles.php        #   LAYOUTS: each stored block layout name → its profile and
│   │                                #   block-picker label (the one list the factory, picker and
│   │                                #   scope resolver read)
│   ├── Indexer/                     # MySQL → Typesense
│   │   ├── OmekaSourceRepository.php    # reads only what an anonymous visitor may see (+ opt-in restricted text)
│   │   ├── PropertyResolver.php     #   property ids by term, resolved once per run and shared
│   │   ├── ValueBag.php             #   one resource's values, with shared normalisation (labels
│   │   │                            #   trimmed, blank ones dropped)
│   │   ├── AuthorityResolver.php    #   per-rebuild lookup of the authority items behind facets
│   │   ├── MapperInterface.php · MapperRegistry.php · PreparesBatch.php
│   │   │                            #   MapperRegistry: the one kind → mapper map (also what makes a
│   │   │                            #   kind valid in profile config)
│   │   ├── ResearchItemMapper · ProjectMapper · PublicationMapper · PodcastMapper ·
│   │   │   VideoMapper · SectionMapper · ReverseLinkMapper · TermMapper
│   │   │                            #   ReverseLinkMapper maps both `person` and `organisation`;
│   │   │                            #   TermMapper builds on it (+ a location's geopoint)
│   │   ├── DocumentAssembler.php    #   batch mapping shared by full and incremental runs
│   │   ├── SchemaProvider.php       #   a profile's Typesense schema
│   │   ├── CollectionOps.php        #   every collection, document and alias call of the indexer:
│   │   │                            #   create, count, import, delete, read and promote an alias
│   │   │                            #   (verified), cleanup that never deletes an aliased collection
│   │   ├── Reindexer.php            #   one profile's full rebuild: fresh collection, paged import,
│   │   │                            #   replay, reconcile with the source, verify, promote
│   │   ├── ChangeApplier.php        #   applies queued changes: the worker's drain, a rebuild's
│   │   │                            #   replay before promotion and its drain after
│   │   ├── ReindexOrchestrator.php  #   the one construction path for rebuild jobs (stopwords,
│   │   │                            #   rebuilds, optional analytics)
│   │   ├── RebuildLock.php · RebuildStateStore.php      # advisory locks; dre_search_profile_state
│   │   ├── ItemEventListener.php    #   Omeka write events → queued work (Module.php attaches it)
│   │   ├── ScopeMatcher.php         #   which profiles hold each changed item (one UNION ALL)
│   │   ├── ChangeQueue.php          #   dre_search_change: deduplicated, revision-checked work
│   │   ├── IncrementalIndexer.php · WorkerLease.php     # the one coalesced drain worker
│   │   ├── ImportResult.php         #   normalises Typesense import replies
│   │   ├── StopwordsSync.php · SynonymsSync.php · AnalyticsSync.php
│   │   └── Exception/               #   BatchImport · RebuildLocked · ReindexCancelled ·
│   │                                #   SourceCountMismatch (`source_count_mismatch`) · Verification
│   ├── Job/                         # Omeka jobs: IndexSearchProfile · IndexAllSearchProfiles ·
│   │                                #   DrainSearchChanges · SyncStopwords · ProvisionAnalytics
│   ├── Search/                      # The public proxy: browser → PHP → Typesense
│   │   ├── SearchRequest.php        #   immutable, bounded request shapes
│   │   ├── BlockScopeResolver.php · ResourceScopeResolver.php   # server-side scope: a block's
│   │   │                            #   locked filter, a project's records (scope_item)
│   │   ├── FilterExpression.php     #   structural checks on an editor's locked filter
│   │   ├── QueryBuilder.php         #   request → Typesense parameters, every mode from one matching
│   │   │                            #   core; is_public:=true lives here
│   │   ├── SearchExecutor.php       #   every search: POST multi_search + the missing-set fallback
│   │   ├── SearchProxy.php          #   orchestrates search, facet, suggest, federated, union, map,
│   │   │                            #   export, health: validation and scope, the gate, the breaker,
│   │   │                            #   the cache (first pages too), logging; degrades on outages
│   │   ├── FacetRecount.php         #   multi-select facets: recount each refined facet, merge
│   │   ├── BulkPager.php            #   the export and map pulls; a short pull fails closed
│   │   │                            #   (Exception/IncompletePullException)
│   │   ├── ResponseNormalizer.php   #   the public JSON shapes, highlights, restricted-text excerpts, suggestion subtitles
│   │   ├── HealthReport.php         #   GET /dre-search/api/health
│   │   ├── SearchStateQuery.php     #   does the raw URL carry a block's own state (skip the
│   │   │                            #   server-rendered first page)
│   │   ├── ReadinessGate.php        #   per profile: answer, hide pending ids, or pause
│   │   ├── SearchCache.php          #   MySQL cache with epochs (dre_search_cache)
│   │   ├── RateLimiter.php          #   per-address fixed window (dre_search_rate_limit)
│   │   ├── PopularSearches.php · PopularAnalytics.php · PopularModeration.php
│   │   │                            #   opt-in popular searches, editor-moderated
│   │   ├── CorpusCounts.php         #   public counts with the indexing membership rules
│   │   ├── TypesenseClientProvider.php  # lazy clients (admin, index, search); "Typesense is
│   │   │                            #   optional" lives here
│   │   ├── TypesenseSearchKeyProvider.php   # the search-only key public searches use: configured,
│   │   │                            #   else minted with the admin key, else the admin key
│   │   └── Exception/               #   RequestValidationException · IncompletePullException
│   ├── Controller/
│   │   ├── SearchController.php     # /dre-search/api/* (JSON) and the federated results page
│   │   └── Admin/MaintenanceController.php  # rebuilds, retries, analytics, moderation, key status
│   ├── Site/
│   │   ├── BlockLayout/             # SearchBlock: one class for all twelve layouts, bound by
│   │   │                            #   SearchBlockFactory to BlockProfiles::LAYOUTS ·
│   │   │                            #   SearchBlockSettings (saved data, legacy too)
│   │   ├── ResourcePageBlockLayout/ProjectRecords.php   # the item-page Project records block
│   │   ├── ResourcePagePlacement.php    # puts it on sites' item pages (install/upgrade)
│   │   └── Navigation/Link/FederatedSearch.php
│   ├── View/
│   │   ├── SurfaceBootstrap.php     # the client bootstrap for blocks, Project records and the
│   │   │                            #   federated tabs (one builder, one set of endpoint paths)
│   │   ├── BundleAssets.php         # the one place the client enters the page head; preloads
│   │   │                            #   from the manifest (BundleManifest.php)
│   │   ├── ClientStrings.php        # translated client strings → window.dreSearchTranslations
│   │   └── Helper/                  # dreSearchBar (built from the connection settings, no proxy) ·
│   │                                #   dreFederatedSearch · dreSearchAssets ·
│   │                                #   DreBootstrapJson (the escaped bootstrap blob)
│   ├── Form/                        # ConfigForm (Typesense connection, search-only key) ·
│   │                                #   MaintenanceForm (CSRF)
│   ├── Security/HtmlSanitizer.php   # block introductions
│   ├── Service/                     # factories
│   └── svelte/                      # Svelte 5 + TypeScript client (built to asset/dist)
│       ├── main.ts                  #   ESM entry: mounts the header bars; imports App and
│       │                            #   FederatedApp on demand (they must stay lazy: check:size)
│       ├── App.svelte               #   one search surface: its state (query, filters, sort, page,
│       │                            #   years, view), URL sync, layout, facets, skip link
│       ├── components/
│       │   ├── FederatedApp.svelte  #   the results page: All (union) + one tab per corpus,
│       │   │                        #   each an embedded App
│       │   ├── SearchBar.svelte · SearchBox.svelte      # header and block search inputs, one
│       │   │                        #   combobox (lib/typeahead.svelte.ts)
│       │   ├── FacetPanel · FacetGroup · YearRangeFacet · FilterChip
│       │   ├── FilterLink · FilterButton    # card filter values as toggles: inline values (a span
│       │   │                        #   with role=button, see its docblock) and chips (<button>)
│       │   ├── ResultsList · ResultSummary · SortSelect · ViewToggle · Pagination ·
│       │   │   ResultSkeleton · ExportMenu · CiteMenu · CopyLinkButton · MapView
│       │   ├── ResultItem · ProjectCard · PublicationCard · PodcastCard · VideoCard ·
│       │   │   PersonCard · SectionCard · OrganisationCard · TermCard · MixedResultCard
│       │   │                        #   one card per kind (+ the union list's)
│       │   └── CardThumb · Highlight · MatchedIn · IdentifierLinks · RegisterMark ·
│       │       RecordLink · Sparkline
│       ├── lib/
│       │   ├── api.ts               #   the module's JSON endpoints (never Typesense)
│       │   ├── transport.ts         #   "latest request wins" tickets, abort test, request deadlines
│       │   ├── searchResults.svelte.ts      # what a surface has fetched (results, map) and the
│       │   │                        #   rules for fetching it; App calls run() from an $effect
│       │   ├── federatedResults.svelte.ts   # the same for the federated page: tab cache, counts
│       │   ├── typeahead.svelte.ts  #   the search-box combobox shared by SearchBox and SearchBar
│       │   ├── initialState.ts      #   may a surface adopt its server-rendered first page
│       │   ├── resultCard.ts        #   the fields a card derives from a document, as pure functions
│       │   ├── cardFilters.ts       #   the card filter toggles' context and labels
│       │   ├── types.ts             #   the JSON shapes the PHP emits
│       │   ├── urlState.ts          #   URL ↔ state codec (b{block}. / r{item}. / bare prefixes);
│       │   │                        #   a typing burst is one history entry
│       │   ├── i18n.ts              #   English strings, Intl plurals; overrides from
│       │   │                        #   window.dreSearchTranslations
│       │   ├── announce.ts          #   the live region: settled results only, after a pause
│       │   ├── highlight.ts         #   private-use match sentinels → <mark>, escaped
│       │   ├── export.ts            #   TXT / JSON / RIS / BibTeX serializers
│       │   ├── maplibreLoader.ts    #   page / vendored / pinned-CDN MapLibre (check:maplibre)
│       │   ├── tokenBridge.ts · entity.ts               # DRE-theme tokens from JS
│       │   └── filterChips · headings · identifiers · keyboard · popover · searchHistory ·
│       │       sparkline · text · thumbnail · typeGlyph
│       └── styles/                  #   buttons.css · card.css, shared by the components
├── scripts/                         # Tooling (Node unless .php); none of it ships
│   ├── shared/versions.mjs          #   the version sites: read by lint:version, written by bump
│   ├── shared/audit-exceptions.mjs  #   documented npm audit exceptions (empty)
│   ├── bump-version.mjs             #   npm run bump
│   ├── check-version-consistency.mjs    # npm run lint:version (and the release, with the tag)
│   ├── check-bundle-size.mjs        #   npm run check:size, the end of npm run build
│   ├── check-audit.mjs              #   npm run check:audit (CI)
│   ├── check-docblocks.mjs          #   npm run lint:docblocks
│   ├── check-maplibre-pins.mjs      #   npm run check:maplibre (weekly)
│   ├── check-lighthouse.mjs · lighthouse/{pages,capture,server}.mjs   # Lighthouse budgets
│   │                                #   (check:lighthouse, capture:lighthouse)
│   ├── check-design-tokens.mjs · design-token-allowlist.txt · lib/   # token lint; lib/ is
│   │                                #   VENDORED from DRE-theme (hash-checked, never edit)
│   ├── check-profile-schema.php     #   profiles ↔ schema ↔ client contracts (lint:schema)
│   ├── build-translations.php       #   npm run i18n / lint:i18n
│   └── check-psr-log-origin.php     #   CI: PSR Log must load from Omeka core
├── tests/
│   ├── phpunit/                     # PHPUnit, random order; integration cases need OMEKA_VENDOR,
│   │                                #   MySQL and Typesense (CONTRIBUTING.md); files named by the
│   │                                #   class they test (one per mapper)
│   │   └── Support/                 #   mapper fixtures, an SQLite Omeka, and Typesense/: an
│   │                                #   in-memory Typesense the CollectionOps tests drive
│   ├── stubs/omeka.stub             # PHPStan stubs
│   ├── frontend/                    # Vitest (jsdom), named by module: client units, the
│   │                                #   .svelte.ts state modules, components, the tooling
│   │                                #   scripts; runs in a west-of-Greenwich timezone
│   ├── browser/                     # Playwright against the built bundle, every request stubbed
│   │                                #   (fixture.ts): search.spec.ts (axe, toggles, skip link) ·
│   │                                #   touch-targets.spec.ts (measured, not read from the CSS)
│   └── lighthouse/                  # fixtures (captured from AMIRA), placeholder thumbnails;
│                                    #   baseline.json once CI has measured it
├── .github/workflows/
│   ├── ci.yml                       # frontend · browser · php (8.4, 8.5) · release-shape
│   ├── release.yml                  # gates (ci.yml) → build (read-only) → publish
│   ├── lighthouse.yml               # Lighthouse budgets, every PR and push to main
│   ├── maplibre-pins.yml            # weekly MapLibre lockstep with DRE Visualizations
│   └── codeql.yml
└── docs/                            # architecture · code-map · data-model · operations ·
                                     #   profile-configuration · public-api · upgrade-1.17
```

## Where to start

| To change…                       | Start at                                                                                                                                               |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| a corpus's facets, fields, sorts | its profile in `config/module.config.php` ([profile configuration](profile-configuration.md)), then its mapper and card                                |
| a new corpus or block            | a profile, a row in `Settings/BlockProfiles::LAYOUTS` and its name under `block_layouts` ([adding a corpus](profile-configuration.md#adding-a-corpus)) |
| what gets indexed from Omeka     | `Indexer/OmekaSourceRepository`, the kind's mapper (`Indexer/MapperRegistry`), `ValueBag`                                                              |
| how a rebuild publishes          | `Indexer/Reindexer`, `Indexer/CollectionOps`, `Indexer/ChangeApplier`                                                                                  |
| the Typesense query              | `Search/QueryBuilder` (one matching core, a mode per endpoint), run through `SearchExecutor`                                                           |
| a public JSON shape              | `Search/ResponseNormalizer` ([public API](public-api.md))                                                                                              |
| what the browser may ask         | `Search/SearchRequest`, `Controller/SearchController`, and `Module::PUBLIC_ACTIONS` for a new endpoint                                                 |
| which Typesense key searches     | `Search/TypesenseSearchKeyProvider`, `Search/TypesenseClientProvider` ([keys](architecture.md#keys))                                                   |
| what a surface fetches           | `src/svelte/lib/searchResults.svelte.ts` / `federatedResults.svelte.ts`, over `lib/api.ts`                                                             |
| what a result card shows         | `src/svelte/components/<Kind>Card.svelte` (`ResultItem` for research items) and its fields in `lib/resultCard.ts`                                      |
| how the bundle reaches the page  | `View/BundleAssets` + `asset/dist/manifest.json`; budgets in `scripts/check-bundle-size.mjs`                                                           |
| a version, a release             | `npm run bump`, `release.yml` ([CONTRIBUTING.md](../CONTRIBUTING.md#versions))                                                                         |
