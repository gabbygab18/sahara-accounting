<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Where the paper is filed (booklet, envelope), and an SI that was cancelled (kept so its number is accounted for). */
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->string('booklet_number', 50)->nullable()->after('or_number');
            $table->string('envelope_number', 50)->nullable()->after('booklet_number');
            $table->boolean('si_cancelled')->default(false)->after('si_missing');
        });
    }

    public function down(): void
    {
        Schema::table('records', fn (Blueprint $table) => $table->dropColumn(['booklet_number', 'envelope_number', 'si_cancelled']));
    }
};
