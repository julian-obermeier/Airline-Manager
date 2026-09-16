<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worlds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('type', 32)->default('persistent');
            $table->string('status', 32)->default('draft');
            $table->decimal('speed_multiplier', 8, 2)->default(1.00);
            $table->timestampTz('simulated_at');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->bigInteger('random_seed');
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();
        });

        Schema::create('world_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('active');
            $table->timestampTz('joined_at');
            $table->timestampTz('last_active_at')->nullable();
            $table->timestampsTz();
            $table->unique(['world_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('world_memberships');
        Schema::dropIfExists('worlds');
    }
};
