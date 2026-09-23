import './inertia';
import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import MonthPicker from '../../resources/js/Components/MonthPicker';
import Dialog from '../../resources/js/Components/Dialog';
import AppointmentCard from '../../resources/js/Components/AppointmentCard';
import BookingForm from '../../resources/js/Components/BookingForm';
import CalendarPanel from '../../resources/js/Components/CalendarPanel';
import NavigationProgress from '../../resources/js/Components/NavigationProgress';
import type { Appointment, Connection } from '../../resources/js/types';
import { shared, visits } from './inertia';

import { appointment, connection } from './fixtures';

describe('date picker', () => {
    const props = { date: '2026-10-12', today: '2026-10-12', month: '2026-10', appointmentDates: [] };
    it('navigates months, selects a date, and returns to today', async () => {
        const select = vi.fn();
        const changeMonth = vi.fn();
        const { rerender } = render(<MonthPicker {...props} onSelect={select} onMonthChange={changeMonth} />);
        expect(screen.queryByRole('button', { name: 'Back to today' })).toBeNull();
        await userEvent.click(screen.getByLabelText('Next month'));
        expect(changeMonth).toHaveBeenLastCalledWith('2026-11');
        rerender(<MonthPicker {...props} month="2026-11" onSelect={select} onMonthChange={changeMonth} />);
        expect(screen.getByRole('heading')).toHaveTextContent('November 2026');
        await userEvent.click(screen.getByLabelText('Previous month'));
        expect(changeMonth).toHaveBeenLastCalledWith('2026-10');
        rerender(<MonthPicker {...props} onSelect={select} onMonthChange={changeMonth} />);
        await userEvent.click(screen.getByLabelText('Tuesday, October 13, 2026'));
        expect(select).toHaveBeenLastCalledWith('2026-10-13');
        rerender(<MonthPicker {...props} date="2026-10-13" onSelect={select} onMonthChange={changeMonth} />);
        await userEvent.click(screen.getByRole('button', { name: 'Back to today' }));
        expect(select).toHaveBeenLastCalledWith('2026-10-12');
        rerender(<MonthPicker {...props} onSelect={select} onMonthChange={changeMonth} />);
        expect(screen.queryByRole('button', { name: 'Back to today' })).toBeNull();
        expect(screen.getByLabelText('Monday, October 12, 2026')).toHaveAttribute('aria-current', 'date');
    });
    it('marks booked days accessibly, including the selected date, and clears stale markers', () => {
        const callbacks = { onSelect: vi.fn(), onMonthChange: vi.fn() };
        const { rerender } = render(
            <MonthPicker {...props} {...callbacks} appointmentDates={['2026-10-12', '2026-10-13']} />,
        );
        const today = screen.getByRole('button', { name: 'Monday, October 12, 2026' });
        expect(today).toHaveAccessibleDescription('Has appointments');
        expect(today).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: 'Tuesday, October 13, 2026' })).toHaveAccessibleDescription(
            'Has appointments',
        );
        expect(
            screen.getByRole('button', { name: 'Wednesday, October 14, 2026' }),
        ).not.toHaveAccessibleDescription();
        rerender(<MonthPicker {...props} {...callbacks} appointmentDates={[]} />);
        expect(today).not.toHaveAccessibleDescription();
    });
});

describe('dialog', () => {
    it('focuses the initial field, wraps keyboard focus, and restores scrolling', () => {
        const close = vi.fn();
        const { unmount } = render(
            <Dialog title="Book" description="Details" onClose={close}>
                <input aria-label="Title" data-autofocus />
                <button>Save</button>
            </Dialog>,
        );
        expect(screen.getByLabelText('Title')).toHaveFocus();
        screen.getByRole('button', { name: 'Save' }).focus();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Tab' });
        expect(screen.getByRole('button', { name: 'Close dialog' })).toHaveFocus();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Tab', shiftKey: true });
        expect(screen.getByRole('button', { name: 'Save' })).toHaveFocus();
        screen.getByLabelText('Title').focus();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Tab' });
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'a' });
        fireEvent(screen.getByRole('dialog'), new Event('cancel', { bubbles: false, cancelable: true }));
        expect(close).toHaveBeenCalledOnce();
        expect(document.body.style.overflow).toBe('hidden');
        unmount();
        expect(document.body.style.overflow).toBe('');
    });
    it('cannot close while an operation is running', () => {
        const close = vi.fn();
        render(
            <Dialog title="Saving" onClose={close} busy>
                <span>Wait</span>
            </Dialog>,
        );
        fireEvent(screen.getByRole('dialog'), new Event('cancel', { bubbles: false, cancelable: true }));
        expect(close).not.toHaveBeenCalled();
        expect(screen.getByLabelText('Close dialog')).toBeDisabled();
    });
});

