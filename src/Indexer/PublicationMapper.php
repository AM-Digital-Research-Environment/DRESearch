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
final class PublicationMapper extends AbstractMapper
{
    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        $bag = new ValueBag($values);
        $doc = $this->skeleton($item, $bag, 'bibo:abstract');

        // Extracted publication full text is a search-only payload.
        $fulltext = $bag->firstLiteral($this->displayProperty('fulltext'));
        if ($fulltext !== null) {
            $doc['fulltext'] = $fulltext;
        }

        // "Full text available" means the open-access repository holds the
        // record (on AMIRA, an EPub Bayreuth permalink), not that text has been
        // extracted yet; the permalink becomes the card's "Full text" link. A
        // profile without a fulltext_source falls back to the extracted text.
        // has_fulltext is the one stable token behind the client's quick toggle.
        $source = $this->profile->fulltextSource();
        if ($source !== null) {
            $fulltextUrl = null;
            foreach ($source['properties'] as $term) {
                $fulltextUrl ??= $bag->firstUrlUnder($term, $source['url_prefix']);
            }
            if ($fulltextUrl !== null) {
                $doc['fulltext_url_s'] = $fulltextUrl;
                $doc['has_fulltext'] = 'Yes';
            }
        } elseif ($fulltext !== null) {
            $doc['has_fulltext'] = 'Yes';
        }

        // Facet fields. creator_ss (the Author / Editor facet) has no property of
        // its own; it is the author ∪ editor union built below.
        $this->addFacetLabels($doc, $bag);

        // People. Authors and editors are kept apart for the byline (author_ss /
        // editor_ss, display-only — the card marks editors "(eds.)"), then merged
        // into the creator_ss facet so one sidebar filter finds everything a person
        // authored OR edited. Names are linked persons (literal fallback); the card
        // filters creator_ss by the clicked name.
        $authors = $bag->labels($this->displayProperty('author_ss'));
        if ($authors !== []) {
            $doc['author_ss'] = $authors;
        }
        $editors = $bag->labels($this->displayProperty('editor_ss'));
        if ($editors !== []) {
            $doc['editor_ss'] = $editors;
        }
        $creators = array_values(array_unique(array_merge($authors, $editors)));
        if ($creators !== []) {
            $doc['creator_ss'] = $creators;
        }

        // Bibliographic-reference bits.
        if (($vol = $bag->firstLiteral($this->displayProperty('volume_s'))) !== null) {
            $doc['volume_s'] = $vol;
        }
        if (($issue = $bag->firstLiteral($this->displayProperty('issue_s'))) !== null) {
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

        $this->addThumbnail($doc, $thumbnailUrl);
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
