import { defineConfig } from 'playwright/test';
import { tmpdir } from 'node:os';
import path from 'node:path';

export default defineConfig({
    testDir: './tests/browser',
    testMatch: '**/*.spec.mjs',
    outputDir: path.join(tmpdir(), 'hermes-bean-playwright-voice'),
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 20_000,
    expect: { timeout: 3_000 },
    use: {
        baseURL: 'http://127.0.0.1:4179',
        browserName: 'chromium',
        headless: true,
        trace: 'retain-on-failure',
    },
    reporter: [['line']],
    webServer: {
        command: 'node tests/browser/voice-v2-static-server.mjs',
        url: 'http://127.0.0.1:4179/tests/browser/fixtures/voice-v2-harness.html',
        reuseExistingServer: false,
        timeout: 10_000,
    },
});
