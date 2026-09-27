<?php

namespace App\Exceptions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * The last word on a value the database will not take twice.
 *
 * Every screen checks the uniqueness it can before saving, so reaching this is
 * either a race — two people typing the same site code at the same moment — or
 * a path that was missed. Neither deserves a stack trace: the person is told,
 * in a sentence, which value is already taken, on the field that holds it where
 * the field can be worked out.
 */
class DuplicateValue
{
    /** The column the database complained about, when its message names one. */
    public static function column(UniqueConstraintViolationException $exception, ?string $table = null): ?string
    {
        $message = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();

        // SQLite: "UNIQUE constraint failed: sites.code, sites.name"
        if (preg_match('/UNIQUE constraint failed: (.+)$/', $message, $matches)) {
            $columns = array_map(fn (string $column): string => trim(last(explode('.', trim($column)))), explode(',', $matches[1]));

            return $columns[0] ?: null;
        }

        // MySQL: "Duplicate entry 'Cairo' for key 'sites_code_unique'", and the
        // key is the table name, the column(s) and "unique" joined by
        // underscores — which only a table name can be peeled off reliably.
        if (preg_match("/for key '([^']+)'/", $message, $matches)) {
            $key = last(explode('.', $matches[1]));
            $key = (string) preg_replace('/_unique$/', '', $key);
            $table ??= self::table($exception);

            if ($table !== null && str_starts_with($key, $table.'_')) {
                $key = substr($key, strlen($table) + 1);
            }

            return $key !== '' ? $key : null;
        }

        return null;
    }

    /** The value that is already taken, when the message quotes it. */
    public static function value(UniqueConstraintViolationException $exception): ?string
    {
        $message = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();

        return preg_match("/Duplicate entry '([^']*)'/", $message, $matches) ? $matches[1] : null;
    }

    /** The table being written to, read off the statement. */
    public static function table(UniqueConstraintViolationException $exception): ?string
    {
        return preg_match('/^\s*(?:insert into|update|replace into)\s+[`"]?([a-z0-9_]+)[`"]?/i', $exception->getSql(), $matches)
            ? mb_strtolower($matches[1])
            : null;
    }

    /**
     * The same refusal as a validation error, so a form shows it on the field
     * and every other screen shows it as a message.
     */
    public static function asValidation(UniqueConstraintViolationException $exception): ValidationException
    {
        $column = self::column($exception);
        $value = self::value($exception);
        $label = $column !== null ? str_replace('_', ' ', $column) : 'value';

        $message = $value !== null && $value !== ''
            ? "“{$value}” is already used by another record, and the {$label} has to be unique. Give a different one."
            : "That {$label} is already used by another record, and it has to be unique. Give a different one.";

        // Both keys: forms keep their state under "data", and anything else
        // reads the plain field name.
        $keys = $column !== null ? ["data.{$column}", $column] : ['data', 'unique'];

        return ValidationException::withMessages(array_fill_keys($keys, $message));
    }
}
