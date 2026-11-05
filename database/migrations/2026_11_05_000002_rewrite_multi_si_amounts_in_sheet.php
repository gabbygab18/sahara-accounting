<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Records with several SIs: write their rows again so the SI amounts show as ₱1,000.00 in the sheet.
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('records')->whereNotNull('sis')->get(['id', 'sis']) as $r) {
            if (count(json_decode($r->sis, true) ?: []) > 1) {
                DB::table('records')->where('id', $r->id)->update(['pending_sync' => true]);
            }
        }
    }

    public function down(): void {}
};
