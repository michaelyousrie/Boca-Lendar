import { ChevronLeft, ChevronRight } from 'lucide-react';
import { dateLabel, monthDays, moveMonth } from '../lib/dates';

type Props = {
    date: string;
    today: string;
    month: string;
    appointmentDates: string[];
    onSelect: (date: string) => void;
    onMonthChange: (month: string) => void;
};

export default function MonthPicker({
    date,
    today,
    month,
    appointmentDates,
    onSelect,
    onMonthChange,
}: Props) {
    return (
        <section className="month-picker" aria-label="Choose a date">
            <div className="month-heading">
                <h2>{dateLabel(`${month}-01`, { month: 'long', year: 'numeric' })}</h2>
                <div className="button-pair">
                    <button
                        className="icon-button"
                        aria-label="Previous month"
                        onClick={() => onMonthChange(moveMonth(`${month}-01`, -1).slice(0, 7))}
                    >
                        <ChevronLeft size={16} />
                    </button>
                    <button
                        className="icon-button"
                        aria-label="Next month"
                        onClick={() => onMonthChange(moveMonth(`${month}-01`, 1).slice(0, 7))}
                    >
                        <ChevronRight size={16} />
                    </button>
                </div>
            </div>
            <div className="month-grid">
                <div className="weekdays" aria-hidden="true">
                    {['M', 'T', 'W', 'T', 'F', 'S', 'S'].map((day, index) => (
                        <span key={index}>{day}</span>
                    ))}
                </div>
                <div className="month-dates">
                    {monthDays(month).map((day, index) =>
                        day ? (
                            <button
                                type="button"
                                key={day}
                                onClick={() => onSelect(day)}
                                aria-label={dateLabel(day, {
                                    weekday: 'long',
                                    month: 'long',
                                    day: 'numeric',
                                    year: 'numeric',
                                })}
                                aria-pressed={day === date}
                                aria-current={day === today ? 'date' : undefined}
                                aria-description={
                                    appointmentDates.includes(day) ? 'Has appointments' : undefined
                                }
                                className={`${day === date ? 'selected' : ''} ${day === today ? 'is-today' : ''}`}
                            >
                                {appointmentDates.includes(day) && (
                                    <span className="appointment-marker" aria-hidden="true" />
                                )}
                                {Number(day.slice(-2))}
                            </button>
                        ) : (
                            <span key={`blank-${index}`} />
                        ),
                    )}
                </div>
            </div>
            {date !== today && (
                <button className="text-button today-link" onClick={() => onSelect(today)}>
                    Back to today
                </button>
            )}
        </section>
    );
}
