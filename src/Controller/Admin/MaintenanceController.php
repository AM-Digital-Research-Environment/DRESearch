<?php

declare(strict_types=1);

namespace DRESearch\Controller\Admin;

use DRESearch\Form\MaintenanceForm;
use DRESearch\Indexer\AnalyticsSync;
use DRESearch\Indexer\ChangeQueue;
use DRESearch\Indexer\RebuildStateStore;
use DRESearch\Indexer\WorkerLease;
use DRESearch\Job\DrainSearchChanges;
use DRESearch\Job\IndexAllSearchProfiles;
use DRESearch\Job\IndexSearchProfile;
use DRESearch\Job\ProvisionAnalytics;
use DRESearch\Job\SyncStopwords;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;

/**
 * Admin → DRE Search. Shows the Typesense connection, each corpus' index and
 * public-search state (live, hiding pending records, or paused), the
 * incremental worker, and dispatches the rebuild/maintenance jobs.
 */
class MaintenanceController extends AbstractActionController
{
    private ?bool $healthy = null;
    private ?string $healthError = null;

    public function __construct(
        private readonly TypesenseClientProvider $provider,
        private readonly ProfileRegistry $registry,
        private readonly RebuildStateStore $stateStore,
        private readonly ChangeQueue $queue,
        private readonly ?WorkerLease $lease = null,
        private readonly int $exclusionLimit = 250,
    ) {
    }

    public function indexAction(): ViewModel
    {
        if (!\DRESearch\Module::dependenciesAvailable()) {
            $this->messenger()->addError('DRE Search is missing its vendor/ directory, so search is unavailable. Install the DRESearch.zip release asset or run "composer install --no-dev" in the module directory.'); // @translate
        }
        $view = new ViewModel([
            'configured' => $this->provider->isConfigured(),
            'profiles'   => $this->collectStatuses(),
            'worker'     => $this->workerStatus(),
            'analytics'  => $this->collectAnalytics(),
            'form'       => $this->getForm(MaintenanceForm::class),
        ]);
        $view->setTemplate('dre-search/admin/maintenance/index');
        return $view;
    }

    public function reindexAction(): \Laminas\Http\Response
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

        $allowShrink = (bool) $this->params()->fromPost('allow_shrink', false);

        if ($this->params()->fromPost('drain_queue')) {
            $this->dispatchJob(DrainSearchChanges::class, [], 'Retry of pending changes queued. Track progress in %1$sjob #%2$s%3$s.'); // @translate
        } elseif ($this->params()->fromPost('sync_stopwords')) {
            // Refresh the English stopword set without rebuilding any collection
            // (e.g. after editing data/stopwords.json).
            $this->dispatchJob(SyncStopwords::class, [], 'Stopword sync queued. Track progress in %1$sjob #%2$s%3$s.'); // @translate
        } elseif ($this->params()->fromPost('provision_analytics')) {
            $this->dispatchJob(ProvisionAnalytics::class, [], 'Search analytics provisioning queued. Track progress in %1$sjob #%2$s%3$s.'); // @translate
        } elseif ($this->params()->fromPost('reindex_all')) {
            // One background job that rebuilds every corpus in turn: gentler on
            // the host than one job per corpus, and one entry to track.
            $this->dispatchJob(IndexAllSearchProfiles::class, ['allow_shrink' => $allowShrink], 'Reindex of all corpora queued. Track progress in %1$sjob #%2$s%3$s.'); // @translate
        } else {
            $profile = $this->registry->get((string) $this->params()->fromPost('profile', ''));
            if ($profile === null) {
                $this->messenger()->addError('Unknown search profile.'); // @translate
                return $this->redirect()->toRoute('admin/dre-search');
            }
            $this->dispatchJob(
                IndexSearchProfile::class,
                ['profile' => $profile->name(), 'allow_shrink' => $allowShrink],
                'Reindex of “%4$s” queued. Track progress in %1$sjob #%2$s%3$s.', // @translate
                [$this->translate($profile->label())],
            );
        }

