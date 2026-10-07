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
use DRESearch\Search\PopularAnalytics;
use DRESearch\Search\PopularModeration;
use DRESearch\Search\PopularSearches;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;

/**
 * Admin → DRE Search. Shows the Typesense connection, each corpus' index and
 * public-search state (live, hiding pending records, or paused), the
 * incremental worker, and dispatches the rebuild/maintenance jobs. Editors
 * also moderate the popular searches here: none is shown until approved.
 */
class MaintenanceController extends AbstractActionController
{
    /** Queries awaiting review listed per corpus; hiding some brings up the next. */
    private const REVIEW_ROWS = 20;

    private ?bool $healthy = null;
    private ?string $healthError = null;

    public function __construct(
        private readonly TypesenseClientProvider $provider,
        private readonly ProfileRegistry $registry,
        private readonly RebuildStateStore $stateStore,
        private readonly ChangeQueue $queue,
        private readonly ?WorkerLease $lease = null,
        private readonly int $exclusionLimit = 250,
        private readonly ?PopularModeration $moderation = null,
        /** @var array{enabled?:bool,min_count?:int,limit?:int} the `popular_searches` config */
        private readonly array $popularSearches = [],
    ) {
    }

    public function indexAction(): ViewModel
    {
        if (!\DRESearch\Module::phpSupported()) {
            $this->messenger()->addError(sprintf(
                $this->translate('DRE Search requires PHP %1$s or newer and this server runs PHP %2$s, so search is unavailable.'), // @translate
                \DRESearch\MIN_PHP_VERSION,
                PHP_VERSION,
            ));
        } elseif (!\DRESearch\Module::dependenciesAvailable()) {
            $this->messenger()->addError('DRE Search is missing its vendor/ directory, so search is unavailable. Install the DRESearch.zip release asset or run "composer install --no-dev" in the module directory.'); // @translate
        }
        $view = new ViewModel([
            'configured' => $this->provider->isConfigured(),
            'profiles'   => $this->collectStatuses(),
            'worker'     => $this->workerStatus(),
            'analytics'  => $this->collectAnalytics(),
            'popular'    => $this->collectPopular(),
            'form'       => $this->getForm(MaintenanceForm::class),
        ]);
        $view->setTemplate('dre-search/admin/maintenance/index');
        return $view;
    }

