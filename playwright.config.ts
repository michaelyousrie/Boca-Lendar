import { defineConfig, devices } from '@playwright/test';
import { browserEnvironment } from './tests/browser/environment';

export default defineConfig({
    testDir: './tests/browser',
    fullyParallel: false,
    workers: 1,
    timeout: 30_000,
    retries: 0,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: 'http://127.0.0.1:8011',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        reducedMotion: 'reduce',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } } },
        { name: 'mobile', use: { ...devices['iPhone 13'], defaultBrowserType: 'chromium' } },
    ],
    webServer: {
        command:
            'php artisan migrate:fresh --force && php -S 127.0.0.1:8011 -t public tests/Fixtures/browser.php',
        url: 'http://127.0.0.1:8011/login',
        env: browserEnvironment,
        reuseExistingServer: false,
        timeout: 60_000,
    },
});
