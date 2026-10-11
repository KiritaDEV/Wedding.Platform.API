<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('websites')->where('schema_version', '<', 3)->orderBy('id')->chunkById(100, function ($websites): void {
                foreach ($websites as $website) {
                    $settings = $this->decodeSettings($website->id, $website->design_settings);
                    $settings['projectDefaults'] = new stdClass;
                    DB::table('websites')->where('id', $website->id)->update([
                        'design_settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                        'schema_version' => 3,
                    ]);
                }
            });
        });
    }

    public function down(): void
    {
        DB::table('websites')->where('schema_version', 3)->orderBy('id')->chunkById(100, function ($websites): void {
            foreach ($websites as $website) {
                $settings = $this->decodeSettings($website->id, $website->design_settings);
                $overrides = $settings['projectDefaults'] ?? [];
                if (array_key_exists('projectDefaults', $settings)
                    && (! is_array($overrides) && ! is_object($overrides) || count((array) $overrides) > 0)) {
                    throw new RuntimeException("Cannot roll back Website [{$website->id}] because it has persisted Project Design Default overrides.");
                }
            }
        });

        DB::transaction(function (): void {
            DB::table('websites')->where('schema_version', 3)->orderBy('id')->chunkById(100, function ($websites): void {
                foreach ($websites as $website) {
                    $settings = $this->decodeSettings($website->id, $website->design_settings);
                    unset($settings['projectDefaults']);
                    DB::table('websites')->where('id', $website->id)->update([
                        'design_settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                        'schema_version' => 2,
                    ]);
                }
            });
        });
    }

    /** @return array<string, mixed> */
    private function decodeSettings(string $websiteId, string $json): array
    {
        $settings = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($settings)) {
            throw new RuntimeException("Website [{$websiteId}] has invalid Design Settings JSON.");
        }

        return $settings;
    }
};
