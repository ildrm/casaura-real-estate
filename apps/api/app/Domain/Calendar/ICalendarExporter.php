<?php

namespace App\Domain\Calendar;

use Illuminate\Support\Carbon;

final class ICalendarExporter implements CalendarExporter
{
    public function export(array $viewing): string
    {
        $format = fn (mixed $value) => Carbon::parse($value)->utc()->format('Ymd\THis\Z');

        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Casaura//Viewings//EN',
            'CALSCALE:GREGORIAN', 'BEGIN:VEVENT', 'UID:'.$viewing['id'].'@casaura',
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$format($viewing['starts_at']), 'DTEND:'.$format($viewing['ends_at']),
            'SUMMARY:Property viewing', 'DESCRIPTION:Scheduled through Casaura',
            'END:VEVENT', 'END:VCALENDAR', '',
        ]);
    }
}
