import { Link } from '@inertiajs/react';
import { CalendarDays, RefreshCw } from 'lucide-react';
import type { Connection } from '../types';

export default function CalendarPanel({
    connection,
    googleConfigured,
    visibleIds,
    onToggle,
}: {
    connection: Connection | null;
    googleConfigured: boolean;
    visibleIds: string[];
    onToggle: (id: string) => void;
}) {
    const refresh = (
        <Link
            as="button"
            method="post"
            href="/calendar/refresh"
            preserveScroll
            className="icon-button"
            aria-label="Refresh calendars"
        >
            <RefreshCw size={15} />
        </Link>
    );

    return (
        <section className="calendar-panel" aria-label="Google Calendar">
            {(connection || googleConfigured) && (
                <div className="section-heading">
                    <h2>Google Account</h2>
                </div>
            )}
            {connection ? (
                <>
                    {connection.calendars.length > 0 ? (
                        <fieldset className="calendar-visibility">
                            <legend className="sr-only">Visible calendars</legend>
                            {connection.calendars.map((calendar, index) => (
                                <div className="connection-controls" key={calendar.id}>
                                    <label>
                                        <input
                                            type="checkbox"
                                            checked={visibleIds.includes(calendar.id)}
                                            onChange={() => onToggle(calendar.id)}
                                        />
                                        <span>{calendar.name}</span>
                                    </label>
                                    {index === 0 && refresh}
                                </div>
                            ))}
                        </fieldset>
                    ) : (
                        <div className="connection-controls">
                            <div className="connection-account">
                                <span
                                    className={`connection-dot ${connection.needs_reconnect ? 'offline' : ''}`}
                                />
                                <span>{connection.email}</span>
                            </div>
                            {refresh}
                        </div>
                    )}
                    {connection.needs_reconnect && (
                        <div className="connection-alert">
                            <a href="/calendar/google">Reconnect Google</a>
                        </div>
                    )}
                    {connection.calendars.length === 0 && (
                        <p className="field-hint">No calendars found. Create one in Google, then refresh.</p>
                    )}
                </>
            ) : googleConfigured ? (
                <a className="button secondary full-width" href="/calendar/google">
                    Connect Google
                </a>
            ) : null}
            {!googleConfigured && (
                <div className="demo-note">
                    <CalendarDays size={17} />
                    <p>
                        <strong>No place safer than localhost.</strong>This demo stores calendar events
                        locally for demo without needing a Google account.
                    </p>
                </div>
            )}
        </section>
    );
}