        return $this->redirect()->toRoute('admin/dre-search');
    }

    /**
     * Dispatch a job and report it with a link. Dispatching can fail without
     * an exception in some strategies (no job entity); say so rather than
     * dereferencing null.
     *
     * @param array<string,mixed> $args
     * @param list<string> $extra message arguments after the link (%4$s, …)
     */
    private function dispatchJob(string $class, array $args, string $template, array $extra = []): void
    {
        $job = $this->jobDispatcher()->dispatch($class, $args === [] ? null : $args);
        if ($job === null) {
            $this->messenger()->addError('The background job could not be queued. Check the Omeka job runner (PHP CLI path) under Settings.'); // @translate
            return;
        }
        $jobUrl = $this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]);
        $message = new Message(
            $template,
            sprintf('<a href="%s">', htmlspecialchars($jobUrl, ENT_QUOTES, 'UTF-8')),
            $job->getId(),
            '</a>',
            ...array_map(static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'), $extra),
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
    }

    /**
     * One status row per profile: connection state, the live collection's
     * document count (0 when the server is up but the collection was never
     * built; null when unreachable) and what public search is doing.
     *
     * @return list<array<string,mixed>>
     */
    private function collectStatuses(): array
    {
        $client = $this->provider->getClient();
        $states = $this->stateStore->all();
        $pending = $this->queue->counts();
        $oldest = $this->queue->oldest();
        $healthy = $this->probeHealth();
        $rows = [];

        foreach ($this->registry->all() as $profile) {
            $state = $states[$profile->name()] ?? [];
            $count = $pending[$profile->name()] ?? 0;
            $dirty = !empty($state['dirty']);
            $row = [
                'name'       => $profile->name(),
                'label'      => $profile->label(),
                'collection' => $profile->collection(),
                'reachable'  => false,
                'documents'  => null,
                'error'      => null,
                'state'      => $state,
                'pending'    => $count,
                'oldest_pending' => $oldest[$profile->name()] ?? null,
                // Mirrors Search\ReadinessGate: dirty or too much pending work
                // pauses the corpus; a little pending work only hides records.
                'public'     => $dirty || $count > $this->exclusionLimit ? 'paused' : ($count > 0 ? 'hiding' : 'live'),
                'rejected'   => array_values(array_filter(array_map('intval', explode(',', (string) ($state['rejected_ids'] ?? ''))))),
            ];

            if ($client !== null && $healthy) {
                try {
                    $info = $client->collections[$profile->collection()]->retrieve();
                    $row['reachable'] = true;
                    $row['documents'] = isset($info['num_documents']) ? (int) $info['num_documents'] : null;
                } catch (\Throwable $e) {
                    $row['reachable'] = true;
                    if ($e instanceof \Typesense\Exceptions\ObjectNotFound) {
                        $row['documents'] = 0;
                    } else {
                        $row['error'] = $e->getMessage();
                    }
                }
            }

            if (!$healthy) {
                $row['error'] = $this->healthError;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array{requested:bool,heartbeat:?string,alive:bool}|null */
    private function workerStatus(): ?array
    {
        try {
            return $this->lease?->status();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{enabled:bool,rows:list<array{profile:string,kind:string,q:string,count:int}>} */
    private function collectAnalytics(): array
    {
        $client = $this->provider->getClient();
        if ($client === null || !$this->probeHealth()) {
            return ['enabled' => false, 'rows' => []];
        }
        $searches = [];
        $labels = [];
        foreach ($this->registry->all() as $profile) {
            foreach (['popular' => 'Popular', 'nohits' => 'No results'] as $suffix => $kind) {
                $labels[] = ['profile' => $profile->label(), 'kind' => $kind];
                $searches[] = [
                    'collection' => AnalyticsSync::collectionName($profile->name(), $suffix),
                    'q' => '*', 'query_by' => 'q', 'sort_by' => 'count:desc', 'per_page' => 5,
                    'enable_analytics' => false, 'highlight_fields' => 'none',
                ];
            }
        }
        try {
            $response = $client->multiSearch->perform(['searches' => $searches]);
        } catch (\Throwable) {
            return ['enabled' => false, 'rows' => []];
        }
        $rows = [];
        $enabled = false;
        foreach ($response['results'] ?? [] as $i => $result) {
            if (!isset($result['error'])) {
                $enabled = true;
            }
            foreach ($result['hits'] ?? [] as $hit) {
                $doc = $hit['document'];
                $rows[] = $labels[$i] + ['q' => (string) $doc['q'], 'count' => (int) $doc['count']];
            }
        }
        return ['enabled' => $enabled, 'rows' => $rows];
    }

    private function probeHealth(): bool
    {
        if ($this->healthy !== null) {
            return $this->healthy;
        }
        try {
            $client = $this->provider->getClient();
            return $this->healthy = $client !== null && !empty($client->health->retrieve()['ok']);
        } catch (\Throwable $error) {
            $this->healthError = $error->getMessage();
            return $this->healthy = false;
        }
    }
}