describe('appointment state', () => {
    it.each([
        [{}, 'Synced to Google'],
        [{ sync_status: 'local' }, 'Saved locally'],
        [{ sync_status: 'local', status: 'cancelled', holds_slot: false }, 'Cancelled'],
        [{ sync_status: 'pending' }, 'Syncing to Google'],
        [{ sync_status: 'pending', sync_error: 'Try later' }, 'Retrying Google sync'],
        [{ sync_status: 'failed', sync_error: 'Reconnect' }, 'Failed to sync'],
        [{ status: 'cancelled', sync_status: 'pending' }, 'Removing from Google'],
        [{ status: 'cancelled', holds_slot: false }, 'Removed from Google'],
        [{ status: 'cancelled', sync_status: 'failed', sync_error: 'Reconnect' }, 'Failed to sync'],
    ])('explains state %j', (changes, label) => {
        render(
            <AppointmentCard
                appointment={{ ...appointment, ...changes } as Appointment}
                timezone="UTC"
                connection={null}
            />,
        );
        expect(screen.getByText(label)).toBeVisible();
        if (changes.status === 'cancelled') {
            expect(screen.getAllByText('Cancelled')).toHaveLength(1);
            expect(screen.queryByRole('button', { name: 'Cancel', exact: true })).toBeNull();
            expect(screen.queryByText('Saved locally')).toBeNull();
        } else {
            expect(screen.queryByText('Cancelled')).toBeNull();
        }
    });
    it('requires confirmation before cancelling, and supports keeping the appointment', async () => {
        render(<AppointmentCard appointment={appointment} timezone="UTC" connection={connection} />);
        await userEvent.click(screen.getByRole('button', { name: 'Cancel', exact: true }));
        await userEvent.click(screen.getByRole('button', { name: 'Close dialog' }));
        await userEvent.click(screen.getByRole('button', { name: 'Cancel', exact: true }));
        await userEvent.click(screen.getByRole('button', { name: 'Keep appointment' }));
        expect(visits.post).not.toHaveBeenCalled();
        await userEvent.click(screen.getByRole('button', { name: 'Cancel', exact: true }));
        await userEvent.click(screen.getByRole('button', { name: 'Cancel appointment', exact: true }));
        expect(visits.post).toHaveBeenCalledWith('/appointments/one/cancel', {}, expect.any(Object));
        const callbacks = visits.post.mock.calls[0][2];
        act(() => {
            callbacks.onSuccess();
            callbacks.onFinish();
        });
        expect(screen.queryByRole('dialog')).toBeNull();
    });
    it('retries synchronization while retaining the displayed booking', async () => {
        render(
            <AppointmentCard
                appointment={{ ...appointment, sync_status: 'failed', sync_error: 'Reconnect' }}
                timezone="UTC"
                connection={connection}
            />,
        );
        await userEvent.click(screen.getByRole('button', { name: 'Retry sync' }));
        expect(visits.post).toHaveBeenCalledWith('/appointments/one/retry', {}, expect.any(Object));
        expect(screen.getByText('Discovery session')).toBeVisible();
    });
});

describe('booking form', () => {
    it('submits the chosen fields and a stable request key', async () => {
        const close = vi.fn();
        render(
            <BookingForm
                date="2026-10-12"
                timezone="UTC"
                timezones={['UTC', 'Africa/Cairo']}
                calendars={connection.calendars}
                selectedCalendarId="work"
                onClose={close}
            />,
        );
        expect(screen.getByLabelText('Google calendar')).toHaveValue('work');
        expect(screen.getByRole('option', { name: 'Holidays (read only)' })).toBeDisabled();
        await userEvent.selectOptions(screen.getByLabelText('Google calendar'), 'personal');
        await userEvent.type(screen.getByLabelText('Appointment title'), 'New consultation');
        await userEvent.type(screen.getByLabelText('Customer name'), 'Sam');
        await userEvent.type(screen.getByLabelText('Customer email'), 'sam@example.com');
        await userEvent.click(screen.getByLabelText('Appointment timezone'));
        await userEvent.type(screen.getByLabelText('Appointment timezone'), 'cairo');
        await userEvent.click(screen.getByRole('option', { name: 'Africa/Cairo' }));
        fireEvent.submit(screen.getByRole('button', { name: 'Reserve appointment' }).closest('form')!);
        expect(visits.post).toHaveBeenCalledWith(
            '/appointments',
            expect.objectContaining({
                calendar_id: 'personal',
                title: 'New consultation',
                customer_name: 'Sam',
                timezone: 'Africa/Cairo',
                request_key: expect.stringMatching(/^[0-9a-f-]{36}$/),
            }),
            expect.any(Object),
        );
        const callbacks = visits.post.mock.calls[0][2];
        act(() =>
            callbacks.onError({
                start_time: 'Already reserved',
                request_key: 'Used',
                calendar_id: 'Reconnect',
            }),
        );
        expect(screen.getByText('Already reserved')).toBeVisible();
        expect(screen.getByText('Used')).toBeVisible();
        expect(screen.getByText('Reconnect')).toBeVisible();
        expect(screen.getByLabelText('Start time')).toHaveFocus();
        act(() => callbacks.onStart({}));
        expect(screen.getByRole('button', { name: 'Saving...' })).toBeDisabled();
        act(() => {
            callbacks.onSuccess({});
            callbacks.onFinish({});
        });
        expect(close).toHaveBeenCalled();
    });
    it.each([null, 'missing', 'holiday'])(
        'falls back to a writable calendar when the saved calendar is %s',
        (selectedCalendarId) => {
            render(
                <BookingForm
                    date="2026-10-12"
                    timezone="UTC"
                    timezones={['UTC']}
                    calendars={connection.calendars}
                    selectedCalendarId={selectedCalendarId}
                    onClose={vi.fn()}
                />,
            );
            expect(screen.getByLabelText('Google calendar')).toHaveValue('work');
        },
    );
    it('closes without saving when going back', async () => {
        const close = vi.fn();
        render(
            <BookingForm
                date="2026-10-12"
                timezone="UTC"
                timezones={['UTC']}
                calendars={connection.calendars}
                selectedCalendarId="work"
                onClose={close}
            />,
        );
        await userEvent.click(screen.getByRole('button', { name: 'Go back' }));
        expect(close).toHaveBeenCalledOnce();
        expect(visits.post).not.toHaveBeenCalled();
    });
});

