<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

use DRESearch\Search\SearchProxy;
use DRESearch\Search\QueryBuilder;
use DRESearch\Security\HtmlSanitizer;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use DRESearch\Settings\SortOptions;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;

/**
 * Shared page block for a faceted search over one {@see SearchProfile}. A thin
 * subclass per corpus binds it to a profile name (research items, projects,
 * publications, people, sections, organisations, and the authority-term corpora —
 * genres, languages, locations, subjects & tags), each appearing as its own entry
 * in the block picker.
 *
 * Persisted block data (site_block.data):
 *   {
 *     "title":            "Optional H2",
 *     "intro_html":       "Optional intro HTML",
 *     "facets":           ["institution_ss", ...],         // which facets to show
 *     "show_year":        "1",                               // year slider (range profiles)
 *     "default_sort":     "relevance" | "newest" | "oldest" | "title",
 *     "results_per_page": 20,
 *     "locked_filter":    "section_ss:=`Mobilities`"         // optional, raw filter_by
 *   }
 *
 * render() injects the Svelte bundle, builds a bootstrap blob (including the
 * profile name + card kind so the client renders the right card), and server-side
 * renders the first page so the block paints immediately.
 */
abstract class AbstractSearchBlock extends AbstractBlockLayout
{
    public function __construct(
        private readonly SearchProxy $proxy,
        private readonly ProfileRegistry $registry,
    ) {
    }

    /** The profile name this block searches (e.g. 'research_items'). */
    abstract protected function profileName(): string;

