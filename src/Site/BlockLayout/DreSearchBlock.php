<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

use DRESearch\Search\SearchProxy;
use DRESearch\Settings\FacetConfig;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;

/**
 * Page block that drops the faceted research-item search onto any Site page.
 *
 * Persisted block data (site_block.data):
 *   {
 *     "title":            "Optional H2",
 *     "intro_html":       "Optional intro HTML",
 *     "facets":           ["type_s","country_ss", ...],   // which facets to show
 *     "default_sort":     "relevance" | "newest" | "oldest" | "title",
 *     "results_per_page": 20,
 *     "locked_filter":    "project_s:=`Remoboko`"          // optional, raw filter_by
 *   }
 *
 * render() injects the Svelte bundle, builds a bootstrap blob, and server-side
 * renders the first page through SearchProxy so the block paints results
 * immediately (and degrades to a quiet notice when Typesense is off).
 */
class DreSearchBlock extends AbstractBlockLayout
{
    private const SORT_OPTIONS = [
        'relevance' => 'Relevance',       // @translate
        'newest'    => 'Newest first',    // @translate
        'oldest'    => 'Oldest first',    // @translate
        'title'     => 'Title (A–Z)',     // @translate
    ];

    public function __construct(private readonly SearchProxy $proxy)
    {
    }

    public function getLabel()
    {
        return 'DRE Search'; // @translate
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        ?SitePageRepresentation $page = null,
        ?SitePageBlockRepresentation $block = null
    ) {
        $data = $block ? $block->data() : [];
        $title        = (string) ($data['title'] ?? '');
        $introHtml    = (string) ($data['intro_html'] ?? '');
        $facets       = $data['facets'] ?? FacetConfig::fieldNames();
        $defaultSort  = (string) ($data['default_sort'] ?? 'relevance');
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
                <?php foreach (FacetConfig::all() as $field => $def): ?>
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
                    <?php foreach (self::SORT_OPTIONS as $key => $label): ?>
                        <option value="<?= $escAttr($key) ?>"<?= $key === $defaultSort ? ' selected' : '' ?>>
                            <?= $esc($t($label)) ?>
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
                    <?= $esc($t('Pins this block to a subset, Typesense filter_by syntax. Example: project_s:=`Remoboko`')) ?>
                </div>
            </div>
            <div class="inputs">
                <input id="dre-search-locked" type="text"
                       name="<?= $escAttr($prefix) ?>[locked_filter]"
                       value="<?= $escAttr($lockedFilter) ?>" placeholder="project_s:=`...`">
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

        // Inject the bundle once per page (headLink/headScript dedupe by URL).
        $view->headLink()->appendStylesheet($view->assetUrl('css/dre-search.css', 'DRESearch'));
        $view->headLink()->appendStylesheet($view->assetUrl('dist/dre-search.css', 'DRESearch'));
        $view->headScript()->appendFile(
            $view->assetUrl('dist/dre-search.js', 'DRESearch'),
            'text/javascript',
            ['defer' => true]
        );

        // Sanitise the configured facet list against the known fields.
        $facets = array_values(array_intersect(
            FacetConfig::fieldNames(),
            (array) ($data['facets'] ?? FacetConfig::fieldNames())
        ));
        if ($facets === []) {
            $facets = FacetConfig::fieldNames();
        }

        $defaultSort  = (string) ($data['default_sort'] ?? 'relevance');
        $perPage      = (int) ($data['results_per_page'] ?? 20);
        $perPage      = $perPage > 0 ? $perPage : 20;
        $lockedFilter = (string) ($data['locked_filter'] ?? '');

        $facetLabels = [];
        foreach ($facets as $field) {
            $facetLabels[$field] = (string) $view->translate(FacetConfig::label($field));
        }

        $siteSlug = $block->page()->site()->slug();

        $bootstrap = [
            'block_id'      => (int) $block->id(),
            'facets'        => $facets,
            'facet_labels'  => $facetLabels,
            'default_sort'  => $defaultSort,
            'per_page'      => $perPage,
            'locked_filter' => $lockedFilter,
            // Client builds result links as `${item_url_base}/${id}`.
            'item_url_base' => $view->basePath('/s/' . $siteSlug . '/item'),
            'endpoints'     => [
                'search'  => $view->basePath('/dre-search/api/search'),
                'suggest' => $view->basePath('/dre-search/api/suggest'),
            ],
        ];

        // Server-render the first (browse) page so the block paints immediately.
        $bootstrap['initial_response'] = $this->proxy->search([
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
