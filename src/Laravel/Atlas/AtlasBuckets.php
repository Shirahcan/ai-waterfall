<?php

namespace Shirahcan\AiClient\Laravel\Atlas;

use Illuminate\Support\Facades\DB;

/**
 * Shared, Atlas-authored SQL for dimensions a raw column cannot answer: a timestamp bucketed by
 * month, and a user's primary role. Never plan input, so the identifiers are fixed here.
 *
 * ⚠ BOTH WERE "FUTURE WORK" FOR MONTHS, AND REAL QUESTIONS HIT THEM. An admin asked "how many users
 * are on the platform, grouped by roles" and Porter could say only "155 in total, 61 consultants";
 * "signups per month" had no answer at all (2026-10-30 audit).
 */
final class AtlasBuckets
{
    /** `2026-10`: the month a timestamp falls in, on MySQL and on the SQLite test database. */
    public static function month(string $qualifiedColumn): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$qualifiedColumn})"
            : "DATE_FORMAT({$qualifiedColumn}, '%Y-%m')";
    }

    /**
     * Whole days from a date to today (negative while it is still ahead), on MySQL/MariaDB and SQLite.
     *
     * ⚠ COMPUTED HERE SO THE MODEL NEVER DOES DATE MATH. Handed "due 2026-09-15" on 2026-10-30, Porter
     * called five past deadlines "in the future" and told an admin the overdue flag looked stale.
     */
    public static function daysSince(string $qualifiedColumn): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(julianday(date('now')) - julianday(date({$qualifiedColumn})) AS INTEGER)"
            : "DATEDIFF(CURRENT_DATE, {$qualifiedColumn})";
    }

    /** Whole hours from one timestamp to another (null when either is empty), on MySQL/MariaDB and SQLite. */
    public static function hoursBetween(string $fromColumn, string $toColumn): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST((julianday({$toColumn}) - julianday({$fromColumn})) * 24 AS INTEGER)"
            : "TIMESTAMPDIFF(HOUR, {$fromColumn}, {$toColumn})";
    }

    /** Whole days between two datetime expressions (either may be CURRENT_TIMESTAMP), on MySQL/MariaDB and SQLite. */
    public static function daysBetween(string $fromColumn, string $toColumn): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(julianday({$toColumn}) - julianday({$fromColumn}) AS INTEGER)"
            : "TIMESTAMPDIFF(DAY, {$fromColumn}, {$toColumn})";
    }

    /** One text value out of a JSON column (`$.displayName`), on MySQL/MariaDB and on SQLite. */
    public static function jsonText(string $qualifiedColumn, string $path): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "json_extract({$qualifiedColumn}, '{$path}')"
            : "JSON_UNQUOTE(JSON_EXTRACT({$qualifiedColumn}, '{$path}'))";
    }

    /** The primary-role values {@see primaryRole()} produces, most senior first. */
    public const ROLES = ['admin', 'team_member', 'consultant', 'employer', 'partner', 'client', 'other'];

    /**
     * A user's PRIMARY role, so a count by role adds up to the number of users.
     *
     * ⚠ `users.roles` IS A JSON ARRAY AND EVERY ROLE IS STORED TWICE (`client` and `portify:client`),
     * and a person may hold several. Grouping the raw values would count most users twice and some
     * three times. Each user is counted once, under their most senior role.
     */
    public static function primaryRole(string $rolesColumn = 'users.roles'): string
    {
        $has = static fn (string ...$roles): string => '('.implode(' OR ', array_map(
            static fn (string $r) => "{$rolesColumn} LIKE '%\"{$r}\"%' OR {$rolesColumn} LIKE '%:{$r}\"%'",
            $roles,
        )).')';

        return 'CASE'
            .' WHEN '.$has('super-admin', 'admin', 'service-admin').' THEN \'admin\''
            .' WHEN '.$has('team_member').' THEN \'team_member\''
            .' WHEN '.$has('consultant').' THEN \'consultant\''
            .' WHEN '.$has('employer', 'employer_contact').' THEN \'employer\''
            .' WHEN '.$has('partner').' THEN \'partner\''
            .' WHEN '.$has('client').' THEN \'client\''
            ." ELSE 'other' END";
    }
}
