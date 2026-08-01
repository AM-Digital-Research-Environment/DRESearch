<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

/**
 * Read-only access to Omeka values with deterministic, shared normalisation.
 * Mappers should not each invent their own literal/link/deduplication rules.
 */
final class ValueBag
{
    /** @param array<string,list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values */
    public function __construct(private readonly array $values)
    {
    }

    /** @return list<array{vrid:?int,value:?string,uri:?string,title:?string}> */
    public function rows(?string $term): array
    {
        return $term !== null && $term !== '' ? ($this->values[$term] ?? []) : [];
    }

    public function firstLiteral(?string $term): ?string
    {
        foreach ($this->rows($term) as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    /** Linked titles with literal fallback, deduplicated in source order. @return list<string> */
    public function labels(?string $term): array
    {
        $labels = [];
        foreach ($this->rows($term) as $row) {
            $label = trim((string) (($row['title'] ?? '') !== '' ? $row['title'] : ($row['value'] ?? '')));
            if ($label !== '') {
                $labels[$label] = true;
            }
        }
        return array_keys($labels);
    }

    /** @return array{0:list<string>,1:list<string>} Parallel names and resource ids. */
    public function people(?string $term): array
    {
        $names = [];
        $ids = [];
        foreach ($this->rows($term) as $row) {
            $name = trim((string) (($row['title'] ?? '') !== '' ? $row['title'] : ($row['value'] ?? '')));
            if ($name === '' || isset($names[$name])) {
                continue;
            }
            $names[$name] = true;
            $ids[] = $row['vrid'] !== null ? (string) $row['vrid'] : '';
        }
        return [array_keys($names), $ids];
    }

    public function firstResourceId(?string $term): ?int
    {
        foreach ($this->rows($term) as $row) {
            if ($row['vrid'] !== null && $row['vrid'] > 0) {
                return $row['vrid'];
            }
        }
        return null;
    }

    public function firstInt(?string $term): ?int
    {
        foreach ($this->rows($term) as $row) {
            if (preg_match('/\d+/', (string) ($row['value'] ?? ''), $match)) {
                return (int) $match[0];
            }
        }
        return null;
    }

    public function firstFloat(?string $term): ?float
    {
        foreach ($this->rows($term) as $row) {
            $raw = trim((string) ($row['value'] ?? ''));
            if ($raw !== '' && is_numeric($raw)) {
                $value = (float) $raw;
                if (is_finite($value)) {
                    return $value;
                }
            }
        }
        return null;
    }

    /** Returns only an absolute HTTP(S) URL. */
    public function firstUrl(?string $term): ?string
    {
        foreach ($this->rows($term) as $row) {
            foreach ([$row['uri'] ?? null, $row['value'] ?? null] as $candidate) {
                $url = self::safeHttpUrl((string) ($candidate ?? ''));
                if ($url !== null) {
                    return $url;
                }
            }
        }
        return null;
    }

    public function firstYear(?string $term): ?int
    {
        foreach ($this->rows($term) as $row) {
            if (preg_match('/\b(1\d{3}|20\d{2})\b/', (string) ($row['value'] ?? ''), $match)) {
                return (int) $match[1];
            }
        }
        return null;
    }

    /** @return array{0:?int,1:?int} */
    public function firstYearRange(?string $term): array
    {
        foreach ($this->rows($term) as $row) {
            if (!preg_match_all('/\b(1\d{3}|20\d{2})\b/', (string) ($row['value'] ?? ''), $matches)) {
                continue;
            }
            $years = array_map('intval', $matches[1]);
            $start = $years[0];
            $end = max($start, (int) end($years));
            return [$start, $end];
        }
        return [null, null];
    }

    /** Convert a DOI URI/bare value into a safe resolver URL. */
    public function firstDoi(string $term = 'bibo:doi'): ?string
    {
        foreach ($this->rows($term) as $row) {
            $raw = trim((string) (($row['uri'] ?? '') !== '' ? $row['uri'] : ($row['value'] ?? '')));
            $doi = preg_replace('~^https?://(?:dx\.)?doi\.org/~i', '', $raw) ?? '';
            $doi = preg_replace('/^doi:\s*/i', '', trim($doi)) ?? '';
            if ($doi !== '' && preg_match('~^10\.\d{4,9}/\S+$~i', $doi) && !preg_match('/[\x00-\x20]/', $doi)) {
                return 'https://doi.org/' . $doi;
            }
        }
        return null;
    }

    public static function safeHttpUrl(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $candidate : null;
    }
}
