<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Before the "Cancelled SI" box, a cancelled SI was entered with "CANCELLED" as its company or project, so it
     * still counted as unpaid. Flag those (and rewrite their sheet rows, where the status becomes "Cancelled").
     */
    public function up(): void
    {
        DB::table('records')->where('si_cancelled', false)
            ->where(fn ($q) => $q->whereAny(['sold_to', 'project'], 'like', 'cancelled%')->orWhereAny(['sold_to', 'project'], 'like', 'canceled%'))
            ->update(['si_cancelled' => true, 'pending_sync' => true]);
    }

    public function down(): void
    {
        // Left flagged: the box can be unticked per record.
    }
};
