// @vitest-environment node
import { mkdtempSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { build } from 'vite';
import { describe, expect, it } from 'vitest';

const root = resolve(import.meta.dirname, '../..');

describe('compiled module URL identity', () => {
  it('never imports shared runtime state from the versioned entry', async () => {
    // asset/dist is a build product (the release archive carries it), so the
    // contract is checked on a fresh build rather than on committed files.
    const outDir = mkdtempSync(join(tmpdir(), 'dre-search-bundle-'));
    try {
      await build({
        root,
        configFile: resolve(root, 'vite.config.ts'),
        logLevel: 'silent',
        build: { outDir, emptyOutDir: true },
      });
      const directory = join(outDir, 'chunks');
      const chunks = readdirSync(directory).filter((name) => name.endsWith('.js'));
      expect(chunks.length).toBeGreaterThan(0);
      for (const name of chunks) {
        const code = readFileSync(join(directory, name), 'utf8');
        // Omeka loads dre-search.js?v=VERSION. Importing ../dre-search.js creates
        // another module instance, splitting Svelte mount/effect state.
        expect(code, name).not.toMatch(/from\s*["'][^"']*dre-search\.js(?:\?[^"']*)?["']/);
      }
    } finally {
      rmSync(outDir, { recursive: true, force: true });
    }
  }, 120_000);
});
