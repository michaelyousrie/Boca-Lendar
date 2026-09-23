import { ChevronLeft, ChevronRight } from 'lucide-react';
import { addDays, calendarDate, dateLabel } from '../lib/dates';
import TimezoneSelect from './TimezoneSelect';

type Props = {
    date: string;
    today: string;
    timezone: string;
    timezones: string[];
    navigate: (date: string, timezone?: string) => void;
};

export default function ScheduleHeader({ date, today, timezone, timezones, navigate }: Props) {
    const weekStart = addDays(date, -((calendarDate(date).getUTCDay() + 6) % 7));
    return (
        <>
            {' '}
            <div className="schedule-toolbar">
                <div className="schedule-date">
                    <h2>{dateLabel(date, { month: 'long', year: 'numeric' })}</h2>
                </div>
                <div className="schedule-controls">
                    <TimezoneSelect
                        label="Display timezone"
                        value={timezone}
                        timezones={timezones}
                        onChange={(zone) => navigate(date, zone)}
                    />
                    <div className="button-pair bordered">
                        <button
                            className="icon-button"
                            aria-label="Previous week"
                            onClick={() => navigate(addDays(date, -7))}
                        >
                            <ChevronLeft size={18} />
                        </button>
                        <button
                            className="icon-button"
                            aria-label="Next week"
                            onClick={() => navigate(addDays(date, 7))}
                        >
                            <ChevronRight size={18} />
                        </button>
                    </div>
                </div>
            </div>
            <div className="week-strip" aria-label="Week dates">
                {Array.from({ length: 7 }, (_, index) => addDays(weekStart, index)).map((day) => (
                    <button
                        key={day}
                        className={`week-day ${date === day ? 'selected' : ''}`}
                        aria-label={dateLabel(day, {
                            weekday: 'long',
                            month: 'long',
                            day: 'numeric',
                        })}
                        aria-pressed={day === date}
                        onClick={() => navigate(day)}
                    >
                        <span>{dateLabel(day, { weekday: 'short' })}</span>
                        <strong>{Number(day.slice(-2))}</strong>
                        <span className={`day-marker ${day === today ? 'today-marker' : ''}`}>
                            {day === today ? 'Today' : '\u00a0'}
                        </span>
                    </button>
                ))}
            </div>
        </>
    );
}
