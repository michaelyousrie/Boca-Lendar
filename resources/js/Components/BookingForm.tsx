import { FormEvent, useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import Dialog from './Dialog';
import Field from './Field';
import { preferredCalendar } from '../lib/calendars';
import { bookingDefaults, bookingTimeError, localDateTime } from '../lib/bookingTime';
import type { Appointment, Calendar } from '../types';

type Props = {
    date: string;
    timezone: string;
    timezones: string[];
    calendars: Calendar[];
    selectedCalendarId: string | null;
    appointment?: Appointment;
    onClose: () => void;
};

export default function BookingForm({
    date,
    timezone,
    timezones,
    calendars,
    selectedCalendarId,
    appointment,
    onClose,
}: Props) {
    const [now, setNow] = useState(() => new Date());
    const [initialTime] = useState(() =>
        appointment
            ? appointment.all_day
                ? { date: appointment.starts_at, start_time: '10:00' }
                : localDateTime(new Date(appointment.starts_at), appointment.timezone)
            : bookingDefaults(date, timezone, now),
    );
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 30_000);
        return () => window.clearInterval(timer);
    }, []);
    const form = useForm({
        request_key: crypto.randomUUID(),
        calendar_id: appointment
            ? (appointment.calendar_id ?? '')
            : preferredCalendar(calendars, selectedCalendarId),
        all_day: appointment?.all_day ?? false,
        revision: appointment?.revision ?? '',
        title: appointment?.title ?? '',
        customer_name: appointment?.customer_name ?? '',
        customer_email: appointment?.customer_email ?? '',
        ...initialTime,
        duration: appointment
            ? String(
                  (Date.parse(appointment.ends_at) - Date.parse(appointment.starts_at)) /
                      (appointment.all_day ? 86400000 : 60000),
              )
            : '30',
        timezone: appointment?.timezone ?? timezone,
    });
    function submit(event: FormEvent) {
        event.preventDefault();
        const current = new Date();
        setNow(current);
        form.clearErrors('date', 'start_time');
        const error = bookingTimeError(
            form.data.date,
            form.data.start_time,
            form.data.timezone,
            current,
            form.data.all_day,
        );
        if (error) {
            form.setError(error.field, error.message);
            document.getElementById(error.field)!.focus();
            return;
        }
        form.post(appointment ? `/appointments/${appointment.id}/update` : '/appointments', {
            preserveScroll: true,
            onSuccess: onClose,
            onError: (errors) => document.getElementById(Object.keys(errors)[0])?.focus(),
        });
    }
    const minimum = localDateTime(
        new Date((Math.floor(now.getTime() / 60_000) + 1) * 60_000),
        form.data.timezone,
    );
    const fieldProps = (name: Exclude<keyof typeof form.data, 'all_day'>) => ({
        id: name,
        value: form.data[name],
        'aria-invalid': Boolean(form.errors[name]),
        'aria-describedby': form.errors[name] ? `${name}-error` : undefined,
        onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
            form.setData(name, event.target.value);
            form.clearErrors('date', 'start_time');
        },
    });

    return (
        <Dialog
            title={appointment ? 'Edit appointment' : 'New appointment'}
            onClose={onClose}
            busy={form.processing}
        >
            <form onSubmit={submit}>
                {(calendars.length > 0 || appointment?.calendar_id) && (
                    <Field id="calendar_id" label="Google calendar" error={form.errors.calendar_id}>
                        <select
                            {...fieldProps('calendar_id')}
                            disabled={form.processing || Boolean(appointment)}
                            data-autofocus={!appointment || undefined}
                        >
                            {appointment ? (
                                <option value={appointment.calendar_id ?? ''}>
                                    {appointment.calendar_name}
                                </option>
                            ) : (
                                <>
                                    <option value="">Local only</option>
                                    {calendars.map((calendar) => (
                                        <option
                                            key={calendar.id}
                                            value={calendar.id}
                                            disabled={!calendar.writable}
                                        >
                                            {calendar.name}
                                            {!calendar.writable ? ' (read only)' : ''}
                                        </option>
                                    ))}
                                </>
                            )}
                        </select>
                    </Field>
                )}
                <Field id="title" label="Appointment title" error={form.errors.title}>
                    <input
                        {...fieldProps('title')}
                        data-autofocus={Boolean(appointment) || calendars.length === 0 || undefined}
                        maxLength={150}
                        required
                    />
                </Field>
                <div className="form-row">
                    <Field id="customer_name" label="Customer name" error={form.errors.customer_name}>
                        <input
                            {...fieldProps('customer_name')}
                            autoComplete="name"
                            maxLength={100}
                            required
                        />
                    </Field>
                    <Field id="customer_email" label="Customer email" error={form.errors.customer_email}>
                        <input
                            {...fieldProps('customer_email')}
                            type="email"
                            autoComplete="email"
                            maxLength={254}
                            required
                        />
                    </Field>
                </div>
                <label className="checkbox-label">
                    <input
                        type="checkbox"
                        checked={form.data.all_day}
                        onChange={(event) => {
                            form.setData('all_day', event.target.checked);
                            form.setData('duration', event.target.checked ? '1' : '30');
                        }}
                    />
                    All day
                </label>
                <div className={`form-row ${form.data.all_day ? '' : 'three'}`}>
                    <Field id="date" label="Date" error={form.errors.date}>
                        <input
                            {...fieldProps('date')}
                            type="date"
                            min={
                                form.data.all_day ? localDateTime(now, form.data.timezone).date : minimum.date
                            }
                            required
                        />
                    </Field>
                    {!form.data.all_day && (
                        <Field id="start_time" label="Start time" error={form.errors.start_time}>
                            <input
                                {...fieldProps('start_time')}
                                type="time"
                                min={form.data.date === minimum.date ? minimum.start_time : undefined}
                                required
                            />
                        </Field>
                    )}
                    <Field
                        id="duration"
                        label={form.data.all_day ? 'Duration (days)' : 'Duration (min)'}
                        error={form.errors.duration}
                    >
                        <input
                            {...fieldProps('duration')}
                            type="number"
                            min={form.data.all_day ? 1 : 5}
                            max={form.data.all_day ? 365 : 525600}
                            required
                        />
                    </Field>
                </div>
                <Field id="timezone" label="Appointment timezone" error={form.errors.timezone}>
                    <select {...fieldProps('timezone')}>
                        {timezones.map((zone) => (
                            <option key={zone}>{zone}</option>
                        ))}
                    </select>
                </Field>
                {(form.errors.request_key || form.errors.revision) && (
                    <p className="field-error" role="alert">
                        {form.errors.request_key || form.errors.revision}
                    </p>
                )}
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
                        {form.processing && <LoaderCircle className="spin" size={17} />}
                        {form.processing ? 'Saving...' : appointment ? 'Save changes' : 'Reserve appointment'}
                    </button>
                </div>
            </form>
        </Dialog>
    );
}
