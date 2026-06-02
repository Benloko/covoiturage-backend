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
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('document_number', 80)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('document_front_path');
            $table->string('document_back_path')->nullable();
            $table->string('selfie_path');
            $table->string('liveness_video_path');
            $table->string('liveness_check_status', 20)->default('pending');
            $table->decimal('liveness_score', 5, 2)->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
