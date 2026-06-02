<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/**
 * Maps one authority term — a genre, language, location, or subject/tag — into a
 * Typesense document. This is the generic mapper behind the `term` kind: every
 * such corpus is an authority item set whose substance lives in the *reverse*
 * direction, exactly like the people and organisations corpora.
 *
 * A term record itself carries almost nothing — a display title and, for some
 * corpora, a dcterms:type that distinguishes sub-kinds (a location is a Country or
 * a geographic location; a subject is an LCSH heading or a tag). Everything else a
 * useful card wants is the count of public records that reference the term, which
 * the {@see Reindexer} computes from `reverse_links` config and hands in via
 * $item['counts'] (and $item['roles'] for any corpus that derives roles).
 *
 * Which property feeds which facet comes from {@see SearchProfile} (config-driven);
 * the stable field names (type_s, item_count, publication_count, …) are the
 * interface. The logic is deliberately the same as {@see PersonMapper} and
 * {@see OrganisationMapper} — they are all reverse-link entity corpora.
 */
final class TermMapper implements MapperInterface
{
    public function __construct(private readonly SearchProfile $profile)
    {
    }

    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        $doc = [
            'id'        => (string) $item['id'],
            'is_public' => $item['is_public'],
            'title'     => $item['title'] !== '' ? $item['title'] : sprintf('[Unnamed #%d]', $item['id']),
        ];

        // Property-backed facets (e.g. type_s ← dcterms:type). Derived facets such
        // as roles_ss carry no property and are filled below.
        foreach ($this->profile->all() as $field => $def) {
            if (empty($def['property'])) {
                continue;
            }
            $titles = $this->linkedTitles($values, $def['property']);
            if ($titles === []) {
                continue;
            }
            $doc[$field] = $this->profile->isMultivalued($field) ? $titles : $titles[0];
        }

        // Roles — present only if the corpus derives any (see Reindexer reverse_links).
        if ($this->profile->hasFacet('roles_ss')) {
            $roles = array_values(array_unique(array_map('strval', $item['roles'] ?? [])));
            if ($roles) {
                $doc['roles_ss'] = $roles;
            }
        }

        // Association counts (research items, publications, …). Always emitted so
        // the card can show "0" and the field can be sorted on.
        $counts = $item['counts'] ?? [];
        foreach ($this->profile->displayFields() as $field => $def) {
            if (($def['type'] ?? '') === 'int32') {
                $doc[$field] = (int) ($counts[$field] ?? 0);
            }
        }

        if ($thumbnailUrl !== null) {
            $doc['thumbnail_url'] = $thumbnailUrl;
        }

        return $doc;
    }

    /**
     * Linked-resource titles (literal fallback) for a property, deduped, order
     * preserved.
     *
     * @param array<string, list<array{vrid:?int, value:?string, uri:?string, title:?string}>> $values
     * @return list<string>
     */
    private function linkedTitles(array $values, ?string $term): array
    {
        if ($term === null || $term === '') {
            return [];
        }
        $out = [];
        foreach ($values[$term] ?? [] as $v) {
            $label = ($v['title'] ?? '') !== '' ? $v['title'] : ($v['value'] ?? '');
            if ($label !== null && $label !== '') {
                $out[] = $label;
            }
        }
        return array_values(array_unique($out));
    }
}
