<?php

namespace App\Console\Commands;

use App\Actions\Websites\InitializeWebsiteSections;
use App\Models\Website;
use Illuminate\Console\Command;

class SyncWebsiteSections extends Command
{
    protected $signature = 'websites:sync-sections';

    protected $description = 'Add missing canonical Sections to existing Websites without changing existing Sections';

    public function handle(InitializeWebsiteSections $initialize): int
    {
        $count = 0;
        Website::query()->with('event')->orderBy('id')->chunkById(100, function ($websites) use ($initialize, &$count): void {
            foreach ($websites as $website) {
                $initialize->handle($website, enableMissingSections: false);
                $count++;
            }
        });

        $this->info("Synchronized {$count} Website(s). Missing Sections were added disabled.");

        return self::SUCCESS;
    }
}
