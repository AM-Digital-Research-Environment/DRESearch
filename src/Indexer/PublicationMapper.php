<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/**
 * Maps one publication (a bibliographic reference: journal article, book,
 * chapter, thesis, …) into a Typesense document.
 *
 * Publications come from the MongoDB2OmekaS pipeline and live in a single item
 * set, spread across ~10 type-specific resource templates (so the profile filters
 * by item set, not template). Their links are unambiguous — bibo:authorList is
 * always authors, dcterms:isPartOf the journal / book title, dcterms:publisher
 * the publisher — so facets resolve straight from the linked title or the literal
 * value (no AuthorityResolver). Authors keep their person item ids alongside the
 * names so the card can link each one.
 *
 * Which property feeds which field comes from {@see SearchProfile} (config-driven);
 * the stable Typesense field names (author_ss, container_ss, year, …) are the
 * interface. Beyond the facets, the mapper emits the bits a bibliographic
 * reference needs — editors, volume/issue, a normalised page range, a DOI link —
 * as display-only fields.
 *
 * Publication-specific shapes:
 *   - abstract : on bibo:abstract (not dcterms:abstract).
 *   - year     : dcterms:date is a numeric:timestamp whose @value is the year
 *                string ("2026") → the first 4-digit run.
 *   - pages    : the pipeline splits pages across bibo:pages / bibo:pageStart /
 *                bibo:pageEnd / bibo:numPages by publication kind; recombined
 *                here into one display string.
 *   - doi      : a URI value — prefer its @id (the full https://doi.org/… link).
 */
final class PublicationMapper implements MapperInterface
{
    public function __construct(private readonly SearchProfile $profile)
    {
    }

    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        $doc = [
            'id'        => (string) $item['id'],
            'is_public' => $item['is_public'],
            'title'     => $item['title'] !== '' ? $item['title'] : sprintf('[Untitled #%d]', $item['id']),
        ];

        if (($abstract = $this->firstLiteral($values, 'bibo:abstract')) !== null) {
            $doc['abstract'] = $abstract;
        }

        $df = $this->profile->displayFields();

        // Facet fields. A people facet (one with a parallel "<base>_ids" display
        // field, e.g. author_ss ↔ author_ids) is filled with names + ids kept in
        // lockstep; others take linked titles (or literal fallback). Single-valued
        // facets store the first title, multi-valued ones the deduped list.
        foreach ($this->profile->all() as $field => $def) {
            if (empty($def['property'])) {
                continue;
            }
            $idField = substr($field, -3) === '_ss' ? substr($field, 0, -3) . '_ids' : null;
            if ($idField !== null && isset($df[$idField])) {
                [$names, $ids] = $this->collectPeople($values, $def['property']);
                if ($names) {
                    $doc[$field] = $names;
                    $doc[$idField] = $ids;
                }
                continue;
            }
            $titles = $this->linkedTitles($values, $def['property']);
            if ($titles === []) {
                continue;
            }
            $doc[$field] = $this->profile->isMultivalued($field) ? $titles : $titles[0];
        }

        // Editors (display only) — same linked-title shape.
        $editors = $this->linkedTitles($values, $df['editor_ss']['property'] ?? null);
        if ($editors) {
            $doc['editor_ss'] = $editors;
        }

        // Bibliographic-reference bits.
        if (($vol = $this->firstLiteral($values, $df['volume_s']['property'] ?? 'bibo:volume')) !== null) {
            $doc['volume_s'] = $vol;
        }
        if (($issue = $this->firstLiteral($values, $df['issue_s']['property'] ?? 'bibo:issue')) !== null) {
            $doc['issue_s'] = $issue;
        }
        if (($pages = $this->resolvePages($values)) !== null) {
            $doc['pages_s'] = $pages;
        }
        if (($doi = $this->resolveDoi($values)) !== null) {
            $doc['doi_s'] = $doi;
        }

        // Year — single point (dcterms:date numeric:timestamp).
        if (($year = $this->resolveYear($values, $this->profile->dateProperty())) !== null) {
            $doc['year'] = $year;
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

    /**
     * Collect a person property's display names and matching resource ids (empty
     * string where a value is a literal rather than a link), deduped by name and
     * kept parallel so author_ss[i] ↔ author_ids[i].
     *
     * @param array<string, list<array{vrid:?int, value:?string, uri:?string, title:?string}>> $values
     * @return array{0:list<string>, 1:list<string>}
     */
    private function collectPeople(array $values, ?string $term): array
    {
        $names = [];
        $ids = [];
        $seen = [];
        if ($term === null) {
            return [$names, $ids];
        }
        foreach ($values[$term] ?? [] as $v) {
            $name = ($v['title'] ?? '') !== '' ? $v['title'] : ($v['value'] ?? '');
            if ($name === null || $name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $names[] = $name;
            $ids[] = $v['vrid'] !== null ? (string) $v['vrid'] : '';
        }
        return [$names, $ids];
    }

    /** @param array<string, list<array{vrid:?int, value:?string, uri:?string, title:?string}>> $values */
    private function firstLiteral(array $values, ?string $term): ?string
    {
        if ($term === null || $term === '') {
            return null;
        }
        foreach ($values[$term] ?? [] as $v) {
            if (($v['value'] ?? '') !== '') {
                return $v['value'];
            }
        }
        return null;
    }

    /**
     * Recombine the pipeline's split page fields into one display string:
     * an explicit pages literal, else "start–end", else a lone start, else
     * "N pp." for a monograph's total page count.
     *
     * @param array<string, list<array{vrid:?int, value:?string, uri:?string, title:?string}>> $values
     */
    private function resolvePages(array $values): ?string
    {
        if (($pages = $this->firstLiteral($values, 'bibo:pages')) !== null) {
            return $pages;
        }
        $start = $this->firstLiteral($values, 'bibo:pageStart');
        $end = $this->firstLiteral($values, 'bibo:pageEnd');
        if ($start !== null && $end !== null) {
            return $start . '–' . $end; // en dash
        }
        if ($start !== null) {
            return $start;
        }
        if (($num = $this->firstLiteral($values, 'bibo:numPages')) !== null) {
            return $num . ' pp.';
        }
        return null;
    }

    /**
     * The DOI as a resolvable link — prefer the URI value's @id (the full
     * https://doi.org/… link), falling back to the label (bare DOI, prefixed).
     *
     * @param array<string, list<array{vrid:?int, value:?string, uri:?string, title:?string}>> $values
     */
    private function resolveDoi(array $values): ?string
    {
        foreach ($values['bibo:doi'] ?? [] as $v) {
            if (($v['uri'] ?? '') !== '') {
                return $v['uri'];
            }
            $label = $v['value'] ?? '';
            if ($label !== '') {
                return str_starts_with($label, 'http') ? $label : 'https://doi.org/' . $label;
            }
        }
        return null;
    }

    /**
     * Publication year — the first plausible 4-digit run (1000–2099) in the
     * date value. Bounded so a stray epoch can't be mistaken for a year.
     *
     * @param array<string, list<array{vrid:?int, value:?string, uri:?string, title:?string}>> $values
     */
    private function resolveYear(array $values, ?string $term): ?int
    {
        if ($term === null) {
            return null;
        }
        foreach ($values[$term] ?? [] as $v) {
            $raw = (string) ($v['value'] ?? '');
            if ($raw !== '' && preg_match('/\b(1\d{3}|20\d{2})\b/', $raw, $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }
}
