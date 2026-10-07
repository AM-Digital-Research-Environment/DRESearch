<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

/**
 * A mapper that wants to see a whole batch's values before mapping any one
 * document, e.g. to resolve every linked authority in one query rather than
 * one per document. {@see DocumentAssembler} calls it once per batch.
 */
interface PreparesBatch
{
    /** @param array<int,array<string,list<array{vrid:?int,value:?string,uri:?string,title:?string}>>> $valuesById */
    public function prepare(array $valuesById): void;
}
