import { vi } from 'vitest';
import type { SharedProps } from '../../resources/js/types';

export const shared: SharedProps = {
    auth: { user: { id: 1, name: 'Jordan', email: 'jordan@example.com' } },
    flash: { success: null, error: null },
    errors: {},
};
export const startPoll = vi.fn();
export const stopPoll = vi.fn();
export const visits = { get: vi.fn(), post: vi.fn() };

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();
    vi.spyOn(actual.router, 'get').mockImplementation(visits.get);
    vi.spyOn(actual.router, 'post').mockImplementation(visits.post);
    vi.spyOn(actual.router, 'poll').mockImplementation((...args) => {
        startPoll(...args);
        return { start: vi.fn(), stop: vi.fn(), destroy: stopPoll };
    });
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: shared }),
        Link: ({
            children,
            href,
            as: Tag = 'a',
            method: _method,
            preserveScroll: _scroll,
            ...props
        }: any) => (
            <Tag {...props} href={Tag === 'a' ? href : undefined}>
                {children}
            </Tag>
        ),
    };
});
