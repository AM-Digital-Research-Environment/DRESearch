<?php

declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

use DRESearch\Search\Exception\RequestValidationException;
use DRESearch\Search\FilterExpression;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\SearchStateQuery;
use DRESearch\Search\QueryBuilder;
use DRESearch\Security\HtmlSanitizer;
use DRESearch\Settings\BlockProfiles;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use DRESearch\Settings\SortOptions;
use DRESearch\View\BundleAssets;
use DRESearch\View\SurfaceBootstrap;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Site\BlockLayout\TemplateableBlockLayoutInterface;
use Omeka\Stdlib\ErrorStore;

/**
 * The page block for a faceted search over one {@see SearchProfile}. One
 * instance per registered layout name ({@see BlockProfiles}), built by
 * {@see \DRESearch\Service\BlockLayout\SearchBlockFactory}: research items,
 * projects, publications, podcasts, videos, people, sections, organisations,
 * and the authority-term corpora — genres, languages, locations, subjects &
 * tags — each appearing as its own entry in the block picker. The layout names
 * are what `site_page_block.layout` stores, so they never change.
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
 * prepareRender() injects the Svelte bundle once per page; render() builds a
 * bootstrap blob ({@see SurfaceBootstrap}: the profile name + card kind so the
 * client renders the right card) and server-side renders the first page so the
 * block paints immediately — from the shared cache when another visitor just
 * did ({@see SearchProxy::firstPage()}), and not at all when the URL already
 * carries this block's own search state, which the client would fetch instead.
 * Themes may supply their own markup through Omeka's block templates
 * (`common/block-template/<name>`, declared under `block_templates` in the
 * theme config); the bootstrap and mount contract stay the same.
 */
final class SearchBlock extends AbstractBlockLayout implements TemplateableBlockLayoutInterface
{
    private readonly string $profileName;
    private readonly string $label;
    /** @var \Closure():string */
    private readonly \Closure $queryString;

    /**
     * @param string $layout a registered layout name ({@see BlockProfiles::LAYOUTS})
     * @param (\Closure():string)|null $queryString the request's raw query string
     *        ({@see SearchStateQuery}); defaults to the web request's, and is
     *        empty outside one (a job rendering a page)
     */
    public function __construct(
        private readonly SearchProxy $proxy,
        private readonly ProfileRegistry $registry,
        string $layout,
        ?\Closure $queryString = null,
    ) {
        $this->queryString = $queryString ?? static fn(): string => (string) ($_SERVER['QUERY_STRING'] ?? '');
        $profile = BlockProfiles::profileFor($layout);
        $label = BlockProfiles::labelFor($layout);
        if ($profile === null || $label === null) {
            throw new \InvalidArgumentException(sprintf('Unknown DRE Search block layout "%s".', $layout));
        }
        $this->profileName = $profile;
        $this->label = $label;
    }

    /** The block picker's entry (Omeka translates it). */
    public function getLabel()
    {
        return $this->label;
    }

    /** The profile this block searches, or null when the configuration no longer has it. */
    private function profile(): ?SearchProfile
    {
        return $this->registry->get($this->profileName);
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
                <?php if ($hasYearFacet) : ?>
                    <input type="hidden" name="<?= $escAttr($prefix) ?>[show_year]" value="0">
                    <label style="display:block;">
                        <input type="checkbox"
                               name="<?= $escAttr($prefix) ?>[show_year]"
                               value="1" <?= $showYear ? 'checked' : '' ?>>
                        <?= $esc($t($profile->dateLabel())) ?>
                        <code style="opacity:.6"><?= $esc($t('range slider')) ?></code>
                    </label>
                <?php endif; ?>
                <?php foreach ($allFacets as $field => $def) : ?>
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
                    <?php foreach ($sortOptions as $option) : ?>
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

    /** Inject the bundle once per page that uses this layout, preloading the block's chunk. */
    public function prepareRender(PhpRenderer $view): void
    {
        BundleAssets::inject($view, BundleAssets::SEARCH_BLOCK);
    }

    /**
     * Validate and normalise block data when a page is saved — through the
     * page editor AND the REST API, which used to store anything. A locked
     * filter that could escape its group (Typesense && and || share one
     * precedence level) is refused here rather than at search time.
     */
    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore): void
    {
        $data = $block->getData();
        if (!is_array($data)) {
            $data = [];
        }
        $filter = trim((string) ($data['locked_filter'] ?? ''));
        if ($filter !== '') {
            $problem = FilterExpression::problem($filter);
            if ($problem !== null) {
                $errorStore->addError('o:block[o:data][locked_filter]', $problem);
            }
        }
        $settings = new SearchBlockSettings($data, $this->profile());
        $data['locked_filter'] = $filter;
        $data['results_per_page'] = $settings->perPage();
        $data['default_sort'] = $settings->defaultSort();
        if (array_key_exists('facets', $data)) {
            $data['facets'] = $settings->facets();
        }
        $data['title'] = trim((string) ($data['title'] ?? ''));
        $block->setData($data);
    }

    /** The block's title and intro feed Omeka's own site-page search. */
    public function getFulltextText(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $data = $block->data();
        return trim((string) ($data['title'] ?? '') . ' ' . strip_tags((string) ($data['intro_html'] ?? '')));
    }

    /**
     * A block whose corpus is no longer configured renders nothing: there is
     * no search for it to run.
     *
     * @param string|null $templateViewScript a theme's block template, or the module's
     */
    public function render(
        PhpRenderer $view,
        SitePageBlockRepresentation $block,
        $templateViewScript = 'common/block-layout/dre-search-block'
    ) {
        $templateViewScript = (string) ($templateViewScript ?? 'common/block-layout/dre-search-block');
        $profile = $this->profile();
        if ($profile === null) {
            return '';
        }
        $data = $block->data();
        $blockId = (int) $block->id();
        $settings = new SearchBlockSettings($data, $profile);
        $surface = SurfaceBootstrap::forView($view, $this->proxy->popularEnabled());
        $corpus = $surface->corpus(
            $profile,
            $settings,
            null,
            $settings->showYear() ? $this->proxy->yearBounds($profile->name()) : null,
        );
        $bootstrap = $surface->embedded($corpus, $block->page()->site()->slug(), $blockId);

        // Server-render the first (browse) page so the block paints immediately,
        // unless the URL already carries this block's own state (`b{id}.…`): the
        // client would discard the default page and fetch the one asked for.
        if (!SearchStateQuery::carriesState(($this->queryString)(), SearchStateQuery::blockPrefix($blockId))) {
            // A block whose saved scope is invalid (e.g. a locked filter set
            // through the REST API) renders its "unavailable" state, not a 500.
            try {
                $bootstrap['initial_response'] = $this->proxy->firstPage($profile->name(), [
                    'q'        => '',
                    'page'     => 1,
                    'per_page' => $settings->perPage(),
                    'sort'     => $settings->defaultSort(),
                    'facets'   => $corpus['facets'],
                    'block_id' => $blockId,
                ]);
            } catch (RequestValidationException) {
                $bootstrap['initial_response'] = ['available' => false, 'found' => 0, 'page' => 1, 'hits' => [], 'facets' => []];
            }
        }

        return $view->partial($templateViewScript, [
            'block'      => $block,
            'data'       => $data,
            'bootstrap'  => $bootstrap,
            'title'      => (string) ($data['title'] ?? ''),
            'corpus_label' => (string) $view->translate($profile->label()),
            'intro_html' => HtmlSanitizer::sanitize((string) ($data['intro_html'] ?? '')),
        ]);
    }
}
