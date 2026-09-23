import './inertia';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Auth from '../../resources/js/Pages/Auth';
import Dashboard from '../../resources/js/Pages/Dashboard';
import { connection, appointment } from './fixtures';
import { shared, startPoll, visits } from './inertia';

beforeEach(() => {
    shared.flash = { success: null, error: null };
    shared.errors = {};
});

const props = {
    date: '2026-10-12',
    today: '2026-10-12',
    month: '2026-10',
    appointmentDates: ['2026-10-12'],
    timezone: 'UTC',
    timezones: ['UTC', 'Africa/Cairo'],
    googleConfigured: true,
    connection,
    appointments: [appointment],
    syncIssues: [],
    upcomingEvents: [],
    calendarSync: {},
};

describe('authentication forms', () => {
    it('signs in and clears the password after the request finishes', async () => {
        render(<Auth register={false} />);
        await userEvent.type(screen.getByLabelText('Email address'), 'alex@example.com');
        await userEvent.type(screen.getByLabelText('Password', { exact: true }), 'test-password');
        await userEvent.click(screen.getByLabelText('Keep me signed in'));
        await userEvent.click(screen.getByRole('button', { name: 'Sign in', exact: true }));
        expect(visits.post).toHaveBeenCalledWith(
            '/login',
            expect.objectContaining({ email: 'alex@example.com', remember: true }),
            expect.any(Object),
        );
        const callbacks = visits.post.mock.calls[0][2];
        act(() => callbacks.onStart({}));
        expect(screen.getByRole('button', { name: 'Sign in', exact: true })).toBeDisabled();
        act(() => callbacks.onFinish({}));
        expect(screen.getByLabelText('Password', { exact: true })).toHaveValue('');
    });
    it('registers with confirmation and displays validation errors', async () => {
        render(<Auth register />);
        await userEvent.type(screen.getByLabelText('Your name'), 'Alex');
        await userEvent.type(screen.getByLabelText('Email address'), 'alex@example.com');
        await userEvent.type(screen.getByLabelText('Password', { exact: true }), 'test-password');
        await userEvent.type(screen.getByLabelText('Confirm password'), 'different-password');
        await userEvent.click(screen.getByRole('button', { name: 'Create account' }));
        expect(visits.post).toHaveBeenCalledWith(
            '/register',
            expect.objectContaining({ name: 'Alex', password_confirmation: 'different-password' }),
            expect.any(Object),
        );
        act(() =>
            visits.post.mock.calls[0][2].onError({
                name: 'Name error',
                email: 'Email error',
                password: 'Passwords must match',
            }),
        );
        expect(screen.getByText('Passwords must match')).toBeVisible();
        expect(screen.getByLabelText('Your name')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByLabelText('Email address')).toHaveAttribute('aria-describedby', 'email-error');
    });
});

