<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The day the invoice was countered (received by the client): the terms count from it. */
    public function up(): void
    {
        Schema::table('records', fn (Blueprint $table) => $table->date('counter_date')->nullable()->after('si_date'));
    }

    public function down(): void
    {
        Schema::table('records', fn (Blueprint $table) => $table->dropColumn('counter_date'));
    }
};
