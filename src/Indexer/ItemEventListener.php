<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use Laminas\EventManager\Event;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Response as OmekaApiResponse;

/**
 * Handlers for the api.*.post events that drive incremental indexing.
 *
 * Lives separately from Module.php so the module stays focused on lifecycle +
 * bootstrap concerns and the listener can be unit-tested with a mocked
 * {@see IncrementalIndexer}. Method shapes match Laminas's `[object, 'method']`
 * callback convention so they attach without a closure wrapper.
 */
final class ItemEventListener
{
    public function __construct(
        private readonly IncrementalIndexer $indexer
    ) {
    }

    /** Index a newly-created item across its matching profile collection(s). */
    public function onItemCreate(Event $event): void
    {
        $item = $this->extractItem($event);
        if ($item === null) {
            return;
        }
        $this->indexer->indexItem((int) $item->id());
    }

    /** Re-map and upsert after Omeka commits an item edit. */
    public function onItemUpdate(Event $event): void
    {
        $item = $this->extractItem($event);
        if ($item === null) {
            return;
        }
        $this->indexer->indexItem((int) $item->id());
    }

    /** Remove the document(s) after an item delete. */
    public function onItemDelete(Event $event): void
    {
        $item = $this->extractItem($event);
        if ($item === null) {
            return;
        }
        $this->indexer->deleteItem((int) $item->id());
    }

    /**
     * Pull the ItemRepresentation out of an api.*.post event. Defensive about
     * the shape so a future Omeka change returns null rather than fataling.
     */
    private function extractItem(Event $event): ?ItemRepresentation
    {
        $response = $event->getParam('response');
        if (!$response instanceof OmekaApiResponse) {
            return null;
        }
        $content = $response->getContent();
        return $content instanceof ItemRepresentation ? $content : null;
    }
}