    protected function profile(): ?SearchProfile
    {
        return $this->registry->get($this->profileName());
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        ?SitePageRepresentation $page = null,
        ?SitePageBlockRepresentation $block = null
    ) {
        $profile = $this->profile();
        $allFacets = $profile ? $profile->all() : [];
        $hasYearFacet = $profile && $profile->hasYearFacet();

        $data = $block ? $block->data() : [];
        $settings = new SearchBlockSettings($data, $profile);
        $title        = (string) ($data['title'] ?? '');
        $introHtml    = (string) ($data['intro_html'] ?? '');
        $facets       = $settings->facets();
        $showYear     = !$block || $settings->showYear();
        $defaultSort  = $settings->defaultSort();
        $perPage      = $settings->perPage();
        $lockedFilter = $settings->lockedFilter();

        $esc     = fn(string $s): string => $view->escapeHtml($s);
        $escAttr = fn(string $s): string => $view->escapeHtmlAttr($s);
        $t       = fn(string $s): string => (string) $view->translate($s);
        $prefix  = 'o:block[__blockIndex__][o:data]';
        $idPrefix = 'dre-search-__blockIndex__-';
        $sortOptions = $profile ? SortOptions::forProfile($profile, $t) : [];

        ob_start();
        ?>
        <div class="field">
            <div class="field-meta">
                <label for="<?= $escAttr($idPrefix) ?>title"><?= $esc($t('Title (optional)')) ?></label>
            </div>
            <div class="inputs">
                <input id="<?= $escAttr($idPrefix) ?>title" type="text"
                       name="<?= $escAttr($prefix) ?>[title]" value="<?= $escAttr($title) ?>">
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="<?= $escAttr($idPrefix) ?>intro"><?= $esc($t('Intro HTML (optional)')) ?></label>
                <div class="field-description"><?= $esc($t('Plain HTML rendered above the search.')) ?></div>
            </div>
            <div class="inputs">
                <textarea id="<?= $escAttr($idPrefix) ?>intro" rows="3"
                          name="<?= $escAttr($prefix) ?>[intro_html]"><?= $esc($introHtml) ?></textarea>
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label><?= $esc($t('Filters to show')) ?></label>
                <div class="field-description"><?= $esc($t('Which facets appear in the sidebar.')) ?></div>
            </div>
            <div class="inputs">
                <?php if ($hasYearFacet): ?>
                    <input type="hidden" name="<?= $escAttr($prefix) ?>[show_year]" value="0">
                    <label style="display:block;">
                        <input type="checkbox"
                               name="<?= $escAttr($prefix) ?>[show_year]"
                               value="1" <?= $showYear ? 'checked' : '' ?>>
                        <?= $esc($t($profile->dateLabel())) ?>
                        <code style="opacity:.6"><?= $esc($t('range slider')) ?></code>
                    </label>
                <?php endif; ?>
                <?php foreach ($allFacets as $field => $def): ?>
                    <label style="display:block;">
                        <input type="checkbox"
                               name="<?= $escAttr($prefix) ?>[facets][]"
                               value="<?= $escAttr($field) ?>"
                               <?= in_array($field, (array) $facets, true) ? 'checked' : '' ?>>
                        <?= $esc($t($def['label'])) ?>
                        <code style="opacity:.6"><?= $esc($field) ?></code>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="<?= $escAttr($idPrefix) ?>sort"><?= $esc($t('Default sort')) ?></label>
            </div>
            <div class="inputs">
                <select id="<?= $escAttr($idPrefix) ?>sort" name="<?= $escAttr($prefix) ?>[default_sort]">
                    <?php foreach ($sortOptions as $option): ?>
                        <option value="<?= $escAttr($option['value']) ?>"<?= $option['value'] === $defaultSort ? ' selected' : '' ?>>
                            <?= $esc($option['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="<?= $escAttr($idPrefix) ?>perpage"><?= $esc($t('Results per page')) ?></label>
            </div>
            <div class="inputs">
                <input id="<?= $escAttr($idPrefix) ?>perpage" type="number" min="1" max="<?= QueryBuilder::PER_PAGE_MAX ?>" step="1"
                       name="<?= $escAttr($prefix) ?>[results_per_page]" value="<?= $escAttr((string) $perPage) ?>">
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="<?= $escAttr($idPrefix) ?>locked"><?= $esc($t('Locked filter (optional)')) ?></label>
                <div class="field-description">
                    <?= $esc($t('Pins this block to a subset, Typesense filter_by syntax. Example: section_ss:=`Mobilities`')) ?>
                </div>
            </div>
            <div class="inputs">
                <input id="<?= $escAttr($idPrefix) ?>locked" type="text" maxlength="1000"
                       name="<?= $escAttr($prefix) ?>[locked_filter]"
                       value="<?= $escAttr($lockedFilter) ?>" placeholder="field:=`...`">
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function render(
        PhpRenderer $view,
        SitePageBlockRepresentation $block,
        $templateViewScript = 'common/block-layout/dre-search-block'
    ) {
        $data = $block->data();
        $profile = $this->profile();
        $settings = new SearchBlockSettings($data, $profile);

        // Inject the bundle once per page (headLink/headScript dedupe by URL).
        $view->headLink()->appendStylesheet($view->assetUrl('css/dre-search.css', 'DRESearch'));
        $view->headLink()->appendStylesheet($view->assetUrl('dist/dre-search.css', 'DRESearch'));
        $view->headScript()->appendFile(
            $view->assetUrl('dist/dre-search.js', 'DRESearch'),
            'text/javascript',
            ['defer' => true]
        );

        $facets = $settings->facets();
        $showYear = $settings->showYear();

        // Sort options for this corpus (drops year sorts on date-less corpora,
        // adds the count sort where configured). default_sort is validated against
        // them so a stale/invalid saved value can't reach the client.
        $t           = fn(string $s): string => (string) $view->translate($s);
        $defaultSort = $settings->defaultSort();
        $sortOptions = $profile ? SortOptions::forProfile($profile, $t) : [];
        $perPage = $settings->perPage();

        $facetLabels = [];
        foreach ($facets as $field) {
            $facetLabels[$field] = (string) $view->translate($profile->facetLabel($field));
        }

        $profileName = $profile ? $profile->name() : '';
        $siteSlug = $block->page()->site()->slug();

        // Corpus-specific search-box hint (e.g. "Search genres…"), so corpora that
        // share a card kind can still read distinctly. Null → the client uses its
        // kind-derived default.
        $placeholder = $profile && $profile->placeholder() !== ''
            ? (string) $view->translate($profile->placeholder())
            : null;

        $bootstrap = [
            'block_id'      => (int) $block->id(),
            'profile'       => $profileName,
            'card_kind'     => $profile ? $profile->kind() : 'item',
            'search_placeholder' => $placeholder,
            'date_mode'     => $profile ? $profile->dateMode() : 'single',
            'show_year'     => $showYear,
            'year_bounds'   => $showYear ? $this->proxy->yearBounds($profileName) : null,
            'facets'        => $facets,
            'facet_labels'  => $facetLabels,
            'default_sort'  => $defaultSort,
            'sort_options'  => $sortOptions,
            'per_page'      => $perPage,
            // Client builds result links as `${item_url_base}/${id}`.
            'item_url_base' => $view->basePath('/s/' . $siteSlug . '/item'),
            'endpoints'     => [
                'search'  => $view->basePath('/dre-search/api/search'),
                'export'  => $view->basePath('/dre-search/api/export'),
                'suggest' => $view->basePath('/dre-search/api/suggest'),
            ],
        ];

        // Server-render the first (browse) page so the block paints immediately.
        $bootstrap['initial_response'] = $this->proxy->search($profileName, [
            'q'             => '',
            'page'          => 1,
            'per_page'      => $perPage,
            'sort'          => $defaultSort,
            'facets'        => $facets,
            'block_id'      => (int) $block->id(),
        ]);

        return $view->partial($templateViewScript, [
            'block'      => $block,
            'data'       => $data,
            'bootstrap'  => $bootstrap,
            'title'      => (string) ($data['title'] ?? ''),
            'intro_html' => HtmlSanitizer::sanitize((string) ($data['intro_html'] ?? '')),
        ]);
    }
}
