<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Public sign-up is off; accounts are made here or on Settings → Users.
Artisan::command('user:create {name} {email} {--role=admin : admin or staff}', function (string $name, string $email) {
    if (! isset(User::PRESETS[$this->option('role')])) {
        return $this->error('Role must be admin or staff.');
    }
    $password = $this->secret('Password (min 8 characters)');
    if (strlen((string) $password) < 8) {
        return $this->error('Password must be at least 8 characters.');
    }

    User::forceCreate(['name' => $name, 'email' => $email, 'password' => $password, 'role' => $this->option('role'), 'email_verified_at' => now()]);
    $this->info("Created {$email}.");
})->purpose('Create a login');

// Picks up edits made in the Google Sheet. Locally run `php artisan schedule:work`; on hosting a
// once-a-minute cron can only run it every minute (the Sync Now button covers the gap).
Schedule::command('sheets:sync')->everyFifteenSeconds()->withoutOverlapping();
Schedule::command('records:backup')->dailyAt('01:00');

// Encodes the client's old Excel tracker (or brings back a backup). Records land in the app and go to the sheet on the next sync.
Artisan::command('records:import {file}', function (string $file) {
    $rows = \App\Support\RecordWorkbook::read($file);
    if (! $rows) {
        return $this->error('No header row with COMPANY / SI # / AMOUNT-like columns was found.');
    }
    \Illuminate\Support\Facades\DB::transaction(fn () => collect($rows)->each(fn ($fields) => \App\Models\Record::create($fields)));
    $this->info('Imported '.count($rows).' records.');
})->purpose('Import records from an Excel tracker');

// Daily copy of every record in storage/app/backups, the last 30 kept. Restore with records:import.
Artisan::command('records:backup', function () {
    $dir = storage_path('app/backups');
    \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
    \App\Support\RecordWorkbook::save(\App\Models\Record::orderBy('id')->lazy(), $path = "{$dir}/records-".now()->format('Y-m-d').'.xlsx');
    foreach (array_slice(array_reverse(glob("{$dir}/records-*.xlsx")), 30) as $old) {
        unlink($old);
    }
    $this->info("Saved {$path}");
})->purpose('Back up all records to Excel');
