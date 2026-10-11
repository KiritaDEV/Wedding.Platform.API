<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const SECTION_COLUMNS = [
        'id',
        'website_id',
        'type',
        'sort_order',
        'is_enabled',
        'content',
        'appearance',
        'created_at',
        'updated_at',
    ];

    private const EVENT_ID_INDEX = 'websites_event_id_index';

    private const EVENT_ID_UNIQUE = 'websites_event_id_unique';

    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('event_id');
        });

        DB::table('websites')->whereNull('name')->update(['name' => 'Website']);

        if (DB::table('websites')->whereNull('name')->exists()) {
            throw new RuntimeException('Every Website Project must have a name before the column becomes non-null.');
        }

        $this->alterWebsitesPreservingSections(function (): void {
            Schema::table('websites', function (Blueprint $table) {
                $table->string('name', 100)->nullable(false)->change();
            });
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->index('event_id', self::EVENT_ID_INDEX);
        });

        $this->alterWebsitesPreservingSections(function (): void {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropUnique(self::EVENT_ID_UNIQUE);
            });
        });
    }

    public function down(): void
    {
        $duplicateEventId = DB::table('websites')
            ->select('event_id')
            ->groupBy('event_id')
            ->havingRaw('COUNT(*) > 1')
            ->value('event_id');

        if ($duplicateEventId !== null) {
            throw new RuntimeException(
                "Cannot restore one Website per Event because Event [{$duplicateEventId}] has multiple Website Projects.",
            );
        }

        Schema::table('websites', function (Blueprint $table) {
            $table->unique('event_id', self::EVENT_ID_UNIQUE);
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->dropIndex(self::EVENT_ID_INDEX);
        });

        $this->alterWebsitesPreservingSections(function (): void {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        });
    }

    private function alterWebsitesPreservingSections(Closure $alter): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite' || ! Schema::hasTable('website_sections')) {
            $alter();

            return;
        }

        $backup = 'website_sections_p11_backup';
        if (Schema::hasTable($backup)) {
            throw new RuntimeException("Cannot alter Websites while recovery table [{$backup}] already exists.");
        }

        Schema::create($backup, function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('website_id');
            $table->string('type');
            $table->unsignedInteger('sort_order');
            $table->boolean('is_enabled');
            $table->json('content');
            $table->json('appearance');
            $table->timestamps();
        });

        try {
            DB::table($backup)->insertUsing(
                self::SECTION_COLUMNS,
                DB::table('website_sections')->select(self::SECTION_COLUMNS),
            );

            $alter();

            $this->restoreMissingSections($backup);
            Schema::drop($backup);
        } catch (Throwable $exception) {
            try {
                $this->restoreMissingSections($backup);
            } catch (Throwable) {
                // Preserve the backup and rethrow the original migration failure.
            }

            throw $exception;
        }
    }

    private function restoreMissingSections(string $backup): void
    {
        DB::table('website_sections')->insertOrIgnoreUsing(
            self::SECTION_COLUMNS,
            DB::table($backup)->select(self::SECTION_COLUMNS),
        );

        $missingSectionExists = DB::table($backup)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('website_sections')
                    ->whereColumn('website_sections.id', 'website_sections_p11_backup.id');
            })
            ->exists();

        if ($missingSectionExists) {
            throw new RuntimeException('Unable to restore every Website Section from the SQLite recovery table.');
        }
    }
};
