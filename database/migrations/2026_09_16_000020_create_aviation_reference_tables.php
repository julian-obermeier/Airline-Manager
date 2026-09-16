<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('icao_code', 4)->unique();
            $table->char('iata_code', 3)->nullable()->index();
            $table->string('name', 180);
            $table->string('city', 120);
            $table->char('country_code', 2)->index();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->string('timezone', 64);
            $table->integer('elevation_ft')->nullable();
            $table->unsignedInteger('passenger_capacity_yearly')->nullable();
            $table->unsignedBigInteger('cargo_capacity_tonnes_yearly')->nullable();
            $table->jsonb('runways')->nullable();
            $table->jsonb('operational_restrictions')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('aircraft_types', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('manufacturer', 120)->index();
            $table->string('model', 120);
            $table->string('variant', 120)->nullable();
            $table->string('icao_type_code', 8)->nullable()->index();
            $table->unsignedInteger('typical_seats')->nullable();
            $table->unsignedInteger('max_seats')->nullable();
            $table->unsignedInteger('range_km')->nullable();
            $table->unsignedInteger('cruise_speed_kmh')->nullable();
            $table->unsignedInteger('minimum_runway_m')->nullable();
            $table->unsignedInteger('max_payload_kg')->nullable();
            $table->unsignedInteger('fuel_capacity_l')->nullable();
            $table->bigInteger('reference_purchase_price_minor')->nullable();
            $table->char('reference_currency', 3)->default('EUR');
            $table->string('production_status', 32)->default('active');
            $table->jsonb('technical_data')->nullable();
            $table->timestampsTz();
            $table->unique(['manufacturer', 'model', 'variant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_types');
        Schema::dropIfExists('airports');
    }
};
