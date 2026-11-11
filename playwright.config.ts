import { defineConfig, devices } from '@playwright/test';

/**
 * Real-browser tests for the BUILT client (asset/dist). Every request is
 * answered by page.route() from tests/browser/fixture.ts — the page shell, the
 * bundle and stubbed API responses — under a made-up origin, so no server,
 * Omeka or Typesense is involved. `npm run test:browser` builds first.
 */
export default defineConfig({
  testDir: 'tests/browser',
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['github']] : 'list',
  use: {
    baseURL: 'http://dre.test',
    trace: 'retain-on-failure',
  },
  // WebKit too: Safari is where YearRangeFacet's iOS workaround and the
  // header bar's overlay are most likely to break, and Chromium cannot see it.
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
});
