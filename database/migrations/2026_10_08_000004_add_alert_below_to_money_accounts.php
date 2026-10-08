<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Seuil d'alerte d'un compte : notification quand le solde passe en dessous (centimes, vide = pas d'alerte). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_accounts', function (Blueprint $table) {
            $table->bigInteger('alert_below')->nullable()->after('opening_on');
        });
    }

    public function down(): void
    {
        Schema::table('money_accounts', fn (Blueprint $table) => $table->dropColumn('alert_below'));
    }
};
