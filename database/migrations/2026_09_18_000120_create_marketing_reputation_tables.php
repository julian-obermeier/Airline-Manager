<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airline_reputation_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('awareness_score', 5, 2)->default(12);
            $table->decimal('reputation_score', 5, 2)->default(50);
            $table->decimal('satisfaction_score', 5, 2)->default(55);
            $table->decimal('service_quality_score', 5, 2)->default(55);
            $table->bigInteger('brand_value_minor')->default(0);
            $table->unsignedInteger('completed_flights')->default(0);
            $table->unsignedInteger('cancelled_flights')->default(0);
            $table->timestamp('last_evaluated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['world_id', 'reputation_score'], 'airline_rep_world_score_idx');
        });

        Schema::create('route_commercial_metrics', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('route_id')->unique()->constrained('routes')->cascadeOnDelete();
            $table->decimal('awareness_score', 5, 2)->default(10);
            $table->decimal('satisfaction_score', 5, 2)->default(55);
            $table->decimal('historical_load_factor', 5, 4)->default(0);
            $table->unsignedInteger('completed_flights')->default(0);
            $table->unsignedInteger('cancelled_flights')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['airline_id', 'awareness_score'], 'route_metric_airline_awareness_idx');
        });

        Schema::create('marketing_campaigns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('route_id')->nullable()->constrained('routes')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('scope', 20);
            $table->string('channel', 24);
            $table->bigInteger('budget_minor');
            $table->char('currency', 3)->default('EUR');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 24)->default('active');
            $table->decimal('demand_boost', 5, 4)->default(0);
            $table->decimal('awareness_gain', 5, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['world_id', 'airline_id', 'status', 'starts_at', 'ends_at'], 'campaign_active_lookup_idx');
            $table->index(['route_id', 'status'], 'campaign_route_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaigns');
        Schema::dropIfExists('route_commercial_metrics');
        Schema::dropIfExists('airline_reputation_profiles');
    }
};
