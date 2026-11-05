<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The client's layout: GROUP (company), COMPANY (sold to on the SI), PROJECT, and a PO date.
// The Google Sheet moves to one tab per group, so every record is written to it again.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->string('sold_to')->nullable()->after('company');
            $table->date('po_date')->nullable()->after('tin');
        });
        DB::table('records')->update(['pending_sync' => true]);
    }

    public function down(): void
    {
        Schema::table('records', fn (Blueprint $table) => $table->dropColumn(['sold_to', 'po_date']));
    }
};
