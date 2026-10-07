<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;
use Laminas\EventManager\Event;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Response as OmekaApiResponse;
use Omeka\Entity\Item;
use Omeka\Entity\Media;

/**
 * Defensive Omeka event adapter covering items, media parents, item-set
 * destruction and resource-template changes.
 *
 * Omeka's batch create/update/delete run every id through the per-resource
 * `api.{create,update,delete}.{pre,post}` events, so no batch listener is
 * needed (listening to both did every id's work twice).
 *
 * What must be known after a destructive write is captured in its pre event:
 * the dependants (documents that embed this item), and the profiles whose
 * scope held it — a deleted or re-templated item no longer matches its old
 * profile, which must still delete its document.
 */
final class ItemEventListener
{
    /** @var array<int,list<int>> item id => dependants before the write */
    private array $beforeDependencies = [];
    /** @var array<string,list<int>> profile => ids scoped before the write */
    private array $formerScope = [];
    /** @var list<int> */
    private array $pendingItemDeletes = [];
    /** @var list<int> */
    private array $pendingDeleteDependencies = [];
    /** @var list<int> */
    private array $pendingMediaParents = [];
    /** @var list<int> */
    private array $pendingSetMembers = [];
    /** @var list<int> */
    private array $pendingTemplateItems = [];

    public function __construct(
        private readonly IncrementalIndexer $indexer,
        private readonly Connection $connection,
    ) {
    }

    public function onItemCreate(Event $event): void
    {
        $ids = array_values(array_unique(array_merge($this->itemIdsFromResponse($event), $this->requestIds($event))));
        if ($ids !== []) {
            $this->indexer->syncItems(array_merge($ids, $this->indexer->dependenciesOf($ids)));
        }
    }

    public function onItemUpdatePre(Event $event): void
    {
        $ids = $this->requestIds($event);
        if ($ids === []) {
            return;
        }
        foreach ($ids as $id) {
            $this->beforeDependencies[$id] = $this->indexer->dependencies($id);
        }
        $this->formerScope = ScopeMatcher::merge($this->formerScope, $this->indexer->membership($ids));
    }

    public function onItemUpdate(Event $event): void
    {
        $ids = array_values(array_unique(array_merge($this->requestIds($event), $this->itemIdsFromResponse($event))));
        if ($ids === []) {
            return;
        }
        $affected = array_merge($ids, $this->indexer->dependenciesOf($ids));
        $former = [];
        foreach ($ids as $id) {
            array_push($affected, ...($this->beforeDependencies[$id] ?? []));
            unset($this->beforeDependencies[$id]);
        }
        foreach ($this->formerScope as $profile => $scoped) {
            $mine = array_values(array_intersect($scoped, $ids));
            if ($mine !== []) {
                $former[$profile] = $mine;
                $this->formerScope[$profile] = array_values(array_diff($scoped, $ids));
            }
        }
        $this->formerScope = array_filter($this->formerScope);
        $this->indexer->syncItems($affected, 'Omeka write', $former);
    }

    public function onItemDeletePre(Event $event): void
    {
        $ids = $this->requestIds($event);
        if ($ids === []) {
            return;
        }
        $this->pendingItemDeletes = array_values(array_unique(array_merge($this->pendingItemDeletes, $ids)));
        array_push($this->pendingDeleteDependencies, ...$this->indexer->dependenciesOf($ids));
        $this->formerScope = ScopeMatcher::merge($this->formerScope, $this->indexer->membership($ids));
    }

    public function onItemDelete(Event $event): void
    {
        $ids = array_values(array_unique(array_merge(
            $this->pendingItemDeletes,
            $this->itemIdsFromResponse($event),
            $this->requestIds($event),
        )));
        $dependencies = $this->pendingDeleteDependencies;
        $this->pendingItemDeletes = [];
        $this->pendingDeleteDependencies = [];
        $former = $this->formerScope;
        $this->formerScope = [];
        // The deleted rows match no scope any more: $former carries them to
        // the profiles that indexed them, whose drain deletes the documents.
        $this->indexer->syncItems(array_merge($ids, $dependencies), 'item deletion', $former);
    }

