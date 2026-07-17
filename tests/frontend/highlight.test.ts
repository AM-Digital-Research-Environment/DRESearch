import { describe, expect, it } from 'vitest';
import { parseHighlight, stripMarkers } from '../../src/svelte/lib/highlight';

describe('highlight sentinel parser', () => {
  it('keeps HTML-like content as text segments', () => {
    const input = '<img src=x onerror=alert(1)> \uE000match\uE001';
    expect(parseHighlight(input)).toEqual([
      { text: '<img src=x onerror=alert(1)> ', mark: false },
      { text: 'match', mark: true },
    ]);
    expect(stripMarkers(input)).toBe('<img src=x onerror=alert(1)> match');
  });
});
