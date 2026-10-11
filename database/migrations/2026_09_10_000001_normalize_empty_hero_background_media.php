<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('website_sections')
            ->where('type', 'hero')
            ->orderBy('id')
            ->chunkById(250, function ($sections): void {
                foreach ($sections as $section) {
                    $appearance = json_decode($section->appearance, true);
                    if (! is_array($appearance) || ($appearance['shared']['backgroundMedia'] ?? null) !== '') {
                        continue;
                    }

                    unset($appearance['shared']['backgroundMedia']);
                    DB::table('website_sections')->where('id', $section->id)->update([
                        'appearance' => json_encode($appearance, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // Empty-string media was invalid and is intentionally not restored.
    }
};