    public function onMediaSave(Event $event): void
    {
        $response = $event->getParam('response');
        if (!$response instanceof OmekaApiResponse) {
            return;
        }
        foreach ($this->flatten($response->getContent()) as $content) {
            if ($content instanceof MediaRepresentation && $content->item() !== null) {
                $this->pendingMediaParents[] = (int) $content->item()->id();
            } elseif ($content instanceof Media && $content->getItem() !== null) {
                $this->pendingMediaParents[] = (int) $content->getItem()->getId();
            }
        }
        $this->onMediaDelete($event);
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
        $parents = ScopeMatcher::normalize($this->pendingMediaParents);
        $this->pendingMediaParents = [];
        if ($parents !== []) {
            $this->indexer->syncItems(array_merge($parents, $this->indexer->dependenciesOf($parents)));
        }
    }

    /**
     * Deleting an item set removes its members from every set-scoped profile
     * and changes the authority lookups of the items that link to them. An
     * item-set UPDATE cannot change any document (scope and authority lookups
     * use membership, which is edited on the item side) and is not observed.
     */
    public function onItemSetDeletePre(Event $event): void
    {
        foreach ($this->requestIds($event) as $id) {
            $members = $this->fetchFirstColumn(
                'SELECT item_id FROM item_item_set WHERE item_set_id = :id',
                ['id' => $id],
            );
            array_push($this->pendingSetMembers, ...array_map('intval', $members));
        }
        $members = ScopeMatcher::normalize($this->pendingSetMembers);
        if ($members !== []) {
            $this->formerScope = ScopeMatcher::merge($this->formerScope, $this->indexer->membership($members));
            array_push($this->pendingDeleteDependencies, ...$this->indexer->dependenciesOf($members));
        }
    }

    public function onItemSetDelete(Event $event): void
    {
        $members = ScopeMatcher::normalize($this->pendingSetMembers);
        $dependencies = $this->pendingDeleteDependencies;
        $former = $this->formerScope;
        $this->pendingSetMembers = [];
        $this->pendingDeleteDependencies = [];
        $this->formerScope = [];
        if ($members !== []) {
            $this->indexer->syncItems(array_merge($members, $dependencies), 'item set deletion', $former);
        }
    }

    /**
     * A template's title property drives the computed title of every item
     * using it (and the linked titles other documents embed); its alternate
     * labels drive role names; deleting it un-templates its items, moving them
     * out of template-scoped profiles. Capture the items before the write.
     */
    public function onResourceTemplatePre(Event $event): void
    {
        foreach ($this->requestIds($event) as $id) {
            $items = $this->fetchFirstColumn(
                'SELECT id FROM resource WHERE resource_template_id = :id AND resource_type = :type',
                ['id' => $id, 'type' => Item::class],
            );
            array_push($this->pendingTemplateItems, ...array_map('intval', $items));
        }
        $items = ScopeMatcher::normalize($this->pendingTemplateItems);
        if ($items !== []) {
            $this->formerScope = ScopeMatcher::merge($this->formerScope, $this->indexer->membership($items));
        }
    }

    public function onResourceTemplatePost(Event $event): void
    {
        $items = ScopeMatcher::normalize($this->pendingTemplateItems);
        $former = $this->formerScope;
        $this->pendingTemplateItems = [];
        $this->formerScope = [];
        if ($items !== []) {
            $this->indexer->syncItems(
                array_merge($items, $this->indexer->dependenciesOf($items)),
                'resource template change',
                $former,
            );
        }
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
            } elseif ($content instanceof Item) {
                $ids[] = (int) $content->getId();
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
     * best-effort: a metadata-query failure marks the index stale, but it must
     * never roll back the user's write.
     *
     * @param array<string,int|string> $params
     * @return list<mixed>
     */
    private function fetchFirstColumn(string $sql, array $params): array
    {
        try {
            return array_values($this->connection->executeQuery($sql, $params)->fetchFirstColumn());
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
