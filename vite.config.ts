import { defineConfig } from 'vitest/config';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { resolve } from 'node:path';

/** ESM entry with page-specific chunks; commit the complete asset/dist tree. */
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
