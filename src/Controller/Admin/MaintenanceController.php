<?php
declare(strict_types=1);

namespace DRESearch\Controller\Admin;

use DRESearch\Form\MaintenanceForm;
use DRESearch\Job\IndexResearchItems;
use DRESearch\Search\TypesenseClientProvider;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;

/**
 * Admin → DRE Search. Shows the Typesense connection status and a button that
 * dispatches the reindex background job.
 */
class MaintenanceController extends AbstractActionController
{
    public function __construct(private readonly TypesenseClientProvider $provider)
    {
    }

    public function indexAction(): ViewModel
    {
        $view = new ViewModel([
            'status' => $this->collectStatus(),
            'form'   => $this->getForm(MaintenanceForm::class),
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

        $job = $this->jobDispatcher()->dispatch(IndexResearchItems::class);
        $jobUrl = $this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]);
        $message = new Message(
            'Reindex queued. Track progress in %1$sjob #%2$s%3$s.', // @translate
            sprintf('<a href="%s">', htmlspecialchars($jobUrl, ENT_QUOTES, 'UTF-8')),
            $job->getId(),
            '</a>'
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        return $this->redirect()->toRoute('admin/dre-search');
    }

    /**
     * @return array{configured:bool, reachable:bool, collection:string, documents:?int, error:?string}
     */
    private function collectStatus(): array
    {
        $status = [
            'configured' => $this->provider->isConfigured(),
            'reachable'  => false,
            'collection' => $this->provider->collection(),
            'documents'  => null,
            'error'      => null,
        ];

        $client = $this->provider->getClient();
        if ($client === null) {
            return $status;
        }

        try {
            $info = $client->collections[$this->provider->collection()]->retrieve();
            $status['reachable'] = true;
            $status['documents'] = isset($info['num_documents']) ? (int) $info['num_documents'] : null;
        } catch (\Throwable $e) {
            // The collection may simply not exist yet (never reindexed). Probe
            // health to tell "server down" from "collection absent".
            try {
                $client->health->retrieve();
                $status['reachable'] = true;
                $status['documents'] = 0;
            } catch (\Throwable $inner) {
                $status['error'] = $inner->getMessage();
            }
        }

        return $status;
    }
}
