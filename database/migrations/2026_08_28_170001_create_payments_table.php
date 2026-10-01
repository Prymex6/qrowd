<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payments for packages.
     *
     * The order is created BEFORE the customer reaches the gateway, with a number
     * of its own and the amount recorded. That way, when the notification comes
     * back, we have something to compare it against - without it the amount would
     * be dictated by whoever sent the request.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // Nasz numer zamowienia - to on wraca w powiadomieniu.
            $table->string('order_id', 64)->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();

            $table->string('plan', 20);
            $table->unsignedInteger('amount');          // w groszach - zadnych ulamkow
            $table->string('status', 20)->default('new');   // nowa | oplacona | odrzucona

            // Dane z bramki, zapisywane przy powiadomieniu.
            $table->string('payment_id', 64)->nullable();
            $table->string('secure', 64)->nullable();
            $table->timestamp('paid_at')->nullable();

            // Cala tresc powiadomienia - do reklamacji i sporu z operatorem.
            $table->json('notification')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