describe('appointment workspace', () => {
    it('edits an appointment in its original timezone and reports a stale form', async () => {
        render(<Dashboard {...props} timezone="Africa/Cairo" />);
        await userEvent.click(
            within(screen.getByRole('article', { name: 'Discovery session' })).getByRole('button', {
                name: 'Edit',
                exact: true,
            }),
        );
        expect(screen.getByRole('dialog', { name: 'Edit appointment' })).toBeVisible();
        expect(screen.getByLabelText('Start time')).toHaveValue('07:00');
        expect(screen.getByLabelText('Duration (min)')).toHaveValue(60);
        expect(screen.getByLabelText('Appointment timezone')).toHaveValue('UTC');
        expect(screen.getByLabelText('Google calendar')).toBeDisabled();
        await userEvent.clear(screen.getByLabelText('Appointment title'));
        await userEvent.type(screen.getByLabelText('Appointment title'), 'Revised session');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(visits.post).toHaveBeenLastCalledWith(
            '/appointments/one/update',
            expect.objectContaining({
                revision: appointment.revision,
                title: 'Revised session',
                duration: '60',
                timezone: 'UTC',
            }),
            expect.any(Object),
        );
        act(() => visits.post.mock.calls.at(-1)![2].onError({ revision: 'Changed elsewhere' }));
        expect(screen.getByText('Changed elsewhere')).toBeVisible();
        await userEvent.click(screen.getByRole('button', { name: 'Go back' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('opens upcoming event dates and enables their calendar when hidden', async () => {
        const events = [
            {
                id: 'local',
                title: 'Local booking',
                date: '2026-10-13',
                starts_at: '2026-10-13T10:00:00Z',
                all_day: false,
                calendar_id: null,
            },
            {
                id: 'visible',
                title: 'Work review',
                date: '2026-10-14',
                starts_at: '2026-10-14T10:00:00Z',
                all_day: false,
                calendar_id: 'work',
            },
            {
                id: 'hidden',
                title: 'Personal review',
                date: '2026-10-15',
                starts_at: '2026-10-15T10:00:00Z',
                all_day: false,
                calendar_id: 'personal',
            },
        ];
        render(<Dashboard {...props} upcomingEvents={events} />);
        expect(screen.getByRole('heading', { name: 'Google Account' })).toBeVisible();
        for (const event of events) {
            await userEvent.click(screen.getByRole('button', { name: new RegExp(event.title) }));
            expect(visits.get).toHaveBeenLastCalledWith(
                '/appointments',
                { date: event.date, timezone: 'UTC' },
                expect.not.objectContaining({ headers: expect.anything() }),
            );
        }
    });
    it('loads another month of markers without changing the selected date or closing the sidebar', async () => {
        render(<Dashboard {...props} />);
        await userEvent.click(screen.getByRole('button', { name: 'Calendars & dates' }));
        await userEvent.click(screen.getByRole('button', { name: 'Next month' }));
        expect(visits.get).toHaveBeenLastCalledWith(
            '/appointments',
            { date: '2026-10-12', timezone: 'UTC', month: '2026-11' },
            { only: ['month', 'appointmentDates'], preserveState: true, preserveScroll: true },
        );
        expect(screen.getByRole('article', { name: 'Discovery session' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Calendars & dates' })).toHaveAttribute(
            'aria-expanded',
            'true',
        );
    });
    it('navigates dates and display timezones without changing appointment times', async () => {
        render(<Dashboard {...props} />);
        await userEvent.click(screen.getByRole('button', { name: 'Next week' }));
        expect(visits.get).toHaveBeenLastCalledWith(
            '/appointments',
            { date: '2026-10-19', timezone: 'UTC' },
            expect.not.objectContaining({ headers: expect.anything() }),
        );
        act(() => visits.get.mock.calls.at(-1)![2].onSuccess());
        await userEvent.click(screen.getByRole('button', { name: 'Previous week' }));
        expect(visits.get).toHaveBeenLastCalledWith(
            '/appointments',
            { date: '2026-10-05', timezone: 'UTC' },
            expect.not.objectContaining({ headers: expect.anything() }),
        );
        await userEvent.click(screen.getByRole('button', { name: 'Wednesday, October 14', exact: true }));
        expect(visits.get).toHaveBeenLastCalledWith(
            '/appointments',
            { date: '2026-10-14', timezone: 'UTC' },
            expect.not.objectContaining({ headers: expect.anything() }),
        );
        await userEvent.click(screen.getByLabelText('Display timezone'));
        await userEvent.type(screen.getByLabelText('Display timezone'), 'cairo');
        await userEvent.click(screen.getByRole('option', { name: 'Africa/Cairo' }));
        expect(visits.get).toHaveBeenLastCalledWith(
            '/appointments',
            { date: '2026-10-12', timezone: 'Africa/Cairo' },
            expect.not.objectContaining({ headers: expect.anything() }),
        );
        expect(screen.getByText('7:00 AM')).toBeVisible();
    });
    it('opens and closes the booking form from the main action', async () => {
        render(<Dashboard {...props} />);
        await userEvent.click(screen.getByRole('button', { name: 'New appointment', exact: true }));
        expect(screen.getByRole('dialog')).toBeVisible();
        await userEvent.click(screen.getByRole('button', { name: 'Go back' }));
        expect(screen.queryByRole('dialog')).toBeNull();
    });
    it('allows the first booking before a default calendar has been saved', async () => {
        render(<Dashboard {...props} connection={{ ...connection, selected_calendar_id: null }} />);
        await userEvent.click(screen.getByRole('button', { name: 'New appointment', exact: true }));
        expect(screen.getByLabelText('Google calendar')).toHaveValue('work');
    });
    it('still allows local booking when every Google calendar is read-only', () => {
        render(<Dashboard {...props} connection={{ ...connection, calendars: [connection.calendars[2]] }} />);
        expect(screen.getByRole('button', { name: 'New appointment', exact: true })).toBeEnabled();
    });
    it('opens the sidebar and allows bookings without a connection', async () => {
        render(<Dashboard {...props} connection={null} appointments={[]} />);
        expect(screen.getByRole('button', { name: 'New appointment', exact: true })).toBeEnabled();
        await userEvent.click(screen.getByRole('button', { name: 'Calendars & dates' }));
        expect(screen.getByRole('button', { name: 'Calendars & dates' })).toHaveAttribute(
            'aria-expanded',
            'true',
        );
        await userEvent.click(screen.getByRole('button', { name: 'Calendars & dates' }));
        expect(screen.getByRole('button', { name: 'Calendars & dates' })).toHaveAttribute(
            'aria-expanded',
            'false',
        );
    });
    it('lets an empty day start a booking', async () => {
        render(<Dashboard {...props} appointments={[]} />);
        expect(screen.getByRole('heading', { name: "It's a bit quiet in here" })).toBeVisible();
        expect(screen.queryByRole('region', { name: 'Cancelled appointments' })).toBeNull();
        await userEvent.click(screen.getByRole('button', { name: 'New appointment', exact: true }));
        expect(screen.getByRole('dialog')).toBeVisible();
    });
    it('keeps cancellation progress available when the collapsed section is expanded', async () => {
        render(
            <Dashboard
                {...props}
                appointments={[{ ...appointment, status: 'cancelled', sync_status: 'pending' }]}
                syncIssues={[
                    {
                        id: 'one',
                        all_day: false,
                        title: 'Discovery session',
                        date: props.date,
                        starts_at: appointment.starts_at,
                        status: 'cancelled',
                        sync_status: 'pending',
                        sync_error: null,
                    },
                ]}
            />,
        );
        expect(startPoll).toHaveBeenCalled();
        const cancelled = screen.getByRole('region', { name: 'Cancelled appointments' });
        expect(within(cancelled).getByText('1')).toBeVisible();
        expect(within(cancelled).getByText('Discovery session')).not.toBeVisible();
        await userEvent.click(within(cancelled).getByText('Cancelled appointments'));
        expect(within(cancelled).getByRole('article', { name: 'Discovery session' })).toBeVisible();
        expect(within(cancelled).getByText('Time reserved until Google confirms removal.')).toBeVisible();
        expect(within(cancelled).getByText('Removing from Google')).toBeVisible();
        expect(screen.queryByRole('heading', { name: "It's a bit quiet in here" })).toBeNull();
        expect(screen.getByRole('region', { name: 'Google sync' })).toHaveTextContent('Syncing');
    });
    it('groups cancelled appointments behind a toggle with an always-visible count', async () => {
        render(
            <Dashboard
                {...props}
                appointments={[
                    {
                        ...appointment,
                        id: 'cancelled-one',
                        title: 'Earlier cancellation',
                        status: 'cancelled',
                    },
                    appointment,
                    { ...appointment, id: 'cancelled-two', title: 'Later cancellation', status: 'cancelled' },
                ]}
            />,
        );

        expect(screen.getByText('Earlier cancellation')).not.toBeVisible();
        expect(
            within(screen.getByRole('region', { name: 'Cancelled appointments' })).getByText('2'),
        ).toBeVisible();
        await userEvent.click(screen.getByText('Cancelled appointments'));
        expect(screen.getAllByRole('article').map((item) => item.getAttribute('aria-label'))).toEqual([
            'Discovery session',
            'Earlier cancellation',
            'Later cancellation',
        ]);
        const cancelled = screen.getByRole('region', { name: 'Cancelled appointments' });
        expect(within(cancelled).getAllByRole('article')).toHaveLength(2);
        expect(within(cancelled).getByText('2')).toBeVisible();
        expect(within(cancelled).queryByRole('article', { name: 'Discovery session' })).toBeNull();
        expect(screen.getByText('1 appointment')).toBeVisible();
        await userEvent.click(screen.getByText('Cancelled appointments'));
        expect(screen.getByText('Earlier cancellation')).not.toBeVisible();
        expect(within(cancelled).getByText('2')).toBeVisible();
    });
    it('preserves the toggle through background updates and resets it for another day', async () => {
        const local = { ...appointment, sync_status: 'local' as const };
        const { rerender } = render(<Dashboard {...props} appointments={[local]} />);
        expect(screen.queryByRole('region', { name: 'Cancelled appointments' })).toBeNull();

        rerender(
            <Dashboard {...props} appointments={[{ ...local, status: 'cancelled', holds_slot: false }]} />,
        );

        const cancelled = screen.getByRole('region', { name: 'Cancelled appointments' });
        expect(within(cancelled).getByText('Discovery session')).not.toBeVisible();
        await userEvent.click(within(cancelled).getByText('Cancelled appointments'));
        expect(within(cancelled).getByRole('article', { name: 'Discovery session' })).toBeVisible();
        expect(screen.getAllByRole('article')).toHaveLength(1);
        expect(screen.getByText('0 appointments')).toBeVisible();

        rerender(
            <Dashboard
                {...props}
                appointments={[
                    { ...local, status: 'cancelled', holds_slot: false },
                    {
                        ...local,
                        id: 'two',
                        title: 'Second cancellation',
                        status: 'cancelled',
                        holds_slot: false,
                    },
                ]}
            />,
        );
        expect(screen.getByText('Second cancellation')).toBeVisible();
        expect(within(cancelled).getByText('2')).toBeVisible();
        rerender(
            <Dashboard
                {...props}
                date="2026-10-13"
                appointments={[{ ...local, status: 'cancelled', holds_slot: false }]}
            />,
        );
        expect(screen.getByText('Discovery session')).not.toBeVisible();
        expect(within(cancelled).getByText('1')).toBeVisible();
        rerender(<Dashboard {...props} date="2026-10-13" appointments={[]} />);

        expect(screen.queryByRole('region', { name: 'Cancelled appointments' })).toBeNull();
        expect(screen.queryByRole('article')).toBeNull();
        expect(screen.getByRole('heading', { name: "It's a bit quiet in here" })).toBeVisible();
    });
    it('always shows appointments directly, without a mirror view', () => {
        render(<Dashboard {...props} />);
        expect(screen.queryByRole('button', { name: 'Calendar mirror' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Agenda', exact: true })).toBeNull();
        expect(screen.getByRole('article', { name: 'Discovery session' })).toBeVisible();
    });
    it('opens a local booking form when no Google account is connected', async () => {
        render(<Dashboard {...props} connection={null} />);
        await userEvent.click(screen.getByRole('button', { name: 'New appointment', exact: true }));
        expect(screen.queryByLabelText('Google calendar')).toBeNull();
        expect(screen.getByLabelText('Appointment title')).toHaveFocus();
    });
    it('still permits saving appointments when Google needs reconnection', () => {
        render(
            <Dashboard {...props} connection={{ ...connection, needs_reconnect: true }} date="2026-10-13" />,
        );
        expect(screen.queryByText('Demo workspace')).toBeNull();
        expect(screen.getByRole('button', { name: 'New appointment', exact: true })).toBeEnabled();
        expect(startPoll).not.toHaveBeenCalled();
    });
    it('announces success and provider failures', () => {
        shared.flash = { success: 'Booked successfully', error: null };
        const { rerender } = render(<Dashboard {...props} />);
        expect(screen.getByRole('status')).toHaveTextContent('Booked successfully');
        shared.flash = { success: null, error: 'Reconnect Google' };
        rerender(<Dashboard {...props} />);
        expect(screen.getByRole('alert')).toHaveTextContent('Reconnect Google');
    });
});
