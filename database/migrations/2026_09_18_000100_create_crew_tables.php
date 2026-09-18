<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('home_airport_id')->nullable()->constrained('airports')->nullOnDelete();
            $table->foreignUlid('current_airport_id')->nullable()->constrained('airports')->nullOnDelete();
            $table->string('employee_number', 24);
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('role', 32);
            $table->string('status', 24)->default('active');
            $table->bigInteger('monthly_salary_minor');
            $table->char('currency', 3)->default('EUR');
            $table->timestamp('hired_at');
            $table->timestamp('terminated_at')->nullable();
            $table->unsignedSmallInteger('max_duty_minutes_day')->default(780);
            $table->unsignedSmallInteger('min_rest_minutes')->default(660);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['airline_id', 'employee_number'], 'crew_airline_employee_uq');
            $table->index(['world_id', 'airline_id', 'status'], 'crew_world_airline_status_idx');
            $table->index(['airline_id', 'role', 'status'], 'crew_airline_role_status_idx');
            $table->index(['airline_id', 'current_airport_id'], 'crew_airline_location_idx');
        });

        Schema::create('crew_qualifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('crew_member_id')->constrained('crew_members')->cascadeOnDelete();
            $table->foreignUlid('aircraft_type_id')->constrained('aircraft_types')->restrictOnDelete();
            $table->string('qualification_type', 32)->default('type_rating');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['crew_member_id', 'aircraft_type_id', 'qualification_type'],
                'crew_qual_member_type_kind_uq'
            );
            $table->index(['aircraft_type_id', 'status'], 'crew_qual_type_status_idx');
        });

        Schema::create('flight_crew_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->foreignUlid('crew_member_id')->constrained('crew_members')->cascadeOnDelete();
            $table->string('duty_role', 32);
            $table->timestamp('duty_start_at');
            $table->timestamp('duty_end_at');
            $table->timestamp('assigned_at');
            $table->string('status', 24)->default('assigned');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['flight_id', 'crew_member_id'], 'flight_crew_flight_member_uq');
            $table->index(
                ['crew_member_id', 'status', 'duty_start_at', 'duty_end_at'],
                'flight_crew_member_duty_idx'
            );
            $table->index(['flight_id', 'duty_role', 'status'], 'flight_crew_flight_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_crew_assignments');
        Schema::dropIfExists('crew_qualifications');
        Schema::dropIfExists('crew_members');
    }
};
