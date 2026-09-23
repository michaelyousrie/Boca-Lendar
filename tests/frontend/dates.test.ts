import { describe, expect, it } from 'vitest';
import {
    addDays,
    calendarDate,
    dateLabel,
    durationLabel,
    monthDays,
    moveMonth,
    timeLabel,
} from '../../resources/js/lib/dates';

describe('calendar arithmetic', () => {
    it.each([
        ['2026-12-31', 1, '2027-01-01'],
        ['2026-01-01', -1, '2025-12-31'],
        ['2028-02-28', 1, '2028-02-29'],
        ['2026-03-08', 1, '2026-03-09'],
    ])('moves %s by %i days without the browser timezone changing the date', (date, days, result) => {
        expect(addDays(date, days)).toBe(result);
    });
    it('anchors calendar labels to the date instead of the browser timezone', () => {
        expect(calendarDate('2026-10-12').toISOString()).toBe('2026-10-12T12:00:00.000Z');
        expect(dateLabel('2026-10-12', { weekday: 'long' })).toBe('Monday');
    });
    it('starts weeks on Monday and includes leap day', () => {
        const days = monthDays('2028-02-20');
        expect(days[0]).toBeNull();
        expect(days[1]).toBe('2028-02-01');
        expect(days.at(-1)).toBe('2028-02-29');
        expect(days.filter(Boolean)).toHaveLength(29);
    });
    it('moves between months without overflowing dates at the end of a month', () => {
        expect(moveMonth('2026-01-31', 1)).toBe('2026-02-01');
        expect(moveMonth('2026-01-31', -1)).toBe('2025-12-01');
    });
    it('formats an instant in the chosen display timezone', () => {
        expect(timeLabel('2026-10-12T07:00:00Z', 'Africa/Cairo')).toBe('10:00 AM');
        expect(timeLabel('2026-12-12T07:00:00Z', 'Africa/Cairo')).toBe('9:00 AM');
    });
    it.each([
        [30, '30 min'],
        [60, '1h'],
        [90, '1h 30m'],
        [480, '8h'],
    ])('formats %i minutes', (minutes, label) => {
        expect(
            durationLabel('2026-01-01T00:00:00Z', new Date(Date.UTC(2026, 0, 1, 0, minutes)).toISOString()),
        ).toBe(label);
    });
});