describe('calendar connection panel', () => {
    const visibility = { visibleIds: [], onToggle: vi.fn() };
    it.each([true, false])('explains Google availability when configuration is %s', (googleConfigured) => {
        render(<CalendarPanel {...visibility} connection={null} googleConfigured={googleConfigured} />);
        expect(
            screen.getByText(googleConfigured ? 'Connect Google' : 'No place safer than localhost.'),
        ).toBeVisible();
    });
    it('keeps connection controls in the sidebar without a booking destination selector', () => {
        render(
            <CalendarPanel
                {...visibility}
                connection={{ ...connection, calendars: [] }}
                googleConfigured={false}
            />,
        );
        expect(screen.getByRole('button', { name: 'Refresh calendars' })).toBeVisible();
        expect(screen.queryByRole('combobox')).toBeNull();
        expect(screen.getByText('No place safer than localhost.')).toBeVisible();
    });
    it('combines a calendar checkbox and refresh control without repeating the account email', async () => {
        const toggle = vi.fn();
        render(
            <CalendarPanel
                {...visibility}
                connection={{
                    ...connection,
                    calendars: [{ ...connection.calendars[0], name: connection.email }],
                }}
                googleConfigured
                visibleIds={['work']}
                onToggle={toggle}
            />,
        );
        expect(screen.getAllByText(connection.email)).toHaveLength(1);
        const checkbox = screen.getByRole('checkbox', { name: connection.email });
        expect(checkbox).toBeChecked();
        expect(checkbox.closest('.connection-controls')).toContainElement(
            screen.getByRole('button', { name: 'Refresh calendars' }),
        );
        await userEvent.click(checkbox);
        expect(toggle).toHaveBeenCalledWith('work');
    });
    it('explains missing permissions and an empty calendar list', () => {
        shared.errors = { calendar_id: 'Choose another calendar' };
        render(
            <CalendarPanel
                {...visibility}
                connection={{ ...connection, needs_reconnect: true, calendars: [] }}
                googleConfigured
            />,
        );
        expect(screen.getByRole('link', { name: 'Reconnect Google' })).toHaveAttribute(
            'href',
            '/calendar/google',
        );
        expect(screen.getByText(/No calendars found/)).toBeVisible();
        shared.errors = {};
    });
});

describe('navigation feedback', () => {
    it('only displays progress for visible visits', () => {
        const listeners: Record<string, Function> = {};
        const off = vi.fn();
        const spy = vi.spyOn(router, 'on').mockImplementation(((name: string, callback: Function) => {
            listeners[name] = callback;
            return off;
        }) as any);
        const { unmount } = render(<NavigationProgress />);
        act(() => listeners.start({ detail: { visit: { showProgress: false } } }));
        expect(screen.queryByRole('progressbar')).toBeNull();
        act(() => listeners.start({ detail: { visit: { showProgress: true } } }));
        expect(screen.getByRole('progressbar', { name: 'Loading page' })).toBeVisible();
        act(() => listeners.finish());
        expect(screen.queryByRole('progressbar')).toBeNull();
        unmount();
        expect(off).toHaveBeenCalledTimes(2);
        spy.mockRestore();
    });
});
