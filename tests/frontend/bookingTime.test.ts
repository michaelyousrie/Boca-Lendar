import { describe, expect, it } from 'vitest';
import { bookingDefaults, bookingTimeError, localDateTime } from '../../resources/js/lib/bookingTime';

describe('booking defaults', () => {
    it.each([
        ['before 10 AM', '2026-09-23', 'Africa/Cairo', '2026-09-23T05:05:00Z', '2026-09-23', '08:15'],
        ['after 10 AM', '2026-09-23', 'Africa/Cairo', '2026-09-23T12:07:00Z', '2026-09-23', '15:15'],
        ['exact quarter hour', '2026-09-23', 'UTC', '2026-09-23T12:15:00Z', '2026-09-23', '12:30'],
        ['past day selected', '2026-09-20', 'UTC', '2026-09-23T12:07:00Z', '2026-09-23', '12:15'],
        ['future day selected', '2026-09-28', 'UTC', '2026-09-23T12:07:00Z', '2026-09-28', '10:00'],
        ['year rollover', '2026-12-31', 'UTC', '2026-12-31T23:59:59Z', '2027-01-01', '00:00'],
        [
            'local date ahead of UTC',
            '2026-09-23',
            'Asia/Kathmandu',
            '2026-09-23T20:12:00Z',
            '2026-09-24',
            '02:00',
        ],
        [
            'local date behind UTC',
            '2026-09-22',
            'America/Los_Angeles',
            '2026-09-23T00:05:00Z',
            '2026-09-22',
            '17:15',
        ],
        [
            'spring clock change',
            '2026-03-08',
            'America/New_York',
            '2026-03-08T06:59:00Z',
            '2026-03-08',
            '03:00',
        ],
        [
            'autumn clock change',
            '2026-11-01',
            'America/New_York',
            '2026-11-01T04:59:00Z',
            '2026-11-01',
            '02:00',
        ],
        [
            'half-hour clock change',
            '2026-10-04',
            'Australia/Lord_Howe',
            '2026-10-03T15:29:00Z',
            '2026-10-04',
            '02:30',
        ],
    ])('chooses a future time for %s', (_, selected, timezone, now, date, start_time) => {
        const result = bookingDefaults(selected, timezone, new Date(now));
        expect(result).toEqual({ date, start_time });
        expect(bookingTimeError(result.date, result.start_time, timezone, new Date(now))).toBeNull();
    });

    it('uses 00:00 at midnight rather than 24:00', () => {
        expect(localDateTime(new Date('2026-09-23T21:00:00Z'), 'Africa/Cairo')).toEqual({
            date: '2026-09-24',
            start_time: '00:00',
        });
    });
});

describe('booking time validation', () => {
    it.each([
        ['2026-09-22', '23:59', 'date', 'Choose today or a future date.'],
        ['2026-09-23', '09:59', 'start_time', 'Choose a future time.'],
        ['2026-09-23', '10:00', 'start_time', 'Choose a future time.'],
    ])('rejects %s at %s in the appointment timezone', (date, time, field, message) => {
        expect(bookingTimeError(date, time, 'Africa/Cairo', new Date('2026-09-23T07:00:00Z'))).toEqual({
            field,
            message,
        });
    });

    it('rejects the current minute after its first second', () => {
        expect(
            bookingTimeError('2026-09-23', '10:00', 'Africa/Cairo', new Date('2026-09-23T07:00:30Z')),
        ).toEqual({ field: 'start_time', message: 'Choose a future time.' });
    });

    it.each([
        ['2026-09-23', '10:01', 'Africa/Cairo'],
        ['2026-09-23', '03:01', 'America/New_York'],
        ['2026-09-24', '00:00', 'Asia/Tokyo'],
    ])('allows the future instant %s %s in %s', (date, time, timezone) => {
        expect(bookingTimeError(date, time, timezone, new Date('2026-09-23T07:00:00Z'))).toBeNull();
    });

    it.each([
        ['2026-03-08', '02:30', 'America/New_York'],
        ['2026-02-30', '10:00', 'UTC'],
        ['2026-09-23', '25:00', 'UTC'],
    ])('rejects the nonexistent time %s %s in %s', (date, time, timezone) => {
        expect(bookingTimeError(date, time, timezone, new Date('2026-01-01T00:00:00Z'))).toEqual({
            field: 'start_time',
            message: 'Choose a valid time. This time may be skipped by a clock change.',
        });
    });

    it('requires an unambiguous instant during the autumn clock change', () => {
        expect(
            bookingTimeError('2026-11-01', '01:30', 'America/New_York', new Date('2026-10-31T12:00:00Z')),
        ).toEqual({
            field: 'start_time',
            message: 'This time occurs twice. Choose UTC to specify the exact time.',
        });
        expect(bookingTimeError('2026-11-01', '06:30', 'UTC', new Date('2026-10-31T12:00:00Z'))).toBeNull();
    });
});
