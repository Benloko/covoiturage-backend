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
        Schema::table('identity_verifications', function (Blueprint $table) {
            $table->string('face_match_status', 20)->nullable()->after('liveness_score');
            $table->decimal('face_match_score', 5, 2)->nullable()->after('face_match_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            $table->dropColumn([
                'face_match_status',
                'face_match_score',
            ]);
        });
    }
};

