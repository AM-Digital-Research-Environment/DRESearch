<?php

declare(strict_types=1);

namespace DRESearch\Search;

use Doctrine\DBAL\Connection;
use DRESearch\Search\Exception\RequestValidationException;

/** Resolves enforceable block scope from persisted server-side block data. */
final class BlockScopeResolver
{
    public function __construct(private readonly Connection $connection)
    {
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
