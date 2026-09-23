import './inertia';
import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import AppointmentCard from '../../resources/js/Components/AppointmentCard';
import SyncAppointmentForm from '../../resources/js/Components/SyncAppointmentForm';
import SyncIssues from '../../resources/js/Components/SyncIssues';
import { appointment, connection } from './fixtures';
import { visits } from './inertia';

const local = { ...appointment, sync_status: 'local' as const };

describe('syncing an existing local appointment', () => {
    it('opens a destination choice and keeps the local appointment when validation fails', async () => {
        render(<AppointmentCard appointment={local} timezone="UTC" connection={connection} />);
        await userEvent.click(screen.getByRole('button', { name: 'Sync to Google' }));
        expect(screen.getByLabelText('Google calendar')).toHaveValue('work');
        expect(screen.getByRole('option', { name: 'Holidays (read only)' })).toBeDisabled();
        await userEvent.selectOptions(screen.getByLabelText('Google calendar'), 'personal');
        await userEvent.click(screen.getByRole('button', { name: 'Sync appointment' }));
        expect(visits.post).toHaveBeenCalledWith(
            '/appointments/one/sync',
            { calendar_id: 'personal' },
            expect.any(Object),
        );
        const callbacks = visits.post.mock.calls[0][2];
        act(() => callbacks.onError({ calendar_id: 'This calendar has a conflict.' }));
        expect(screen.getByText('This calendar has a conflict.')).toBeVisible();
        expect(screen.getByLabelText('Google calendar')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('Saved locally')).toBeVisible();
        act(() => callbacks.onStart({}));
        expect(screen.getByRole('button', { name: 'Saving...' })).toBeDisabled();
        act(() => {
            callbacks.onSuccess({});
            callbacks.onFinish({});
        });
        expect(screen.queryByRole('dialog')).toBeNull();
    });
    it('does not offer Google sync without writable calendars and explains local cancellation', async () => {
        const { rerender } = render(<AppointmentCard appointment={local} timezone="UTC" connection={null} />);
        expect(screen.queryByRole('button', { name: 'Sync to Google' })).toBeNull();
        rerender(
            <AppointmentCard
                appointment={local}
                timezone="UTC"
                connection={{ ...connection, calendars: [connection.calendars[2]] }}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Sync to Google' })).toBeNull();
        await userEvent.click(screen.getByRole('button', { name: 'Cancel', exact: true }));
        expect(screen.getByText('This frees the reserved time.')).toBeVisible();
    });
    it('can dismiss destination selection without changing the appointment', async () => {
        const close = vi.fn();
        render(<SyncAppointmentForm appointment={local} connection={connection} onClose={close} />);
        await userEvent.click(screen.getByRole('button', { name: 'Go back' }));
        expect(close).toHaveBeenCalledOnce();
        expect(visits.post).not.toHaveBeenCalled();
    });
});

describe('sidebar sync activity', () => {
    it('shows pending work and failure reasons across dates, with navigation and retry', async () => {
        const select = vi.fn();
        render(
            <SyncIssues
                timezone="UTC"
                onSelect={select}
                issues={[
                    {
                        id: 'waiting',
                        all_day: false,
                        title: 'Tomorrow',
                        date: '2026-10-13',
                        starts_at: '2026-10-13T12:00:00Z',
                        status: 'scheduled',
                        sync_status: 'pending',
                        sync_error: null,
                    },
                    {
                        id: 'failed',
                        all_day: true,
                        title: 'Cancelled meeting',
                        date: '2026-10-14',
                        starts_at: '2026-10-14T14:00:00Z',
                        status: 'cancelled',
                        sync_status: 'failed',
                        sync_error: 'Google permission was revoked.',
                    },
                ]}
            />,
        );
        expect(screen.getByText('Oct 14, All day')).toBeVisible();
        expect(screen.getByText('Syncing')).toBeVisible();
        expect(screen.getByText('Failed to sync')).toBeVisible();
        expect(screen.getByText('Google permission was revoked.')).toBeVisible();
        expect(screen.getByText('Removing from Google.')).toBeVisible();
        await userEvent.click(screen.getByRole('button', { name: 'Tomorrow' }));
        expect(select).toHaveBeenCalledWith('2026-10-13');
        expect(screen.getByRole('button', { name: 'Retry sync for Cancelled meeting' })).toBeVisible();
    });
});
