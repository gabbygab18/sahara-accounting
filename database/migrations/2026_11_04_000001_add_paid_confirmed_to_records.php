<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Paid by the agent is "for checking" until the admin confirms it (double check against the bank / OR).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->boolean('paid_confirmed')->default(false)->after('paid');
        });
        DB::table('records')->where('paid', true)->update(['paid_confirmed' => true]); // already paid before this existed
    }

    public function down(): void
    {
        Schema::table('records', fn (Blueprint $table) => $table->dropColumn('paid_confirmed'));
    }
};
