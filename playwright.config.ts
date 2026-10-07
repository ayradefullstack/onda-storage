/// <reference types="node" />

import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end tests against the LOCAL Herd site (not part of `composer
 * ci:check`: they need a seeded database, a running queue worker and a built
 * `public/build`). See the "End-to-end tests" section of README.md.
 */
export default defineConfig({
    testDir: './tests/e2e',
    // One browser, one test at a time: the scenario saturates a single
    // local upload connection on purpose.
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 10 * 60_000,
    reporter: [['list']],
    outputDir: 'node_modules/.playwright-results',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://onda-storage.test',
        ...devices['Desktop Chrome'],
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
