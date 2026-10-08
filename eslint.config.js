// Flat config (ESLint 9+/10).
// Lints the Svelte client, its tests and the Node tooling in scripts/; PHP is
// linted separately (php -l / phpcs).

import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import sveltePlugin from 'eslint-plugin-svelte';
import svelteParser from 'svelte-eslint-parser';
import globals from 'globals';

export default [
  {
    // scripts/lib is vendored verbatim from DRE-theme (checked against
    // VENDORED.sha256) and linted there; a fix here would break the hash.
    ignores: [
      'asset/dist/**',
      'node_modules/**',
      'vendor/**',
      'scripts/lib/**',
      'test-results/**',
      'playwright-report/**',
    ],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...sveltePlugin.configs['flat/recommended'],
  {
    languageOptions: {
      globals: { ...globals.browser },
      parserOptions: {
        // Svelte 5 with runes — ESM + TypeScript.
        ecmaVersion: 2022,
        sourceType: 'module',
      },
    },
    rules: {
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],
      'no-console': ['warn', { allow: ['warn', 'error'] }],
    },
  },
  {
    // Node tooling: the lint scripts and the Playwright config and tests
    // (page.evaluate callbacks keep the browser globals from above).
    files: ['scripts/**/*.mjs', 'playwright.config.ts', 'tests/browser/**/*.ts'],
    languageOptions: {
      globals: { ...globals.node },
    },
    rules: {
      // Command-line scripts report on stdout by design.
      'no-console': 'off',
    },
  },
  {
    // The Svelte plugin needs the .svelte parser explicitly per-file.
    files: ['**/*.svelte', '**/*.svelte.ts', '**/*.svelte.js'],
    languageOptions: {
      parser: svelteParser,
      parserOptions: {
        parser: tseslint.parser,
        extraFileExtensions: ['.svelte'],
        svelteFeatures: { runes: true },
      },
    },
  },
  {
    // Type-aware rules for the shipped client. The project service reads
    // tsconfig.json, which already includes the .svelte files. A promise
    // nobody awaits drops its rejection silently; deliberate fire-and-forget
    // calls are marked with `void`.
    files: ['src/svelte/**/*.ts', 'src/svelte/**/*.svelte', 'src/svelte/**/*.svelte.ts'],
    languageOptions: {
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
        extraFileExtensions: ['.svelte'],
      },
    },
    rules: {
      '@typescript-eslint/await-thenable': 'error',
      '@typescript-eslint/no-floating-promises': 'error',
      '@typescript-eslint/no-misused-promises': 'error',
    },
  },
];
