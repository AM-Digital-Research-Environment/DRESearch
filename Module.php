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
            'api.delete.post',
            [$listener, 'onItemDelete']
        );
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
            $data[$key] = $settings->get($key, '');
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
        // Typesense collections are intentionally left untouched — they may be
        // shared with a parallel install, and dropping data on uninstall is
        // surprising. Clean them up from the Typesense side if needed.
    }
}
