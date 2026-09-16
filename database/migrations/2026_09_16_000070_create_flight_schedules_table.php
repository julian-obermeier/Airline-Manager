<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_schedules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->foreignUlid('outbound_route_id')->constrained('routes')->cascadeOnDelete();
            $table->foreignUlid('return_route_id')->constrained('routes')->cascadeOnDelete();
            $table->string('outbound_flight_number', 12);
            $table->string('return_flight_number', 12);
            $table->json('days_of_week');
            $table->date('starts_on');
            $table->string('departure_time', 5);
            $table->unsignedSmallInteger('turnaround_minutes');
            $table->unsignedSmallInteger('generation_horizon_days')->default(28);
            $table->string('status', 24)->default('active');
            $table->date('last_generated_on')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['world_id', 'status'], 'flight_schedules_world_status_idx');
            $table->index(['aircraft_id', 'status'], 'flight_schedules_aircraft_status_idx');
        });

        Schema::table('flights', function (Blueprint $table): void {
            $table->foreignUlid('flight_schedule_id')
                ->nullable()
                ->after('aircraft_id')
                ->constrained('flight_schedules')
                ->nullOnDelete();
            $table->index(['flight_schedule_id', 'scheduled_departure_at'], 'flights_schedule_departure_idx');
        });
    }

    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table): void {
            $table->dropForeign(['flight_schedule_id']);
            $table->dropIndex('flights_schedule_departure_idx');
            $table->dropColumn('flight_schedule_id');
        });

        Schema::dropIfExists('flight_schedules');
    }
};
