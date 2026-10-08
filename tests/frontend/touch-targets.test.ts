import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const component = (name: string): string =>
  readFileSync(join(process.cwd(), 'src', 'svelte', 'components', name), 'utf8');
const stylesheet = (name: string): string =>
  readFileSync(join(process.cwd(), 'src', 'svelte', 'styles', name), 'utf8');

/** The CSS rule for `selector` in `source` (up to the closing brace). */
const ruleFor = (source: string, selector: string): string => {
  const start = source.indexOf(`${selector} {`);
  return start === -1 ? '' : source.slice(start, source.indexOf('}', start));
};

describe('touch-target contracts', () => {
  it.each([
    ['SearchBar.svelte', '.dre-search-bar__toggle'],
    ['SearchBar.svelte', '.dre-search-bar__clear'],
    ['SearchBox.svelte', '.dre-search-box__clear'],
    ['ExportMenu.svelte', '.dre-export__item'],
    ['Pagination.svelte', '.dre-pager__btn'],
    ['FederatedApp.svelte', '.dre-fed__search > button'],
  ])('%s gives %s a 44px control token', (file, selector) => {
    const source = component(file);
    const ruleStart = source.indexOf(`${selector} {`);
    const ruleEnd = source.indexOf('\n  }', ruleStart);
    const rule = source.slice(ruleStart, ruleEnd);

    expect(ruleStart, `${selector} should have a CSS rule`).toBeGreaterThan(-1);
    expect(rule).toContain('var(--size-control-lg, 2.75rem)');
  });

  it('the shared secondary button is a 44px control', () => {
    expect(ruleFor(stylesheet('buttons.css'), '.dre-button-secondary')).toContain(
      'min-height: var(--size-control-lg, 2.75rem)',
    );
  });

  it.each([
    ['ExportMenu.svelte', 'dre-export__trigger'],
    ['CopyLinkButton.svelte', ''],
    ['Pagination.svelte', 'dre-pager__btn'],
    ['CiteMenu.svelte', ''],
  ])('%s draws %s as the shared secondary button', (file, base) => {
    const source = component(file);
    expect(source).toContain("import '../styles/buttons.css';");
    expect(source).toContain(`class="${base ? base + ' ' : ''}dre-button-secondary"`);
  });

  it.each(['SortSelect.svelte', 'ViewToggle.svelte'])('%s uses the 44px control token', (file) => {
    expect(component(file)).toContain('var(--size-control-lg, 2.75rem)');
  });
});
