<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airlines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('home_airport_id')->constrained('airports')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->char('icao_code', 3)->nullable();
            $table->char('iata_code', 2)->nullable();
            $table->string('callsign', 40)->nullable();
            $table->char('country_code', 2);
            $table->char('base_currency', 3)->default('EUR');
            $table->string('business_model', 40);
            $table->string('service_concept', 40)->nullable();
            $table->string('target_group', 40)->nullable();
            $table->bigInteger('starting_capital_minor');
            $table->string('status', 32)->default('active');
            $table->json('branding')->nullable();
            $table->timestamps();

            $table->unique(['world_id', 'slug']);
            $table->unique(['world_id', 'icao_code']);
            $table->unique(['world_id', 'iata_code']);
            $table->unique(['world_id', 'callsign']);
        });

        Schema::create('aircraft', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('aircraft_type_id')->constrained('aircraft_types')->restrictOnDelete();
            $table->foreignUlid('current_airport_id')->nullable()->constrained('airports')->nullOnDelete();
            $table->string('registration', 16);
            $table->string('serial_number', 80)->nullable();
            $table->date('manufactured_on')->nullable();
            $table->string('engine_variant', 120)->nullable();
            $table->decimal('flight_hours', 12, 2)->default(0);
            $table->unsignedBigInteger('flight_cycles')->default(0);
            $table->decimal('condition_percent', 5, 2)->default(100.00);
            $table->string('status', 32)->default('available');
            $table->string('ownership_type', 32)->default('owned');
            $table->bigInteger('acquisition_price_minor')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->json('configuration')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['world_id', 'registration']);
            $table->index(['world_id', 'airline_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft');
        Schema::dropIfExists('airlines');
    }
};