    /**
     * Approve, hide or revoke one popular-search candidate. The pressed button
     * names the decision and carries the query; the form carries the corpus.
     */
    public function moderateAction(): \Laminas\Http\Response
    {
        $back = fn(): \Laminas\Http\Response => $this->redirect()->toRoute('admin/dre-search', [], ['fragment' => 'dre-search-popular']);
        if (!$this->getRequest()->isPost()) {
            return $back();
        }
        $form = $this->getForm(MaintenanceForm::class);
        $form->setData($this->params()->fromPost());
        if (!$form->isValid()) {
            $this->messenger()->addError('Invalid form submission. Please try again.'); // @translate
            return $back();
        }
        $profile = $this->registry->get((string) $this->params()->fromPost('profile', ''));
        if ($profile === null) {
            $this->messenger()->addError('Unknown search profile.'); // @translate
            return $back();
        }
        $decision = null;
        $query = '';
        foreach (['approve', 'hide', 'revoke'] as $candidate) {
            $value = $this->params()->fromPost($candidate);
            if (is_string($value) && $value !== '') {
                [$decision, $query] = [$candidate, $value];
                break;
            }
        }
        if ($decision === null || $this->moderation === null) {
            $this->messenger()->addError('No moderation decision was submitted.'); // @translate
            return $back();
        }

        $identity = $this->identity();
        $userId = $identity instanceof \Omeka\Entity\User ? $identity->getId() : null;
        $args = [PopularSearches::clean($query), $this->translate($profile->label())];
        try {
            if ($decision === 'approve') {
                $this->moderation->approve($profile->name(), $query, $userId);
                $template = 'Approved “%1$s”: it can now appear among the popular searches of %2$s.'; // @translate
            } elseif ($decision === 'hide') {
                $this->moderation->hide($profile->name(), $query, $userId);
                $template = 'Hid “%1$s” from the popular searches of %2$s.'; // @translate
            } elseif ($this->moderation->revoke($profile->name(), $query)) {
                $template = 'Withdrew the decision on “%1$s” for %2$s: it is back in review and not shown.'; // @translate
            } else {
                $template = 'There was no decision on “%1$s” for %2$s to withdraw.'; // @translate
            }
        } catch (\InvalidArgumentException) {
            $this->messenger()->addError('This query can never be shown to visitors (too short or long, or shaped like personal data).'); // @translate
            return $back();
        } catch (\Throwable $error) {
            $this->logger()->err('DRESearch: could not save a popular-search decision: ' . $error->getMessage());
            $this->messenger()->addError('The decision could not be saved. If the module was just updated, run its upgrade under Modules.'); // @translate
            return $back();
        }
        // Message escapes its arguments: the query is visitor-typed text.
        $this->messenger()->addSuccess(new Message($template, ...$args));
        return $back();
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
            $this->dispatchJob(SyncStopwords::class, [], 'Stopword and synonym sync queued. Track progress in %1$sjob #%2$s%3$s.'); // @translate
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

    /**
     * The popular-search moderation queue: per corpus, the candidates
     * {@see PopularSearches} lets through (most-typed first, at most
     * REVIEW_ROWS awaiting review) with their decision, then decisions on
     * queries that are not candidates now. `shown` marks what visitors see.
     *
     * @return array{enabled:bool, store:bool, analytics:bool, min_count:int, limit:int,
     *     profiles:list<array{name:string, label:string, more:int,
     *         rows:list<array{q:string, count:?int, status:string, shown:bool, decided_at:?string}>,
     *         hidden:list<array{q:string, count:?int, status:string, shown:bool, decided_at:?string}>}>}
     */
    private function collectPopular(): array
    {
        $minCount = max(1, (int) ($this->popularSearches['min_count'] ?? 5));
        $limit = max(1, min(10, (int) ($this->popularSearches['limit'] ?? 5)));
        $client = $this->probeHealth() ? $this->provider->getClient() : null;
        $store = $this->moderation !== null;
        $analytics = false;
        $profiles = [];
        foreach ($this->registry->all() as $profile) {
            try {
                $decisions = $this->moderation?->decisions($profile->name()) ?? [];
            } catch (\Throwable) {
                $decisions = [];
                $store = false;
            }
            $candidates = [];
            if ($client !== null) {
                try {
                    $fetched = PopularAnalytics::fetch($client, $profile->name(), $minCount);
                    $analytics = $analytics || $fetched['available'];
                    $candidates = PopularSearches::candidates($fetched['popular'], $fetched['nohits'], $minCount);
                } catch (\Throwable) {
                    // Unreachable analytics: list the decisions alone.
                }
            }
            $approved = array_map(
                static fn(array $d): string => $d['query'],
                array_filter($decisions, static fn(array $d): bool => $d['status'] === PopularModeration::APPROVED),
            );
            $shown = array_flip(array_map(
                [PopularSearches::class, 'key'],
                PopularSearches::approved($candidates, $approved, $limit),
            ));

            $rows = [];
            $hidden = [];
            $awaiting = 0;
            $more = 0;
            $row = static fn(string $q, ?int $count, ?array $decision, bool $isShown): array => [
                'q' => $decision['query'] ?? $q,
                'count' => $count,
                'status' => $decision['status'] ?? 'pending',
                'shown' => $isShown,
                'decided_at' => $decision['decided_at'] ?? null,
            ];
            foreach ($candidates as $candidate) {
                $key = PopularSearches::key($candidate['q']);
                $decision = $decisions[$key] ?? null;
                unset($decisions[$key]);
                if ($decision === null && $awaiting++ >= self::REVIEW_ROWS) {
                    $more++;
                    continue;
                }
                $entry = $row($candidate['q'], $candidate['count'], $decision, isset($shown[$key]));
                if ($entry['status'] === PopularModeration::HIDDEN) {
                    $hidden[] = $entry;
                } else {
                    $rows[] = $entry;
                }
            }
            foreach ($decisions as $decision) {
                $entry = $row($decision['query'], null, $decision, false);
                if ($entry['status'] === PopularModeration::HIDDEN) {
                    $hidden[] = $entry;
                } else {
                    $rows[] = $entry;
                }
            }
            if ($rows !== [] || $hidden !== [] || $more > 0) {
                $profiles[] = [
                    'name' => $profile->name(),
                    'label' => $profile->label(),
                    'rows' => $rows,
                    'hidden' => $hidden,
                    'more' => $more,
                ];
            }
        }
        return [
            'enabled' => (bool) ($this->popularSearches['enabled'] ?? false),
            'store' => $store,
            'analytics' => $analytics,
            'min_count' => $minCount,
            'limit' => $limit,
            'profiles' => $profiles,
        ];
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
