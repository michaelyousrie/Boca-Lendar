import './inertia';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import BookingForm from '../../resources/js/Components/BookingForm';
import { visits } from './inertia';

const props = {
    date: '2026-09-23',
    timezone: 'UTC',
    timezones: ['UTC', 'Africa/Cairo', 'America/Los_Angeles'],
    calendars: [],
    selectedCalendarId: null,
    onClose: vi.fn(),
};

describe('future booking form', () => {
    it('defaults to the next quarter hour and prevents earlier dates and times', () => {
        vi.setSystemTime(new Date('2026-09-23T12:07:00Z'));
        render(<BookingForm {...props} timezone="Africa/Cairo" />);
        const date = screen.getByLabelText('Date');
        const time = screen.getByLabelText('Start time');
        expect(date).toHaveValue('2026-09-23');
        expect(time).toHaveValue('15:15');
        expect(date).toHaveAttribute('min', '2026-09-23');
        expect(time).toHaveAttribute('min', '15:08');
        fireEvent.change(time, { target: { value: '10:00' } });
        expect(time).toBeInvalid();
        fireEvent.change(date, { target: { value: '2026-09-22' } });
        expect(date).toBeInvalid();
        fireEvent.submit(screen.getByRole('button', { name: 'Reserve appointment' }).closest('form')!);
        expect(screen.getByRole('alert')).toHaveTextContent('Choose today or a future date.');
        expect(date).toHaveFocus();
        expect(visits.post).not.toHaveBeenCalled();
    });

    it('updates limits when changing dates or timezones without rewriting the chosen time', () => {
        vi.setSystemTime(new Date('2026-09-23T22:07:00Z'));
        render(<BookingForm {...props} />);
        const date = screen.getByLabelText('Date');
        const time = screen.getByLabelText('Start time');
        fireEvent.click(screen.getByLabelText('Appointment timezone'));
        fireEvent.click(screen.getByRole('option', { name: 'Africa/Cairo' }));
        expect(date).toHaveAttribute('min', '2026-09-24');
        expect(date).toBeInvalid();
        fireEvent.change(date, { target: { value: '2026-09-24' } });
        expect(time).toHaveAttribute('min', '01:08');
        fireEvent.change(date, { target: { value: '2026-09-25' } });
        expect(time).not.toHaveAttribute('min');
        expect(time).toHaveValue('22:15');
    });

    it('rechecks the clock on submit even before the next timer tick', () => {
        vi.setSystemTime(new Date('2026-09-23T12:14:59Z'));
        render(<BookingForm {...props} />);
        const form = screen.getByRole('button', { name: 'Reserve appointment' }).closest('form')!;
        vi.setSystemTime(new Date('2026-09-23T12:15:01Z'));
        fireEvent.submit(form);
        expect(screen.getByRole('alert')).toHaveTextContent('Choose a future time.');
        expect(screen.getByLabelText('Start time')).toHaveFocus();
        expect(visits.post).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText('Start time'), { target: { value: '12:30' } });
        expect(screen.queryByRole('alert')).toBeNull();
        fireEvent.submit(form);
        expect(visits.post).toHaveBeenCalledWith(
            '/appointments',
            expect.objectContaining({ start_time: '12:30' }),
            expect.any(Object),
        );
    });

    it('advances date limits across midnight and clears its timer on close', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-09-23T23:59:40Z'));
        const { unmount } = render(<BookingForm {...props} />);
        expect(screen.getByLabelText('Date')).toHaveValue('2026-09-24');
        expect(screen.getByLabelText('Start time')).toHaveValue('00:00');
        act(() => vi.advanceTimersByTime(30_000));
        expect(screen.getByLabelText('Start time')).toHaveAttribute('min', '00:01');
        fireEvent.submit(screen.getByRole('button', { name: 'Reserve appointment' }).closest('form')!);
        expect(screen.getByRole('alert')).toHaveTextContent('Choose a future time.');
        expect(visits.post).not.toHaveBeenCalled();
        unmount();
        act(() => vi.runOnlyPendingTimers());
        expect(vi.getTimerCount()).toBe(0);
    });
});
