<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Classement perso / pro de chaque opération : compte « mixte » (perso et pro sur le
 * même compte, chaque opération est à classer) et mots du libellé → perso ou pro,
 * appris à chaque classement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_accounts', function (Blueprint $table) {
            $table->boolean('mixed')->default(false);
        });

        Schema::create('money_scope_rules', function (Blueprint $table) {
            $table->id();
            $table->string('keyword', 60)->unique();
            // perso ou pro
            $table->string('scope', 10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_scope_rules');
        Schema::table('money_accounts', fn (Blueprint $table) => $table->dropColumn('mixed'));
    }
};
