<?php
declare(strict_types=1);

namespace DRESearch\Controller\Admin;

use DRESearch\Form\MaintenanceForm;
use DRESearch\Job\IndexAllSearchProfiles;
use DRESearch\Job\IndexSearchProfile;
use DRESearch\Job\SyncStopwords;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;

/**
 * Admin → DRE Search. Shows the Typesense connection status and, per search
 * profile, the collection's document count and a button that dispatches that
 * profile's reindex background job.
 */
class MaintenanceController extends AbstractActionController
{
    public function __construct(
        private readonly TypesenseClientProvider $provider,
        private readonly ProfileRegistry $registry,
    ) {
    }

    public function indexAction(): ViewModel
    {
        $view = new ViewModel([
            'configured' => $this->provider->isConfigured(),
            'profiles'   => $this->collectStatuses(),
            'form'       => $this->getForm(MaintenanceForm::class),
        ]);
        $view->setTemplate('dre-search/admin/maintenance/index');
        return $view;
    }

    public function reindexAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/dre-search');
        }

        $form = $this->getForm(MaintenanceForm::class);
        $form->setData($this->params()->fromPost());
        if (!$form->isValid()) {
            $this->messenger()->addError('Invalid form submission. Please try again.'); // @translate
            return $this->redirect()->toRoute('admin/dre-search');
        }

        if (!$this->provider->isConfigured()) {
            $this->messenger()->addError('Typesense is not configured. Set the connection under Modules → DRE Search → Configure.'); // @translate
            return $this->redirect()->toRoute('admin/dre-search');
        }

        // "Sync stopwords" — refresh the FR/EN/DE stopword set on Typesense without
        // rebuilding any collection (e.g. after editing data/stopwords.json).
        if ($this->params()->fromPost('sync_stopwords')) {
            $job = $this->jobDispatcher()->dispatch(SyncStopwords::class);
            $jobUrl = $this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]);
            $message = new Message(
                'Stopword sync queued. Track progress in %1$sjob #%2$s%3$s.', // @translate
                sprintf('<a href="%s">', htmlspecialchars($jobUrl, ENT_QUOTES, 'UTF-8')),
                $job->getId(),
                '</a>'
            );
            $message->setEscapeHtml(false);
            $this->messenger()->addSuccess($message);
            return $this->redirect()->toRoute('admin/dre-search');
        }

        // "Reindex all" — one background job that rebuilds every corpus in turn
        // (gentler on the host than dispatching one job per corpus, and a single
        // entry to track in Admin → Jobs).
        if ($this->params()->fromPost('reindex_all')) {
            $job = $this->jobDispatcher()->dispatch(IndexAllSearchProfiles::class);
            $jobUrl = $this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]);
            $message = new Message(
                'Reindex of all corpora queued. Track progress in %1$sjob #%2$s%3$s.', // @translate
                sprintf('<a href="%s">', htmlspecialchars($jobUrl, ENT_QUOTES, 'UTF-8')),
                $job->getId(),
                '</a>'
            );
            $message->setEscapeHtml(false);
            $this->messenger()->addSuccess($message);
            return $this->redirect()->toRoute('admin/dre-search');
        }

        $profile = $this->registry->get((string) $this->params()->fromPost('profile', ''));
        if ($profile === null) {
            $this->messenger()->addError('Unknown search profile.'); // @translate
            return $this->redirect()->toRoute('admin/dre-search');
        }

        $job = $this->jobDispatcher()->dispatch(IndexSearchProfile::class, ['profile' => $profile->name()]);
        $jobUrl = $this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]);
        $message = new Message(
            'Reindex of “%1$s” queued. Track progress in %2$sjob #%3$s%4$s.', // @translate
            $profile->label(),
            sprintf('<a href="%s">', htmlspecialchars($jobUrl, ENT_QUOTES, 'UTF-8')),
            $job->getId(),
            '</a>'
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        return $this->redirect()->toRoute('admin/dre-search');
    }

    /**
     * One status row per profile: connection state plus the live collection's
     * document count (0 when the server is up but the collection was never
     * built; null when unreachable).
     *
     * @return list<array{name:string, label:string, collection:string, reachable:bool, documents:?int, error:?string}>
     */
    private function collectStatuses(): array
    {
        $client = $this->provider->getClient();
        $rows = [];

        foreach ($this->registry->all() as $profile) {
            $row = [
                'name'       => $profile->name(),
                'label'      => $profile->label(),
                'collection' => $profile->collection(),
                'reachable'  => false,
                'documents'  => null,
                'error'      => null,
            ];

            if ($client !== null) {
                try {
                    $info = $client->collections[$profile->collection()]->retrieve();
                    $row['reachable'] = true;
                    $row['documents'] = isset($info['num_documents']) ? (int) $info['num_documents'] : null;
                } catch (\Throwable $e) {
                    // The collection may simply not exist yet (never reindexed).
                    // Probe health to tell "server down" from "collection absent".
                    try {
                        $client->health->retrieve();
                        $row['reachable'] = true;
                        $row['documents'] = 0;
                    } catch (\Throwable $inner) {
                        $row['error'] = $inner->getMessage();
                    }
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
