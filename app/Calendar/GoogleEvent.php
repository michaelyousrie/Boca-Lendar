<?php

namespace App\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GoogleEvent
{
    public static function from(array $item): array
    {
        $allDay = isset($item['start']['date']);
        $field = $allDay ? 'date' : 'dateTime';
        $format = $allDay ? ['date_format:Y-m-d'] : ['date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'];
        $validator = Validator::make($item, [
            'id' => ['required', 'string'], 'summary' => ['nullable', 'string'],
            'start.'.$field => ['required', ...$format], 'end.'.$field => ['required', ...$format],
        ]);
        if ($validator->fails()) {
            throw new CalendarException('Google returned an unreadable event. Try refreshing appointments.');
        }
        $start = $allDay ? $item['start']['date'] : CarbonImmutable::parse($item['start']['dateTime'])->utc()->toIso8601String();
        $end = $allDay ? $item['end']['date'] : CarbonImmutable::parse($item['end']['dateTime'])->utc()->toIso8601String();
        if ($end <= $start) {
            throw new CalendarException('Google returned an unreadable event. Try refreshing appointments.');
        }
        $url = $item['htmlLink'] ?? '';
        $safeUrl = is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && in_array(parse_url($url, PHP_URL_HOST), ['calendar.google.com', 'www.google.com'], true);

        $appointmentId = data_get($item, 'extendedProperties.private.appointment_id');

        $customer = data_get($item, 'extendedProperties.private', []);

        return [
            'id' => $item['id'], 'title' => trim($item['summary'] ?? '') ?: 'Untitled appointment',
            'starts_at' => $start, 'ends_at' => $end, 'all_day' => $allDay,
            'url' => $safeUrl ? $url : null,
            'timezone' => in_array($item['start']['timeZone'] ?? '', timezone_identifiers_list(), true) ? $item['start']['timeZone'] : null,
            'customer_name' => is_string($customer['customer_name'] ?? null) ? mb_substr($customer['customer_name'], 0, 100) : null,
            'customer_email' => filter_var($customer['customer_email'] ?? null, FILTER_VALIDATE_EMAIL) ? $customer['customer_email'] : null,
            'appointment_id' => is_string($appointmentId) && Str::isUuid($appointmentId) ? $appointmentId : null,
        ];
    }
}
