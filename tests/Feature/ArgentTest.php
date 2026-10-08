<?php

namespace Tests\Feature;

use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyGoal;
use App\Models\MoneyRecurring;
use App\Models\MoneyRule;
use App\Models\MoneyTransaction;
use App\Models\MoneyWeeklyReport;
use App\Models\Setting;
use App\Models\User;
use App\Services\BackupService;
use App\Services\MoneyImportService;
use App\Services\MoneySyncService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArgentTest extends TestCase
{
    use RefreshDatabase;

    private const DEVIS = 'https://devis.test';

    private User $owner;

    /** Ce que renvoie l'app de devis (modifiable pendant un test). */
    private array $devis = [];

    private int $devisStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00');
        $this->owner = User::factory()->create(['email' => 'matt@example.com', 'password' => 'MotDePasse-2026!']);
        // Paiement de 400 € le 10/10 (facture FAC-2026-0001) et frais de 120 € le 12/10.
        $this->devis = [
            'generated_at' => now()->toIso8601String(),
            'payments' => [['id' => 1, 'date' => '2026-10-10', 'amount' => 40000, 'method' => 'virement', 'client' => 'M. Paul Durand', 'invoice' => 'FAC-2026-0001']],
            'expenses' => [['id' => 7, 'date' => '2026-10-12', 'amount' => 12000, 'category' => 'materiaux', 'label' => 'Tuiles', 'supplier' => 'Point P']],
            'summary' => ['to_collect' => 60000, 'overdue_invoices' => 0, 'pending_quotes' => 2, 'pending_amount' => 350000],
        ];
        Http::fake([self::DEVIS.'/api/v1/argent' => function ($request) {
            return $request->hasHeader('Authorization', 'Bearer mc_cle-argent')
                ? Http::response($this->devis, $this->devisStatus)
                : Http::response(['message' => 'Clé d\'accès invalide ou révoquée.'], 401);
        }]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setUpMoney(string $code = '482913', bool $linkDevis = true): void
    {
        $this->post(route('setup.store'), [
            'code' => $code, 'code_confirmation' => $code, 'perso_balance' => '1 500,00', 'pro_balance' => '-200',
            'devis_url' => $linkDevis ? self::DEVIS : '', 'devis_token' => $linkDevis ? 'mc_cle-argent' : '',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
    }

    private function account(string $scope): MoneyAccount
    {
        return MoneyAccount::query()->where('scope', $scope)->firstOrFail();
    }

    public function test_login_then_code_then_quote_payments_and_expenses_arrive(): void
    {
        auth()->logout();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->post(route('login'), ['email' => 'matt@example.com', 'password' => 'faux'])->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => 'matt@example.com', 'password' => 'MotDePasse-2026!', 'remember' => 1])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('setup'));
        $this->get(route('setup'))->assertOk()->assertSee('Choisissez un code Argent');

        $this->post(route('setup.store'), ['code' => '12', 'code_confirmation' => '12'])->assertSessionHasErrors('code');
        $this->setUpMoney();

        $this->assertSame(150000, $this->account('perso')->balance());
        // Solde pro de départ (−200 €) au 14/10 : le paiement du 10 et le frais du 12 comptent dans les bilans, pas dans ce solde.
        $this->assertSame(-20000, $this->account('pro')->balance());
        $payment = MoneyTransaction::query()->where('source_ref', 'payment:1')->sole();
        $this->assertSame(40000, $payment->amount);
        $this->assertSame('devis_payment', $payment->category->system_key);
        $this->assertSame('Paiement M. Paul Durand · facture FAC-2026-0001', $payment->label);
        $expense = MoneyTransaction::query()->where('source_ref', 'expense:7')->sole();
        $this->assertSame(-12000, $expense->amount);
        $this->assertSame('Tuiles · Point P', $expense->label);
        $this->assertSame('devis_materiaux', $expense->category->system_key);

        $this->get(route('dashboard', ['vue' => 'pro']))->assertOk()
            ->assertSee(Money::format(40000))->assertSee(Money::format(60000))->assertSee('Devis en attente (2)')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertDatabaseHas('activity_log', ['action' => 'auth.login']);
    }

    public function test_nobody_else_can_open_it(): void
    {
        $this->setUpMoney();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($other)->get(route('unlock'))->assertForbidden();
        $this->actingAs($other)->post(route('setup.store'), ['code' => '1111', 'code_confirmation' => '1111'])->assertForbidden();
        $this->actingAs($other)->get(route('export'))->assertForbidden();
    }

    public function test_code_is_asked_again_after_locking_or_inactivity_and_blocks_after_five_wrong_codes(): void
    {
        $this->setUpMoney('482913');
        $this->get(route('transactions.index'))->assertOk();

        $this->post(route('lock'))->assertRedirect(route('unlock'));
        $this->get(route('transactions.index'))->assertRedirect(route('unlock'));
        $this->post(route('unlock.store'), ['code' => '482913'])->assertRedirect(route('transactions.index'));

        // 15 minutes sans rien ouvrir : reverrouillé.
        Carbon::setTestNow(now()->addMinutes(16));
        $this->get(route('dashboard'))->assertRedirect(route('unlock'));

        for ($i = 1; $i <= 4; $i++) {
            $this->post(route('unlock.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->post(route('unlock.store'), ['code' => '000000'])->assertSessionHasErrors(['code' => 'Code faux. App bloquée 15 minutes.']);
        $this->assertDatabaseHas('activity_log', ['action' => 'argent.blocked']);
        // Même le bon code est refusé pendant le blocage.
        $this->post(route('unlock.store'), ['code' => '482913'])->assertSessionHasErrors('code');
        $this->get(route('dashboard'))->assertRedirect(route('unlock'));

        Carbon::setTestNow(now()->addMinutes(16));
        $this->post(route('unlock.store'), ['code' => '482913'])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_forgotten_code_is_replaced_with_the_account_password_and_old_code_stops_working(): void
    {
        $this->setUpMoney('482913');
        $this->post(route('lock'));

        $this->post(route('forgot.store'), ['password' => 'faux', 'code' => '5555', 'code_confirmation' => '5555'])->assertSessionHasErrors('password');
        $this->post(route('forgot.store'), ['password' => 'MotDePasse-2026!', 'code' => '5555', 'code_confirmation' => '5555'])
            ->assertRedirect(route('dashboard'));
        $this->post(route('lock'));
        $this->post(route('unlock.store'), ['code' => '482913'])->assertSessionHasErrors('code');
        $this->post(route('unlock.store'), ['code' => '5555'])->assertRedirect(route('dashboard'));
    }

    public function test_quick_add_expense_income_and_transfer(): void
    {
        $this->setUpMoney(linkDevis: false);
        $perso = $this->account('perso');
        $pro = $this->account('pro');
        $courses = MoneyCategory::query()->where('name', 'Courses')->sole();
        $salary = MoneyCategory::query()->where('name', 'Salaire / rémunération')->sole();

        $this->from(route('dashboard'))->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => '62,40', 'account_id' => $perso->id, 'category_id' => $courses->id, 'label' => 'Leclerc', 'occurred_on' => '2026-10-14',
        ])->assertRedirect(route('dashboard'))->assertSessionHas('status', 'Dépense ajoutée.');
        $this->post(route('transactions.store'), [
            'type' => 'income', 'amount' => '2000', 'account_id' => $perso->id, 'category_id' => $salary->id, 'occurred_on' => '2026-10-01',
        ])->assertSessionHasNoErrors();
        $this->post(route('transactions.store'), [
            'type' => 'transfer', 'amount' => '300', 'account_id' => $pro->id, 'to_account_id' => $perso->id, 'occurred_on' => '2026-10-14',
        ])->assertSessionHasNoErrors();
        $this->post(route('transactions.store'), ['type' => 'expense', 'amount' => '5', 'account_id' => $perso->id, 'category_id' => $salary->id, 'occurred_on' => '2026-10-14'])
            ->assertSessionHasErrors('category_id');
        $this->post(route('transactions.store'), ['type' => 'expense', 'amount' => '0', 'account_id' => $perso->id, 'occurred_on' => '2026-10-14'])
            ->assertSessionHasErrors('amount');

        // Le salaire du 1er est avant la date du solde de départ (14/10) : déjà compris dedans.
        $this->assertSame(150000 - 6240 + 30000, $perso->balance());
        $this->assertSame(-20000 - 30000, $pro->balance());

        // Le virement ne compte ni comme gagné ni comme dépensé.
        $this->get(route('dashboard', ['vue' => 'perso', 'periode' => 'mois']))->assertOk()
            ->assertViewHas('totals', ['income' => 200000, 'expense' => 6240, 'net' => 193760]);
        $this->get(route('transactions.index', ['vue' => 'all', 'q' => 'Leclerc']))->assertOk()->assertSee('Leclerc')->assertDontSee('Virement vers');

        $transfer = MoneyTransaction::query()->where('kind', 'transfer')->where('amount', '<', 0)->sole();
        $this->delete(route('transactions.destroy', $transfer))->assertRedirect(route('transactions.index'));
        $this->assertSame(0, MoneyTransaction::query()->where('kind', 'transfer')->count());
    }

    public function test_sync_never_duplicates_follows_deletions_keeps_manual_category_and_reports_errors(): void
    {
        $this->setUpMoney();
        $sync = app(MoneySyncService::class);
        $this->assertSame(['added' => 0, 'updated' => 0, 'removed' => 0], $sync->run());

        $line = MoneyTransaction::query()->where('source_ref', 'payment:1')->sole();
        $other = MoneyCategory::query()->where('name', 'Autres recettes pro')->sole();
        $this->put(route('transactions.update', $line), ['category_id' => $other->id, 'label' => 'Chantier Durand'])->assertSessionHasNoErrors();
        $this->delete(route('transactions.destroy', $line))->assertSessionHasErrors('transaction');

        // Paiement modifié (montant) puis supprimé dans l'app de devis.
        $this->devis['payments'][0]['amount'] = 45000;
        $this->assertSame(['added' => 0, 'updated' => 1, 'removed' => 0], $sync->run());
        $this->assertSame('Chantier Durand', $line->fresh()->label);
        $this->assertSame($other->id, $line->fresh()->category_id);
        $this->devis['payments'] = [];
        $this->post(route('sync'))->assertSessionHas('status');
        $this->assertSame(0, MoneyTransaction::query()->where('source_ref', 'like', 'payment:%')->count());

        // Paiement en espèces vers la caisse.
        $cash = MoneyAccount::query()->create(['name' => 'Caisse', 'kind' => 'especes', 'scope' => 'pro', 'opening_on' => '2026-10-01']);
        $this->put(route('settings.update'), ['lock_minutes' => 15, 'sync_account_id' => $this->account('pro')->id, 'cash_account_id' => $cash->id, 'weekly_push' => 1])
            ->assertSessionHasNoErrors();
        $this->devis['payments'] = [['id' => 2, 'date' => '2026-10-13', 'amount' => 25000, 'method' => 'especes', 'client' => 'Mme Martin', 'invoice' => 'FAC-2026-0002']];
        $sync->run();
        $this->assertSame(25000, $cash->balance());

        // App de devis en panne ou clé refusée : message clair, rien n'est effacé.
        $this->devisStatus = 500;
        $this->from(route('settings'))->post(route('sync'))->assertSessionHasErrors(['sync' => 'Réponse inattendue de l\'app de devis (erreur 500). Est-elle à jour ?']);
        $this->devisStatus = 200;
        $this->put(route('settings.devis'), ['devis_url' => self::DEVIS, 'devis_token' => 'mc_mauvaise'])
            ->assertSessionHasErrors(['devis_token' => 'Clé refusée par l\'app de devis : créez une nouvelle « clé de l\'app Argent » (Réglages → Accès Claude) et collez-la ici.']);
        $this->assertSame(2, MoneyTransaction::query()->where('source', 'devis')->count());
        // La clé n'est jamais gardée en clair.
        $this->assertStringNotContainsString('mc_mauvaise', (string) Setting::query()->where('key', 'devis.token')->value('value'));
    }

    public function test_fixed_expenses_are_written_on_their_due_dates_and_forecast(): void
    {
        $this->setUpMoney(linkDevis: false);
        $perso = $this->account('perso');
        $rent = MoneyCategory::query()->where('name', 'Logement (loyer, crédit)')->sole();

        $this->post(route('recurrings.store'), [
            'label' => 'Loyer', 'type' => 'expense', 'amount' => '750', 'account_id' => $perso->id, 'category_id' => $rent->id,
            'frequency' => 'mensuel', 'next_on' => '2026-09-05',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, MoneyTransaction::query()->where('source', 'recurring')->count());
        $this->assertSame('2026-11-05', MoneyRecurring::query()->sole()->next_on->toDateString());

        $this->post(route('recurrings.store'), [
            'label' => 'Netflix', 'type' => 'expense', 'amount' => '13,49', 'account_id' => $perso->id, 'frequency' => 'mensuel', 'next_on' => '2026-10-20',
        ])->assertSessionHasNoErrors();
        $this->get(route('recurrings.index'))->assertOk()->assertSee('Netflix')->assertSee(Money::format(75000 + 1349));
        $this->get(route('dashboard'))->assertOk()->assertSee('Netflix');

        Carbon::setTestNow('2026-10-20 07:00');
        $this->artisan('app:argent-jour')->assertSuccessful();
        $this->assertSame(1, MoneyTransaction::query()->where('label', 'Netflix')->count());
    }

    public function test_bank_statement_import_skips_duplicates_and_learns_categories(): void
    {
        $this->setUpMoney();
        $pro = $this->account('pro');
        $courses = MoneyCategory::query()->where('name', 'Courses')->sole();
        $csv = mb_convert_encoding(implode("\r\n", [
            'Téléchargement du 14/10/2026;;;;',
            'Compte courant n° 123;;;;',
            '',
            'Date;Libellé;Débit euros;Crédit euros;',
            '13/10/2026;CB CARREFOUR MARKET 12/10;45,20;;',
            '10/10/2026;VIR SEPA RECU DE DURAND PAUL;;400,00;',
            '11/10/2026;PRLV SEPA EDF CLIENTS PARTICULIERS;"1 234,56";;',
        ]), 'Windows-1252', 'UTF-8');

        $this->post(route('import.preview'), ['account_id' => $pro->id, 'file' => UploadedFile::fake()->createWithContent('releve.csv', $csv)])
            ->assertRedirect(route('import.show'));
        $rows = $this->get(route('import.show'))->assertOk()->assertSee('CB CARREFOUR MARKET 12/10')->assertSee('Déjà notée ?')->viewData('rows');
        $this->assertSame([-4520, 40000, -123456], array_column($rows, 'amount'));
        // Le virement du client ressemble au paiement déjà venu de l'app de devis : décoché.
        $this->assertSame(['new', 'similar', 'new'], array_column($rows, 'status'));

        $this->post(route('import.store'), ['import' => [0 => 1, 2 => 1], 'category' => [0 => $courses->id]])
            ->assertRedirect(route('transactions.index', ['compte' => $pro->id, 'periode' => 'tout']));
        $this->assertSame(2, MoneyTransaction::query()->where('source', 'import')->count());
        $this->assertSame($courses->id, MoneyRule::query()->where('keyword', 'carrefour market')->value('category_id'));

        $this->post(route('import.preview'), ['account_id' => $pro->id, 'file' => UploadedFile::fake()->createWithContent('releve.csv', $csv)]);
        $rows = $this->get(route('import.show'))->viewData('rows');
        $this->assertSame(['known', 'similar', 'known'], array_column($rows, 'status'));
        $this->assertSame($courses->id, $rows[0]['category_id']);

        $this->post(route('import.preview'), ['account_id' => $pro->id, 'file' => UploadedFile::fake()->createWithContent('vide.csv', "a;b\n1;2")])
            ->assertSessionHasErrors('file');
    }

    public function test_ofx_statement_and_labels_are_read(): void
    {
        $ofx = "OFXHEADER:100\nDATA:OFXSGML\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>\n"
            ."<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261003120000<TRNAMT>-12.50<FITID>A1<NAME>BOULANGERIE<MEMO>CB 02/10\n"
            ."<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20261005<TRNAMT>1500,00<FITID>A2<NAME>SALAIRE\n"
            .'</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';

        $this->assertSame([
            ['date' => '2026-10-03', 'label' => 'BOULANGERIE CB 02/10', 'amount' => -1250, 'fitid' => 'A1'],
            ['date' => '2026-10-05', 'label' => 'SALAIRE', 'amount' => 150000, 'fitid' => 'A2'],
        ], app(MoneyImportService::class)->parse($ofx));
        $this->assertSame('carrefour market', app(MoneyImportService::class)->keyword('CB CARREFOUR MARKET 12/10 X1234'));
        $this->assertSame('edf clients', app(MoneyImportService::class)->keyword('PRLV SEPA EDF CLIENTS PARTICULIERS'));
    }

    public function test_goals_budgets_and_accounts(): void
    {
        $this->setUpMoney(linkDevis: false);
        $perso = $this->account('perso');
        $courses = MoneyCategory::query()->where('name', 'Courses')->sole();

        $this->post(route('goals.store'), ['name' => 'Vacances', 'kind' => 'epargne', 'target' => '1200', 'deadline' => '2027-04-14', 'saved' => '200'])
            ->assertSessionHasNoErrors();
        $goal = MoneyGoal::query()->sole();
        $this->post(route('goals.contribute', $goal), ['contribution' => '1000'])->assertSessionHas('status', 'Objectif « Vacances » atteint, bravo !');
        $this->assertNotNull($goal->fresh()->achieved_at);

        $this->post(route('goals.store'), ['name' => 'Pas plus de 2 000 €', 'kind' => 'depenses', 'target' => '2000', 'period' => 'mois', 'scope' => 'perso'])->assertSessionHasNoErrors();
        $this->put(route('categories.update', $courses), ['monthly_budget' => '400'])->assertSessionHasNoErrors();
        $this->post(route('transactions.store'), ['type' => 'expense', 'amount' => '450', 'account_id' => $perso->id, 'category_id' => $courses->id, 'occurred_on' => '2026-10-02']);

        $this->get(route('categories.index'))->assertOk()->assertSee('113 %');
        $this->get(route('goals.index'))->assertOk()->assertSee('Vacances')->assertSee('Encore '.Money::format(155000).' possibles ce mois-ci');

        $this->post(route('accounts.store'), ['name' => 'Livret A', 'kind' => 'epargne', 'scope' => 'perso', 'opening_balance' => '5 000', 'opening_on' => '2026-10-01', 'color' => '#00AA88'])
            ->assertSessionHasNoErrors();
        $livret = MoneyAccount::query()->where('name', 'Livret A')->sole();
        $this->get(route('accounts.show', $livret))->assertOk()->assertSee(Money::format(500000));
        $this->delete(route('accounts.destroy', $livret))->assertRedirect(route('accounts.index'));
        $this->assertModelMissing($livret);
        $this->delete(route('accounts.destroy', $perso));
        $this->assertNotNull($perso->fresh()->archived_at);
    }

    public function test_monday_update_makes_the_weekly_report_and_every_page_opens(): void
    {
        $this->setUpMoney();
        $this->post(route('transactions.store'), ['type' => 'expense', 'amount' => '80', 'account_id' => $this->account('perso')->id, 'label' => 'Essence', 'occurred_on' => '2026-10-13']);
        $this->devis['expenses'][] = ['id' => 8, 'date' => '2026-10-15', 'amount' => 3000, 'category' => 'dechets', 'label' => 'Déchetterie', 'supplier' => null];

        Carbon::setTestNow('2026-10-19 07:00');
        $this->artisan('app:argent-semaine')->assertSuccessful();
        $report = MoneyWeeklyReport::query()->sole();
        $this->assertSame('2026-10-12', $report->week_start->toDateString());
        // Frais du 12 (120 €) et du 15 (30 €, arrivé avec la mise à jour du lundi) + essence 80 €.
        $this->assertSame(['income' => 0, 'expense' => 23000, 'net' => -23000], $report->data['all']);
        $this->assertSame(15000, $report->data['quotes']['spent']);

        // App de devis injoignable le lundi : le bilan est fait quand même, l'erreur est signalée.
        $this->devisStatus = 500;
        Carbon::setTestNow('2026-10-26 07:00');
        $this->artisan('app:argent-semaine')->assertSuccessful();
        $this->assertSame(2, MoneyWeeklyReport::query()->count());

        $this->post(route('unlock.store'), ['code' => '482913']);
        $this->get(route('settings'))->assertOk()->assertSee('Dernière mise à jour automatique impossible');
        foreach ([
            route('dashboard'), route('dashboard', ['periode' => 'annee', 'vue' => 'pro']), route('transactions.index', ['periode' => 'tout']),
            route('accounts.index'), route('categories.index'), route('goals.index'), route('recurrings.index'),
            route('reports.index'), route('reports.show', $report), route('import.create'),
            route('transactions.edit', MoneyTransaction::query()->first()),
        ] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('reports.show', $report))->assertSee('Semaine du 12 octobre au 18 octobre 2026');

        $csv = $this->get(route('export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Essence', $csv);
        $this->assertStringContainsString('-80,00', $csv);

        $this->artisan('app:backup')->assertSuccessful();
        $name = app(BackupService::class)->list()->first()['name'];
        $this->get(route('settings'))->assertSee(route('backups.download', $name));
        $this->get(route('backups.download', $name))->assertOk()->assertDownload($name);
    }

    public function test_password_change_and_logout(): void
    {
        $this->setUpMoney(linkDevis: false);
        $this->put(route('settings.password'), ['current_password' => 'faux', 'password' => 'NouveauPasse-2027', 'password_confirmation' => 'NouveauPasse-2027'])
            ->assertSessionHasErrors('current_password');
        $this->put(route('settings.password'), ['current_password' => 'MotDePasse-2026!', 'password' => 'court', 'password_confirmation' => 'court'])
            ->assertSessionHasErrors('password');
        $this->put(route('settings.password'), ['current_password' => 'MotDePasse-2026!', 'password' => 'NouveauPasse-2027', 'password_confirmation' => 'NouveauPasse-2027'])
            ->assertSessionHasNoErrors();

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->post(route('login'), ['email' => 'matt@example.com', 'password' => 'NouveauPasse-2027'])->assertRedirect(route('dashboard'));
        // Après une nouvelle connexion, le code est redemandé.
        $this->get(route('dashboard'))->assertRedirect(route('unlock'));
    }
}
