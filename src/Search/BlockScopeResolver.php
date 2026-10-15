<?php

declare(strict_types=1);

namespace DRESearch\Search;

use Doctrine\DBAL\Connection;
use DRESearch\Search\Exception\RequestValidationException;

/**
 * Resolves enforceable request scope from server-side data: a saved page
 * block's locked filter (`block_id`), or a research project's records
 * (`scope_item`, through {@see ResourceScopeResolver}).
 */
final class BlockScopeResolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?ResourceScopeResolver $resources = null,
    ) {
    }

    /**
     * The one server filter a request runs under, or null for none. A request
     * names at most one scope: two would have to be combined, and refusing the
     * pair is simpler to trust than any combination rule.
     */
    public function resolveScope(?int $blockId, ?int $scopeItem, string $profile): ?string
    {
        if ($blockId !== null && $scopeItem !== null) {
            throw new RequestValidationException('conflicting_scope', 'Send either block_id or scope_item, not both.');
        }
        if ($scopeItem !== null) {
            if ($this->resources === null) {
                throw ResourceScopeResolver::unavailable();
            }
            return $this->resources->resolve($scopeItem, $profile);
        }
        return $this->resolve($blockId, $profile);
    }

    public function resolve(?int $blockId, string $profile): ?string
    {
        if ($blockId === null) {
            return null;
        }
        $row = $this->connection->executeQuery(
            'SELECT layout, data FROM site_page_block WHERE id = :id',
            ['id' => $blockId],
        )->fetchAssociative();
        if ($row === false) {
            // One code for "absent" and "wrong profile": distinct answers would
            // let anyone enumerate which block ids exist.
            throw new RequestValidationException('invalid_block_scope', 'The requested block scope is not available.');
        }
        $expected = \DRESearch\Settings\BlockProfiles::PROFILES[(string) ($row['layout'] ?? '')] ?? null;
        if ($expected === null || $expected !== $profile) {
            throw new RequestValidationException('invalid_block_scope', 'The requested block scope is not available.');
        }
        $data = json_decode((string) ($row['data'] ?? ''), true);
        $filter = is_array($data) ? trim((string) ($data['locked_filter'] ?? '')) : '';
        if ($filter === '') {
            return null;
        }
        if (FilterExpression::problem($filter) !== null) {
            throw new RequestValidationException('invalid_block_scope', 'The saved block scope is invalid.');
        }
        return $filter;
    }
}
