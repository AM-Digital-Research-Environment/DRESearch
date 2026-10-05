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
            throw new RequestValidationException('unknown_block_scope', 'The requested block scope does not exist.');
        }
        $expected = \DRESearch\Settings\BlockProfiles::PROFILES[(string) ($row['layout'] ?? '')] ?? null;
        if ($expected === null || $expected !== $profile) {
            throw new RequestValidationException('block_scope_mismatch', 'The block scope does not match the requested profile.');
        }
        $data = json_decode((string) ($row['data'] ?? ''), true);
        $filter = is_array($data) ? trim((string) ($data['locked_filter'] ?? '')) : '';
        if (mb_strlen($filter) > 1000) {
            throw new RequestValidationException('invalid_block_scope', 'The saved block scope is too long.');
        }
        return $filter !== '' ? $filter : null;
    }
}
