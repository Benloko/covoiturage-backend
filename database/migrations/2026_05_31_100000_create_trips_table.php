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
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->string('from_city', 120);
            $table->string('from_label', 255)->nullable();
            $table->string('to_city', 120);
            $table->string('to_label', 255)->nullable();
            $table->dateTime('departure_at');
            $table->dateTime('arrival_at')->nullable();
            $table->unsignedInteger('price_fcfa');
            $table->string('vehicle_type', 50)->nullable();
            $table->string('vehicle_model', 80)->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->unsignedSmallInteger('seats_total')->default(4);
            $table->unsignedSmallInteger('seats_available')->default(4);
            $table->timestamps();

            $table->index(['status', 'departure_at']);
            $table->index(['from_city', 'to_city']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};

