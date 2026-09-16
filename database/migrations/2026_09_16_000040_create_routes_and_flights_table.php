<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('origin_airport_id')->constrained('airports')->restrictOnDelete();
            $table->foreignUlid('destination_airport_id')->constrained('airports')->restrictOnDelete();
            $table->decimal('distance_km', 10, 2);
            $table->unsignedInteger('planned_block_minutes');
            $table->string('status', 32)->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();

            // Explicit short names keep indexes below MariaDB/MySQL's 64-char identifier limit.
            $table->unique(
                ['airline_id', 'origin_airport_id', 'destination_airport_id'],
                'routes_airline_origin_dest_uq'
            );
            $table->index(
                ['world_id', 'origin_airport_id', 'destination_airport_id'],
                'routes_world_origin_dest_idx'
            );
        });

        Schema::create('flights', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('route_id')->constrained('routes')->cascadeOnDelete();
            $table->foreignUlid('aircraft_id')->nullable()->constrained('aircraft')->nullOnDelete();
            $table->string('flight_number', 12);
            $table->timestamp('scheduled_departure_at');
            $table->timestamp('scheduled_arrival_at');
            $table->timestamp('actual_departure_at')->nullable();
            $table->timestamp('actual_arrival_at')->nullable();
            $table->string('status', 32)->default('scheduled');
            $table->integer('delay_minutes')->default(0);
            $table->unsignedInteger('passengers_booked')->default(0);
            $table->unsignedInteger('cargo_kg_booked')->default(0);
            $table->json('operational_data')->nullable();
            $table->timestamps();

            $table->unique(
                ['world_id', 'flight_number', 'scheduled_departure_at'],
                'flights_world_number_departure_uq'
            );
            $table->index(
                ['world_id', 'status', 'scheduled_departure_at'],
                'flights_world_status_departure_idx'
            );
            $table->index(
                ['aircraft_id', 'scheduled_departure_at', 'scheduled_arrival_at'],
                'flights_aircraft_schedule_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flights');
        Schema::dropIfExists('routes');
    }
};
