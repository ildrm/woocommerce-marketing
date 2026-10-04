const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
    testDir: '.', testMatch: '*.spec.js', timeout: 180000, expect: { timeout: 15000 }, fullyParallel: false, workers: 1,
    reporter: [['list'], ['json', { outputFile: '../../.runtime/e2e-results.json' }]],
    use: { baseURL: 'http://127.0.0.1:8089', viewport: { width: 1440, height: 1000 },
        launchOptions: { executablePath: process.env.WMOS_BROWSER_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' },
        trace: 'retain-on-failure', screenshot: 'only-on-failure' },
    outputDir: '../../.runtime/e2e-artifacts'
});
