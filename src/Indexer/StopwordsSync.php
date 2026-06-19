<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use RuntimeException;
use Typesense\Client as TypesenseClient;

/**
 * Idempotent uploader for the English stopword set.
 *
 * Reads data/stopwords.json and PUTs it as the "dre_default" stopword set in
 * Typesense. {@see \DRESearch\Search\QueryBuilder} references that set on every
 * non-browse query (`stopwords: dre_default`), so common function words don't
 * dilute relevance.
 *
 * Idempotent: PUT /stopwords/{name} replaces the set, so it is safe (and cheap)
 * to call on every reindex. Kept separate from {@see Reindexer} because stopwords
 * are a Typesense-wide concept, not per-collection, and it's useful to refresh the
 * word list standalone (the Maintenance "Sync stopwords" action) without rebuilding
 * every collection. Returns stats for the caller to log — it does no logging itself
 * (the jobs own the Omeka job log).
 */
final class StopwordsSync
{
    /** The single stopword set name, referenced by QueryBuilder at query time. */
    public const SET_NAME = 'dre_default';

    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly string $stopwordsJsonPath,
    ) {
    }

    /**
     * Build a sync against the module's bundled data/stopwords.json
     * (src/Indexer/StopwordsSync.php → module root is two levels up).
     */
    public static function create(TypesenseClient $typesense): self
    {
        return new self($typesense, dirname(__DIR__, 2) . '/data/stopwords.json');
    }

    /**
     * @return array{set: string, locale: string, count: int}
     */
    public function sync(): array
    {
        if (!is_readable($this->stopwordsJsonPath)) {
            throw new RuntimeException("Stopwords file not readable: {$this->stopwordsJsonPath}");
        }

        $payload = json_decode((string) file_get_contents($this->stopwordsJsonPath), true);
        if (!is_array($payload) || !isset($payload['stopwords']) || !is_array($payload['stopwords'])) {
            throw new RuntimeException(
                "Stopwords file malformed (missing 'stopwords' array): {$this->stopwordsJsonPath}"
            );
        }

        // Locale 'en' so the set folds diacritics the same way the (default-locale)
        // collections do — see the _locale_note in data/stopwords.json.
        $locale = is_string($payload['locale'] ?? null) ? $payload['locale'] : 'en';
        $words = array_values(array_unique(array_filter(
            array_map(static fn($w): string => (string) $w, $payload['stopwords']),
            static fn(string $w): bool => $w !== '',
        )));

        // typesense-php v6: $client->stopwords->put($stopwordSet) is the single
        // create-or-update method; the set name travels in the payload (`name`),
        // not as a separate argument. Verified identical to v5.
        // @phpstan-ignore-next-line  property access on Typesense\Client
        $this->typesense->stopwords->put([
            'name'      => self::SET_NAME,
            'stopwords' => $words,
            'locale'    => $locale,
        ]);

        return [
            'set'    => self::SET_NAME,
            'locale' => $locale,
            'count'  => count($words),
        ];
    }
}
