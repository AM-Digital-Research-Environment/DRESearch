<?php
declare(strict_types=1);

/**
 * DRESearch module configuration.
 *
 * Wires the public search proxy, the admin maintenance page, the dreSearch
 * page block, services, and view templates. Deliberately lean — no browse
 * tables, no scoped-key endpoints, no event listeners.
 */

namespace DRESearch;

return [
    'service_manager' => [
        'factories' => [
            // Lazily builds the Typesense client from settings → env → config
            // defaults. Reports isConfigured(); getClient() returns null when
            // Typesense is not set up, so every consumer degrades gracefully.
            Search\TypesenseClientProvider::class => Service\TypesenseClientProviderFactory::class,
            // Server-side search: forwards queries to Typesense, forcing
            // is_public:=true, and normalises the response for the client.
            Search\SearchProxy::class => Service\SearchProxyFactory::class,
            // The search profiles (corpora) and their facet / index mapping,
            // built from the 'dre_search.profiles' config below. A reuser
            // overrides templates, item sets, facets, query fields, etc. via
            // config/local.config.php — no module source edits needed.
            Settings\ProfileRegistry::class => Service\ProfileRegistryFactory::class,
        ],
    ],

    'controllers' => [
        'factories' => [
            Controller\SearchController::class            => Service\SearchControllerFactory::class,
            Controller\Admin\MaintenanceController::class => Service\Controller\MaintenanceControllerFactory::class,
        ],
    ],

    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class      => Form\ConfigForm::class,
            Form\MaintenanceForm::class => Form\MaintenanceForm::class,
        ],
    ],

    // Page blocks — let editors drop a faceted search surface onto any Site
    // page. One per corpus; factories (not invokables) so render() can pull
    // SearchProxy for the server-rendered first page of results.
    'block_layouts' => [
        'factories' => [
            // Research items: keeps the original 'dreSearch' id so blocks
            // already placed on site pages keep working after the upgrade.
            'dreSearch'             => Service\BlockLayout\ResearchItemsSearchBlockFactory::class,
            'dreSearchProjects'     => Service\BlockLayout\ResearchProjectsSearchBlockFactory::class,
            'dreSearchPublications'  => Service\BlockLayout\ResearchPublicationsSearchBlockFactory::class,
            'dreSearchPeople'        => Service\BlockLayout\ResearchPeopleSearchBlockFactory::class,
            'dreSearchSections'      => Service\BlockLayout\ResearchSectionsSearchBlockFactory::class,
            'dreSearchOrganisations' => Service\BlockLayout\ResearchOrganisationsSearchBlockFactory::class,
            'dreSearchGenres'        => Service\BlockLayout\ResearchGenresSearchBlockFactory::class,
            'dreSearchLanguages'     => Service\BlockLayout\ResearchLanguagesSearchBlockFactory::class,
            'dreSearchLocations'     => Service\BlockLayout\ResearchLocationsSearchBlockFactory::class,
            'dreSearchSubjects'      => Service\BlockLayout\ResearchSubjectsSearchBlockFactory::class,
        ],
    ],

    'view_helpers' => [
        'invokables' => [
            // Serialises the per-block bootstrap blob with consistent,
            // injection-safe JSON flags (JSON_HEX_TAG).
            'dreBootstrapJson' => View\Helper\DreBootstrapJson::class,
            // Injects the Svelte bundle into <head>; the theme calls it early in
            // layout.phtml so the header search bar (layout chrome) gets the bundle.
            'dreSearchAssets'  => View\Helper\SearchAssets::class,
        ],
        'factories' => [
            // Theme header search bar (federated autocomplete). The theme calls
            // it guarded by getHelperPluginManager()->has('dreSearchBar'), so it
            // degrades to the core search form when this module is absent.
            'dreSearchBar'       => Service\View\Helper\SearchBarFactory::class,
            // The federated results page surface (one tab per corpus).
            'dreFederatedSearch' => Service\View\Helper\FederatedSearchFactory::class,
        ],
    ],

    'router' => [
        'routes' => [
            // Public JSON proxy — the page block's search + autocomplete calls.
            // Top-level (not site-scoped) so one block works on any site; the
            // block passes basePath()-resolved URLs to the client.
            'dre-search-api-search' => [
                'type'    => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route'    => '/dre-search/api/search',
                    'defaults' => [
                        'controller' => Controller\SearchController::class,
                        'action'     => 'apiSearch',
                    ],
                ],
            ],
            'dre-search-api-suggest' => [
                'type'    => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route'    => '/dre-search/api/suggest',
                    'defaults' => [
                        'controller' => Controller\SearchController::class,
                        'action'     => 'apiSuggest',
                    ],
                ],
            ],
            // Federated autocomplete across every corpus (the theme header bar).
            'dre-search-api-suggest-all' => [
                'type'    => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route'    => '/dre-search/api/suggest-all',
                    'defaults' => [
                        'controller' => Controller\SearchController::class,
                        'action'     => 'apiSuggestAll',
                    ],
                ],
            ],
            // Federated search (per-corpus counts + the focused corpus's results)
            // — backs the grouped-by-type results page.
            'dre-search-api-search-all' => [
                'type'    => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route'    => '/dre-search/api/search-all',
                    'defaults' => [
                        'controller' => Controller\SearchController::class,
                        'action'     => 'apiSearchAll',
                    ],
                ],
            ],

            // Federated results page, site-scoped so Omeka wraps it in the active
            // theme layout and currentSite() resolves. Child of the core `site`
            // route → /s/{site-slug}/dre-search. Mirrors the admin child route
            // pattern below (own __NAMESPACE__ + FQCN controller); __SITE__ is
            // inherited from the parent route, so site context is preserved.
            'site' => [
                'child_routes' => [
                    'dre-search' => [
                        'type'    => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route'    => '/dre-search',
                            'defaults' => [
                                '__NAMESPACE__' => 'DRESearch\Controller',
                                'controller'    => Controller\SearchController::class,
                                'action'        => 'results',
                            ],
                        ],
                    ],
                ],
            ],

            // Admin maintenance: connection status + reindex dispatch.
            'admin' => [
                'child_routes' => [
                    'dre-search' => [
                        'type'    => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route'    => '/dre-search',
                            'defaults' => [
                                '__NAMESPACE__' => 'DRESearch\Controller\Admin',
                                'controller'    => Controller\Admin\MaintenanceController::class,
                                'action'        => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'reindex' => [
                                'type'    => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route'    => '/reindex',
                                    'defaults' => ['action' => 'reindex'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],

    // Sidebar entry under Omeka's "Modules" admin menu.
    'navigation' => [
        'AdminModule' => [
            [
                'label'    => 'DRE Search', // @translate
                'route'    => 'admin/dre-search',
                'resource' => Controller\Admin\MaintenanceController::class,
                'class'    => 'o-icon-search',
                'pages'    => [
                    ['route' => 'admin/dre-search/reindex', 'visible' => false],
                ],
            ],
        ],
    ],

    // ── Module configuration ────────────────────────────────────────────
    // Everything instance-specific lives here and is overridable, key by key,
    // via config/local.config.php — porting DRESearch to another Omeka instance
    // means a config override, not a source edit. The Typesense field names
    // (type_s, institution_ss, year_start, …) are the stable interface; what
    // backs each one on the Omeka side is what you change.
    //
    // Connection bits are additionally overridable by the admin settings form
    // and by environment variables (see TypesenseClientProviderFactory).
    'dre_search' => [
        'typesense' => [
            'host'     => 'typesense',
            'port'     => 8108,
            'protocol' => 'http',
        ],
        'public_search' => [
            // Hard filter appended to every public query, enforced server-side.
            'filter_by' => 'is_public:=true',
        ],

        // ── Search profiles (corpora) ───────────────────────────────────────
        // Each profile is an independent Typesense collection + facet/index
        // mapping. The first profile is the default (used when a request/block
        // omits a profile name). Add a third corpus (e.g. publications) by
        // adding a profile here + a mapper — no rewrite. Each profile's `kind`
        // selects its indexer mapper and its result card ('item' | 'project' |
        // 'publication' | 'person' | 'section' | 'organisation' | 'term'). The
        // `term` kind (genres, languages, locations, subjects & tags) is the
        // generic authority-term corpus: a name + optional Type facet + reverse-
        // link counts, all driven by config (TermMapper + TermCard).
        'profiles' => [

            // Research items — resource template 10. Eight facets resolved from
            // linked authority items (three share a property and are split by
            // the target's dcterms:type / item set; see ResearchItemMapper).
            'research_items' => [
                'label'       => 'Research items', // @translate
                // Alias that always points at the live collection; the reindexer
                // builds dre_research_<timestamp> and swaps the alias atomically.
                'collection'  => 'dre_research_current',
                'kind'        => 'item',
                'template_id' => 10,
                'item_set_id' => null, // research items aren't confined to one set
                'query_by'    => 'title,abstract,description,subject_ss,tag_ss,creator_ss',
                // mode 'single' = one origin year; facet => a year range slider.
                'date'        => ['mode' => 'single', 'label' => 'Year', 'facet' => true],

                // Authority item sets backing the facets (DRE instance defaults).
                'authority_item_sets' => [
                    'type'     => 1,
                    'language' => 19,
                    'project'  => 20,
                    'location' => 1851,
                    'subject'  => 1852,
                    'audience' => 3169,
                    'digital'  => 7438,
                    'genre'    => 21, // also on dcterms:format — excluded from digitisation
                ],

                // dcterms:type discriminator target items, used to split the
                // shared-property facets (see ResearchItemMapper).
                'type_items' => [
                    'lcsh'         => 3167,
                    'tag'          => 22199,
                    'country'      => 3168,
                    'geo_location' => 22431,
                ],

                // Facets: Typesense field => { Omeka property, UI label,
                // multi-valued? }. Order here is the display order.
                'facets' => [
                    'type_s'          => ['property' => 'dcterms:type',       'label' => 'Type',                'array' => false],
                    'project_s'       => ['property' => 'dcterms:isPartOf',   'label' => 'Project',             'array' => false],
                    // Geographic pair: Country = place of origin (dcterms:spatial,
                    // rolled up to country); Current location = where the item is
                    // held now (dcterms:provenance — a specific place OR repository
                    // institution, NOT rolled up, so e.g. "University of Bayreuth"
                    // is filterable). Same provenance link that feeds the Locations
                    // corpus's "Current location" relationship.
                    'country_ss'      => ['property' => 'dcterms:spatial',    'label' => 'Country',             'array' => true],
                    'provenance_ss'   => ['property' => 'dcterms:provenance', 'label' => 'Current location',    'array' => true],
                    'language_ss'     => ['property' => 'dcterms:language',   'label' => 'Language',            'array' => true],
                    'subject_ss'      => ['property' => 'dcterms:subject',  'label' => 'Subject',             'array' => true],
                    'tag_ss'          => ['property' => 'dcterms:subject',  'label' => 'Tag',                 'array' => true],
                    'audience_ss'     => ['property' => 'dcterms:audience', 'label' => 'Target audience',     'array' => true],
                    'digitisation_ss' => ['property' => 'dcterms:format',   'label' => 'Digitisation method', 'array' => true],
                ],

                // creator_ss is populated by ResearchItemMapper from a union of
                // role properties (see read_properties); declared here only so
                // the schema knows the field. property = null (mapper-emitted).
                'display_fields' => [
                    'creator_ss' => ['property' => null, 'type' => 'string[]', 'facet' => true],
                ],

                // Extra property terms the reindexer must read beyond the facet
                // properties (display, dates, creator roles).
                'read_properties' => [
                    'dcterms:abstract', 'dcterms:description',
                    'dcterms:issued', 'dcterms:created', 'dcterms:date',
                    'dcterms:creator', 'dcterms:contributor', 'marcrel:aut', 'marcrel:edt',
                ],
            ],

            // Research projects — resource template 5, item set 20. Links are
            // unambiguous (frapo:isFundedBy = institution, dcterms:isPartOf =
            // research section, dcterms:creator = PI), so facets resolve straight
            // from the linked title (see ProjectMapper). A date *range* from
            // dcterms:temporal, and a reverse count of the research items that
            // belong to each project (the has_items facet + card figure).
            'research_projects' => [
                'label'       => 'Research projects', // @translate
                'collection'  => 'dre_projects_current',
                'kind'        => 'project',
                'template_id' => 5,
                'item_set_id' => 20,
                'query_by'    => 'title,abstract,pi_ss,member_ss,institution_ss,section_ss',
                // mode 'range' = start/end years; facet => a year range slider.
                'date'        => ['mode' => 'range', 'property' => 'dcterms:temporal', 'label' => 'Year', 'facet' => true],

                'facets' => [
                    'institution_ss' => ['property' => 'frapo:isFundedBy', 'label' => 'Institution',       'array' => true],
                    'section_ss'     => ['property' => 'dcterms:isPartOf',  'label' => 'Research section',   'array' => true],
                    // Derived (mapper-built) facet: union of PIs + members, so a
                    // project is findable by anyone associated with it.
                    'people_ss'      => ['property' => null, 'label' => 'Associated people', 'array' => true, 'derived' => true],
                    'has_items'      => ['property' => null, 'label' => 'Has research items', 'array' => false, 'derived' => true],
                ],

                // pi_ss / member_ss are linked-title fields the mapper fills from
                // their property; pi_ids carries the matching person item ids (so
                // the card can link each PI to its Omeka page); item_count is the
                // reverse-count figure.
                'display_fields' => [
                    'pi_ss'      => ['property' => 'dcterms:creator', 'type' => 'string[]', 'facet' => false],
                    'pi_ids'     => ['property' => null, 'type' => 'string[]', 'facet' => false, 'index' => false],
                    'member_ss'  => ['property' => 'foaf:member',     'type' => 'string[]', 'facet' => false],
                    'item_count' => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                ],

                'read_properties' => ['dcterms:abstract'],

                // has_items / item_count: count research items (template 10) that
                // link to each project via dcterms:isPartOf. Public items only.
                'item_link' => [
                    'from_template' => 10,
                    'property'      => 'dcterms:isPartOf',
                    'public_only'   => true,
                ],
            ],

            // Publications — the cluster bibliography (journal articles, books,
            // chapters, theses, …). These span ~10 type-specific templates but
            // share a single item set, so the corpus is defined by item_set_id
            // (template_id is null). Links are unambiguous: bibo:authorList =
            // authors, dcterms:isPartOf = journal/book title, dcterms:publisher =
            // publisher; keywords + language are linked subjects/languages (literal
            // fallback). The card shows a formatted bibliographic reference, so the
            // mapper also emits editors, volume/issue, a page range, and a DOI link.
            'research_publications' => [
                'label'       => 'Publications', // @translate
                'collection'  => 'dre_publications_current',
                'kind'        => 'publication',
                'template_id' => null, // spans the publication-type templates (article, book, chapter, …)
                'item_set_id' => 29918, // the single "publications" item set holds them all
                'query_by'    => 'title,abstract,author_ss,editor_ss,container_ss,publisher_ss,keyword_ss',
                // mode 'single' = one publication year; facet => a year range slider.
                // dcterms:date is a numeric:timestamp whose @value is the year.
                'date'        => ['mode' => 'single', 'property' => 'dcterms:date', 'label' => 'Year', 'facet' => true],

                'facets' => [
                    'type_s'       => ['property' => 'dcterms:type',      'label' => 'Type',         'array' => false],
                    'author_ss'    => ['property' => 'bibo:authorList',   'label' => 'Author',       'array' => true],
                    'container_ss' => ['property' => 'dcterms:isPartOf',  'label' => 'Journal / Book', 'array' => true],
                    'publisher_ss' => ['property' => 'dcterms:publisher', 'label' => 'Publisher',    'array' => true],
                    'keyword_ss'   => ['property' => 'dcterms:subject',   'label' => 'Keyword',      'array' => true],
                    'language_ss'  => ['property' => 'dcterms:language',  'label' => 'Language',     'array' => true],
                ],

                // Display-only reference bits. author_ids carries the person item
                // ids parallel to author_ss (so the card links each author);
                // volume/issue/pages/doi are shown only, never searched/faceted.
                'display_fields' => [
                    'author_ids' => ['property' => null,             'type' => 'string[]', 'facet' => false, 'index' => false],
                    'editor_ss'  => ['property' => 'bibo:editorList', 'type' => 'string[]', 'facet' => false],
                    'volume_s'   => ['property' => 'bibo:volume',    'type' => 'string', 'facet' => false, 'index' => false],
                    'issue_s'    => ['property' => 'bibo:issue',     'type' => 'string', 'facet' => false, 'index' => false],
                    'pages_s'    => ['property' => null,             'type' => 'string', 'facet' => false, 'index' => false],
                    'doi_s'      => ['property' => null,             'type' => 'string', 'facet' => false, 'index' => false],
                ],

                // Properties read beyond the facet/display/date props: the abstract,
                // the page-family the mapper recombines, and the DOI URI value.
                'read_properties' => [
                    'bibo:abstract',
                    'bibo:pages', 'bibo:pageStart', 'bibo:pageEnd', 'bibo:numPages',
                    'bibo:doi',
                ],
            ],

            // People — resource template 4, item set 18. A person record carries
            // only a name + affiliation (dcterms:isPartOf → linked Institution);
            // everything else lives in the *reverse* direction. `person_links`
            // tells the reindexer how to count the records that reference each
            // person (research items, publications) and which relationships to
            // surface as roles. No date — the corpus sorts by name.
            'research_people' => [
                'label'       => 'People', // @translate
                'collection'  => 'dre_people_current',
                'kind'        => 'person',
                'template_id' => 4,
                'item_set_id' => 18,
                'query_by'    => 'title,affiliation_ss,roles_ss',
                'date'        => ['mode' => 'none'],

                'facets' => [
                    'affiliation_ss' => ['property' => 'dcterms:isPartOf', 'label' => 'Affiliation', 'array' => true],
                    // Derived from the reverse relationships below.
                    'roles_ss'       => ['property' => null, 'label' => 'Role', 'array' => true, 'derived' => true],
                ],

                // Card figures — association counts (mapper-emitted, sortable).
                'display_fields' => [
                    'item_count'        => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                    'publication_count' => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                ],

                // Reverse links: how many records reference each person, and the
                // roles they earn. Counts = DISTINCT public records of that corpus
                // referencing the person (any property). Roles = presence of a
                // reference via the given properties: a fixed label per rule, or —
                // with `per_property` — one label per distinct property the person
                // is referenced by, taken from that property's template alternate
                // label. Public only. (The same generic mechanism powers the
                // organisations corpus.)
                'reverse_links' => [
                    'counts' => [
                        'item_count'        => ['from_template' => 10,    'public_only' => true],
                        'publication_count' => ['from_item_set' => 29918, 'public_only' => true],
                    ],
                    'roles' => [
                        ['label' => 'Principal investigator', 'from_template' => 5,     'properties' => ['dcterms:creator'], 'public_only' => true],
                        ['label' => 'Project member',         'from_template' => 5,     'properties' => ['foaf:member'],     'public_only' => true],
                        ['label' => 'Author',                 'from_item_set' => 29918, 'properties' => ['bibo:authorList'], 'public_only' => true],
                        ['label' => 'Editor',                 'from_item_set' => 29918, 'properties' => ['bibo:editorList'], 'public_only' => true],
                        // Every specific contributor role a person holds on a research
                        // item, as its own facet value — one per marcrel:* relator in
                        // use (Author, Photographer, Interviewee, Translator, Collector,
                        // …), labelled by that role's template-10 alternate label. This
                        // `per_property` rule expands the whole marcrel vocabulary in a
                        // single query, replacing the former catch-all "Research
                        // contributor" bucket that hid which role each person played.
                        ['per_property' => true, 'from_template' => 10, 'vocabulary' => 'marcrel', 'public_only' => true],
                    ],
                ],
            ],

            // Research sections — resource template 7, item set 17 (the cluster's
            // 13 thematic sections incl. the synthetic "External"). A section names
            // its leaders + members and an abstract; the project count is the
            // reverse count of projects (template 5) linking via dcterms:isPartOf
            // (the same `item_link` mechanism the projects corpus uses). Phase is
            // *not* stored — it's derived from which leadership property is present
            // (PIs = Phase 1, spokesperson = Phase 2; External has neither).
            'research_sections' => [
                'label'       => 'Research sections', // @translate
                'collection'  => 'dre_sections_current',
                'kind'        => 'section',
                'template_id' => 7,
                'item_set_id' => 17,
                'query_by'    => 'title,abstract,pi_ss,spokesperson_ss,people_ss',
                'date'        => ['mode' => 'none'],

                'facets' => [
                    // Derived (mapper-built): "Phase 1" (has PIs) / "Phase 2" (has
                    // spokesperson); External has neither, so no phase value.
                    'phase_s'   => ['property' => null, 'label' => 'Phase', 'array' => false, 'derived' => true],
                    // Derived union of PIs + spokesperson + members.
                    'people_ss' => ['property' => null, 'label' => 'Associated persons', 'array' => true, 'derived' => true],
                ],

                // Leaders (linked person titles) + the two card figures. member_count
                // is counted from foaf:member; project_count comes from item_link.
                'display_fields' => [
                    'pi_ss'           => ['property' => 'dcterms:creator', 'type' => 'string[]', 'facet' => false],
                    'spokesperson_ss' => ['property' => 'marcrel:spk',    'type' => 'string[]', 'facet' => false],
                    'member_count'    => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                    'project_count'   => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                ],

                'read_properties' => ['dcterms:abstract', 'foaf:member', 'dcterms:creator', 'marcrel:spk'],

                // project_count: count public projects (template 5) that link to
                // each section via dcterms:isPartOf.
                'item_link' => [
                    'from_template' => 5,
                    'property'      => 'dcterms:isPartOf',
                    'public_only'   => true,
                ],
            ],

            // Organisations — resource template 2 (foaf:Organization), item set 110.
            // This single corpus holds BOTH institutions and groups (bands, choirs,
            // archives, …): the pipeline stores them as the same Organisation item
            // and tells them apart with dcterms:type ("Institution" / "Group"), so
            // the Type facet splits the corpus. An organisation record itself carries
            // only a name + type; everything else is in the *reverse* direction, so —
            // exactly like the people corpus — `reverse_links` counts the records that
            // reference each organisation (projects it funds, research items crediting
            // it, people affiliated with it) and derives the roles it plays. No date —
            // the corpus sorts by name.
            'research_organisations' => [
                'label'       => 'Organisations', // @translate
                'collection'  => 'dre_organisations_current',
                'kind'        => 'organisation',
                'template_id' => 2,
                'item_set_id' => 110,
                'query_by'    => 'title',
                'date'        => ['mode' => 'none'],

                'facets' => [
                    // Institution vs Group, straight from dcterms:type (linked
                    // type item → its title). Single-valued.
                    'type_s'   => ['property' => 'dcterms:type', 'label' => 'Type', 'array' => false],
                    // Derived from the reverse relationships below — how the
                    // organisation participates in the research data.
                    'roles_ss' => ['property' => null, 'label' => 'Role', 'array' => true, 'derived' => true],
                ],

                // Card figures — association counts (mapper-emitted, sortable). The
                // card shows only the non-zero ones, so groups read as "N research
                // items" while institutions read as "N projects · N people".
                'display_fields' => [
                    'project_count' => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                    'item_count'    => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                    'people_count'  => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                ],

                // Reverse links (the same mechanism as the people corpus). Counts =
                // DISTINCT public records referencing the organisation via the rule;
                // roles = presence of any such reference. Public only.
                'reverse_links' => [
                    'counts' => [
                        // Projects funded by this institution (frapo:isFundedBy).
                        'project_count' => ['from_template' => 5,  'properties' => ['frapo:isFundedBy'], 'public_only' => true],
                        // Research items crediting this organisation in any role
                        // (marcrel:* etc.) — the "contributed items" figure groups have.
                        'item_count'    => ['from_template' => 10, 'public_only' => true],
                        // People who name this organisation as their affiliation.
                        'people_count'  => ['from_template' => 4,  'properties' => ['dcterms:isPartOf'], 'public_only' => true],
                    ],
                    'roles' => [
                        ['label' => 'Funder',           'from_template' => 5,  'properties' => ['frapo:isFundedBy'], 'public_only' => true],
                        ['label' => 'Contributor',      'from_template' => 10, 'public_only' => true],
                        ['label' => 'Host institution', 'from_template' => 4,  'properties' => ['dcterms:isPartOf'], 'public_only' => true],
                    ],
                ],
            ],

            // ── Authority-term corpora ──────────────────────────────────────────
            // Genres, languages, locations, and subjects & tags. Each indexes one
            // curated authority item set and — exactly like the people and
            // organisations corpora — derives its substance from the *reverse*
            // direction: how many public records reference each term. They share
            // the generic `term` kind (TermMapper + TermCard): a name, an optional
            // Type facet straight from the term's own dcterms:type, and reverse-link
            // association counts. Scoped by item set alone (template_id null), since
            // the authority set is the definitive grouping. No date — sort by name.
            // The linking properties below are the same ones the working
            // research-items / publications facets already use, so the reverse counts
            // line up with those corpora.

            // Genres — item set 21. A research item links to a genre via
            // dcterms:format (the same property as the digitisation method, but
            // genres live in set 21, not the technical set 7438). One reverse count:
            // the public research items in each genre. No sub-type → no facets.
            'research_genres' => [
                'label'       => 'Genres', // @translate
                'placeholder' => 'Search genres…', // @translate
                'collection'  => 'dre_genres_current',
                'kind'        => 'term',
                'template_id' => null,
                'item_set_id' => 21,
                'query_by'    => 'title',
                'date'        => ['mode' => 'none'],
                'default_sort' => 'count',
                'sort_count'  => [
                    'field' => 'item_count',
                    'label' => 'Most research items', // @translate
                ],

                'facets' => [],

                'display_fields' => [
                    'item_count' => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                ],

                'reverse_links' => [
                    'counts' => [
                        'item_count' => ['from_template' => 10, 'properties' => ['dcterms:format'], 'public_only' => true],
                    ],
                ],
            ],

            // Languages — item set 19. Both research items and publications link to
            // a language via dcterms:language, so two reverse counts (items +
            // publications). No sub-type → no facets.
            'research_languages' => [
                'label'       => 'Languages', // @translate
                'placeholder' => 'Search languages…', // @translate
                'collection'  => 'dre_languages_current',
                'kind'        => 'term',
                'template_id' => null,
                'item_set_id' => 19,
                'query_by'    => 'title',
                'date'        => ['mode' => 'none'],
                'default_sort' => 'count',
                'sort_count'  => [
                    'field' => 'item_count',
                    'label' => 'Most research items', // @translate
                ],

                'facets' => [],

                'display_fields' => [
                    'item_count'        => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                    'publication_count' => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                ],

                'reverse_links' => [
                    'counts' => [
                        'item_count'        => ['from_template' => 10,    'properties' => ['dcterms:language'], 'public_only' => true],
                        'publication_count' => ['from_item_set' => 29918, 'properties' => ['dcterms:language'], 'public_only' => true],
                    ],
                ],
            ],

            // Locations — item set 1851. Two facets: the place's own dcterms:type
            // (Country vs geographic location / city), and a derived Relationship
            // facet for HOW records reference the place — Place of origin
            // (dcterms:spatial, from location.origin) vs Current location
            // (dcterms:provenance, from location.current). item_count counts the
            // research items referencing the place either way. Unlike the
            // research-items Country facet, this corpus does NOT roll cities up to
            // their country — each place term shows its own direct mention count, so
            // both "Nigeria" and "Lagos" are findable. dcterms:provenance also
            // targets repository institutions; the geocoded ones (template 2 with
            // geo:lat/geo:long) are folded in via extra_sources below, surfacing as
            // a new Type "Institution" with a "Current location" relationship.
            'research_locations' => [
                'label'       => 'Locations', // @translate
                'placeholder' => 'Search locations…', // @translate
                'collection'  => 'dre_locations_current',
                'kind'        => 'term',
                'template_id' => null,
                'item_set_id' => 1851,
                'query_by'    => 'title',
                'date'        => ['mode' => 'none'],

                // Fold geocoded organisations (template 2, set 110) into this
                // corpus alongside the place authority: the repository institutions
                // used as a research item's Current Location (dcterms:provenance)
                // that now carry geo:lat/geo:long. require_property keeps it to the
                // geocoded ones; their own dcterms:type ("Institution") flows into
                // the Type facet, and the dcterms:provenance reverse-link below
                // gives each its held-items count + "Current location" role.
                'extra_sources' => [
                    [
                        'template_id'      => 2,
                        'item_set_id'      => 110,
                        'require_property' => 'geo:lat',
                    ],
                ],
                'default_sort' => 'count',
                'sort_count'  => [
                    'field' => 'item_count',
                    'label' => 'Most research items', // @translate
                ],

                'facets' => [
                    'type_s'   => ['property' => 'dcterms:type', 'label' => 'Type', 'array' => false],
                    // Derived from the reverse relationships below.
                    'roles_ss' => ['property' => null, 'label' => 'Relationship', 'array' => true, 'derived' => true],
                ],

                'display_fields' => [
                    'item_count' => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                ],

                'reverse_links' => [
                    'counts' => [
                        // Research items referencing the place as origin OR current
                        // location (deduped — an item counted once even if both).
                        'item_count' => ['from_template' => 10, 'properties' => ['dcterms:spatial', 'dcterms:provenance'], 'public_only' => true],
                    ],
                    'roles' => [
                        ['label' => 'Place of origin',  'from_template' => 10, 'properties' => ['dcterms:spatial'],    'public_only' => true],
                        ['label' => 'Current location', 'from_template' => 10, 'properties' => ['dcterms:provenance'], 'public_only' => true],
                    ],
                ],
            ],

            // Subjects & tags — item set 1852, the same set the research-items
            // Subject / Tag facets read. A term's own dcterms:type (LCSH heading vs
            // tag) drives the Type facet, so this one corpus covers both. Research
            // items and publications both reference them via dcterms:subject; two
            // reverse counts (items + publications).
            'research_subjects' => [
                'label'       => 'Subjects & tags', // @translate
                'placeholder' => 'Search subjects & tags…', // @translate
                'collection'  => 'dre_subjects_current',
                'kind'        => 'term',
                'template_id' => null,
                'item_set_id' => 1852,
                'query_by'    => 'title',
                'date'        => ['mode' => 'none'],
                'default_sort' => 'count',
                'sort_count'  => [
                    'field' => 'item_count',
                    'label' => 'Most research items', // @translate
                ],

                'facets' => [
                    'type_s' => ['property' => 'dcterms:type', 'label' => 'Type', 'array' => false],
                ],

                'display_fields' => [
                    'item_count'        => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                    'publication_count' => ['property' => null, 'type' => 'int32', 'facet' => false, 'sort' => true],
                ],

                'reverse_links' => [
                    'counts' => [
                        'item_count'        => ['from_template' => 10,    'properties' => ['dcterms:subject'], 'public_only' => true],
                        'publication_count' => ['from_item_set' => 29918, 'properties' => ['dcterms:subject'], 'public_only' => true],
                    ],
                ],
            ],
        ],
    ],
];
