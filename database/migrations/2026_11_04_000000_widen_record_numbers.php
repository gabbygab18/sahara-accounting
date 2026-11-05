<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PO, DR and SI can each hold several numbers, comma separated ("1001, 1002").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            foreach (['po_number', 'dr_number', 'si_number'] as $column) {
                $table->string($column, 255)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            foreach (['po_number', 'dr_number', 'si_number'] as $column) {
                $table->string($column, 100)->nullable()->change();
            }
        });
    }
};
