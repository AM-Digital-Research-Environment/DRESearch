<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/**
 * Maps one person (resource template 4, item set 18) into a Typesense document.
 *
 * A person record itself carries almost nothing — the display name and the
 * affiliation(s) (dcterms:isPartOf → linked Institution). Everything else a
 * People search wants is in the *reverse* direction: how many research items and
 * publications a person is associated with, and which roles they hold (principal
 * investigator, project member, author, editor, research contributor). Those are
 * computed by the {@see Reindexer} from `person_links` config and handed in via
 * $item['counts'] and $item['roles']; this mapper just lays them into the doc.
 *
 * Which property feeds which facet comes from {@see SearchProfile} (config-driven);
 * the stable field names (affiliation_ss, roles_ss, item_count, …) are the
 * interface.
 */
final class PersonMapper implements MapperInterface
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

        // Property-backed facets (e.g. affiliation_ss ← dcterms:isPartOf). Derived
        // facets such as roles_ss carry no property and are filled below.
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

        // Roles — the reverse relationships the person holds (see Reindexer).
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
