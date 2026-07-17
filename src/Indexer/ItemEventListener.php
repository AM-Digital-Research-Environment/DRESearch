<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;
use Laminas\EventManager\Event;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Response as OmekaApiResponse;

/**
 * Defensive Omeka event adapter covering single/batch items, media parents,
 * and item-set membership/destruction. IDs needed after destructive operations
 * are captured during the corresponding pre event.
 */
final class ItemEventListener
{
    /** @var list<int> */
    private array $pendingItemDeletes = [];
    /** @var list<int> */
    private array $pendingDeleteDependencies = [];
    /** @var list<int> */
    private array $pendingMediaParents = [];
    /** @var list<int> */
    private array $pendingItemSetMembers = [];

    public function __construct(
        private readonly IncrementalIndexer $indexer,
        private readonly Connection $connection,
    ) {
    }

    public function onItemCreate(Event $event): void
    {
        foreach ($this->itemIdsFromResponse($event) as $id) {
            $this->indexer->syncItemWithDependencies($id);
        }
    }

    public function onItemUpdate(Event $event): void
    {
        $this->onItemCreate($event);
    }

    public function onItemDeletePre(Event $event): void
    {
        $ids = $this->requestIds($event);
        $this->pendingItemDeletes = array_values(array_unique(array_merge($this->pendingItemDeletes, $ids)));
        foreach ($ids as $id) {
            $dependencies = $this->fetchFirstColumn(
                'SELECT DISTINCT resource_id FROM value WHERE value_resource_id = :id',
                ['id' => $id],
            );
            $this->pendingDeleteDependencies = array_merge(
                $this->pendingDeleteDependencies,
                array_map('intval', $dependencies),
            );
        }
    }

    public function onItemDelete(Event $event): void
    {
        $ids = array_values(array_unique(array_merge(
            $this->pendingItemDeletes,
            $this->itemIdsFromResponse($event),
            $this->requestIds($event),
        )));
        $this->pendingItemDeletes = [];
        foreach ($ids as $id) {
            $this->indexer->deleteItem($id);
        }
        $dependencies = array_values(array_unique($this->pendingDeleteDependencies));
        $this->pendingDeleteDependencies = [];
        $this->indexer->syncItems($dependencies, 'item deletion dependency refresh');
    }

    public function onItemBatch(Event $event): void
    {
        $ids = array_values(array_unique(array_merge($this->requestIds($event), $this->itemIdsFromResponse($event))));
        $this->indexer->syncItems($ids, 'item batch update');
    }

    public function onItemBatchDeletePre(Event $event): void
    {
        $this->onItemDeletePre($event);
    }

    public function onItemBatchDelete(Event $event): void
    {
        $this->onItemDelete($event);
    }

    public function onMediaSave(Event $event): void
    {
        $response = $event->getParam('response');
        if (!$response instanceof OmekaApiResponse) {
            return;
        }
        foreach ($this->flatten($response->getContent()) as $content) {
            if ($content instanceof MediaRepresentation && $content->item() !== null) {
                $this->indexer->syncItemWithDependencies((int) $content->item()->id());
            }
        }
    }

    public function onMediaDeletePre(Event $event): void
    {
        foreach ($this->requestIds($event) as $id) {
            $parent = $this->fetchOne(
                'SELECT item_id FROM media WHERE id = :id',
                ['id' => $id],
            );
            if ($parent !== false) {
                $this->pendingMediaParents[] = (int) $parent;
            }
        }
    }

    public function onMediaDelete(Event $event): void
    {
        $parents = array_values(array_unique($this->pendingMediaParents));
        $this->pendingMediaParents = [];
        $this->indexer->syncItems($parents, 'media deletion');
    }

    public function onItemSetPre(Event $event): void
    {
        foreach ($this->requestIds($event) as $id) {
            $members = $this->fetchFirstColumn(
                'SELECT item_id FROM item_item_set WHERE item_set_id = :id',
                ['id' => $id],
            );
            $this->pendingItemSetMembers = array_merge($this->pendingItemSetMembers, array_map('intval', $members));
        }
    }

    public function onItemSetPost(Event $event): void
    {
        $members = array_values(array_unique($this->pendingItemSetMembers));
        $this->pendingItemSetMembers = [];
        $this->indexer->syncItems($members, 'item-set membership change');
    }

    /** @return list<int> */
    private function itemIdsFromResponse(Event $event): array
    {
        $response = $event->getParam('response');
        if (!$response instanceof OmekaApiResponse) {
            return [];
        }
        $ids = [];
        foreach ($this->flatten($response->getContent()) as $content) {
            if ($content instanceof ItemRepresentation) {
                $ids[] = (int) $content->id();
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /** @return list<int> */
    private function requestIds(Event $event): array
    {
        $request = $event->getParam('request');
        if (!is_object($request)) {
            return [];
        }
        $ids = [];
        foreach (['getId', 'getIds'] as $method) {
            if (!method_exists($request, $method)) {
                continue;
            }
            $value = $request->{$method}();
            foreach (is_array($value) ? $value : [$value] as $id) {
                if (is_numeric($id) && (int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }
        }
        if (method_exists($request, 'getContent')) {
            $content = $request->getContent();
            foreach (is_array($content) ? $content : [] as $key => $value) {
                if (($key === 'o:id' || $key === 'id') && is_numeric($value)) {
                    $ids[] = (int) $value;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /** @return list<mixed> */
    private function flatten(mixed $content): array
    {
        if ($content instanceof \Traversable) {
            $content = iterator_to_array($content, false);
        }
        if (!is_array($content)) {
            return [$content];
        }
        $out = [];
        array_walk_recursive($content, static function (mixed $value) use (&$out): void {
            if (is_object($value)) {
                $out[] = $value;
            }
        });
        return $out;
    }

    /**
     * Event listeners run inside Omeka's write lifecycle. Dependency capture is
     * best-effort: a metadata-query failure may leave the index marked stale on
     * the next synchronization, but it must never roll back the user's write.
     *
     * @param array<string,int|string> $params
     * @return list<mixed>
     */
    private function fetchFirstColumn(string $sql, array $params): array
    {
        try {
            return $this->connection->executeQuery($sql, $params)->fetchFirstColumn();
        } catch (\Throwable) {
            $this->indexer->markDirty('An Omeka event dependency lookup failed; run a full rebuild.');
            return [];
        }
    }

    /** @param array<string,int|string> $params */
    private function fetchOne(string $sql, array $params): mixed
    {
        try {
            return $this->connection->executeQuery($sql, $params)->fetchOne();
        } catch (\Throwable) {
            $this->indexer->markDirty('An Omeka event dependency lookup failed; run a full rebuild.');
            return false;
        }
    }
}
