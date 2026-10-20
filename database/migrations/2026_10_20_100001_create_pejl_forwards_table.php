<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spårbarhet för appförsäljning som vidarebefordras till Pejl. Belopp är nullbara så att en
// failed-rad med saknade fält går att spara.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pejl_forwards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('event_id');
            $table->string('kind', 20);
            $table->string('store', 40)->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('external_reference');
            $table->timestamp('occurred_at')->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 4)->nullable();
            $table->decimal('original_amount', 12, 4)->nullable();
            $table->string('original_currency', 3)->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('tax_percentage', 8, 6)->nullable();
            $table->decimal('commission_percentage', 8, 6)->nullable();
            $table->decimal('fx_rate', 12, 6)->nullable();
            $table->date('fx_date')->nullable();
            $table->decimal('amount_ex_vat_sek', 12, 2)->nullable();
            $table->decimal('cost_sek', 12, 2)->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'external_reference']);
            $table->index(['store', 'transaction_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pejl_forwards');
    }
};
