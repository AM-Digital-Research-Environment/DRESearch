<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\FacetConfig;

/**
 * Maps one research item — its denormalised columns plus its grouped property
 * values — into a Typesense document, resolving the eight linked-resource
 * facets (including the three shared-property cases) via {@see AuthorityResolver}.
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

    public function __construct(private readonly AuthorityResolver $auth)
    {
    }

    /**
     * @param array{id:int, title:string, is_public:bool} $item
     * @param array<string, list<array{vrid:?int, value:?string, title:?string}>> $values
     */
    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
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

        // type_s — single linked authority (item set 1).
        foreach ($values['dcterms:type'] ?? [] as $v) {
            if ($v['vrid'] !== null && ($v['title'] ?? '') !== '') {
                $doc['type_s'] = $v['title'];
                break;
            }
        }

        // project_s — single dcterms:isPartOf target that is a project (set 20).
        // (isPartOf is reused for inter-item relations, so the set check matters.)
        foreach ($values['dcterms:isPartOf'] ?? [] as $v) {
            if ($v['vrid'] !== null
                && $this->auth->inSet($v['vrid'], FacetConfig::ITEM_SET_PROJECT)
                && ($v['title'] ?? '') !== ''
            ) {
                $doc['project_s'] = $v['title'];
                break;
            }
        }

        // language_ss, audience_ss — direct linked titles.
        $this->addMulti($doc, $values, 'dcterms:language', 'language_ss');
        $this->addMulti($doc, $values, 'dcterms:audience', 'audience_ss');

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
        foreach ($values['dcterms:subject'] ?? [] as $v) {
            if ($v['vrid'] === null || ($v['title'] ?? '') === '') {
                continue;
            }
            if ($this->auth->typeItemId($v['vrid']) === FacetConfig::TYPE_ITEM_TAG) {
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
        foreach ($values['dcterms:spatial'] ?? [] as $v) {
            if ($v['vrid'] === null) {
                if (($v['value'] ?? '') !== '') {
                    $countries[] = $v['value']; // rare literal region
                }
                continue;
            }
            if ($this->auth->typeItemId($v['vrid']) === FacetConfig::TYPE_ITEM_COUNTRY) {
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
        foreach ($values['dcterms:format'] ?? [] as $v) {
            if ($v['vrid'] !== null
                && $this->auth->inSet($v['vrid'], FacetConfig::ITEM_SET_DIGITAL)
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
