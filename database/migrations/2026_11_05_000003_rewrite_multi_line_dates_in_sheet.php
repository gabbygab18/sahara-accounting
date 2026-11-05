<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Records with several DRs or SIs: write their rows again so their dates show as 03/10/2026, like the rest of the sheet.
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('records')->get(['id', 'drs', 'sis']) as $r) {
            if (count(json_decode($r->drs ?? '[]', true) ?: []) > 1 || count(json_decode($r->sis ?? '[]', true) ?: []) > 1) {
                DB::table('records')->where('id', $r->id)->update(['pending_sync' => true]);
            }
        }
    }

    public function down(): void {}
};
