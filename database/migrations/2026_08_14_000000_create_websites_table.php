<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        DB::table('events')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('websites')
                    ->whereColumn('websites.event_id', 'events.id');
            })
            ->orderBy('id')
            ->chunk(500, function ($events): void {
                $timestamp = now();

                DB::table('websites')->insert($events->map(fn ($event) => [
                    'id' => (string) Str::ulid(),
                    'event_id' => $event->id,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
