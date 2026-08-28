import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const component = (name: string): string =>
  readFileSync(join(process.cwd(), 'src', 'svelte', 'components', name), 'utf8');

describe('touch-target contracts', () => {
  it.each([
    ['SearchBar.svelte', '.dre-search-bar__toggle'],
    ['SearchBar.svelte', '.dre-search-bar__clear'],
    ['SearchBox.svelte', '.dre-search-box__clear'],
    ['ExportMenu.svelte', '.dre-export__trigger'],
    ['ExportMenu.svelte', '.dre-export__item'],
    ['Pagination.svelte', '.dre-pager button'],
    ['ResultsList.svelte', '.dre-pager__btn'],
    ['FederatedApp.svelte', '.dre-fed__search > button'],
  ])('%s gives %s a 44px control token', (file, selector) => {
    const source = component(file);
    const ruleStart = source.indexOf(`${selector} {`);
    const ruleEnd = source.indexOf('\n  }', ruleStart);
    const rule = source.slice(ruleStart, ruleEnd);

    expect(ruleStart, `${selector} should have a CSS rule`).toBeGreaterThan(-1);
    expect(rule).toContain('var(--size-control-lg, 2.75rem)');
  });

  it.each(['CopyLinkButton.svelte', 'SortSelect.svelte', 'ViewToggle.svelte'])(
    '%s uses the 44px control token',
    (file) => {
      expect(component(file)).toContain('var(--size-control-lg, 2.75rem)');
    },
  );
});
