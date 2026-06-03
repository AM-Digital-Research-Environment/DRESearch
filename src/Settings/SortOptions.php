<?php
declare(strict_types=1);

namespace DRESearch\Settings;

/**
 * Builds the ordered sort choices a corpus offers, as the client's
 * `[{value,label}]` shape with labels already translated.
 *
 * The built-in label map mirrors {@see \DRESearch\Site\BlockLayout\AbstractSearchBlock}'s
 * own SORT_OPTIONS — the page blocks predate this helper and still build their
 * sort list inline. Keep the two in sync; new surfaces (the federated results
 * page) use this so the federated and per-block sort dropdowns read identically.
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
            // The `count` key (e.g. "Most research items") carries a per-profile label.
            $label = $value === 'count'
                ? $translate($profile->sortCountLabel())
                : $translate(self::LABELS[$value] ?? $value);
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
    }
}
