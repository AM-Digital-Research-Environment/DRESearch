<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/**
 * Maps one organisation (resource template 2, item set 110) into a Typesense
 * document. The corpus holds BOTH institutions and groups: the pipeline stores
 * them as the same Organisation item and distinguishes them with dcterms:type
 * ("Institution" / "Group"), which becomes the single-valued `type_s` facet.
 *
 * Like a person, an organisation record itself carries almost nothing — a name
 * and its type. Everything a useful card wants is in the *reverse* direction:
 * how many projects it funds, research items credit it, and people are affiliated
 * with it, plus the roles it plays (funder / contributor / host institution).
 * Those are computed by the {@see Reindexer} from `reverse_links` config and
 * handed in via $item['counts'] and $item['roles']; this mapper lays them in.
 *
 * Which property feeds which facet comes from {@see SearchProfile} (config-driven);
 * the stable field names (type_s, roles_ss, project_count, …) are the interface.
 */
final class OrganisationMapper implements MapperInterface
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

        // Property-backed facets (type_s ← dcterms:type). Derived facets such as
        // roles_ss carry no property and are filled below.
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

        // Roles — how the organisation participates (see Reindexer reverse_links).
        if ($this->profile->hasFacet('roles_ss')) {
            $roles = array_values(array_unique(array_map('strval', $item['roles'] ?? [])));
            if ($roles) {
                $doc['roles_ss'] = $roles;
            }
        }

        // Association counts (projects funded, items credited, people affiliated).
        // Always emitted so the card can show "0" and the field can be sorted on.
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
