<?php
declare(strict_types=1);

namespace DRESearch\Search;

use DRESearch\Search\Exception\RequestValidationException;
use DRESearch\Settings\SearchProfile;

/** Immutable, bounded public request shared by search, federated search/export. */
final class SearchRequest
{
    public const MAX_BODY_BYTES = 65_536;
    public const MAX_QUERY_LENGTH = 500;
    public const MAX_FILTER_FIELDS = 20;
    public const MAX_VALUES_PER_FILTER = 50;
    public const MAX_VALUE_LENGTH = 256;

    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input, SearchProfile $profile, string $mode = 'search'): self
    {
        $allowed = [
            'profile', 'q', 'page', 'per_page', 'sort', 'filters', 'facets',
            'year_from', 'year_to', 'block_id', 'include_counts',
        ];
        if ($mode === 'export') {
            $allowed = array_values(array_diff($allowed, ['page', 'per_page', 'facets', 'include_counts']));
        }
        $unknown = array_values(array_diff(array_keys($input), $allowed));
        if ($unknown !== []) {
            throw new RequestValidationException('unknown_parameter', 'Unknown request parameter: ' . (string) $unknown[0]);
        }
        if (array_key_exists('profile', $input)) {
            self::profile($input['profile']);
        }

        $q = self::boundedString(array_key_exists('q', $input) ? $input['q'] : '', 'q', self::MAX_QUERY_LENGTH);
        $sort = self::boundedString(
            array_key_exists('sort', $input) ? $input['sort'] : $profile->defaultSort(),
            'sort',
            64,
        );
        if (!in_array($sort, $profile->sortOptionValues(), true)) {
            throw new RequestValidationException('invalid_sort', 'The requested sort option is not available for this profile.');
        }

        $data = [
            'q' => $q,
            'sort' => $sort,
            'filters' => self::filters(array_key_exists('filters', $input) ? $input['filters'] : [], $profile),
        ];

        if ($mode !== 'export') {
            $data['page'] = self::integer(
                array_key_exists('page', $input) ? $input['page'] : 1,
                'page',
                1,
                QueryBuilder::MAX_PAGE,
            );
            $data['per_page'] = self::integer(
                array_key_exists('per_page', $input) ? $input['per_page'] : QueryBuilder::PER_PAGE_DEFAULT,
                'per_page',
                1,
                QueryBuilder::PER_PAGE_MAX,
            );
            $data['facets'] = self::facets($input['facets'] ?? null, $profile);
            if (array_key_exists('include_counts', $input) && !is_bool($input['include_counts'])) {
                throw new RequestValidationException(
                    'invalid_include_counts',
                    'Parameter "include_counts" must be a boolean.',
                );
            }
            $data['include_counts'] = $input['include_counts'] ?? true;
        }

        $from = self::optionalYear($input['year_from'] ?? null, 'year_from');
        $to = self::optionalYear($input['year_to'] ?? null, 'year_to');
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $data['year_from'] = $from;
        $data['year_to'] = $to;

        if (isset($input['block_id']) && $input['block_id'] !== '') {
            $data['block_id'] = self::integer($input['block_id'], 'block_id', 1, PHP_INT_MAX);
        }

        return new self($data);
    }

    public static function query(mixed $value): string
    {
        return self::boundedString($value, 'q', self::MAX_QUERY_LENGTH);
    }

    public static function profile(mixed $value): string
    {
        return self::boundedString($value, 'profile', 100);
    }

    public static function blockId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return self::integer($value, 'block_id', 1, PHP_INT_MAX);
    }

    /**
     * Validate the intentionally small public union payload. Per-corpus filters
     * and sorts stay on profile tabs; the merged tab accepts only query/paging.
     *
     * @param array<string,mixed> $input
     * @return array{q:string,page:int,per_page:int}
     */
    public static function union(array $input): array
    {
        $allowed = ['q', 'page', 'per_page'];
        $unknown = array_values(array_diff(array_keys($input), $allowed));
        if ($unknown !== []) {
            throw new RequestValidationException(
                'unknown_parameter',
                'Unknown request parameter: ' . (string) $unknown[0],
            );
        }
        return [
            'q' => self::boundedString($input['q'] ?? '', 'q', self::MAX_QUERY_LENGTH),
            'page' => self::integer($input['page'] ?? 1, 'page', 1, QueryBuilder::MAX_PAGE),
            'per_page' => self::integer(
                $input['per_page'] ?? QueryBuilder::PER_PAGE_DEFAULT,
                'per_page',
                1,
                QueryBuilder::PER_PAGE_MAX,
            ),
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    private static function boundedString(mixed $value, string $field, int $max): string
    {
        if (!is_string($value)) {
            throw new RequestValidationException('invalid_' . $field, sprintf('Parameter "%s" must be a string.', $field));
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            throw new RequestValidationException('invalid_' . $field, sprintf('Parameter "%s" is too long.', $field));
        }
        return $value;
    }

    private static function integer(mixed $value, string $field, int $min, int $max): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RequestValidationException('invalid_' . $field, sprintf('Parameter "%s" must be an integer.', $field));
        }
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            throw new RequestValidationException('invalid_' . $field, sprintf('Parameter "%s" is outside the allowed range.', $field));
        }
        return $value;
    }

    private static function optionalYear(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return self::integer($value, $field, 1, 3000);
    }

    /** @return array<string,list<string>> */
    private static function filters(mixed $input, SearchProfile $profile): array
    {
        if (!is_array($input)) {
            throw new RequestValidationException('invalid_filters', 'Parameter "filters" must be an object.');
        }
        if (count($input) > self::MAX_FILTER_FIELDS) {
            throw new RequestValidationException('too_many_filters', 'Too many filter fields were supplied.');
        }
        $allowed = array_flip($profile->filterableFields());
        $out = [];
        foreach ($input as $field => $values) {
            if (!is_string($field) || !isset($allowed[$field])) {
                throw new RequestValidationException('invalid_filter', 'A filter field is not available for this profile.');
            }
            if (!is_array($values) || count($values) > self::MAX_VALUES_PER_FILTER) {
                throw new RequestValidationException('invalid_filter_values', 'A filter has too many or malformed values.');
            }
            $normalized = [];
            foreach ($values as $value) {
                $text = self::boundedString($value, 'filter_value', self::MAX_VALUE_LENGTH);
                if ($text !== '' && !in_array($text, $normalized, true)) {
                    $normalized[] = $text;
                }
            }
            if ($normalized !== []) {
                $out[$field] = $normalized;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function facets(mixed $input, SearchProfile $profile): array
    {
        if ($input === null) {
            return $profile->fieldNames();
        }
        if (!is_array($input)) {
            throw new RequestValidationException('invalid_facets', 'Parameter "facets" must be an array.');
        }
        $allowed = array_flip($profile->fieldNames());
        $out = [];
        foreach ($input as $field) {
            if (!is_string($field) || !isset($allowed[$field])) {
                throw new RequestValidationException('invalid_facet', 'A requested facet is not available for this profile.');
            }
            if (!in_array($field, $out, true)) {
                $out[] = $field;
            }
        }
        return $out;
    }
}
