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
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('driver_completed_at')->nullable()->after('cancelled_at');
            $table->string('passenger_completion_status', 20)->nullable()->after('driver_completed_at');
            $table->timestamp('passenger_completion_confirmed_at')->nullable()->after('passenger_completion_status');

            $table->string('payout_status', 30)->default('not_ready')->after('passenger_completion_confirmed_at');
            $table->unsignedInteger('payout_amount_fcfa')->nullable()->after('payout_status');
            $table->timestamp('payout_credited_at')->nullable()->after('payout_amount_fcfa');

            $table->index(['passenger_id', 'passenger_completion_status'], 'bookings_passenger_completion_status_idx');
            $table->index(['payout_status'], 'bookings_payout_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_passenger_completion_status_idx');
            $table->dropIndex('bookings_payout_status_idx');

            $table->dropColumn([
                'driver_completed_at',
                'passenger_completion_status',
                'passenger_completion_confirmed_at',
                'payout_status',
                'payout_amount_fcfa',
                'payout_credited_at',
            ]);
        });
    }
};

