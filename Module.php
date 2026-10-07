<?php

/**
 * DRESearch — Omeka S module.
 *
 * Faceted, Typesense-backed search over the DRE "research items" corpus. The
 * index is built on demand from the Omeka dashboard (Admin → DRE Search →
 * Reindex), reading resources straight out of the Omeka database — there is no
 * external ingestion pipeline.
 *
 * Typesense is OPTIONAL. With no connection configured the module installs
 * cleanly, the admin status page says so, and the search block renders a quiet
 * "search unavailable" notice instead of erroring. Nothing here assumes
 * Typesense is reachable at request time.
 */

declare(strict_types=1);

namespace DRESearch;

// Load the module's Composer autoloader at file scope so DRESearch\… classes
// resolve even on first-time install, where Omeka instantiates Module and may
// call install()/getConfigForm() before the ModuleManager autoload pipeline
// runs. Omeka require_once's every active module's Module.php on EVERY request,
// so a missing vendor/ (a source checkout without `composer install`, GitHub's
// "Source code" archive) must degrade this module rather than fatal the whole
// site: install() refuses, and every runtime path reports search unavailable.
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Keep the module's own classes loadable (view helpers, blocks, the admin
    // page) so the theme's header bar and search blocks render their
    // "unavailable" state instead of fataling; only the Typesense SDK is absent.
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'DRESearch\\')) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Stdlib\ArrayUtils;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Permissions\Acl;

class Module extends AbstractModule
{
    /** Settings keys owned by this module (created on configure, dropped on uninstall). */
    private const SETTINGS = [
        'dre_search_typesense_host',
        'dre_search_typesense_port',
        'dre_search_typesense_protocol',
        'dre_search_typesense_api_key',
    ];

    /**
     * Every public SearchController action. A new action MUST be listed here:
     * otherwise anonymous requests fail with PermissionDenied before the action
     * runs (a test asserts the list matches the controller).
     */
    public const PUBLIC_ACTIONS = [
        'apiSearch', 'apiFacet', 'apiExport', 'apiSuggest', 'apiSuggestAll',
        'apiSearchAll', 'apiUnion', 'apiMap', 'apiHealth', 'results',
    ];

    /** Built on the first observed write, then reused for the request. */
    private ?Indexer\ItemEventListener $itemEventListener = null;
    private bool $itemEventListenerResolved = false;

    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * True when the module's Composer dependencies are installed. The release
     * archive (DRESearch.zip) always carries vendor/; a bare source checkout
     * does not until `composer install` has run.
     */
    public static function dependenciesAvailable(): bool
    {
        return class_exists(\Typesense\Client::class);
    }

    public function install(ServiceLocatorInterface $services): void
    {
        if (!self::dependenciesAvailable()) {
            throw new ModuleCannotInstallException(
                'DRE Search is missing its vendor/ directory. Install the DRESearch.zip release asset, '
                . 'or run "composer install --no-dev" in the module directory.'
            );
        }
        $this->installOperationalTables($services);
    }

