import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import UpcomingEvents from '../../resources/js/Components/UpcomingEvents';

describe('upcoming events', () => {
    it('shows the empty section without extra controls', () => {
        render(<UpcomingEvents events={[]} timezone="UTC" onSelect={vi.fn()} />);
        expect(screen.getByRole('heading', { name: 'Upcoming Events' })).toBeVisible();
        expect(screen.getByText('No upcoming events.')).toBeVisible();
        expect(screen.queryByRole('button')).toBeNull();
    });
    it('shows local dates, all-day labels and navigates with the selected event', async () => {
        const onSelect = vi.fn();
        const events = [
            {
                id: 'one',
                title: 'Review',
                date: '2026-11-02',
                starts_at: '2026-11-01T23:00:00Z',
                all_day: false,
                calendar_id: null,
            },
            {
                id: 'two',
                title: 'Holiday',
                date: '2026-11-03',
                starts_at: '2026-11-03T00:00:00Z',
                all_day: true,
                calendar_id: 'work',
            },
        ];
        render(<UpcomingEvents events={events} timezone="Africa/Cairo" onSelect={onSelect} />);
        await userEvent.click(screen.getByRole('button', { name: 'Review Nov 2, 1:00 AM' }));
        expect(onSelect).toHaveBeenCalledWith(events[0]);
        expect(screen.getByRole('button', { name: 'Holiday Nov 3, All day' })).toBeVisible();
    });
});
