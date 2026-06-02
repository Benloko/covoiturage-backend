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
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('vehicle_plate');
            $table->string('identity_verification_status', 20)->default('not_started')->after('avatar_path');
            $table->timestamp('identity_verification_requested_at')->nullable()->after('identity_verification_status');
            $table->timestamp('identity_verified_at')->nullable()->after('identity_verification_requested_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'avatar_path',
                'identity_verification_status',
                'identity_verification_requested_at',
                'identity_verified_at',
            ]);
        });
    }
};
