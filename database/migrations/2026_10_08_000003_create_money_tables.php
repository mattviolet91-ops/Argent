<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Espace « Argent » : comptes perso et pro, mouvements, budgets, objectifs,
 * dépenses fixes et bilans de chaque semaine. Réservé au gérant qui a créé le
 * code Argent. Montants en centimes, signés : + entrée, − sortie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            // courant, epargne, especes, carte, autre
            $table->string('kind', 20)->default('courant');
            // perso ou pro
            $table->string('scope', 10)->default('perso');
            // Solde connu à une date : les mouvements d'avant cette date ne changent plus le solde.
            $table->bigInteger('opening_balance')->default(0);
            $table->date('opening_on');
            $table->string('color', 7)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('money_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            // income (entrée) ou expense (sortie)
            $table->string('type', 10);
            // perso, pro ou both
            $table->string('scope', 10)->default('both');
            $table->string('color', 7)->default('#8A99A6');
            // Budget mensuel (dépenses) en centimes.
            $table->bigInteger('monthly_budget')->nullable();
            // Catégories utilisées par la synchronisation avec les devis (paiements, frais).
            $table->string('system_key', 30)->nullable()->unique();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('money_recurrings', function (Blueprint $table) {
            $table->id();
            $table->string('label', 160);
            $table->bigInteger('amount');
            $table->foreignId('account_id')->constrained('money_accounts')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('money_categories')->nullOnDelete();
            // hebdo, mensuel, trimestriel, annuel
            $table->string('frequency', 12)->default('mensuel');
            $table->date('next_on')->index();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('money_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->date('occurred_on')->index();
            $table->bigInteger('amount');
            // income, expense ou transfer (virement entre deux comptes : deux lignes liées)
            $table->string('kind', 10)->index();
            $table->foreignId('category_id')->nullable()->constrained('money_categories')->nullOnDelete();
            $table->string('label', 160);
            $table->string('notes', 500)->nullable();
            $table->string('transfer_key', 36)->nullable()->index();
            // manual, import, devis, recurring
            $table->string('source', 12)->default('manual');
            // Paiement ou frais du logiciel de devis (« payment:12 », « expense:4 »).
            $table->string('source_ref', 40)->nullable()->unique();
            // Relevé bancaire : empreinte de la ligne, pour ne jamais l'importer deux fois.
            $table->char('import_hash', 64)->nullable()->index();
            $table->foreignId('recurring_id')->nullable()->constrained('money_recurrings')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('money_goals', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            // epargne (mettre de côté), encaisse (chiffre encaissé), gain (gagné − dépensé), depenses (plafond)
            $table->string('kind', 12);
            $table->bigInteger('target');
            // mois ou annee (encaisse, gain, depenses)
            $table->string('period', 10)->nullable();
            // all, perso ou pro
            $table->string('scope', 10)->default('all');
            $table->date('deadline')->nullable();
            // Épargne : compte suivi (son solde) ou montant mis de côté à la main.
            $table->foreignId('account_id')->nullable()->constrained('money_accounts')->nullOnDelete();
            $table->bigInteger('saved')->default(0);
            $table->timestamp('achieved_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        // Mots du libellé bancaire → catégorie (appris à l'import).
        Schema::create('money_rules', function (Blueprint $table) {
            $table->id();
            $table->string('keyword', 60)->unique();
            $table->foreignId('category_id')->constrained('money_categories')->cascadeOnDelete();
            $table->timestamps();
        });

        // Bilan figé de chaque semaine (lundi → dimanche) : la mémoire de l'espace Argent.
        Schema::create('money_weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->date('week_start')->unique();
            $table->json('data');
            $table->timestamps();
        });

        $now = now();
        $position = 0;
        $rows = [];
        foreach (self::CATEGORIES as [$name, $type, $scope, $color, $key]) {
            $rows[] = [
                'name' => $name, 'type' => $type, 'scope' => $scope, 'color' => $color, 'system_key' => $key,
                'position' => ++$position, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('money_categories')->insert($rows);
    }

    /** [nom, type, portée, couleur, clé système] */
    private const CATEGORIES = [
        ['Chantiers (paiements des devis)', 'income', 'pro', '#1E7F4F', 'devis_payment'],
        ['Autres recettes pro', 'income', 'pro', '#4CAF7A', null],
        ['Salaire / rémunération', 'income', 'perso', '#2E7DBA', null],
        ['Aides (CAF, prime…)', 'income', 'perso', '#5AA9E6', null],
        ['Remboursements', 'income', 'both', '#7FC8A9', null],
        ['Autres revenus', 'income', 'both', '#9CCC65', null],

        ['Matériaux', 'expense', 'pro', '#C0392B', 'devis_materiaux'],
        ['Location matériel', 'expense', 'pro', '#D35400', 'devis_location'],
        ['Déchetterie', 'expense', 'pro', '#A0522D', 'devis_dechets'],
        ['Carburant, péage, parking', 'expense', 'both', '#E67E22', 'devis_carburant'],
        ['Outillage', 'expense', 'pro', '#B9770E', 'devis_outillage'],
        ['URSSAF, impôts, charges', 'expense', 'both', '#7D3C98', null],
        ['Assurances', 'expense', 'both', '#5B2C6F', null],
        ['Autres frais pro', 'expense', 'pro', '#8E6E53', 'devis_autre'],

        ['Logement (loyer, crédit)', 'expense', 'perso', '#2C3E50', null],
        ['Courses', 'expense', 'perso', '#16A085', null],
        ['Restaurants, sorties', 'expense', 'perso', '#F39C12', null],
        ['Voiture', 'expense', 'perso', '#E74C3C', null],
        ['Énergie, eau', 'expense', 'perso', '#F1C40F', null],
        ['Téléphone, internet', 'expense', 'both', '#3498DB', null],
        ['Abonnements', 'expense', 'both', '#9B59B6', null],
        ['Santé', 'expense', 'perso', '#1ABC9C', null],
        ['Loisirs, vacances', 'expense', 'perso', '#E84393', null],
        ['Shopping, vêtements', 'expense', 'perso', '#FD79A8', null],
        ['Enfants, famille', 'expense', 'perso', '#00B894', null],
        ['Cadeaux', 'expense', 'perso', '#FF7675', null],
        ['Frais bancaires', 'expense', 'both', '#636E72', null],
        ['Autres dépenses', 'expense', 'both', '#8A99A6', null],
    ];

    public function down(): void
    {
        Schema::dropIfExists('money_weekly_reports');
        Schema::dropIfExists('money_rules');
        Schema::dropIfExists('money_goals');
        Schema::dropIfExists('money_transactions');
        Schema::dropIfExists('money_recurrings');
        Schema::dropIfExists('money_categories');
        Schema::dropIfExists('money_accounts');
    }
};
