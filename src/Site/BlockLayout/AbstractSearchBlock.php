<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

use DRESearch\Search\SearchProxy;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
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
    private const SORT_OPTIONS = [
        'relevance' => 'Relevance',       // @translate
        'newest'    => 'Newest first',    // @translate
        'oldest'    => 'Oldest first',    // @translate
        'title'     => 'Title (A–Z)',     // @translate
    ];

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

    /**
     * Human label for a sort key. Built-ins come from SORT_OPTIONS; the `count`
     * key uses the profile's configured label (e.g. "Most research items").
     *
     * @param callable(string):string $t
     */
    private function sortLabel(?SearchProfile $profile, string $value, callable $t): string
    {
        if ($value === 'count' && $profile !== null) {
            return $t($profile->sortCountLabel());
        }
        // Config-defined numeric sort (e.g. podcasts' "Episode number").
        if ($profile !== null && ($custom = $profile->sortFieldLabel($value)) !== null) {
            return $t($custom);
        }
        return $t(self::SORT_OPTIONS[$value] ?? $value);
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
        $title        = (string) ($data['title'] ?? '');
        $introHtml    = (string) ($data['intro_html'] ?? '');
        $facets       = $data['facets'] ?? array_keys($allFacets);
        $showYear     = !$block || (bool) ($data['show_year'] ?? true);
        $sortValues   = $profile ? $profile->sortOptionValues() : array_keys(self::SORT_OPTIONS);
        $defaultSort  = (string) ($data['default_sort'] ?? ($profile ? $profile->defaultSort() : 'relevance'));
        if (!in_array($defaultSort, $sortValues, true)) {
            $defaultSort = $sortValues[0] ?? 'relevance';
        }
        $perPage      = (int) ($data['results_per_page'] ?? 20);
        $lockedFilter = (string) ($data['locked_filter'] ?? '');

        $esc     = fn(string $s): string => $view->escapeHtml($s);
        $escAttr = fn(string $s): string => $view->escapeHtmlAttr($s);
        $t       = fn(string $s): string => (string) $view->translate($s);
        $prefix  = 'o:block[__blockIndex__][o:data]';

        ob_start();
        ?>
        <div class="field">
            <div class="field-meta">
                <label for="dre-search-title"><?= $esc($t('Title (optional)')) ?></label>
            </div>
            <div class="inputs">
                <input id="dre-search-title" type="text"
                       name="<?= $escAttr($prefix) ?>[title]" value="<?= $escAttr($title) ?>">
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="dre-search-intro"><?= $esc($t('Intro HTML (optional)')) ?></label>
                <div class="field-description"><?= $esc($t('Plain HTML rendered above the search.')) ?></div>
            </div>
            <div class="inputs">
                <textarea id="dre-search-intro" rows="3"
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
                <label for="dre-search-sort"><?= $esc($t('Default sort')) ?></label>
            </div>
            <div class="inputs">
                <select id="dre-search-sort" name="<?= $escAttr($prefix) ?>[default_sort]">
                    <?php foreach ($sortValues as $key): ?>
                        <option value="<?= $escAttr($key) ?>"<?= $key === $defaultSort ? ' selected' : '' ?>>
                            <?= $esc($this->sortLabel($profile, $key, $t)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="dre-search-perpage"><?= $esc($t('Results per page')) ?></label>
            </div>
            <div class="inputs">
                <input id="dre-search-perpage" type="number" min="1" max="50" step="1"
                       name="<?= $escAttr($prefix) ?>[results_per_page]" value="<?= $escAttr((string) $perPage) ?>">
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="dre-search-locked"><?= $esc($t('Locked filter (optional)')) ?></label>
                <div class="field-description">
                    <?= $esc($t('Pins this block to a subset, Typesense filter_by syntax. Example: section_ss:=`Mobilities`')) ?>
                </div>
            </div>
            <div class="inputs">
                <input id="dre-search-locked" type="text"
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

        // Inject the bundle once per page (headLink/headScript dedupe by URL).
        $view->headLink()->appendStylesheet($view->assetUrl('css/dre-search.css', 'DRESearch'));
        $view->headLink()->appendStylesheet($view->assetUrl('dist/dre-search.css', 'DRESearch'));
        $view->headScript()->appendFile(
            $view->assetUrl('dist/dre-search.js', 'DRESearch'),
            'text/javascript',
            ['defer' => true]
        );

        $allFields = $profile ? $profile->fieldNames() : [];

        // Sanitise the configured facet list against the known fields.
        $facets = array_values(array_intersect(
            $allFields,
            (array) ($data['facets'] ?? $allFields)
        ));
        if ($facets === []) {
            $facets = $allFields;
        }

        $hasYearFacet = $profile && $profile->hasYearFacet();
        $showYear     = $hasYearFacet && (bool) ($data['show_year'] ?? true);

        // Sort options for this corpus (drops year sorts on date-less corpora,
        // adds the count sort where configured). default_sort is validated against
        // them so a stale/invalid saved value can't reach the client.
        $t           = fn(string $s): string => (string) $view->translate($s);
        $sortValues  = $profile ? $profile->sortOptionValues() : array_keys(self::SORT_OPTIONS);
        $defaultSort = (string) ($data['default_sort'] ?? ($profile ? $profile->defaultSort() : 'relevance'));
        if (!in_array($defaultSort, $sortValues, true)) {
            $defaultSort = $sortValues[0] ?? 'relevance';
        }
        $sortOptions = [];
        foreach ($sortValues as $value) {
            $sortOptions[] = ['value' => $value, 'label' => $this->sortLabel($profile, $value, $t)];
        }

        $perPage      = (int) ($data['results_per_page'] ?? 20);
        $perPage      = $perPage > 0 ? $perPage : 20;
        $lockedFilter = (string) ($data['locked_filter'] ?? '');

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
            'locked_filter' => $lockedFilter,
            // Client builds result links as `${item_url_base}/${id}`.
            'item_url_base' => $view->basePath('/s/' . $siteSlug . '/item'),
            'endpoints'     => [
                'search'         => $view->basePath('/dre-search/api/search'),
                'suggest'        => $view->basePath('/dre-search/api/suggest'),
                'year_histogram' => $view->basePath('/dre-search/api/year-histogram'),
            ],
        ];

        // Server-render the first (browse) page so the block paints immediately.
        $bootstrap['initial_response'] = $this->proxy->search($profileName, [
            'q'             => '',
            'page'          => 1,
            'per_page'      => $perPage,
            'sort'          => $defaultSort,
            'facets'        => $facets,
            'locked_filter' => $lockedFilter,
        ]);

        return $view->partial($templateViewScript, [
            'block'      => $block,
            'data'       => $data,
            'bootstrap'  => $bootstrap,
            'title'      => (string) ($data['title'] ?? ''),
            'intro_html' => (string) ($data['intro_html'] ?? ''),
        ]);
    }
}
