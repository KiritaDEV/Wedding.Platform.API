<?php

namespace App\Invitations;

final class BuiltinWeddingRoles
{
    /** @return array<string, array{id: string, name: string}> */
    public static function all(): array
    {
        return [
            'principal_sponsor' => ['id' => '01K1R1TA000000000000000001', 'name' => 'Principal Sponsor'],
            'maid_of_honor' => ['id' => '01K1R1TA000000000000000002', 'name' => 'Maid of Honor'],
            'matron_of_honor' => ['id' => '01K1R1TA000000000000000003', 'name' => 'Matron of Honor'],
            'best_man' => ['id' => '01K1R1TA000000000000000004', 'name' => 'Best Man'],
            'bridesmaid' => ['id' => '01K1R1TA000000000000000005', 'name' => 'Bridesmaid'],
            'groomsman' => ['id' => '01K1R1TA000000000000000006', 'name' => 'Groomsman'],
            'candle_sponsor' => ['id' => '01K1R1TA000000000000000007', 'name' => 'Candle Sponsor'],
            'veil_sponsor' => ['id' => '01K1R1TA000000000000000008', 'name' => 'Veil Sponsor'],
            'cord_sponsor' => ['id' => '01K1R1TA000000000000000009', 'name' => 'Cord Sponsor'],
            'ring_bearer' => ['id' => '01K1R1TA00000000000000000A', 'name' => 'Ring Bearer'],
            'coin_bearer' => ['id' => '01K1R1TA00000000000000000B', 'name' => 'Coin Bearer'],
            'bible_bearer' => ['id' => '01K1R1TA00000000000000000C', 'name' => 'Bible Bearer'],
            'flower_girl' => ['id' => '01K1R1TA00000000000000000D', 'name' => 'Flower Girl'],
        ];
    }
}
