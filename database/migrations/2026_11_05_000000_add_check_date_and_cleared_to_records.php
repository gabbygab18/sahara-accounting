<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The check's date (when it can be deposited) and whether it has cleared. New sheet columns, so every row is written again.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->date('check_date')->nullable()->after('check_number');
            $table->boolean('check_cleared')->default(false)->after('check_date');
        });
        DB::table('records')->update(['pending_sync' => true]);
    }

    public function down(): void
    {
        Schema::table('records', fn (Blueprint $table) => $table->dropColumn(['check_date', 'check_cleared']));
    }
};
