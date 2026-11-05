<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A preset (admin / staff) or custom; `permissions` holds the ticked boxes (User::PERMISSIONS).
            $table->string('role', 20)->default('staff')->after('email');
            $table->json('permissions')->nullable()->after('role');
            // Deactivated accounts cannot log in but keep their name on what they did.
            $table->timestamp('disabled_at')->nullable()->after('permissions');
        });

        // One sales invoice / delivery, as on the client's tracker and the master Google Sheet.
        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->string('company')->default('')->index(); // main company, e.g. Cityland Dev Corp
            $table->string('project')->nullable();          // "Sold to" on the invoice, e.g. Pines Peak Tower
            $table->string('tin', 50)->nullable();
            $table->string('po_number', 100)->nullable();
            $table->unsignedSmallInteger('terms_days')->nullable(); // 30 = 30 days, 0 = COD
            $table->date('dr_date')->nullable();
            $table->string('dr_number', 100)->nullable();
            $table->string('delivery_status', 50)->default('Pending');
            $table->date('si_date')->nullable()->index();
            $table->string('si_number', 100)->nullable();
            $table->date('or_date')->nullable();
            $table->string('or_number', 100)->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->boolean('paid')->default(false)->index();
            $table->string('check_number', 100)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Sheet sync: synced_at null = never written to the sheet; pending = changed in the app since.
            $table->timestamp('synced_at')->nullable();
            $table->boolean('pending_sync')->default(true);
            $table->softDeletes(); // deleted in the app, row still to be removed from the sheet
            $table->timestamps();
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = Google Sheet
            $table->unsignedBigInteger('record_id')->nullable()->index();
            $table->string('action', 20); // added | edited | deleted
            $table->string('summary');
            $table->json('changes')->nullable(); // field => [old, new]
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('deleted')->default(0);
            $table->unsignedInteger('pushed')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('records');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'permissions', 'disabled_at']));
    }
};
