<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('driver_deposits', function (Blueprint $table): void {
            $table->string('provider', 30)->default('internal')->after('status');
            $table->string('provider_transaction_id', 120)->nullable()->after('provider');
            $table->string('provider_reference', 120)->nullable()->after('provider_transaction_id');
            $table->string('provider_status', 40)->nullable()->after('provider_reference');
            $table->text('provider_failure_reason')->nullable()->after('provider_status');
            $table->json('provider_payload')->nullable()->after('provider_failure_reason');
            $table->timestamp('provider_last_webhook_at')->nullable()->after('provider_payload');

            $table->index(['provider', 'provider_transaction_id'], 'driver_deposits_provider_txn_idx');
            $table->index(['provider', 'provider_reference'], 'driver_deposits_provider_ref_idx');
        });

        Schema::table('driver_withdrawals', function (Blueprint $table): void {
            $table->string('provider', 30)->default('internal')->after('status');
            $table->string('provider_transaction_id', 120)->nullable()->after('provider');
            $table->string('provider_reference', 120)->nullable()->after('provider_transaction_id');
            $table->string('provider_status', 40)->nullable()->after('provider_reference');
            $table->text('provider_failure_reason')->nullable()->after('provider_status');
            $table->json('provider_payload')->nullable()->after('provider_failure_reason');
            $table->timestamp('provider_last_webhook_at')->nullable()->after('provider_payload');

            $table->index(['provider', 'provider_transaction_id'], 'driver_withdrawals_provider_txn_idx');
            $table->index(['provider', 'provider_reference'], 'driver_withdrawals_provider_ref_idx');
        });

        Schema::table('passenger_deposits', function (Blueprint $table): void {
            $table->string('provider', 30)->default('internal')->after('status');
            $table->string('provider_transaction_id', 120)->nullable()->after('provider');
            $table->string('provider_reference', 120)->nullable()->after('provider_transaction_id');
            $table->string('provider_status', 40)->nullable()->after('provider_reference');
            $table->text('provider_failure_reason')->nullable()->after('provider_status');
            $table->json('provider_payload')->nullable()->after('provider_failure_reason');
            $table->timestamp('provider_last_webhook_at')->nullable()->after('provider_payload');

            $table->index(['provider', 'provider_transaction_id'], 'passenger_deposits_provider_txn_idx');
            $table->index(['provider', 'provider_reference'], 'passenger_deposits_provider_ref_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_deposits', function (Blueprint $table): void {
            $table->dropIndex('driver_deposits_provider_txn_idx');
            $table->dropIndex('driver_deposits_provider_ref_idx');

            $table->dropColumn([
                'provider',
                'provider_transaction_id',
                'provider_reference',
                'provider_status',
                'provider_failure_reason',
                'provider_payload',
                'provider_last_webhook_at',
            ]);
        });

        Schema::table('driver_withdrawals', function (Blueprint $table): void {
            $table->dropIndex('driver_withdrawals_provider_txn_idx');
            $table->dropIndex('driver_withdrawals_provider_ref_idx');

            $table->dropColumn([
                'provider',
                'provider_transaction_id',
                'provider_reference',
                'provider_status',
                'provider_failure_reason',
                'provider_payload',
                'provider_last_webhook_at',
            ]);
        });

        Schema::table('passenger_deposits', function (Blueprint $table): void {
            $table->dropIndex('passenger_deposits_provider_txn_idx');
            $table->dropIndex('passenger_deposits_provider_ref_idx');

            $table->dropColumn([
                'provider',
                'provider_transaction_id',
                'provider_reference',
                'provider_status',
                'provider_failure_reason',
                'provider_payload',
                'provider_last_webhook_at',
            ]);
        });
    }
};
