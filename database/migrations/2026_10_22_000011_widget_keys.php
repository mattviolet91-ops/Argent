<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widgets iPhone : une clé par widget installé (seulement son empreinte est gardée),
 * qui ne permet que de lire le résumé choisi (vue, montants visibles ou non).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_widget_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('token_hash', 64)->unique();
            // all, perso ou pro
            $table->string('scope', 10)->default('all');
            $table->boolean('show_amounts')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_widget_keys');
    }
};
