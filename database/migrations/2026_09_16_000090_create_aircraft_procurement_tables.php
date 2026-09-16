<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aircraft_market_offers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('aircraft_type_id')->constrained('aircraft_types')->restrictOnDelete();
            $table->foreignUlid('location_airport_id')->nullable()->constrained('airports')->nullOnDelete();
            $table->string('serial_number', 80);
            $table->date('manufactured_on')->nullable();
            $table->decimal('flight_hours', 12, 2)->default(0);
            $table->unsignedBigInteger('flight_cycles')->default(0);
            $table->decimal('condition_percent', 5, 2)->default(100.00);
            $table->bigInteger('price_minor');
            $table->char('currency', 3)->default('EUR');
            $table->string('status', 24)->default('available');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['world_id', 'serial_number'], 'market_offers_world_serial_uq');
            $table->index(['world_id', 'status'], 'market_offers_world_status_idx');
        });

        Schema::create('aircraft_procurements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('aircraft_type_id')->constrained('aircraft_types')->restrictOnDelete();
            $table->foreignUlid('market_offer_id')->nullable()->constrained('aircraft_market_offers')->nullOnDelete();
            $table->foreignUlid('delivered_aircraft_id')->nullable()->constrained('aircraft')->nullOnDelete();
            $table->string('procurement_type', 32);
            $table->string('status', 24)->default('ordered');
            $table->string('registration', 16);
            $table->bigInteger('total_price_minor')->default(0);
            $table->bigInteger('upfront_payment_minor')->default(0);
            $table->bigInteger('monthly_payment_minor')->default(0);
            $table->unsignedSmallInteger('lease_term_months')->nullable();
            $table->dateTime('ordered_at');
            $table->dateTime('delivery_due_at');
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('next_payment_at')->nullable();
            $table->dateTime('lease_ends_at')->nullable();
            $table->decimal('delivery_condition_percent', 5, 2)->default(100.00);
            $table->decimal('initial_flight_hours', 12, 2)->default(0);
            $table->unsignedBigInteger('initial_flight_cycles')->default(0);
            $table->date('manufactured_on')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['world_id', 'registration'], 'procurements_world_registration_uq');
            $table->index(['world_id', 'status', 'delivery_due_at'], 'procurements_delivery_idx');
            $table->index(['airline_id', 'status'], 'procurements_airline_status_idx');
            $table->index(['status', 'next_payment_at'], 'procurements_payment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_procurements');
        Schema::dropIfExists('aircraft_market_offers');
    }
};
