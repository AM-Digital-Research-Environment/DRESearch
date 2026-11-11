import { expect, test, type Locator, type Page } from '@playwright/test';
import { serve } from './fixture';

/**
 * Touch targets, measured on the rendered page (DRE-theme integration
 * contract, "Touch-target contract": 44 × 44 CSS px). These used to be
 * assertions on the CSS source — "this rule mentions --size-control-lg" —
 * which stayed green whatever the cascade made of the rule. Dense, repeated
 * controls (card chips, corpus tabs, facet checkboxes) use the WCAG 2.2
 * spacing exception instead and are not measured here.
 */

const MIN = 44;

/** Assert `locator` renders at least `width` × `height` CSS px. */
async function expectTarget(
  locator: Locator,
  { width = MIN, height = MIN }: { width?: number; height?: number } = {},
): Promise<void> {
  await expect(locator).toBeVisible();
  const box = await locator.boundingBox();
  // Sub-pixel layout can land a rem-sized control a hair under its integer size.
  expect(box?.width ?? 0, 'width').toBeGreaterThanOrEqual(width - 0.5);
  expect(box?.height ?? 0, 'height').toBeGreaterThanOrEqual(height - 0.5);
}

async function cards(page: Page): Promise<void> {
  await expect(page.getByRole('article').first()).toBeVisible();
}

test.describe('search block', () => {
  test('the search box clear, sort, view and result actions', async ({ page }) => {
    await serve(page);
    await page.goto('/');
    await cards(page);
    const box = page.getByRole('combobox', { name: 'Search research items…' });
    await box.fill('market');
    await expectTarget(page.getByRole('button', { name: 'Clear search' }));
    // Close the suggestions, which float over the toolbar measured next. Wait
    // for them first: an Escape that lands before the debounced fetch returns
    // leaves the list to open afterwards (seen on WebKit in CI).
    const suggestions = page.getByRole('listbox');
    await expect(suggestions).toBeVisible();
    await box.press('Escape');
    await expect(suggestions).toBeHidden();
    await expectTarget(page.getByRole('combobox', { name: 'Sort' }), { width: MIN });
    // The view switch is one 44px segmented control: its buttons fill it inside
    // the group's 1px border, so each is 42px tall and at least 44px wide.
    const views = page.getByRole('group', { name: 'View as' });
    await expectTarget(views);
    for (const name of ['List', 'Grid']) {
      await expectTarget(views.getByRole('button', { name, exact: true }), { height: MIN - 2 });
    }
    await expectTarget(page.getByRole('button', { name: 'Copy link' }));
    const exportTrigger = page.getByRole('button', { name: 'Export results' });
    await expectTarget(exportTrigger);
    await exportTrigger.click();
    const formats = page.getByRole('group', { name: 'Export results' }).getByRole('button');
    await expect(formats).toHaveCount(4);
    for (const item of await formats.all()) await expectTarget(item, { width: 0 });
  });

  test('the pager', async ({ page }) => {
    await serve(page);
    await page.goto('/');
    const box = page.getByRole('combobox', { name: 'Search research items…' });
    await box.fill('many');
    await box.press('Enter');
    const pager = page.getByRole('navigation', { name: 'Pagination' });
    await expect(pager).toBeVisible();
    for (const button of await pager.getByRole('button').all()) await expectTarget(button);
  });

  test('Try again', async ({ page }) => {
    const stub = await serve(page);
    await page.goto('/');
    await cards(page);
    stub.failSearches = 1;
    const box = page.getByRole('combobox', { name: 'Search research items…' });
    await box.fill('market');
    await box.press('Enter');
    await expectTarget(page.getByRole('button', { name: 'Try again' }), { width: 0 });
  });

  test('the filters toggle on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await serve(page);
    await page.goto('/');
    await cards(page);
    await expectTarget(page.getByRole('button', { name: 'Filters', expanded: false }), {
      width: 375 - 2 * 16 - 1,
    });
  });
});

test.describe('federated results page', () => {
  test('the search box clear and a card’s Cite actions', async ({ page }) => {
    await serve(page, 'federated');
    await page.goto('/search?profile=all');
    await expect(page.getByRole('article').first()).toBeVisible();
    // Cite itself is a card-footer pill, like the chips (spacing exception);
    // the actions it opens are full-size buttons. Measured before typing: a
    // typed query reloads the merged list and closes the popover.
    await page.locator('.dre-cite summary').first().click();
    for (const name of ['Copy BibTeX', 'Copy RIS', 'Download .ris']) {
      await expectTarget(page.getByRole('button', { name }), { width: 0 });
    }
    await page.keyboard.press('Escape');
    await page.getByRole('searchbox', { name: 'Search everything…' }).fill('poetry');
    await expectTarget(page.getByRole('button', { name: 'Clear search' }));
  });
});

test.describe('header search bars', () => {
  test('the collapsed toggle and both clear buttons', async ({ page }) => {
    await serve(page, 'header');
    await page.goto('/');
    await cards(page);
    const header = page.locator('header');
    await header.getByRole('combobox', { name: 'Search everything…' }).fill('market');
    await expectTarget(header.getByRole('button', { name: 'Clear search' }));

    const toggle = header.getByRole('button', { name: 'Search everything…', expanded: false });
    await expectTarget(toggle);
    await toggle.click();
    await page.keyboard.type('lagos');
    await expect(header.getByRole('button', { name: 'Clear search' })).toHaveCount(2);
    for (const clear of await header.getByRole('button', { name: 'Clear search' }).all()) {
      await expectTarget(clear);
    }
  });
});
