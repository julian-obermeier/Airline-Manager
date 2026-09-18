<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_fare_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->string('cabin', 24);
            $table->string('bucket_code', 40);
            $table->bigInteger('previous_fare_minor')->default(0);
            $table->bigInteger('new_fare_minor');
            $table->bigInteger('base_fare_minor');
            $table->decimal('load_factor', 7, 4)->default(0);
            $table->decimal('booking_progress', 7, 4)->default(0);
            $table->decimal('competition_multiplier', 7, 4)->default(1);
            $table->timestamp('calculated_at');
            $table->string('reason', 80);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['flight_id', 'cabin', 'calculated_at'], 'fare_event_flight_cabin_time_idx');
            $table->index(['airline_id', 'calculated_at'], 'fare_event_airline_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_fare_events');
    }
};
