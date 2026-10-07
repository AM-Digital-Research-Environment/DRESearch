// @vitest-environment node
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { build } from 'vite';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

const root = resolve(import.meta.dirname, '../..');

interface ManifestChunk {
  file: string;
  imports?: string[];
  css?: string[];
}

// asset/dist is a build product (the release archive carries it), so the
// contracts are checked on a fresh build rather than on committed files.
let outDir = '';
beforeAll(async () => {
  outDir = mkdtempSync(join(tmpdir(), 'dre-search-bundle-'));
  await build({
    root,
    configFile: resolve(root, 'vite.config.ts'),
    logLevel: 'silent',
    build: { outDir, emptyOutDir: true },
  });
}, 120_000);
afterAll(() => {
  rmSync(outDir, { recursive: true, force: true });
});

describe('compiled module URL identity', () => {
  it('never imports shared runtime state from the versioned entry', () => {
    const directory = join(outDir, 'chunks');
    const chunks = readdirSync(directory).filter((name) => name.endsWith('.js'));
    expect(chunks.length).toBeGreaterThan(0);
    for (const name of chunks) {
      const code = readFileSync(join(directory, name), 'utf8');
      // Omeka loads dre-search.js?v=VERSION. Importing ../dre-search.js creates
      // another module instance, splitting Svelte mount/effect state.
      expect(code, name).not.toMatch(/from\s*["'][^"']*dre-search\.js(?:\?[^"']*)?["']/);
    }
  });
});

describe('build manifest read by the PHP preloader', () => {
  it('names every surface BundleAssets preloads, with files that exist', () => {
    const manifest = JSON.parse(readFileSync(join(outDir, 'manifest.json'), 'utf8')) as Record<
      string,
      ManifestChunk
    >;
    const php = readFileSync(join(root, 'src/View/BundleAssets.php'), 'utf8');
    const keys = [...php.matchAll(/public const \w+ = '([^']+)';/g)].map((m) => m[1] ?? '');
    expect(keys).toHaveLength(3);
    for (const key of keys) {
      expect(manifest, key).toHaveProperty([key]);
    }
    expect(manifest['src/svelte/main.ts']?.file).toBe('dre-search.js');
    for (const chunk of Object.values(manifest)) {
      for (const file of [chunk.file, ...(chunk.css ?? [])]) {
        expect(existsSync(join(outDir, file)), file).toBe(true);
      }
    }
  });
});
