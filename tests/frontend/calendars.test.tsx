import './inertia';
import { act, render, renderHook, screen, within, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import useCalendars from '../../resources/js/lib/useCalendars';
import Dashboard from '../../resources/js/Pages/Dashboard';
import BookingForm from '../../resources/js/Components/BookingForm';
import { connection, appointment } from './fixtures';
import { shared, startPoll, stopPoll, visits } from './inertia';

const imported = {
    ...appointment,
    id: 'imported',
    title: 'Team review',
    customer_name: null,
    customer_email: null,
    url: 'https://calendar.google.com/calendar/event?eid=meeting',
};
const props = {
    date: '2026-10-12',
    today: '2026-10-12',
    month: '2026-10',
    appointmentDates: [],
    timezone: 'UTC',
    timezones: ['UTC', 'Africa/Cairo'],
    googleConfigured: true,
    connection,
    appointments: [appointment, imported],
    syncIssues: [],
    upcomingEvents: [],
    calendarSync: {},
};
beforeEach(() => {
    shared.flash = { success: null, error: null };
    shared.errors = {};
});

describe('one appointment workspace', () => {
    it('shows both sources as appointments, with the same actions and no Google event category', () => {
        render(<Dashboard {...props} />);
        expect(screen.getByText('2 appointments')).toBeVisible();
        for (const title of ['Discovery session', 'Team review']) {
            const card = screen.getByRole('article', { name: title });
            expect(within(card).getByText('Synced to Google')).toBeVisible();
            expect(within(card).getByRole('button', { name: 'Edit', exact: true })).toBeVisible();
            expect(within(card).getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
        }
        expect(screen.queryByText('Google Calendar')).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open in Google' })).toHaveAttribute(
            'rel',
            'noopener noreferrer',
        );
        expect(visits.get).not.toHaveBeenCalled();
        expect(visits.post).not.toHaveBeenCalled();
    });

    it('hides every appointment in an unchecked calendar and refreshes visible calendars only', async () => {
        render(<Dashboard {...props} />);
        await userEvent.click(screen.getByRole('checkbox', { name: 'Work', exact: true }));
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(screen.getByText('0 appointments')).toBeVisible();
        await userEvent.click(screen.getByRole('button', { name: 'Refresh appointments' }));
        expect(visits.post).toHaveBeenLastCalledWith(
            '/calendar/events/refresh',
            { calendar_ids: ['personal', 'holiday'] },
            { preserveScroll: true },
        );
        await userEvent.click(screen.getByRole('checkbox', { name: 'Work', exact: true }));
        expect(screen.getByRole('article', { name: 'Team review' })).toBeVisible();
        expect(connection.selected_calendar_id).toBe('work');
    });

    it('keeps appointments displayed while syncing and shows failures without losing saved edits', () => {
        const { rerender } = render(
            <Dashboard {...props} calendarSync={{ work: { refreshing: true, error: null } }} />,
        );
        expect(screen.getByRole('button', { name: 'Syncing appointments...' })).toBeDisabled();
        expect(screen.getByRole('article', { name: 'Team review' })).toBeVisible();
        expect(startPoll).toHaveBeenLastCalledWith(2000, expect.any(Object));
        rerender(
            <Dashboard {...props} calendarSync={{ work: { refreshing: false, error: 'Unavailable' } }} />,
        );
        expect(screen.getByRole('alert')).toHaveTextContent('WorkUnavailable');
        expect(stopPoll).toHaveBeenCalled();
        expect(startPoll).toHaveBeenLastCalledWith(15000, expect.any(Object));
        rerender(
            <Dashboard
                {...props}
                connection={{ ...connection, needs_reconnect: true }}
                calendarSync={{ work: { refreshing: false, error: 'Reconnect' } }}
            />,
        );
        expect(
            within(screen.getByRole('alert')).getByRole('link', { name: 'Reconnect Google' }),
        ).toBeVisible();
        expect(screen.queryByRole('button', { name: 'Refresh appointments' })).not.toBeInTheDocument();
    });

    it('opens an upcoming appointment and reveals its hidden calendar', async () => {
        render(
            <Dashboard
                {...props}
                upcomingEvents={[
                    {
                        id: 'later',
                        title: 'Later meeting',
                        date: '2026-11-01',
                        starts_at: '2026-11-01T10:00:00Z',
                        all_day: false,
                        calendar_id: 'work',
                    },
                ]}
            />,
        );
        await userEvent.click(screen.getByRole('checkbox', { name: 'Work', exact: true }));
        await userEvent.click(screen.getByRole('button', { name: /Later meeting/ }));
        expect(screen.getByRole('checkbox', { name: 'Work', exact: true })).toBeChecked();
        expect(visits.get).toHaveBeenLastCalledWith(
            '/appointments',
            expect.objectContaining({ date: '2026-11-01' }),
            expect.any(Object),
        );
    });

    it('edits a local appointment without requiring a calendar connection', async () => {
        render(
            <BookingForm
                date="2026-10-12"
                timezone="UTC"
                timezones={['UTC']}
                calendars={[]}
                selectedCalendarId={null}
                appointment={{ ...appointment, calendar_id: null, sync_status: 'local' }}
                onClose={vi.fn()}
            />,
        );
        expect(screen.queryByLabelText('Google calendar')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Appointment title')).toHaveValue('Discovery session');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(visits.post).toHaveBeenLastCalledWith(
            '/appointments/one/update',
            expect.objectContaining({ calendar_id: '' }),
            expect.any(Object),
        );
    });

    it('shows the existing local destination when editing with Google connected', () => {
        render(
            <BookingForm
                date="2026-10-12"
                timezone="UTC"
                timezones={['UTC']}
                calendars={connection.calendars}
                selectedCalendarId="work"
                appointment={{
                    ...appointment,
                    calendar_id: null,
                    calendar_name: 'Local calendar',
                    sync_status: 'local',
                }}
                onClose={vi.fn()}
            />,
        );
        expect(screen.getByLabelText('Google calendar')).toHaveValue('');
        expect(screen.getByLabelText('Google calendar')).toBeDisabled();
        expect(screen.getByRole('option', { name: 'Local calendar' })).toBeVisible();
    });

    it('handles an unconnected account and missing sync state without fetching anything', () => {
        const { result } = renderHook(() => useCalendars(null, {}));
        expect(result.current.visibleIds).toEqual([]);
        expect(result.current.loading).toBe(false);
        expect(result.current.errors).toEqual([]);
        expect(result.current.isVisible(null)).toBe(true);
        expect(visits.get).not.toHaveBeenCalled();
    });

    it('uses the same editor and required customer fields for an imported appointment', async () => {
        render(<Dashboard {...props} />);
        await userEvent.click(
            within(screen.getByRole('article', { name: 'Team review' })).getByRole('button', {
                name: 'Edit',
                exact: true,
            }),
        );
        expect(screen.getByRole('dialog', { name: 'Edit appointment' })).toBeVisible();
        expect(screen.getByLabelText('Appointment title')).toHaveValue('Team review');
        expect(screen.getByLabelText('Customer name')).toHaveValue('');
        expect(screen.getByLabelText('Customer email')).toHaveAttribute('required');
        expect(screen.getByLabelText('Google calendar')).toBeDisabled();
        await userEvent.type(screen.getByLabelText('Customer name'), 'Sam');
        await userEvent.type(screen.getByLabelText('Customer email'), 'sam@example.com');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(visits.post).toHaveBeenLastCalledWith(
            '/appointments/imported/update',
            expect.objectContaining({
                customer_name: 'Sam',
                customer_email: 'sam@example.com',
                duration: '60',
                all_day: false,
            }),
            expect.any(Object),
        );
        await act(async () => {
            await visits.post.mock.calls.at(-1)![2].onSuccess({});
        });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('shows read-only appointments and missing customer details without offering writes', () => {
        render(
            <Dashboard
                {...props}
                appointments={[
                    {
                        ...imported,
                        writable: false,
                        calendar_id: 'holiday',
                        customer_email: 'sam@example.com',
                    },
                ]}
            />,
        );
        const card = screen.getByRole('article', { name: 'Team review' });
        expect(within(card).queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
        expect(within(card).queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
        expect(within(card).getByText('sam@example.com')).toBeVisible();
    });

    it('renders all-day appointments first and preserves their duration in the shared editor', async () => {
        const allDay = { ...imported, all_day: true, starts_at: '2026-10-12', ends_at: '2026-10-15' };
        const { rerender } = render(<Dashboard {...props} appointments={[appointment, allDay]} />);
        expect(screen.getAllByRole('article')[0]).toHaveAccessibleName('Team review');
        expect(screen.getByText('All day')).toBeVisible();
        expect(screen.getByText('Oct 12 to Oct 14')).toBeVisible();
        await userEvent.click(
            within(screen.getByRole('article', { name: 'Team review' })).getByRole('button', {
                name: 'Edit',
                exact: true,
            }),
        );
        expect(screen.getByLabelText('Duration (days)')).toHaveValue(3);
        expect(screen.queryByLabelText('Start time')).not.toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Go back' }));
        rerender(<Dashboard {...props} appointments={[{ ...allDay, ends_at: '2026-10-13' }]} />);
        expect(screen.getByText('Oct 12')).toBeVisible();
        await userEvent.click(screen.getByRole('button', { name: 'Cancel', exact: true }));
        expect(screen.getByRole('dialog')).toHaveTextContent('All day');
    });

    it('creates all-day appointments through the new form and can switch back to timed appointments', async () => {
        render(
            <BookingForm
                date="2026-10-12"
                timezone="UTC"
                timezones={['UTC']}
                calendars={[]}
                selectedCalendarId={null}
                onClose={vi.fn()}
            />,
        );
        await userEvent.click(screen.getByRole('checkbox', { name: 'All day' }));
        expect(screen.getByLabelText('Duration (days)')).toHaveValue(1);
        fireEvent.submit(screen.getByRole('button', { name: 'Reserve appointment' }).closest('form')!);
        expect(visits.post).toHaveBeenLastCalledWith(
            '/appointments',
            expect.objectContaining({ all_day: true, duration: '1' }),
            expect.any(Object),
        );
        await userEvent.click(screen.getByRole('checkbox', { name: 'All day' }));
        expect(screen.getByLabelText('Duration (min)')).toHaveValue(30);
        expect(screen.getByLabelText('Start time')).toBeVisible();
    });
});
