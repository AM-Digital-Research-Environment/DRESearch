<?php
declare(strict_types=1);

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

namespace DRESearch;

// Load the module's Composer autoloader at file scope so DRESearch\… classes
// resolve even on first-time install, where Omeka instantiates Module and may
// call install()/getConfigForm() before the ModuleManager autoload pipeline
// runs. Matches the ImageServer / IiifServer / IwacSearch pattern.
require_once __DIR__ . '/vendor/autoload.php';

use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
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

    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $services): void
    {
        $this->installOperationalTables($services);
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services): void
    {
        if (version_compare((string) $oldVersion, '1.17.0', '<')) {
            $this->installOperationalTables($services);
        }
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
            ['apiSearch', 'apiExport', 'apiSuggest', 'apiSuggestAll', 'apiSearchAll', 'results']
        );

        $acl->allow(
            [Acl::ROLE_EDITOR, Acl::ROLE_SITE_ADMIN, Acl::ROLE_GLOBAL_ADMIN],
            [Controller\Admin\MaintenanceController::class],
            ['index', 'reindex']
        );
    }

    /**
     * Live incremental indexing: re-map (create/update) or remove (delete) a
     * single item in its matching profile collection(s) when Omeka commits an
     * item save. Handler bodies live in Indexer\ItemEventListener so the logic
     * stays testable and Module.php keeps to lifecycle concerns. Typesense stays
     * optional — with no client configured every handler is a no-op, and any
     * Typesense error is swallowed inside the indexer so a save never fails.
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $listener = $this->resolveItemEventListener();
        if ($listener === null) {
            return;
        }

        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.post',
            [$listener, 'onItemCreate']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.post',
            [$listener, 'onItemUpdate']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.delete.pre',
            [$listener, 'onItemDeletePre']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.delete.post',
            [$listener, 'onItemDelete']
        );
        foreach (['api.batch_create.post', 'api.batch_update.post'] as $eventName) {
            $sharedEventManager->attach(\Omeka\Api\Adapter\ItemAdapter::class, $eventName, [$listener, 'onItemBatch']);
        }
        $sharedEventManager->attach(\Omeka\Api\Adapter\ItemAdapter::class, 'api.batch_delete.pre', [$listener, 'onItemBatchDeletePre']);
        $sharedEventManager->attach(\Omeka\Api\Adapter\ItemAdapter::class, 'api.batch_delete.post', [$listener, 'onItemBatchDelete']);

        foreach (['api.create.post', 'api.update.post'] as $eventName) {
            $sharedEventManager->attach(\Omeka\Api\Adapter\MediaAdapter::class, $eventName, [$listener, 'onMediaSave']);
        }
        $sharedEventManager->attach(\Omeka\Api\Adapter\MediaAdapter::class, 'api.delete.pre', [$listener, 'onMediaDeletePre']);
        $sharedEventManager->attach(\Omeka\Api\Adapter\MediaAdapter::class, 'api.delete.post', [$listener, 'onMediaDelete']);

        foreach (['api.update.pre', 'api.delete.pre'] as $eventName) {
            $sharedEventManager->attach(\Omeka\Api\Adapter\ItemSetAdapter::class, $eventName, [$listener, 'onItemSetPre']);
        }
        foreach (['api.update.post', 'api.delete.post'] as $eventName) {
            $sharedEventManager->attach(\Omeka\Api\Adapter\ItemSetAdapter::class, $eventName, [$listener, 'onItemSetPost']);
        }
    }

    /**
     * Resolve the ItemEventListener from the service manager, returning null if
     * the SL isn't available yet (extreme bootstrap edge cases). attachListeners
     * runs after the SL is built, so in normal operation this returns a real
     * listener; the null branch is defensive — a missing SL means we can't attach.
     */
    private function resolveItemEventListener(): ?Indexer\ItemEventListener
    {
        try {
            $sl = $this->getServiceLocator();
            if ($sl === null) {
                return null;
            }
            /** @var Indexer\ItemEventListener $listener */
            $listener = $sl->get(Indexer\ItemEventListener::class);
            return $listener;
        } catch (\Throwable) {
            return null;
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

        return $renderer->formCollection($form, false);
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
    }
}
