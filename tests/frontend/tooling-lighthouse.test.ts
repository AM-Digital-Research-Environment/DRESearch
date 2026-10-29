import { existsSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
// @ts-expect-error — a plain-JS Node module with no type declarations.
import { ORIGIN, PAGES, queryOf } from '../../scripts/lighthouse/pages.mjs';

/**
 * The Lighthouse fixtures (tests/lighthouse/fixtures/, written by
 * scripts/lighthouse/capture.mjs) against what the gate and the module expect
 * of them. The gate itself runs in CI (lighthouse.yml); this catches, on the
 * PR, a fixture that would make it measure the wrong thing: one that loads the
 * live site's bundle instead of this checkout's, reaches the network, or
 * mounts a surface src/View/BundleAssets.php no longer preloads.
 */

type Page = { route: string; surface: string };
const pages = Object.entries(PAGES as Record<string, Page>);
const fixture = (name: string, ext: string) => `tests/lighthouse/fixtures/${name}.${ext}`;

describe('Lighthouse fixtures', () => {
  const bundleAssets = readFileSync('src/View/BundleAssets.php', 'utf8');
  const surfaces = [...bundleAssets.matchAll(/public const \w+ = '([^']+)';/g)].map((m) => m[1]);

  it.each(pages)('%s carries the markers the fixture server expands', (name, page) => {
    const html = readFileSync(fixture(name, 'html'), 'utf8');
    expect(html).toContain(`<!-- dre-search:head ${page.surface} -->`);
    expect(html).toContain('<!-- dre-search:entry -->');
    // The surface is one BundleAssets preloads, keyed as in the build manifest.
    expect(surfaces).toContain(page.surface);
  });

  it.each(pages)('%s loads nothing from the live site', (name) => {
    const html = readFileSync(fixture(name, 'html'), 'utf8');
    const api = readFileSync(fixture(name, 'api.json'), 'utf8');
    for (const text of [html, api]) {
      expect(text).not.toMatch(/\/modules\/DRESearch\//);
      expect(text).not.toContain(`${ORIGIN}/files/`);
    }
    expect(() => JSON.parse(api)).not.toThrow();
  });

  it('measures each page with the query it was captured with', () => {
    expect(queryOf('block')).toBe('');
    expect(queryOf('federated')).toMatch(/^\?q=./);
    const federated = readFileSync(fixture('federated', 'html'), 'utf8');
    const q = new URLSearchParams(queryOf('federated')).get('q');
    expect(federated).toContain(`"initial_query":"${q}"`);
  });

  it('has a placeholder for every thumbnail tier the server answers', () => {
    for (const tier of ['square', 'medium', 'large']) {
      expect(existsSync(`tests/lighthouse/fixtures/files/${tier}.jpg`)).toBe(true);
    }
  });
});
