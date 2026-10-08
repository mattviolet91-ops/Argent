<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Objectifs-projets : une icône par objectif, et des versements automatiques
 * (virement programmé d'un compte vers le compte de l'objectif).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_goals', function (Blueprint $table) {
            $table->string('icon', 20)->nullable()->after('name');
        });
        Schema::table('money_recurrings', function (Blueprint $table) {
            // Rempli : virement de account_id vers ce compte (montant positif).
            $table->foreignId('to_account_id')->nullable()->after('account_id')->constrained('money_accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('money_recurrings', fn (Blueprint $table) => $table->dropConstrainedForeignId('to_account_id'));
        Schema::table('money_goals', fn (Blueprint $table) => $table->dropColumn('icon'));
    }
};
