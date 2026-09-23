import { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    Check,
    ChevronDown,
    CircleAlert,
    Plus,
    RefreshCw,
    SlidersHorizontal,
} from 'lucide-react';
import AppLayout from '../Components/AppLayout';
import ScheduleHeader from '../Components/ScheduleHeader';
import MonthPicker from '../Components/MonthPicker';
import CalendarPanel from '../Components/CalendarPanel';
import SyncIssues from '../Components/SyncIssues';
import UpcomingEvents from '../Components/UpcomingEvents';
import BookingForm from '../Components/BookingForm';
import AppointmentCard from '../Components/AppointmentCard';
import useCalendars from '../lib/useCalendars';
import { dateLabel } from '../lib/dates';
import type {
    Appointment,
    Connection,
    CalendarSyncStatus,
    SharedProps,
    SyncIssue,
    UpcomingEvent,
} from '../types';

type Props = {
    date: string;
    today: string;
    month: string;
    appointmentDates: string[];
    timezone: string;
    timezones: string[];
    googleConfigured: boolean;
    connection: Connection | null;
    appointments: Appointment[];
    syncIssues: SyncIssue[];
    upcomingEvents: UpcomingEvent[];
    calendarSync: Record<string, CalendarSyncStatus>;
};

export default function Dashboard({
    date,
    today,
    month,
    appointmentDates,
    timezone,
    timezones,
    googleConfigured,
    connection,
    appointments,
    syncIssues,
    upcomingEvents,
    calendarSync,
}: Props) {
    const { flash } = usePage<SharedProps>().props;
    const [booking, setBooking] = useState(false);
    const [editing, setEditing] = useState<Appointment>();
    const [sidebar, setSidebar] = useState(false);
    const calendars = useCalendars(connection, calendarSync);
    const pending =
        syncIssues.some((item) => item.sync_status === 'pending') ||
        Object.values(calendarSync).some((feed) => feed.refreshing);
    const connected = Boolean(connection && !connection.needs_reconnect);
    useEffect(() => {
        if (!connected && !pending) return;
        const poll = router.poll(pending ? 2000 : 15000, {
            only: [
                'appointments',
                'connection',
                'syncIssues',
                'appointmentDates',
                'calendarSync',
                'upcomingEvents',
            ],
        });
        return poll.destroy;
    }, [connected, pending]);
    const visible = appointments.filter((item) => calendars.isVisible(item.calendar_id));
    const active = visible
        .filter((item) => item.status !== 'cancelled')
        .sort(
            (a, b) =>
                Number(b.all_day) - Number(a.all_day) || Date.parse(a.starts_at) - Date.parse(b.starts_at),
        );
    const cancelled = visible.filter((item) => item.status === 'cancelled');
    function navigate(nextDate: string, nextTimezone = timezone) {
        router.get(
            '/appointments',
            { date: nextDate, timezone: nextTimezone },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => setSidebar(false),
            },
        );
    }
    function navigateMonth(nextMonth: string) {
        router.get(
            '/appointments',
            { date, timezone, month: nextMonth },
            {
                only: ['month', 'appointmentDates'],
                preserveState: true,
                preserveScroll: true,
            },
        );
    }
    return (
        <>
            <Head title="Appointments" />
            <AppLayout>
                <aside className={`sidebar ${sidebar ? 'mobile-open' : ''}`} aria-label="Calendar settings">
                    <MonthPicker
                        date={date}
                        today={today}
                        month={month}
                        appointmentDates={appointmentDates}
                        onSelect={navigate}
                        onMonthChange={navigateMonth}
                    />
                    <CalendarPanel
                        connection={connection}
                        googleConfigured={googleConfigured}
                        visibleIds={calendars.visibleIds}
                        onToggle={calendars.toggle}
                    />
                    <UpcomingEvents
                        events={upcomingEvents}
                        timezone={timezone}
                        onSelect={(event) => {
                            if (event.calendar_id && !calendars.isVisible(event.calendar_id)) {
                                calendars.toggle(event.calendar_id);
                            }
                            navigate(event.date);
                        }}
                    />
                    <SyncIssues issues={syncIssues} timezone={timezone} onSelect={navigate} />
                </aside>
                <main id="agenda" className="agenda" tabIndex={-1}>
                    <div className="page-heading">
                        <div>
                            <h1>
                                Appointments<span className="heading-dot">.</span>
                            </h1>
                        </div>
                        <button className="button primary new-appointment" onClick={() => setBooking(true)}>
                            <Plus size={19} />
                            New appointment
                        </button>
                    </div>
                    <button
                        className="button secondary mobile-calendar-toggle"
                        aria-expanded={sidebar}
                        onClick={() => setSidebar(!sidebar)}
                    >
                        <SlidersHorizontal size={16} />
                        Calendars & dates
                    </button>
                    {(flash.success || flash.error) && (
                        <div
                            className={`notice ${flash.error ? 'error' : ''}`}
                            role={flash.error ? 'alert' : 'status'}
                        >
                            {flash.error ? <CircleAlert size={18} /> : <Check size={18} />}
                            <span>{flash.error || flash.success}</span>
                        </div>
                    )}
                    <section className="schedule" aria-label="Daily schedule">
                        <ScheduleHeader
                            date={date}
                            today={today}
                            timezone={timezone}
                            timezones={timezones}
                            navigate={navigate}
                        />
                        <div className="day-heading">
                            <div>
                                <h2>
                                    {dateLabel(date, { weekday: 'long', month: 'short', day: 'numeric' })}
                                </h2>
                                <span>
                                    {active.length} {active.length === 1 ? 'appointment' : 'appointments'}
                                </span>
                            </div>
                            {connection && calendars.visibleIds.length > 0 && !connection.needs_reconnect && (
                                <button
                                    className="text-button refresh-events"
                                    onClick={calendars.refresh}
                                    disabled={calendars.loading}
                                >
                                    <RefreshCw
                                        size={13}
                                        className={calendars.loading ? 'spin' : ''}
                                        aria-hidden="true"
                                    />
                                    {calendars.loading ? 'Syncing appointments...' : 'Refresh appointments'}
                                </button>
                            )}
                        </div>
                        <div className="appointments-list">
                            {calendars.errors.map((error) => (
                                <div className="event-load-error" role="alert" key={error.id}>
                                    <strong>{error.name}</strong>
                                    <span>{error.error}</span>
                                    {error.reconnect && <a href="/calendar/google">Reconnect Google</a>}
                                </div>
                            ))}
                            {active.map((item) => (
                                <AppointmentCard
                                    key={item.id}
                                    appointment={item}
                                    onEdit={() => setEditing(item)}
                                    timezone={timezone}
                                    connection={connection}
                                />
                            ))}
                            {cancelled.length > 0 && (
                                <section
                                    className="cancelled-appointments"
                                    aria-labelledby="cancelled-heading"
                                >
                                    <details key={`${date}-${timezone}`}>
                                        <summary className="cancelled-heading">
                                            <h2 id="cancelled-heading">Cancelled appointments</h2>
                                            <span>{cancelled.length}</span>
                                            <ChevronDown size={16} aria-hidden="true" />
                                        </summary>
                                        {cancelled.map((item) => (
                                            <AppointmentCard
                                                key={item.id}
                                                appointment={item}
                                                timezone={timezone}
                                                connection={connection}
                                            />
                                        ))}
                                    </details>
                                </section>
                            )}
                            {visible.length === 0 && !calendars.loading && calendars.errors.length === 0 && (
                                <div className="empty-state">
                                    <span className="empty-calendar">
                                        <CalendarDays size={34} strokeWidth={1.3} />
                                    </span>
                                    <h3>It's a bit quiet in here</h3>
                                    <p>No appointments scheduled for this day.</p>
                                </div>
                            )}
                        </div>
                    </section>
                </main>
            </AppLayout>
            {(booking || editing) && (
                <BookingForm
                    date={date}
                    timezone={timezone}
                    timezones={timezones}
                    calendars={connection?.calendars ?? []}
                    selectedCalendarId={connection?.selected_calendar_id ?? null}
                    appointment={editing}
                    onClose={() => {
                        setBooking(false);
                        setEditing(undefined);
                    }}
                />
            )}
        </>
    );
}
