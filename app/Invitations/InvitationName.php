<?php

namespace App\Invitations;

use App\Models\Invitation;

final class InvitationName
{
    public static function resolve(Invitation $invitation): string
    {
        $customName = NameNormalizer::nullableDisplay($invitation->custom_name);
        if ($customName !== null) {
            return $customName;
        }

        $guestNames = $invitation->guests
            ->sortBy([
                ['created_at', 'asc'],
                ['id', 'asc'],
            ])
            ->map(fn ($guest): string => $guest->fullName())
            ->values();

        return match ($guestNames->count()) {
            0 => '',
            1 => $guestNames[0],
            2 => $guestNames[0].' & '.$guestNames[1],
            default => $guestNames[0].', '.$guestNames[1].' + '.($guestNames->count() - 2).' '.($guestNames->count() === 3 ? 'guest' : 'guests'),
        };
    }

    public static function caseInsensitiveSortExpression(string $driver): string
    {
        $count = '(SELECT COUNT(*) FROM guests guest_count WHERE guest_count.invitation_id = invitations.id)';

        if ($driver === 'mysql') {
            $first = self::mysqlGuestNameAtOffset(0);
            $second = self::mysqlGuestNameAtOffset(1);
            $derived = "CASE WHEN {$count} = 1 THEN {$first} WHEN {$count} = 2 THEN CONCAT({$first}, ' & ', {$second}) WHEN {$count} >= 3 THEN CONCAT({$first}, ', ', {$second}, ' + ', {$count} - 2, CASE WHEN {$count} = 3 THEN ' guest' ELSE ' guests' END) ELSE '' END";
        } else {
            $first = self::sqliteGuestNameAtOffset(0);
            $second = self::sqliteGuestNameAtOffset(1);
            $derived = "CASE WHEN {$count} = 1 THEN {$first} WHEN {$count} = 2 THEN {$first} || ' & ' || {$second} WHEN {$count} >= 3 THEN {$first} || ', ' || {$second} || ' + ' || ({$count} - 2) || CASE WHEN {$count} = 3 THEN ' guest' ELSE ' guests' END ELSE '' END";
        }

        return "LOWER(COALESCE(NULLIF(TRIM(invitations.custom_name), ''), {$derived}, ''))";
    }

    private static function mysqlGuestNameAtOffset(int $offset): string
    {
        return "(SELECT TRIM(CONCAT(g.first_name, ' ', COALESCE(g.last_name, ''))) FROM guests g WHERE g.invitation_id = invitations.id ORDER BY g.created_at, g.id LIMIT 1 OFFSET {$offset})";
    }

    private static function sqliteGuestNameAtOffset(int $offset): string
    {
        return "(SELECT TRIM(g.first_name || ' ' || COALESCE(g.last_name, '')) FROM guests g WHERE g.invitation_id = invitations.id ORDER BY g.created_at, g.id LIMIT 1 OFFSET {$offset})";
    }
}
