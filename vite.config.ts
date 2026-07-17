import { defineConfig } from 'vitest/config';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { resolve } from 'node:path';

/**
 * Single IIFE bundle, emitted into asset/dist/:
 *
 *   dre-search.{js,css} — public faceted-search client. Auto-mounts on any
 *                         page containing a [data-dre-search-root] element
 *                         (emitted by the dreSearch page block), reading its
 *                         per-block bootstrap config from the sibling
 *                         <script type="application/json"> tag.
 *
 * IIFE (not ESM) because Omeka pages are server-rendered HTML with no module
 * loader: the compiled file just runs on DOMContentLoaded and mounts itself.
 * asset/dist/ is committed to the repo so production deployments need only
 * `composer install` + module activation — no Node toolchain on the server.
 *
 * (Kept deliberately simple — a single bundle. If an admin app or header
 * typeahead is added later, switch to the IWAC_BUNDLE matrix pattern.)
 */
export default defineConfig({
  plugins: [svelte()],
  resolve: {
    conditions: ['browser'],
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./tests/frontend/setup.ts'],
    include: ['tests/frontend/**/*.test.ts'],
    clearMocks: true,
  },
  build: {
    outDir: 'asset/dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    sourcemap: false,
    target: 'es2022',
    lib: {
      entry: resolve(__dirname, 'src/svelte/main.ts'),
      formats: ['iife'],
      name: 'DreSearch',
      fileName: () => 'dre-search.js',
    },
    rollupOptions: {
      output: {
        // Stable CSS filename — the block layout + Module.php reference it by
        // literal path (asset/dist/dre-search.css).
        assetFileNames: (asset) =>
          asset.name?.endsWith('.css') ? 'dre-search.css' : 'assets/[name][extname]',
      },
    },
  },
});
