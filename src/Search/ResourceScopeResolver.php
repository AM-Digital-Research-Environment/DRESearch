<?php

declare(strict_types=1);

namespace DRESearch\Search;

use DRESearch\Search\Exception\RequestValidationException;

/**
 * Resolves the `scope_item` request scope: the research records of one
 * research project, for the "Project records" block on the project's item page.
 *
 * Like a block's locked filter, the scope is built on the server from data the
 * visitor cannot edit. The browser names only the project item; this class
 * reads that item as the current user (a private or absent item is refused),
 * checks that it is a project, and pins `project_s` to the exact title the
 * indexer stores for it ({@see \DRESearch\Indexer\OmekaSourceRepository::publicTitles()}).
 *
 * Every refusal — absent, not visible, not a project, a profile other than
 * research items, a title that cannot be matched literally — is the same
 * `invalid_block_scope` error, so the endpoint does not tell anyone which item
 * ids exist or which are projects.
 */
final class ResourceScopeResolver
{
    /** The only corpus a project scope applies to. */
    public const PROFILE = 'research_items';
    /** The research-items field holding the project title. */
    public const FIELD = 'project_s';
    /** Resource class of a research project. */
    public const PROJECT_CLASS = 'foaf:Project';

    /**
     * @param \Closure(int):(array{class:?string,item_sets:list<int>}|null) $readItem
     *        The item as the current user may see it, or null when it is absent
     *        or not visible to them.
     * @param \Closure(int):string $publicTitle
     *        The title the indexer stores for the item ('' when it stores none).
     * @param int|null $projectItemSet The research-items profile's `project`
     *        authority item set.
     */
    public function __construct(
        private readonly \Closure $readItem,
        private readonly \Closure $publicTitle,
        private readonly ?int $projectItemSet,
    ) {
    }

    /** The server-side filter for the project's records; throws when there is none. */
    public function resolve(int $itemId, string $profile): string
    {
        if ($profile !== self::PROFILE || $itemId < 1) {
            throw self::unavailable();
        }
        $item = ($this->readItem)($itemId);
        if ($item === null || !self::isProject($item['class'], $item['item_sets'], $this->projectItemSet)) {
            throw self::unavailable();
        }
        $filter = self::filterFor(($this->publicTitle)($itemId));
        if ($filter === null) {
            throw self::unavailable();
        }
        return $filter;
    }

    /**
     * A research project is an item of class foaf:Project, or one in the
     * research-items profile's `project` authority item set (the set the
     * indexer requires a `dcterms:isPartOf` target to belong to).
     *
     * @param array<int|string,int|string> $itemSetIds
     */
    public static function isProject(?string $classTerm, array $itemSetIds, ?int $projectItemSet): bool
    {
        if ($classTerm === self::PROJECT_CLASS) {
            return true;
        }
        return $projectItemSet !== null && in_array($projectItemSet, array_map('intval', $itemSetIds), true);
    }

    /**
     * `project_s:=`<title>``, or null when the title cannot be matched
     * literally. Typesense has no escape for a backtick inside a
     * backtick-quoted value: one would end the value early and let the rest of
     * the title act as filter syntax. A trailing `*` or `\`, or a value wrapped
     * in double quotes, would be reinterpreted too (the same values
     * {@see SearchRequest} refuses as user filters). Stripping such characters
     * would point the scope at a different title, so the scope is refused.
     */
    public static function filterFor(string $title): ?string
    {
        if (
            $title === ''
            || trim($title) !== $title
            || str_contains($title, '`')
            || str_ends_with($title, '*')
            || str_ends_with($title, '\\')
            || (strlen($title) > 1 && str_starts_with($title, '"') && str_ends_with($title, '"'))
            || preg_match('/[\x00-\x1F\x7F]/', $title)
        ) {
            return null;
        }
        $filter = self::FIELD . ':=`' . $title . '`';
        return FilterExpression::problem($filter) === null ? $filter : null;
    }

    public static function unavailable(): RequestValidationException
    {
        // The block scope's code and message: one answer for every refusal.
        return new RequestValidationException('invalid_block_scope', 'The requested block scope is not available.');
    }
}
