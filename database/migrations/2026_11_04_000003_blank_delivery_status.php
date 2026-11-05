<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Delivery status starts blank (the client never chose "Pending"); the choices are Not delivered, Partial, Delivered.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', fn (Blueprint $table) => $table->string('delivery_status', 50)->default('')->change());
        DB::table('records')->where('delivery_status', 'Pending')->update(['delivery_status' => '', 'pending_sync' => true]);
    }

    public function down(): void
    {
        Schema::table('records', fn (Blueprint $table) => $table->string('delivery_status', 50)->default('Pending')->change());
    }
};
