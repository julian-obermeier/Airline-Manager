<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aircraft_maintenance_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->string('check_type', 32);
            $table->string('status', 24)->default('planned');
            $table->dateTime('planned_start_at');
            $table->dateTime('planned_end_at');
            $table->dateTime('actual_start_at')->nullable();
            $table->dateTime('actual_end_at')->nullable();
            $table->bigInteger('cost_minor');
            $table->char('currency', 3)->default('EUR');
            $table->decimal('condition_before', 5, 2)->nullable();
            $table->decimal('condition_after', 5, 2)->nullable();
            $table->decimal('flight_hours_snapshot', 12, 2)->nullable();
            $table->unsignedBigInteger('flight_cycles_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['aircraft_id', 'status', 'planned_start_at'],
                'maintenance_aircraft_status_start_idx'
            );
            $table->index(['world_id', 'status'], 'maintenance_world_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_maintenance_events');
    }
};
