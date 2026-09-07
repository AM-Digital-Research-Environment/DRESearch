import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

describe('compiled module URL identity', () => {
  it('never imports shared runtime state from the versioned entry', () => {
    const directory = resolve(import.meta.dirname, '../../asset/dist/chunks');
    const chunks = readdirSync(directory).filter((name) => name.endsWith('.js'));
    expect(chunks.length).toBeGreaterThan(0);
    for (const name of chunks) {
      const code = readFileSync(resolve(directory, name), 'utf8');
      // Omeka loads dre-search.js?v=VERSION. Importing ../dre-search.js creates
      // another module instance, splitting Svelte mount/effect state.
      expect(code, name).not.toMatch(/from\s*["'][^"']*dre-search\.js(?:\?[^"']*)?["']/);
    }
  });
});
