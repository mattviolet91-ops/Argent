<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Index pour les calculs (soldes par compte, totaux par catégorie et par période). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_transactions', function (Blueprint $table) {
            $table->index(['account_id', 'occurred_on']);
            $table->index(['category_id', 'occurred_on']);
            $table->index(['source', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::table('money_transactions', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'occurred_on']);
            $table->dropIndex(['category_id', 'occurred_on']);
            $table->dropIndex(['source', 'occurred_on']);
        });
    }
};