    /**
     * Omeka runs upgrade() while the module is still in the "needs upgrade"
     * state, which is NOT active: Omeka merges configuration only for active
     * modules, so none of this module's service factories are registered here.
     * Build what the migration needs from core services (the connection and
     * the application config) — never `$services->get(<module service>)`.
     */
    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services): void
    {
        if (version_compare((string) $oldVersion, '1.22.0', '<')) {
            $this->installOperationalTables($services);
            $connection = $services->get('Omeka\Connection');
            // DBAL 2.13 (Omeka 4.2.x core) has no createSchemaManager().
            $columns = $connection->getSchemaManager()->listTableColumns('dre_search_profile_state');
            if (!isset($columns['dirty_revision'])) {
                $connection->executeStatement("ALTER TABLE dre_search_profile_state ADD dirty_revision CHAR(32) NOT NULL DEFAULT ''");
            }
            (new Indexer\RebuildStateStore($connection))->markDirty(
                $this->profileNames($services),
                'Visibility rules changed. Rebuild all profiles before serving search.',
            );
        }
        if (version_compare((string) $oldVersion, '1.23.0', '<')) {
            // Coalesced drain worker lease + rejected-document report. No
            // rebuild needed: the documents and their schema are unchanged.
            $this->installOperationalTables($services);
            $connection = $services->get('Omeka\Connection');
            $columns = $connection->getSchemaManager()->listTableColumns('dre_search_profile_state');
            if (!isset($columns['rejected_ids'])) {
                $connection->executeStatement('ALTER TABLE dre_search_profile_state ADD rejected_ids TEXT NULL');
            }
        }
    }

    /**
     * Profile names as the running site will see them: the module's own
     * profiles plus any `dre_search.profiles` added in config/local.config.php
     * (which is part of the application config even while this module is
     * inactive). Names only — a migration must not fail on an invalid override.
     *
     * @return list<string>
     */
    private function profileNames(ServiceLocatorInterface $services): array
    {
        $profiles = $this->getConfig()['dre_search']['profiles'] ?? [];
        if ($services->has('Config')) {
            $overrides = $services->get('Config')['dre_search']['profiles'] ?? [];
            if (is_array($overrides)) {
                $profiles = ArrayUtils::merge($profiles, $overrides);
            }
        }
        return array_values(array_map('strval', array_keys($profiles)));
    }

    /**
     * ACL: open the public search proxy to anonymous visitors (the page block's
     * search + autocomplete calls), and the admin maintenance/reindex actions
     * to editors and above. The /admin parent route already enforces auth, so
     * the second grant only narrows which admin roles pass.
     */
    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /** @var Acl $acl */
        $acl = $event->getApplication()->getServiceManager()->get('Omeka\Acl');

        $acl->allow(
            null,
            [Controller\SearchController::class],
            self::PUBLIC_ACTIONS
        );

        $acl->allow(
            [Acl::ROLE_EDITOR, Acl::ROLE_SITE_ADMIN, Acl::ROLE_GLOBAL_ADMIN],
            [Controller\Admin\MaintenanceController::class],
            ['index', 'reindex']
        );
    }

    /**
     * Queue changed items and their dependencies after Omeka writes. Handler
     * bodies live in Indexer\ItemEventListener; a coalesced background worker
     * performs the Typesense writes. The listener (and the indexer, profile
     * registry and client provider behind it) is built on the first write
     * event, not on every request: anonymous page views never construct it.
     *
     * Omeka's batch create/update/delete fire the per-resource events for each
     * id, so batch events are deliberately not observed.
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $items = \Omeka\Api\Adapter\ItemAdapter::class;
        $media = \Omeka\Api\Adapter\MediaAdapter::class;
        $sets = \Omeka\Api\Adapter\ItemSetAdapter::class;
        $templates = \Omeka\Api\Adapter\ResourceTemplateAdapter::class;
        foreach (
            [
                [$items, 'api.create.post', 'onItemCreate'],
                [$items, 'api.update.pre', 'onItemUpdatePre'],
                [$items, 'api.update.post', 'onItemUpdate'],
                [$items, 'api.delete.pre', 'onItemDeletePre'],
                [$items, 'api.delete.post', 'onItemDelete'],
                [$media, 'api.create.post', 'onMediaSave'],
                [$media, 'api.update.pre', 'onMediaDeletePre'],
                [$media, 'api.update.post', 'onMediaSave'],
                [$media, 'api.delete.pre', 'onMediaDeletePre'],
                [$media, 'api.delete.post', 'onMediaDelete'],
                [$sets, 'api.delete.pre', 'onItemSetDeletePre'],
                [$sets, 'api.delete.post', 'onItemSetDelete'],
                [$templates, 'api.update.pre', 'onResourceTemplatePre'],
                [$templates, 'api.update.post', 'onResourceTemplatePost'],
                [$templates, 'api.delete.pre', 'onResourceTemplatePre'],
                [$templates, 'api.delete.post', 'onResourceTemplatePost'],
            ] as [$identifier, $eventName, $method]
        ) {
            $sharedEventManager->attach($identifier, $eventName, function (\Laminas\EventManager\EventInterface $event) use ($method): void {
                $listener = $this->resolveItemEventListener();
                if ($listener === null || !$event instanceof \Laminas\EventManager\Event) {
                    return;
                }
                try {
                    $listener->{$method}($event);
                } catch (\Throwable $error) {
                    // Indexing must never roll back or break the user's write.
                    $this->logIndexingFailure('DRESearch: ' . $method . ' failed: ' . $error->getMessage());
                }
            });
        }
    }

    /**
     * Resolve (once) the ItemEventListener from the service manager. A failure
     * — e.g. an invalid profile override in local.config.php — disables
     * incremental indexing for this request and is logged, never silent.
     */
    private function resolveItemEventListener(): ?Indexer\ItemEventListener
    {
        if ($this->itemEventListenerResolved) {
            return $this->itemEventListener;
        }
        $this->itemEventListenerResolved = true;
        try {
            $sl = $this->getServiceLocator();
            if ($sl === null) {
                return null;
            }
            /** @var Indexer\ItemEventListener $listener */
            $listener = $sl->get(Indexer\ItemEventListener::class);
            return $this->itemEventListener = $listener;
        } catch (\Throwable $error) {
            $this->logIndexingFailure('DRESearch: incremental indexing is disabled for this request: ' . $error->getMessage());
            return null;
        }
    }

    private function logIndexingFailure(string $message): void
    {
        try {
            $this->getServiceLocator()?->get('Omeka\Logger')->err($message);
        } catch (\Throwable) {
            error_log($message);
        }
    }

    /**
     * Module configuration form (Modules → DRE Search → Configure): the
     * Typesense connection. Values are stored in Omeka settings; an env var or
     * a module.config.php default can still override at resolve time (see
     * Service\TypesenseClientProviderFactory).
     */
    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(Form\ConfigForm::class);

        $data = [];
        foreach (self::SETTINGS as $key) {
            // Never place the stored secret back into the rendered admin DOM.
            $data[$key] = $key === 'dre_search_typesense_api_key' ? '' : $settings->get($key, '');
        }
        $form->setData($data);

        return $renderer->formCollection($form, false) . $this->connectionSources($renderer, $settings);
    }

    /**
     * Where each connection value in effect comes from. A saved setting wins
     * over the environment, so a key saved once silently shadowed a rotated
     * TYPESENSE_API_KEY; saying so here makes that visible.
     */
    private function connectionSources(PhpRenderer $renderer, \Omeka\Settings\Settings $settings): string
    {
        $defaults = $this->getConfig()['dre_search']['typesense'] ?? [];
        $rows = [];
        foreach (
            [
                'Host' => ['dre_search_typesense_host', 'TYPESENSE_HOST', $defaults['host'] ?? ''],
                'Port' => ['dre_search_typesense_port', 'TYPESENSE_PORT', $defaults['port'] ?? ''],
                'Protocol' => ['dre_search_typesense_protocol', 'TYPESENSE_PROTOCOL', $defaults['protocol'] ?? ''],
                'API key' => ['dre_search_typesense_api_key', 'TYPESENSE_API_KEY', ''],
            ] as $label => [$setting, $env, $default]
        ) {
            if ((string) $settings->get($setting, '') !== '') {
                $source = $renderer->translate('saved in these settings');
            } elseif (($value = getenv($env)) !== false && $value !== '') {
                $source = sprintf($renderer->translate('environment variable %s'), $env);
            } elseif ((string) $default !== '') {
                $source = $renderer->translate('module default');
            } else {
                $source = $renderer->translate('not set');
            }
            $rows[] = sprintf(
                '<li><strong>%s</strong>: %s</li>',
                $renderer->escapeHtml($renderer->translate($label)),
                $renderer->escapeHtml($source),
            );
        }
        return '<div class="field"><p>' . $renderer->escapeHtml($renderer->translate('Connection values in effect:')) . '</p><ul>'
            . implode('', $rows) . '</ul></div>';
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(Form\ConfigForm::class);

        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        $data = $form->getData();
        foreach (self::SETTINGS as $key) {
            if ($key === 'dre_search_typesense_api_key') {
                if (!empty($data['dre_search_clear_api_key'])) {
                    $settings->set($key, '');
                } elseif ((string) ($data[$key] ?? '') !== '') {
                    $settings->set($key, (string) $data[$key]);
                }
                continue;
            }
            $settings->set($key, (string) ($data[$key] ?? ''));
        }
        return true;
    }

    public function uninstall(\Laminas\ServiceManager\ServiceLocatorInterface $services): void
    {
        $settings = $services->get('Omeka\Settings');
        foreach (self::SETTINGS as $key) {
            $settings->delete($key);
        }
        $connection = $services->get('Omeka\Connection');
        // The generation table is the only record of which Typesense
        // collections this module created. Typesense data is deliberately left
        // in place (it may be shared); record the names so they can be removed
        // from the Typesense side without guessing by prefix.
        try {
            $owned = $connection->executeQuery('SELECT collection_name FROM dre_search_generation')->fetchFirstColumn();
            if ($owned !== [] && $services->has('Omeka\Logger')) {
                $services->get('Omeka\Logger')->notice(
                    'DRESearch uninstalled. Typesense collections it created were left in place: ' . implode(', ', $owned)
                );
            }
        } catch (\Throwable) {
            // Table already gone: nothing to report.
        }
        $connection->executeStatement('DROP TABLE IF EXISTS dre_search_change');
        $connection->executeStatement('DROP TABLE IF EXISTS dre_search_worker');
        $connection->executeStatement('DROP TABLE IF EXISTS dre_search_cache');
        $connection->executeStatement('DROP TABLE IF EXISTS dre_search_rate_limit');
        $connection->executeStatement('DROP TABLE IF EXISTS dre_search_generation');
        $connection->executeStatement('DROP TABLE IF EXISTS dre_search_profile_state');
        // Typesense collections are intentionally left untouched — they may be
        // shared with a parallel install, and dropping data on uninstall is
        // surprising. Clean them up from the Typesense side if needed.
    }

    private function installOperationalTables(ServiceLocatorInterface $services): void
    {
        $connection = $services->get('Omeka\Connection');
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS dre_search_profile_state (
    profile VARCHAR(100) NOT NULL PRIMARY KEY,
    collection_alias VARCHAR(255) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'unconfigured',
    live_collection VARCHAR(255) NULL,
    previous_collection VARCHAR(255) NULL,
    active_job_id VARCHAR(64) NULL,
    active_collection VARCHAR(255) NULL,
    dirty TINYINT(1) NOT NULL DEFAULT 0,
    dirty_reason VARCHAR(255) NULL,
    dirty_revision CHAR(32) NOT NULL DEFAULT '',
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_failure_at DATETIME NULL,
    last_duration_ms INT NULL,
    last_documents INT NULL,
    documents_attempted INT NOT NULL DEFAULT 0,
    documents_imported INT NOT NULL DEFAULT 0,
    documents_failed INT NOT NULL DEFAULT 0,
    last_error_code VARCHAR(64) NULL,
    rejected_ids TEXT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_dre_search_state_status (status),
    INDEX idx_dre_search_state_dirty (dirty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS dre_search_generation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profile VARCHAR(100) NOT NULL,
    collection_name VARCHAR(255) NOT NULL,
    session_token CHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    promoted_at DATETIME NULL,
    UNIQUE INDEX uniq_dre_search_collection (collection_name),
    INDEX idx_dre_search_generation_profile (profile, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS dre_search_rate_limit (
    bucket_key CHAR(64) NOT NULL PRIMARY KEY,
    window_started DATETIME NOT NULL,
    request_count INT NOT NULL DEFAULT 0,
    INDEX idx_dre_search_rate_window (window_started)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS dre_search_change (
    profile VARCHAR(100) NOT NULL,
    item_id INT NOT NULL,
    revision CHAR(32) NOT NULL,
    queued_at DATETIME NOT NULL,
    PRIMARY KEY (profile, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS dre_search_worker (
    name VARCHAR(32) NOT NULL PRIMARY KEY,
    requested TINYINT(1) NOT NULL DEFAULT 0,
    heartbeat DATETIME NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS dre_search_cache (
    cache_key CHAR(64) NOT NULL PRIMARY KEY,
    payload MEDIUMTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_dre_cache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
}
