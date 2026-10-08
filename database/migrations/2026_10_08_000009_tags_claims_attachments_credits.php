<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étiquettes de chantier ou de projet, notes de frais (dépense pro payée avec un
 * compte perso, jusqu'au remboursement), justificatifs joints aux mouvements, et
 * suivi des crédits (capital, taux, durée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_transactions', function (Blueprint $table) {
            // Vue forcée (perso ou pro) : une note de frais payée en perso compte en pro.
            $table->string('scope', 10)->nullable();
            // Note de frais : a_rembourser ou rembourse.
            $table->string('claim', 12)->nullable()->index();
            $table->date('claim_settled_on')->nullable();
            // Virement de remboursement (transfer_key), s'il a été noté.
            $table->string('claim_key', 36)->nullable();
        });

        Schema::create('money_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('color', 7)->default('#2563EB');
            // Budget prévu du chantier ou du projet (facultatif).
            $table->bigInteger('budget')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('money_tag_transaction', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained('money_tags')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('money_transactions')->cascadeOnDelete();
            $table->primary(['tag_id', 'transaction_id']);
            $table->index('transaction_id');
        });

        Schema::create('money_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('money_transactions')->cascadeOnDelete();
            // Chemin dans storage/app/private (jamais accessible directement).
            $table->string('path', 255);
            $table->string('name', 160);
            $table->string('mime', 80);
            $table->unsignedInteger('size');
            $table->timestamps();
        });

        Schema::create('money_credits', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            // Capital emprunté (centimes), taux annuel en centièmes de % (3,45 % → 345), durée en mois.
            $table->bigInteger('principal');
            $table->unsignedInteger('rate')->default(0);
            $table->unsignedSmallInteger('months');
            // Mensualité hors assurance (centimes), et assurance par mois.
            $table->bigInteger('monthly');
            $table->bigInteger('insurance')->default(0);
            $table->date('first_due_on');
            $table->foreignId('account_id')->nullable()->constrained('money_accounts')->nullOnDelete();
            $table->foreignId('recurring_id')->nullable()->constrained('money_recurrings')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_credits');
        Schema::dropIfExists('money_attachments');
        Schema::dropIfExists('money_tag_transaction');
        Schema::dropIfExists('money_tags');
        Schema::table('money_transactions', function (Blueprint $table) {
            $table->dropIndex(['claim']);
            $table->dropColumn(['scope', 'claim', 'claim_settled_on', 'claim_key']);
        });
    }
};
