<?php

use App\Website\WebsiteSchema;
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

    private const SQLITE_BACKUP = 'website_sections_b1_backup';

    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->unsignedSmallInteger('schema_version')
                ->default(WebsiteSchema::LEGACY_SCHEMA_VERSION)
                ->after('design_settings');
        });
    }

    public function down(): void
    {
        $this->alterWebsitesPreservingSections(function (): void {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropColumn('schema_version');
            });
        });
    }

    private function alterWebsitesPreservingSections(Closure $alter): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite' || ! Schema::hasTable('website_sections')) {
            $alter();

            return;
        }

        if (Schema::hasTable(self::SQLITE_BACKUP)) {
            throw new RuntimeException('Cannot alter Websites while the B1 Website Section recovery table already exists.');
        }

        Schema::create(self::SQLITE_BACKUP, function (Blueprint $table) {
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
            DB::table(self::SQLITE_BACKUP)->insertUsing(
                self::SECTION_COLUMNS,
                DB::table('website_sections')->select(self::SECTION_COLUMNS),
            );

            $alter();

            $this->restoreMissingSections();
            Schema::drop(self::SQLITE_BACKUP);
        } catch (Throwable $exception) {
            try {
                $this->restoreMissingSections();
            } catch (Throwable) {
                // Preserve the backup and rethrow the original migration failure.
            }

            throw $exception;
        }
    }

    private function restoreMissingSections(): void
    {
        DB::table('website_sections')->insertOrIgnoreUsing(
            self::SECTION_COLUMNS,
            DB::table(self::SQLITE_BACKUP)->select(self::SECTION_COLUMNS),
        );

        $missingSectionExists = DB::table(self::SQLITE_BACKUP)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('website_sections')
                    ->whereColumn('website_sections.id', self::SQLITE_BACKUP.'.id');
            })
            ->exists();

        if ($missingSectionExists) {
            throw new RuntimeException('Unable to restore every Website Section from the B1 SQLite recovery table.');
        }
    }
};
