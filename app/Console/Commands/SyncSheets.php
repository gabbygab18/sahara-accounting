<?php

namespace App\Console\Commands;

use App\Services\SheetSync;
use Illuminate\Console\Command;

class SyncSheets extends Command
{
    protected $signature = 'sheets:sync';

    protected $description = 'Two-way sync of records with the master Google Sheet';

    public function handle(): int
    {
        if (! $sync = SheetSync::make()) {
            $this->warn('Google Sheets is not connected yet (Settings → Google Sheets).');

            return self::SUCCESS;
        }

        $log = $sync->run();
        if ($log->error) {
            $this->error($log->error);

            return self::FAILURE;
        }
        $this->info("Added {$log->added}, updated {$log->updated}, deleted {$log->deleted}, wrote {$log->pushed} to the sheet.");

        return self::SUCCESS;
    }
}
