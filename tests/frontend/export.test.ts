import { describe, expect, it } from 'vitest';
import { serialize, type ExportMeta } from '../../src/svelte/lib/export';
import type { Doc } from '../../src/svelte/lib/types';

const meta: ExportMeta = {
  query: 'history',
  found: 1,
  filters: {},
  yearFrom: null,
  yearTo: null,
  facetLabels: {},
};

describe('export serializers', () => {
  it('prevents newlines from injecting RIS fields', () => {
    const doc: Doc = { id: '1', title: 'Title\r\nER  - injected', author_ss: ['Doe\nAU  - Bad'] };
    const ris = serialize('ris', [doc], meta, 'publication', '/s/site/item');
    expect(ris).not.toContain('\nER  - injected');
    expect(ris).not.toContain('\nAU  - Bad');
  });

  it('drops unsafe external media URLs', () => {
    const doc: Doc = { id: '2', title: 'Episode', url_s: 'javascript:alert(1)' };
    const ris = serialize('ris', [doc], meta, 'podcast', '/s/site/item');
    expect(ris).not.toContain('javascript:');
    expect(ris).toContain(`UR  - ${window.location.origin}/s/site/item/2`);
  });
});
