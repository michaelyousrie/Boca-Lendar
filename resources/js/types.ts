export type Calendar = { id: string; name: string; timezone: string; writable: boolean };
export type Connection = {
    email: string;
    calendars: Calendar[];
    needs_reconnect: boolean;
    selected_calendar_id: string | null;
};
export type Appointment = {
    id: string;
    revision: string;
    title: string;
    customer_name: string | null;
    customer_email: string | null;
    starts_at: string;
    ends_at: string;
    timezone: string;
    status: 'scheduled' | 'cancelled';
    sync_status: 'local' | 'pending' | 'synced' | 'failed';
    sync_error: string | null;
    calendar_name: string;
    calendar_id: string | null;
    all_day: boolean;
    writable: boolean;
    url: string | null;
    holds_slot: boolean;
};
export type SyncIssue = {
    all_day: boolean;
    id: string;
    title: string;
    date: string;
    starts_at: string;
    status: 'scheduled' | 'cancelled';
    sync_status: 'pending' | 'failed';
    sync_error: string | null;
};
export type SharedProps = {
    auth: { user: { id: number; name: string; email: string } | null };
    flash: { success: string | null; error: string | null };
    [key: string]: unknown;
};

export type CalendarSyncStatus = {
    refreshing: boolean;
    error: string | null;
};

export type UpcomingEvent = {
    id: string;
    title: string;
    starts_at: string;
    date: string;
    all_day: boolean;
    calendar_id: string | null;
};
