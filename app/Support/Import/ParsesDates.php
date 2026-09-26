<?php

namespace App\Support\Import;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Dates however a spreadsheet wrote them: ISO, day-first with slashes, dashes
 * or dots, with a month name, a datetime, or an Excel serial number from a
 * sheet whose date cells lost their format. Day before month where it is
 * ambiguous, as the company's own exports write it.
 */
trait ParsesDates
{
    /** @return list<string> */
    protected function dateFormats(): array
    {
        return ['Y-m-d', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'd.m.Y', 'j.n.Y', 'd-M-Y', 'd M Y', 'j M Y', 'd-M-y', 'M d, Y', 'Y/m/d'];
    }

    public function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        if (ctype_digit($value) && (int) $value > 20000 && (int) $value < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->startOfDay();
        }

        // A timestamp from a datetime cell: the date is what matters.
        $value = (string) preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?.*$/', '', $value);

        foreach ($this->dateFormats() as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date && $date->format($format) === $value && $date->year >= 1900 && $date->year <= 2100) {
                return $date;
            }
        }

        return null;
    }

    /** A date cell checked on the row: null when empty, an error when unreadable. */
    protected function dateField(string $value, string $label, RowCheck $check): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        $date = $this->parseDate($value);

        if (! $date) {
            $check->error("{$label} \"{$value}\" is not a date. Write it as YYYY-MM-DD or DD/MM/YYYY.");
        }

        return $date;
    }
}
