<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('type', 24);
            $table->char('currency', 3);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['airline_id', 'code']);
        });

        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('world_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('airline_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 180);
            $table->string('reference_type', 80)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->string('description', 255);
            $table->timestamp('occurred_at');
            $table->timestamp('posted_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['world_id', 'idempotency_key']);
            $table->index(['airline_id', 'occurred_at']);
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ledger_transaction_id')->constrained('ledger_transactions')->cascadeOnDelete();
            $table->foreignUlid('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('memo', 255)->nullable();
            $table->timestamps();
            $table->index(['ledger_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
    }
};
