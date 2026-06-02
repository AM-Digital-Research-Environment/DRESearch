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
            'dreSearch'         => Service\BlockLayout\ResearchItemsSearchBlockFactory::class,
            'dreSearchProjects' => Service\BlockLayout\ResearchProjectsSearchBlockFactory::class,
        ],
    ],

    'view_helpers' => [
        'invokables' => [
            // Serialises the per-block bootstrap blob with consistent,
            // injection-safe JSON flags (JSON_HEX_TAG).
            'dreBootstrapJson' => View\Helper\DreBootstrapJson::class,
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
        // selects its indexer mapper and its result card ('item' | 'project').
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
                    'type_s'          => ['property' => 'dcterms:type',     'label' => 'Type',                'array' => false],
                    'project_s'       => ['property' => 'dcterms:isPartOf', 'label' => 'Project',             'array' => false],
                    'country_ss'      => ['property' => 'dcterms:spatial',  'label' => 'Country',             'array' => true],
                    'language_ss'     => ['property' => 'dcterms:language', 'label' => 'Language',            'array' => true],
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
        ],
    ],
];
