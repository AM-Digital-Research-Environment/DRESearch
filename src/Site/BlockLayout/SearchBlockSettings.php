<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

use DRESearch\Search\QueryBuilder;
use DRESearch\Settings\SearchProfile;

/** Normalizes current and legacy saved block data without mutating it. */
final class SearchBlockSettings
{
    /** @param array<string,mixed> $data */
    public function __construct(
        private readonly array $data,
        private readonly ?SearchProfile $profile,
    ) {
    }

    /** @return list<string> */
    public function facets(): array
    {
        $all = $this->profile?->fieldNames() ?? [];
        // Missing legacy key means old default (all); an explicit [] stays empty.
        $saved = array_key_exists('facets', $this->data) ? (array) $this->data['facets'] : $all;
        return array_values(array_intersect($all, array_map('strval', $saved)));
    }

    public function showYear(): bool
    {
        return ($this->profile?->hasYearFacet() ?? false)
            && (bool) ($this->data['show_year'] ?? true);
    }

    public function defaultSort(): string
    {
        $values = $this->profile?->sortOptionValues() ?? ['relevance', 'title'];
        $value = (string) ($this->data['default_sort'] ?? ($this->profile?->defaultSort() ?? 'relevance'));
        return in_array($value, $values, true) ? $value : ($values[0] ?? 'relevance');
    }

    public function perPage(): int
    {
        return max(
            1,
            min(QueryBuilder::PER_PAGE_MAX, (int) ($this->data['results_per_page'] ?? QueryBuilder::PER_PAGE_DEFAULT)),
        );
    }

    public function lockedFilter(): string
    {
        return mb_substr(trim((string) ($this->data['locked_filter'] ?? '')), 0, 1000);
    }
}
