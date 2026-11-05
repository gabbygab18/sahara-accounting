<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Each DR has its own date and delivery status, each SI its own date and amount. The old single columns stay,
// filled from the lines (first date, numbers joined, overall status, total), for search, filters and due dates.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->json('drs')->nullable()->after('po_number');
            $table->json('sis')->nullable()->after('drs');
        });

        $split = fn (?string $numbers) => array_values(array_filter(array_map('trim', explode(',', (string) $numbers)), 'strlen')) ?: [null];
        foreach (DB::table('records')->get() as $r) {
            $drs = array_map(fn ($n, $i) => ['date' => $i || ! $r->dr_date ? null : substr($r->dr_date, 0, 10), 'number' => $n, 'status' => $i || $r->delivery_status === '' ? null : $r->delivery_status], $split($r->dr_number), array_keys($split($r->dr_number)));
            $sis = array_map(fn ($n, $i) => ['date' => $i || ! $r->si_date ? null : substr($r->si_date, 0, 10), 'number' => $n, 'amount' => $i || ! (float) $r->amount ? null : (float) $r->amount], $split($r->si_number), array_keys($split($r->si_number)));
            $keep = fn ($lines) => array_values(array_filter($lines, fn ($l) => array_filter($l, fn ($v) => $v !== null)));
            DB::table('records')->where('id', $r->id)->update([
                'drs' => json_encode($keep($drs)),
                'sis' => json_encode($keep($sis)),
                'pending_sync' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('records', fn (Blueprint $table) => $table->dropColumn(['drs', 'sis']));
    }
};
