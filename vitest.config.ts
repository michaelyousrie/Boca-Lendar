import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'jsdom',
        setupFiles: ['tests/frontend/setup.ts'],
        include: ['tests/frontend/**/*.test.{ts,tsx}'],
        coverage: {
            provider: 'v8',
            include: ['resources/js/**/*.{ts,tsx}'],
            exclude: ['resources/js/app.tsx', 'resources/js/types.ts'],
            reporter: ['text', 'html', 'json-summary'],
            reportsDirectory: 'coverage/frontend',
            thresholds: { statements: 100, branches: 100, functions: 100, lines: 100 },
        },
    },
});
