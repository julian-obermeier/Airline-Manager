<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airline_airport_stations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airport_id')->constrained('airports')->restrictOnDelete();
            $table->string('station_type', 24)->default('outstation');
            $table->string('status', 24)->default('active');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('monthly_cost_minor');
            $table->char('currency', 3)->default('EUR');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['airline_id', 'airport_id'], 'airline_station_airport_uq');
            $table->index(['world_id', 'airline_id', 'status'], 'station_world_airline_status_idx');
        });

        Schema::create('airport_slot_reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airport_id')->constrained('airports')->restrictOnDelete();
            $table->foreignUlid('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->string('movement_type', 16);
            $table->timestamp('scheduled_at');
            $table->string('slot_key', 20);
            $table->string('status', 24)->default('reserved');
            $table->bigInteger('fee_minor')->default(0);
            $table->char('currency', 3)->default('EUR');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['flight_id', 'movement_type'], 'slot_flight_movement_uq');
            $table->index(['world_id', 'airport_id', 'slot_key', 'status'], 'slot_capacity_lookup_idx');
            $table->index(['airline_id', 'scheduled_at'], 'slot_airline_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airport_slot_reservations');
        Schema::dropIfExists('airline_airport_stations');
    }
};
