import '@testing-library/jest-dom/vitest';
import { afterEach, beforeEach, vi } from 'vitest';
import { cleanup } from '@testing-library/react';

Object.defineProperty(HTMLDialogElement.prototype, 'showModal', {
    value() {
        this.setAttribute('open', '');
    },
});
Object.defineProperty(HTMLDialogElement.prototype, 'close', {
    value() {
        this.removeAttribute('open');
    },
});
HTMLElement.prototype.scrollIntoView = vi.fn();
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});
beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-09-23T12:00:00Z'));
});
