import { ChevronRight } from 'lucide-react';
import { dateLabel, timeLabel } from '../lib/dates';
import type { UpcomingEvent } from '../types';

export default function UpcomingEvents({
    events,
    timezone,
    onSelect,
}: {
    events: UpcomingEvent[];
    timezone: string;
    onSelect: (event: UpcomingEvent) => void;
}) {
    return (
        <section className="upcoming-panel" aria-labelledby="upcoming-title">
            <div className="section-heading">
                <h2 id="upcoming-title">Upcoming Events</h2>
            </div>
            {events.length === 0 ? (
                <p className="field-hint">No upcoming events.</p>
            ) : (
                <ul className="upcoming-events">
                    {events.map((event) => (
                        <li key={event.id}>
                            <button onClick={() => onSelect(event)}>
                                <span>
                                    <strong>{event.title}</strong>
                                    <span className="upcoming-date">
                                        {dateLabel(event.date, { month: 'short', day: 'numeric' })},{' '}
                                        {event.all_day ? 'All day' : timeLabel(event.starts_at, timezone)}
                                    </span>
                                </span>
                                <ChevronRight size={14} aria-hidden="true" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
