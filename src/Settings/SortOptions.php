<?php

declare(strict_types=1);

namespace DRESearch\Settings;

/**
 * Builds the ordered sort choices a corpus offers, as the client's
 * `[{value,label}]` shape with labels already translated.
 *
 * Every search surface — page blocks, the project records and the federated
 * tabs ({@see \DRESearch\View\SurfaceBootstrap}), and the block form's default
 * sort — reads its choices here, so their sort dropdowns read identically.
 */
final class SortOptions
{
    /** Built-in sort key => human label. */
    private const LABELS = [
        'relevance' => 'Relevance',    // @translate
        'newest'    => 'Newest first', // @translate
        'oldest'    => 'Oldest first', // @translate
        'title'     => 'Title (A–Z)',  // @translate
    ];

    /**
     * @param callable(string):string $translate
     * @return list<array{value:string,label:string}>
     */
    public static function forProfile(SearchProfile $profile, callable $translate): array
    {
        $options = [];
        foreach ($profile->sortOptionValues() as $value) {
            // The `count` key (e.g. "Most research items") and config-defined sorts
            // (e.g. "Episode number") carry a per-profile label.
            if ($value === 'count') {
                $label = $translate($profile->sortCountLabel());
            } elseif (($custom = $profile->sortFieldLabel($value)) !== null) {
                $label = $translate($custom);
            } else {
                $label = $translate(self::LABELS[$value] ?? $value);
            }
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
    }
}
