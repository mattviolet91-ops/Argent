<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garanties et factures d'achat (fichier gardé dans le dossier privé, date de fin
 * de garantie), et « Qui me doit quoi » : l'argent prêté ou avancé à quelqu'un,
 * et ses remboursements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('shop', 120)->nullable();
            $table->date('purchased_on');
            $table->bigInteger('amount')->nullable();
            $table->date('warranty_until')->nullable()->index();
            // Mouvement de l'achat (s'il est noté dans l'app).
            $table->foreignId('transaction_id')->nullable()->constrained('money_transactions')->nullOnDelete();
            // Facture : chemin dans storage/app/private (jamais accessible directement).
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 160)->nullable();
            $table->string('file_mime', 80)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('money_people', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            // ami, famille, client, associe, autre
            $table->string('relation', 12)->default('ami');
            // À rembourser avant le (rappel).
            $table->date('due_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        // Montant signé : + il vous doit davantage (prêt, avance), − il vous doit moins (remboursement).
        Schema::create('money_loan_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('money_people')->cascadeOnDelete();
            $table->date('occurred_on');
            $table->bigInteger('amount');
            // pret, rembourse, emprunt, je_rembourse
            $table->string('type', 14);
            $table->string('note', 160)->nullable();
            // Mouvement noté sur un compte (facultatif).
            $table->foreignId('transaction_id')->nullable()->constrained('money_transactions')->nullOnDelete();
            $table->timestamps();
            $table->index(['person_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_loan_entries');
        Schema::dropIfExists('money_people');
        Schema::dropIfExists('money_purchases');
    }
};
