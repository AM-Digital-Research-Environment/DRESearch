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
 * value (no AuthorityResolver). Authors and editors are emitted as separate
 * display bylines (author_ss / editor_ss) and merged into one creator_ss facet —
 * the "Author / Editor" filter — so a single filter matches either role.
 *
 * Which property feeds which field comes from {@see SearchProfile} (config-driven);
 * the stable Typesense field names (creator_ss, container_ss, year, …) are the
 * interface. Beyond the facets, the mapper emits the bits a bibliographic
 * reference needs — the author/editor bylines, volume/issue, a normalised page
 * range, a DOI link — as display-only fields.
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
        $bag = new ValueBag($values);
        $doc = [
            'id'        => (string) $item['id'],
            'is_public' => $item['is_public'],
            'title'     => $item['title'] !== '' ? $item['title'] : sprintf('[Untitled #%d]', $item['id']),
        ];

        if (($abstract = $bag->firstLiteral('bibo:abstract')) !== null) {
            $doc['abstract'] = $abstract;
        }

        $df = $this->profile->displayFields();

        // Facet fields — each takes its linked titles (or literal fallback). Single-
        // valued facets store the first title, multi-valued ones the deduped list.
        // creator_ss (the Author / Editor facet) has no property of its own; it is
        // the author ∪ editor union built below, so the null-property guard skips it.
        foreach ($this->profile->all() as $field => $def) {
            if (empty($def['property'])) {
                continue;
            }
            $titles = $bag->labels($def['property']);
            if ($titles === []) {
                continue;
            }
            $doc[$field] = $this->profile->isMultivalued($field) ? $titles : $titles[0];
        }

        // People. Authors and editors are kept apart for the byline (author_ss /
        // editor_ss, display-only — the card marks editors "(eds.)"), then merged
        // into the creator_ss facet so one sidebar filter finds everything a person
        // authored OR edited. Names are linked persons (literal fallback); the card
        // filters creator_ss by the clicked name.
        $authors = $bag->labels($df['author_ss']['property'] ?? 'bibo:authorList');
        if ($authors !== []) {
            $doc['author_ss'] = $authors;
        }
        $editors = $bag->labels($df['editor_ss']['property'] ?? 'bibo:editorList');
        if ($editors !== []) {
            $doc['editor_ss'] = $editors;
        }
        $creators = array_values(array_unique(array_merge($authors, $editors)));
        if ($creators !== []) {
            $doc['creator_ss'] = $creators;
        }

        // Bibliographic-reference bits.
        if (($vol = $bag->firstLiteral($df['volume_s']['property'] ?? 'bibo:volume')) !== null) {
            $doc['volume_s'] = $vol;
        }
        if (($issue = $bag->firstLiteral($df['issue_s']['property'] ?? 'bibo:issue')) !== null) {
            $doc['issue_s'] = $issue;
        }
        if (($pages = $this->resolvePages($bag)) !== null) {
            $doc['pages_s'] = $pages;
        }
        if (($doi = $bag->firstDoi()) !== null) {
            $doc['doi_s'] = $doi;
        }

        // Year — single point (dcterms:date numeric:timestamp).
        if (($year = $bag->firstYear($this->profile->dateProperty())) !== null) {
            $doc['year'] = $year;
        }

        if ($thumbnailUrl !== null) {
            $doc['thumbnail_url'] = $thumbnailUrl;
        }

        return $doc;
    }

    /**
     * Recombine the pipeline's split page fields into one display string:
     * an explicit pages literal, else "start–end", else a lone start, else
     * "N pp." for a monograph's total page count.
     *
     */
    private function resolvePages(ValueBag $bag): ?string
    {
        if (($pages = $bag->firstLiteral('bibo:pages')) !== null) {
            return $pages;
        }
        $start = $bag->firstLiteral('bibo:pageStart');
        $end = $bag->firstLiteral('bibo:pageEnd');
        if ($start !== null && $end !== null) {
            return $start . '–' . $end; // en dash
        }
        if ($start !== null) {
            return $start;
        }
        if (($num = $bag->firstLiteral('bibo:numPages')) !== null) {
            return $num . ' pp.';
        }
        return null;
    }
}
