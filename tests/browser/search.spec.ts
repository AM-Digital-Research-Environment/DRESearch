import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { serve } from './fixture';

/** axe violations a visitor would hit: serious and critical only. */
async function seriousViolations(page: Page): Promise<string[]> {
  const results = await new AxeBuilder({ page }).include('main').analyze();
  return results.violations
    .filter((v) => v.impact === 'serious' || v.impact === 'critical')
    .map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
}

async function setTheme(page: Page, mode: 'light' | 'dark'): Promise<void> {
  // The theme writes the resolved mode to both <html> and <body>.
  await page.evaluate((value) => {
    document.documentElement.dataset.theme = value;
    document.body.dataset.theme = value;
  }, mode);
}

test.describe('search block', () => {
  test('mounts from the built bundle and renders result cards', async ({ page }) => {
    await serve(page);
    await page.goto('/');
    const card = page.getByRole('article').filter({ hasText: 'Photograph of the Bayreuth market' });
    await expect(card).toBeVisible();
    await expect(
      card.getByRole('heading', { level: 3, name: 'Photograph of the Bayreuth market' }),
    ).toBeVisible();
    await expect(
      card.getByRole('link', { name: 'Photograph of the Bayreuth market' }),
    ).toHaveAttribute('href', '/s/site/item/101');
    await expect(page.getByRole('status')).toHaveCount(1);
  });

  test('autocomplete opens, follows the arrow keys, closes on Escape and opens a record', async ({
    page,
  }) => {
    await serve(page);
    await page.goto('/');
    const box = page.getByRole('combobox', { name: 'Search research items…' });
    await box.fill('Bayreuth');
    const listbox = page.getByRole('listbox');
    await expect(
      listbox.getByRole('option', { name: /Photograph of the Bayreuth market/ }),
    ).toBeVisible();
    await expect(box).toHaveAttribute('aria-expanded', 'true');

    await box.press('ArrowDown');
    await expect(listbox.getByRole('option').first()).toHaveAttribute('aria-selected', 'true');
    await box.press('Escape');
    await expect(listbox).toBeHidden();
    await expect(box).toHaveAttribute('aria-expanded', 'false');

    await box.fill('Bayreut');
    await page.getByRole('option', { name: /Photograph of the Bayreuth market/ }).click();
    await expect(page).toHaveURL(/\/s\/site\/item\/101$/);
  });

  test('a facet toggles a filter and narrows the results', async ({ page }) => {
    const stub = await serve(page);
    await page.goto('/');
    await page.getByRole('checkbox', { name: /Audio/ }).check();
    await expect(page.getByRole('article')).toHaveCount(1);
    await expect(page.getByRole('article')).toContainText('Interview recording, Lagos');
    expect(stub.searches.at(-1)?.filters).toEqual({ type_s: ['Audio'] });
    await page.getByRole('button', { name: 'Clear all filters' }).click();
    await expect(page.getByRole('article')).toHaveCount(2);
  });

  test('a search that matches nothing says so, through the one status node', async ({ page }) => {
    await serve(page);
    await page.goto('/');
    const box = page.getByRole('combobox', { name: 'Search research items…' });
    await box.fill('nothing');
    await box.press('Enter');
    await expect(
      page.getByText('No records match that search.', { exact: true }).first(),
    ).toBeVisible();
    await expect(page.getByRole('status')).toHaveText('No records match that search.');
    await expect(page.getByRole('article')).toHaveCount(0);
  });

  test('a failed search offers Try again and keeps the detail out of the page', async ({
    page,
  }) => {
    const stub = await serve(page);
    const logged: string[] = [];
    page.on('console', (message) => {
      if (message.type() === 'error') logged.push(message.text());
    });
    await page.goto('/');
    stub.failSearches = 1;
    const box = page.getByRole('combobox', { name: 'Search research items…' });
    await box.fill('market');
    await box.press('Enter');

    const retry = page.getByRole('button', { name: 'Try again' });
    await expect(retry).toBeVisible();
    await expect(page.getByText('Search is temporarily unavailable.').first()).toBeVisible();
    await expect(page.locator('main')).not.toContainText('req-42');
    await expect(page.locator('main')).not.toContainText('500');
    // ...and in the console, for whoever debugs it.
    expect(logged.join('\n')).toContain('req-42');

    await retry.click();
    await expect(retry).toBeHidden();
    await expect(page.getByRole('article').first()).toBeVisible();
  });

  test('a titled block nests its headings under the block title', async ({ page }) => {
    await serve(page, 'titled-block');
    await page.goto('/');
    await expect(
      page.getByRole('heading', { level: 2, name: 'Find research items' }),
    ).toBeVisible();
    await expect(page.getByRole('heading', { level: 3, name: 'Filters' })).toBeVisible();
    await expect(
      page.getByRole('heading', { level: 4, name: 'Photograph of the Bayreuth market' }),
    ).toBeVisible();
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
  });

  test('follows the theme into dark mode', async ({ page }) => {
    await serve(page);
    await page.goto('/');
    const card = page.getByRole('article').first();
    await expect(card).toBeVisible();
    const light = await card.evaluate((el) => getComputedStyle(el).backgroundColor);
    await setTheme(page, 'dark');
    const dark = await card.evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(dark).not.toBe(light);
    expect(await seriousViolations(page)).toEqual([]);
  });

  test('has no serious or critical axe violations', async ({ page }) => {
    await serve(page);
    await page.goto('/');
    await expect(page.getByRole('article').first()).toBeVisible();
    expect(await seriousViolations(page)).toEqual([]);
  });

  test('does not scroll sideways at 320px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 800 });
    await serve(page);
    await page.goto('/');
    await expect(page.getByRole('article').first()).toBeVisible();
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
  });
});

test.describe('federated results page', () => {
  test('corpus tabs: arrows move focus, Enter selects, the panel is named by its tab', async ({
    page,
  }) => {
    await serve(page, 'federated');
    await page.goto('/search');
    const items = page.getByRole('tab', { name: /Research items/ });
    await expect(items).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('tabpanel')).toHaveAttribute(
      'aria-labelledby',
      'dre-fed-tab-research_items',
    );

    await items.focus();
    await page.keyboard.press('ArrowRight');
    const projects = page.getByRole('tab', { name: /Research projects/ });
    await expect(projects).toBeFocused();
    // Manual activation: focus alone does not switch (or fetch).
    await expect(projects).toHaveAttribute('aria-selected', 'false');

    await page.keyboard.press('Enter');
    await expect(projects).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('tabpanel')).toHaveAttribute(
      'aria-labelledby',
      'dre-fed-tab-research_projects',
    );
    await expect(page.getByText('Lived Religion in West Africa')).toBeVisible();
  });

  test('has no serious or critical axe violations, in light or dark', async ({ page }) => {
    await serve(page, 'federated');
    await page.goto('/search');
    await expect(page.getByRole('article').first()).toBeVisible();
    expect(await seriousViolations(page)).toEqual([]);
    await setTheme(page, 'dark');
    expect(await seriousViolations(page)).toEqual([]);
  });

  test('does not scroll sideways at 320px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 800 });
    await serve(page, 'federated');
    await page.goto('/search');
    await expect(page.getByRole('article').first()).toBeVisible();
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
  });
});
