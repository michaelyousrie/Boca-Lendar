import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import Dialog from './Dialog';
import Field from './Field';
import { preferredCalendar } from '../lib/calendars';
import type { Appointment, Connection } from '../types';

export default function SyncAppointmentForm({
    appointment,
    connection,
    onClose,
}: {
    appointment: Appointment;
    connection: Connection;
    onClose: () => void;
}) {
    const form = useForm({
        calendar_id: preferredCalendar(connection.calendars, connection.selected_calendar_id),
    });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`/appointments/${appointment.id}/sync`, { preserveScroll: true, onSuccess: onClose });
    }
    return (
        <Dialog
            title="Sync to Google"
            description={appointment.title}
            onClose={onClose}
            busy={form.processing}
        >
            <form onSubmit={submit}>
                <Field id="sync_calendar_id" label="Google calendar" error={form.errors.calendar_id}>
                    <select
                        id="sync_calendar_id"
                        value={form.data.calendar_id}
                        onChange={(event) => form.setData('calendar_id', event.target.value)}
                        required
                        data-autofocus
                        aria-invalid={Boolean(form.errors.calendar_id)}
                        aria-describedby={form.errors.calendar_id ? 'sync_calendar_id-error' : undefined}
                    >
                        <option value="" disabled>
                            Choose a calendar
                        </option>
                        {connection.calendars.map((calendar) => (
                            <option key={calendar.id} value={calendar.id} disabled={!calendar.writable}>
                                {calendar.name}
                                {!calendar.writable ? ' (read only)' : ''}
                            </option>
                        ))}
                    </select>
                </Field>
                <div className="form-footer">
                    <button
                        className="button secondary"
                        type="button"
                        onClick={onClose}
                        disabled={form.processing}
                    >
                        Go back
                    </button>
                    <button className="button primary" disabled={form.processing}>
                        {form.processing ? 'Saving...' : 'Sync appointment'}
                    </button>
                </div>
            </form>
        </Dialog>
    );
}
