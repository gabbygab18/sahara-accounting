<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** cash: paid in cash, not by check (check_date then holds the day the cash came in). si_missing: the paper SI can't be found. */
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->boolean('cash')->default(false)->after('paid_confirmed');
            $table->boolean('si_missing')->default(false)->after('cash');
        });
    }

    public function down(): void
    {
        Schema::table('records', fn (Blueprint $table) => $table->dropColumn(['cash', 'si_missing']));
    }
};
