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
            $table->unsignedTinyInteger('passenger_rating')->nullable()->after('cancelled_at');
            $table->text('passenger_review')->nullable()->after('passenger_rating');
            $table->timestamp('rated_at')->nullable()->after('passenger_review');

            $table->index(['passenger_id', 'passenger_rating']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['passenger_id', 'passenger_rating']);
            $table->dropColumn(['passenger_rating', 'passenger_review', 'rated_at']);
        });
    }
};
