import {
    Clock3,
    Mail,
    RefreshCw,
    Check,
    CircleAlert,
    CircleX,
    CalendarDays,
    ExternalLink,
} from 'lucide-react';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { Appointment, Connection } from '../types';
import { durationLabel, timeLabel, addDays, dateLabel } from '../lib/dates';
import Dialog from './Dialog';
import SyncAppointmentForm from './SyncAppointmentForm';

type Props = {
    appointment: Appointment;
    timezone: string;
    connection: Connection | null;
    onEdit?: () => void;
};

export default function AppointmentCard({ appointment: item, timezone, connection, onEdit }: Props) {
    const [syncing, setSyncing] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [busy, setBusy] = useState(false);
    const cancelled = item.status === 'cancelled';
    const local = item.sync_status === 'local';
    const complete = local || item.sync_status === 'synced';
    const label = local
        ? 'Saved locally'
        : item.sync_status === 'synced'
          ? cancelled
              ? 'Removed from Google'
              : 'Synced to Google'
          : item.sync_status === 'failed'
            ? 'Failed to sync'
            : cancelled
              ? 'Removing from Google'
              : item.sync_error
                ? 'Retrying Google sync'
                : 'Syncing to Google';
    function action(kind: 'cancel' | 'retry') {
        setBusy(true);
        router.post(
            `/appointments/${item.id}/${kind}`,
            {},
            { preserveScroll: true, onSuccess: () => setConfirm(false), onFinish: () => setBusy(false) },
        );
    }
    return (
        <article className={`appointment-row ${cancelled ? 'cancelled' : ''}`} aria-label={item.title}>
            <div className="appointment-time">
                {item.all_day ? (
                    <span className="all-day-label">All day</span>
                ) : (
                    <>
                        <time dateTime={item.starts_at}>{timeLabel(item.starts_at, timezone)}</time>
                        <span>{timeLabel(item.ends_at, timezone)}</span>
                    </>
                )}
            </div>
            <div className="appointment-card">
                <div className="appointment-top">
                    <div>
                        {!local && (
                            <div className="appointment-calendar">
                                <span className="calendar-dot" />
                                {item.calendar_name}
                            </div>
                        )}
                        <div className="appointment-title">
                            <h3>{item.title}</h3>
                            {cancelled && (
                                <span className="cancelled-badge">
                                    <CircleX size={13} aria-hidden="true" />
                                    Cancelled
                                </span>
                            )}
                        </div>
                    </div>
                    {!item.all_day && (
                        <span className="duration">
                            <Clock3 size={14} />
                            {durationLabel(item.starts_at, item.ends_at)}
                        </span>
                    )}
                </div>
                {item.all_day && (
                    <p className="event-dates">
                        {dateLabel(item.starts_at, { month: 'short', day: 'numeric' })}
                        {addDays(item.ends_at, -1) !== item.starts_at && (
                            <>
                                {' '}
                                to {dateLabel(addDays(item.ends_at, -1), { month: 'short', day: 'numeric' })}
                            </>
                        )}
                    </p>
                )}
                {(item.customer_name || item.customer_email) && (
                    <div className="customer">
                        <span className="customer-avatar" aria-hidden="true">
                            {(item.customer_name || item.customer_email!).slice(0, 1).toUpperCase()}
                        </span>
                        <div>
                            {item.customer_name && <strong>{item.customer_name}</strong>}
                            <span>
                                <Mail size={12} aria-hidden="true" />
                                {item.customer_email}
                            </span>
                        </div>
                    </div>
                )}
                {(!cancelled || !local) && (
                    <div className="appointment-footer">
                        <span
                            className={`sync-status ${complete ? 'success' : item.sync_status === 'failed' ? 'danger' : 'waiting'}`}
                        >
                            {complete ? (
                                <Check size={14} />
                            ) : item.sync_status === 'failed' ? (
                                <CircleAlert size={14} />
                            ) : (
                                <RefreshCw size={13} />
                            )}
                            {label}
                        </span>
                        <div className="appointment-actions">
                            {item.url && (
                                <a
                                    className="text-button"
                                    href={item.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Open in Google <ExternalLink size={12} aria-hidden="true" />
                                </a>
                            )}
                            {item.sync_status === 'failed' && item.writable && (
                                <button
                                    className="text-button"
                                    onClick={() => action('retry')}
                                    disabled={busy}
                                >
                                    Retry sync
                                </button>
                            )}
                            {local &&
                                !cancelled &&
                                connection?.calendars.some((calendar) => calendar.writable) && (
                                    <button className="text-button" onClick={() => setSyncing(true)}>
                                        Sync to Google
                                    </button>
                                )}
                            {!cancelled && item.writable && onEdit && (
                                <button className="text-button" onClick={onEdit} disabled={busy}>
                                    Edit
                                </button>
                            )}
                            {!cancelled && item.writable && (
                                <button
                                    className="text-button cancel-button"
                                    onClick={() => setConfirm(true)}
                                    disabled={busy}
                                >
                                    Cancel
                                </button>
                            )}
                        </div>
                    </div>
                )}
                {item.sync_error && <p className="sync-error">{item.sync_error}</p>}
                {cancelled && item.holds_slot && (
                    <p className="sync-error">Time reserved until Google confirms removal.</p>
                )}
            </div>
            {syncing && connection && (
                <SyncAppointmentForm
                    appointment={item}
                    connection={connection}
                    onClose={() => setSyncing(false)}
                />
            )}
            {confirm && (
                <Dialog
                    title="Cancel this appointment?"
                    description={
                        local ? 'This frees the reserved time.' : 'Also removes this appointment from Google.'
                    }
                    onClose={() => setConfirm(false)}
                    busy={busy}
                >
                    <div className="cancel-summary">
                        <CalendarDays size={22} />
                        <div>
                            <strong>{item.title}</strong>
                            <p>
                                {item.customer_name && `${item.customer_name}, `}
                                {item.all_day ? 'All day' : timeLabel(item.starts_at, timezone)}
                            </p>
                        </div>
                    </div>
                    <div className="form-footer">
                        <button
                            className="button secondary"
                            onClick={() => setConfirm(false)}
                            data-autofocus
                            disabled={busy}
                        >
                            Keep appointment
                        </button>
                        <button
                            className="button destructive"
                            onClick={() => action('cancel')}
                            disabled={busy}
                        >
                            {busy ? 'Cancelling...' : 'Cancel appointment'}
                        </button>
                    </div>
                </Dialog>
            )}
        </article>
    );
}
