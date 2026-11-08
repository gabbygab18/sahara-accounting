<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** An SI whose amount was computed wrong: flagged for the agent to recheck. */
    public function up(): void
    {
        Schema::table('records', fn (Blueprint $table) => $table->boolean('wrong_computation')->default(false)->after('si_cancelled'));
    }

    public function down(): void
    {
        Schema::table('records', fn (Blueprint $table) => $table->dropColumn('wrong_computation'));
    }
};
