<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/**
 * Maps one entity whose substance lives in the *reverse* direction into a
 * Typesense document: people (the `person` kind) and organisations (the
 * `organisation` kind), and underneath {@see TermMapper}, the authority terms.
 *
 * Such a record itself carries almost nothing: a display name, and one or two
 * linked or literal values — a person's affiliation (dcterms:isPartOf → an
 * institution), an organisation's type (dcterms:type: "Institution" or
 * "Group", the single-valued type_s facet that splits that corpus). Everything
 * a useful card wants is how many records reference the entity and which roles
 * it plays there: a person's research items and publications, principal
 * investigator, author or each marcrel relator they hold; an organisation's
 * funded projects, crediting items and affiliated people, funder or host
 * institution. Those are computed by {@see DocumentAssembler} from the
 * profile's `reverse_links` config and handed in as $item['counts'] and
 * $item['roles']; this mapper only lays them in.
 *
 * The two kinds were separate, identical classes: they stay separate kinds (a
 * kind also picks the result card) with one mapping. Which property feeds which
 * facet comes from {@see SearchProfile}; the stable field names (affiliation_ss,
 * type_s, roles_ss, item_count, project_count, …) are the interface.
 */
final class ReverseLinkMapper extends AbstractMapper
{
    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        $bag = new ValueBag($values);
        // An entity has no abstract in its document.
        $doc = $this->skeleton($item, $bag, null, '[Unnamed #%d]');

        // Property-backed facets (affiliation_ss ← dcterms:isPartOf, type_s ←
        // dcterms:type): linked titles with a literal fallback, trimmed and
        // deduplicated. Derived facets such as roles_ss carry no property and
        // are filled below.
        $this->addFacetLabels($doc, $bag);

        // Roles: the reverse relationships the entity holds (reverse_links).
        if ($this->profile->hasFacet('roles_ss')) {
            $roles = array_values(array_unique(array_map('strval', $item['roles'] ?? [])));
            if ($roles) {
                $doc['roles_ss'] = $roles;
            }
        }

        // Association counts (research items, publications, projects, people…).
        // Always emitted so the card can show "0" and the field can be sorted on.
        $counts = $item['counts'] ?? [];
        foreach ($this->profile->displayFields() as $field => $def) {
            if (($def['type'] ?? '') === 'int32') {
                $doc[$field] = (int) ($counts[$field] ?? 0);
            }
        }

        $this->addThumbnail($doc, $thumbnailUrl);
        return $doc;
    }
}
