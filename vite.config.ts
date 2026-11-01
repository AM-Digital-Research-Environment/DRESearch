import { defineConfig } from 'vitest/config';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { resolve } from 'node:path';

/**
 * The test suite runs WEST OF GREENWICH, on purpose (as IwacSearch's does).
 * Dates are indexed as midnight UTC, and a formatter that forgets
 * `timeZone: 'UTC'` prints the day before for every reader in the Americas —
 * a bug the suite could not see while it ran in UTC (CI) or Central Europe
 * (the maintainer's machine), where midnight UTC is still the same day. Set
 * here, before any worker starts, because a worker takes its zone from the
 * environment it inherits; tests/frontend/timezone.test.ts fails if a
 * platform ignores it. The build does not read the zone.
 */
process.env.TZ = 'America/New_York';

/**
 * ESM entry with page-specific chunks. asset/dist is not tracked: the release
 * archive carries the complete tree (entry + every hashed chunk + manifest).
 */
export default defineConfig({
  base: './',
  plugins: [svelte()],
  resolve: {
    conditions: ['browser'],
  },
  test: {
    environment: 'jsdom',
    env: { TZ: 'America/New_York' },
    setupFiles: ['./tests/frontend/setup.ts'],
    include: ['tests/frontend/**/*.test.ts'],
    clearMocks: true,
  },
  build: {
    outDir: 'asset/dist',
    emptyOutDir: true,
    cssCodeSplit: true,
    sourcemap: false,
    target: 'es2022',
    // Read by src/View/BundleAssets.php to preload the chunks a page will import.
    // Not under .vite/: a dot-directory is easy to drop when copying the build.
    manifest: 'manifest.json',
    rollupOptions: {
      // Omeka versions the entry URL; chunks must never import runtime exports from it.
      preserveEntrySignatures: 'strict',
      input: { 'dre-search': resolve(import.meta.dirname, 'src/svelte/main.ts') },
      output: {
        entryFileNames: 'dre-search.js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (asset) =>
          asset.names.includes('dre-search.css')
            ? 'dre-search.css'
            : 'chunks/[name]-[hash][extname]',
      },
    },
  },
});
