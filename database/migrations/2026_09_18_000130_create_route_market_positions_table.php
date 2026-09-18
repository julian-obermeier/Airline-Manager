<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_market_positions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('route_id')->unique()->constrained('routes')->cascadeOnDelete();
            $table->foreignUlid('origin_airport_id')->constrained('airports')->restrictOnDelete();
            $table->foreignUlid('destination_airport_id')->constrained('airports')->restrictOnDelete();
            $table->string('market_key', 80);
            $table->decimal('competition_score', 10, 4)->default(1);
            $table->decimal('market_share', 7, 6)->default(1);
            $table->decimal('competition_multiplier', 7, 4)->default(1);
            $table->unsignedSmallInteger('weekly_frequency')->default(0);
            $table->unsignedInteger('weekly_seat_capacity')->default(0);
            $table->bigInteger('average_economy_fare_minor')->default(0);
            $table->unsignedSmallInteger('competitor_count')->default(0);
            $table->timestamp('calculated_at');
            $table->json('factors')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['world_id', 'origin_airport_id', 'destination_airport_id'],
                'market_position_world_od_idx'
            );
            $table->index(['world_id', 'market_key', 'market_share'], 'market_position_market_share_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_market_positions');
    }
};
