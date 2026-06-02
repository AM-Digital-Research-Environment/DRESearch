<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\FacetConfig;

/**
 * Maps one research item — its denormalised columns plus its grouped property
 * values — into a Typesense document, resolving the eight linked-resource
 * facets (including the three shared-property cases) via {@see AuthorityResolver}.
 *
 * Which Omeka property feeds each facet, and the authority IDs used to
 * disambiguate, all come from {@see FacetConfig} (config-driven). The
 * resolution *logic* keys on the stable facet field names (type_s, subject_ss,
 * country_ss, …).
 *
 * The $values shape is term => list of:
 *   ['vrid' => ?int, 'value' => ?string, 'title' => ?string]
 * where vrid/title come from a value_resource link and value is the literal.
 */
final class ResearchItemMapper
{
    /** Roles folded into creator_ss for search + byline. */
    private const CREATOR_TERMS = ['dcterms:creator', 'dcterms:contributor', 'marcrel:aut', 'marcrel:edt'];

    /** Date properties tried in order for the "origin date". */
    private const DATE_TERMS = ['dcterms:issued', 'dcterms:created', 'dcterms:date'];

    public function __construct(
        private readonly AuthorityResolver $auth,
        private readonly FacetConfig $facetConfig,
    ) {
    }

    /**
     * @param array{id:int, title:string, is_public:bool} $item
     * @param array<string, list<array{vrid:?int, value:?string, title:?string}>> $values
     */
    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        // Facet → Omeka property (config-driven). '' if a facet is unmapped,
        // which simply yields no values from $values.
        $pType     = $this->facetConfig->property('type_s') ?? '';
        $pProject  = $this->facetConfig->property('project_s') ?? '';
        $pCountry  = $this->facetConfig->property('country_ss') ?? '';
        $pLanguage = $this->facetConfig->property('language_ss') ?? '';
        $pSubject  = $this->facetConfig->property('subject_ss') ?? ''; // shared with tag_ss
        $pAudience = $this->facetConfig->property('audience_ss') ?? '';
        $pFormat   = $this->facetConfig->property('digitisation_ss') ?? '';

        $setProject  = $this->facetConfig->itemSet('project');
        $setDigital  = $this->facetConfig->itemSet('digital');
        $itemTag     = $this->facetConfig->typeItem('tag');
        $itemCountry = $this->facetConfig->typeItem('country');

        $doc = [
            'id'        => (string) $item['id'],
            'is_public' => $item['is_public'],
            'title'     => $item['title'] !== '' ? $item['title'] : sprintf('[Untitled #%d]', $item['id']),
        ];

        if (($abstract = $this->firstLiteral($values, 'dcterms:abstract')) !== null) {
            $doc['abstract'] = $abstract;
        }
        if (($description = $this->firstLiteral($values, 'dcterms:description')) !== null) {
            $doc['description'] = $description;
        }

        // type_s — single linked authority.
        foreach ($values[$pType] ?? [] as $v) {
            if ($v['vrid'] !== null && ($v['title'] ?? '') !== '') {
                $doc['type_s'] = $v['title'];
                break;
            }
        }

        // project_s — single dcterms:isPartOf target that is a project (set).
        // (isPartOf is reused for inter-item relations, so the set check matters.)
        foreach ($values[$pProject] ?? [] as $v) {
            if ($v['vrid'] !== null
                && $this->auth->inSet($v['vrid'], $setProject)
                && ($v['title'] ?? '') !== ''
            ) {
                $doc['project_s'] = $v['title'];
                break;
            }
        }

        // language_ss, audience_ss — direct linked titles.
        $this->addMulti($doc, $values, $pLanguage, 'language_ss');
        $this->addMulti($doc, $values, $pAudience, 'audience_ss');

        // creator_ss — union across role properties.
        $creators = [];
        foreach (self::CREATOR_TERMS as $term) {
            foreach ($values[$term] ?? [] as $v) {
                $name = ($v['title'] ?? '') !== '' ? $v['title'] : ($v['value'] ?? '');
                if ($name !== '' && $name !== null) {
                    $creators[] = $name;
                }
            }
        }
        if ($creators) {
            $doc['creator_ss'] = array_values(array_unique($creators));
        }

