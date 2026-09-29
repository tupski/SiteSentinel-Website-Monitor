<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunWebsiteCheck;
use App\Models\Website;
use Illuminate\Console\Command;

final class CheckWebsite extends Command
{
    protected $signature = 'sentinel:check-website {website}';

    protected $description = 'Dispatch a monitoring check for a website.';

    public function handle(): int
    {
        $website = Website::findOrFail($this->argument('website'));
        RunWebsiteCheck::dispatch($website);
        $this->info('Dispatched RunWebsiteCheck for website '.$website->id);

        return self::SUCCESS;
    }
}
