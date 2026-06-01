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

    // Page block — lets editors drop the faceted search surface onto any Site
    // page. Factory (not invokable) so render() can pull SearchProxy for the
    // server-rendered first page of results.
    'block_layouts' => [
        'factories' => [
            'dreSearch' => Service\BlockLayout\DreSearchBlockFactory::class,
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

    // Module-level defaults — read by the client provider factory and the
    // indexer. Settings (admin form) and env vars override the connection bits.
    'dre_search' => [
        'typesense' => [
            'host'       => 'typesense',
            'port'       => 8108,
            'protocol'   => 'http',
            // Alias that always points at the live collection; the reindexer
            // builds dre_research_v1_<timestamp> and swaps the alias atomically.
            'collection' => 'dre_research_current',
        ],
        'public_search' => [
            // Hard filter appended to every public query, enforced server-side.
            'filter_by' => 'is_public:=true',
        ],
        // The resource template whose items are indexed as "research items".
        // (MongoDB2OmekaS writes research items with template id 10.)
        'research_template_id' => 10,
    ],
];