        // subject_ss vs tag_ss — same property, split by the target's dcterms:type.
        $subjects = $tags = [];
        foreach ($values[$pSubject] ?? [] as $v) {
            if ($v['vrid'] === null || ($v['title'] ?? '') === '') {
                continue;
            }
            if ($itemTag !== null && $this->auth->typeItemId($v['vrid']) === $itemTag) {
                $tags[] = $v['title'];
            } else {
                $subjects[] = $v['title']; // default to subject (LCSH)
            }
        }
        if ($subjects) {
            $doc['subject_ss'] = array_values(array_unique($subjects));
        }
        if ($tags) {
            $doc['tag_ss'] = array_values(array_unique($tags));
        }

        // country_ss — spatial that is a country directly, else the city/region's
        // parent country via dcterms:isPartOf.
        $countries = [];
        foreach ($values[$pCountry] ?? [] as $v) {
            if ($v['vrid'] === null) {
                if (($v['value'] ?? '') !== '') {
                    $countries[] = $v['value']; // rare literal region
                }
                continue;
            }
            if ($itemCountry !== null && $this->auth->typeItemId($v['vrid']) === $itemCountry) {
                $countries[] = $v['title'] ?? $this->auth->title($v['vrid']);
            } else {
                $parentId = $this->auth->partOfId($v['vrid']);
                $country = $parentId !== null ? $this->auth->title($parentId) : null;
                if ($country !== null) {
                    $countries[] = $country;
                }
            }
        }
        $countries = array_values(array_unique(array_filter($countries, static fn($c) => $c !== null && $c !== '')));
        if ($countries) {
            $doc['country_ss'] = $countries;
        }

        // digitisation_ss — dcterms:format targets in the digital/technical set
        // only (genres, also on dcterms:format, are excluded).
        $digitisation = [];
        foreach ($values[$pFormat] ?? [] as $v) {
            if ($v['vrid'] !== null
                && $this->auth->inSet($v['vrid'], $setDigital)
                && ($v['title'] ?? '') !== ''
            ) {
                $digitisation[] = $v['title'];
            }
        }
        if ($digitisation) {
            $doc['digitisation_ss'] = array_values(array_unique($digitisation));
        }

        // Origin date.
        [$year, $epoch] = $this->resolveDate($values);
        if ($year !== null) {
            $doc['year'] = $year;
        }
        if ($epoch !== null) {
            $doc['date'] = $epoch;
        }

        if ($thumbnailUrl !== null) {
            $doc['thumbnail_url'] = $thumbnailUrl;
        }

        return $doc;
    }

    /** @param array<string, list<array{vrid:?int, value:?string, title:?string}>> $values */
    private function addMulti(array &$doc, array $values, string $term, string $field): void
    {
        if ($term === '') {
            return;
        }
        $out = [];
        foreach ($values[$term] ?? [] as $v) {
            $label = ($v['title'] ?? '') !== '' ? $v['title'] : ($v['value'] ?? '');
            if ($label !== null && $label !== '') {
                $out[] = $label;
            }
        }
        if ($out) {
            $doc[$field] = array_values(array_unique($out));
        }
    }

    /** @param array<string, list<array{vrid:?int, value:?string, title:?string}>> $values */
    private function firstLiteral(array $values, string $term): ?string
    {
        foreach ($values[$term] ?? [] as $v) {
            if (($v['value'] ?? '') !== '') {
                return $v['value'];
            }
        }
        return null;
    }

    /**
     * @param array<string, list<array{vrid:?int, value:?string, title:?string}>> $values
     * @return array{0:?int, 1:?int} [year, epochSeconds]
     */
    private function resolveDate(array $values): array
    {
        foreach (self::DATE_TERMS as $term) {
            foreach ($values[$term] ?? [] as $v) {
                $raw = $v['value'] ?? '';
                if ($raw === '' || !preg_match('/(\d{4})/', $raw, $m)) {
                    continue;
                }
                $year = (int) $m[1];
                $ts = strtotime($raw);
                $epoch = $ts !== false ? $ts : gmmktime(0, 0, 0, 1, 1, $year);
                return [$year, $epoch !== false ? $epoch : null];
            }
        }
        return [null, null];
    }
}
