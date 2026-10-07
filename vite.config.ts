import { defineConfig } from 'vitest/config';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { resolve } from 'node:path';

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
