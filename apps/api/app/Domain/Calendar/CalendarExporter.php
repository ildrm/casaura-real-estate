<?php

namespace App\Domain\Calendar;

interface CalendarExporter
{
    /** @param array<string, mixed> $viewing */
    public function export(array $viewing): string;
}
