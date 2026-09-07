<?php

declare(strict_types=1);

namespace DRESearch\Settings;

/** Shared SQL membership rules for indexing and public corpus counts. */
final class SourcePredicate
{
    public static function compile(SearchProfile $profile, callable $propertyId): string
    {
        $groups = [];

        $base = self::scopeClause($profile->templateId(), $profile->itemSetId(), null);
        if ($base !== '') {
            $groups[] = $base;
        }

        foreach ($profile->extraSources() as $src) {
            $propId = null;
            if (($src['require_property'] ?? null) !== null) {
                $propId = $propertyId((string) $src['require_property']);
                if ($propId === null) {
                    continue; // property absent here — don't fold this source in
                }
            }
            $clause = self::scopeClause($src['template_id'] ?? null, $src['item_set_id'] ?? null, $propId);
            if ($clause !== '') {
                $groups[] = $clause;
            }
        }

        if ($groups === []) {
            return '';
        }
        return count($groups) === 1 ? $groups[0] : '(' . implode(' OR ', $groups) . ')';
    }

    /**
     * One source group as an AND-combined SQL fragment with every integer inlined:
     * an optional resource_template_id, an optional item-set membership, and an
     * optional "has a value for property P" requirement. Returns '' if nothing is
     * constrained; parenthesised when it has >1 part so it composes safely under OR.
     */
    private static function scopeClause(?int $templateId, ?int $itemSetId, ?int $requirePropId): string
    {
        $parts = [];
        if ($templateId !== null) {
            $parts[] = 'resource_template_id = ' . $templateId;
        }
        if ($itemSetId !== null) {
            $parts[] = 'id IN (SELECT item_id FROM item_item_set WHERE item_set_id = ' . $itemSetId . ')';
        }
        if ($requirePropId !== null) {
            $parts[] = 'id IN (SELECT resource_id FROM value WHERE property_id = ' . $requirePropId . ')';
        }
        if ($parts === []) {
            return '';
        }
        return count($parts) === 1 ? $parts[0] : '(' . implode(' AND ', $parts) . ')';
    }
}
