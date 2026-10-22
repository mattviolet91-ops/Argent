<?php

namespace Tests\Feature;

use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyScopeRule;
use App\Models\MoneyTag;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\MoneyStatsService;
use App\Services\PushService;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

/** Relevés PDF, plusieurs fichiers d'un coup, classement perso / pro (à l'import et après). */
class StatementTest extends TestCase
{
    use RefreshDatabase;

    private MoneyAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 10:00');
        $this->app->instance(PushService::class, Mockery::spy(PushService::class));
        $this->actingAs(User::factory()->create());
        $this->post(route('setup.store'), ['code' => '482913', 'code_confirmation' => '482913', 'perso_balance' => '2 000'])->assertSessionHasNoErrors();
        $this->account = MoneyAccount::query()->where('scope', 'perso')->firstOrFail();
        $this->account->update(['opening_on' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Relevé PDF façon banque : Date, Valeur, Opération, Débit, Crédit. */
    private function pdf(): UploadedFile
    {
        $rows = [
            ['01/10', 'SOLDE CREDITEUR AU 30/09/2026', '', '1 523,40'],
            ['02/10', 'CB CARREFOUR MARKET 01/10', '45,20', ''],
            ['03/10', 'VIR SEPA RECU /DE DURAND PAUL', '', '1 200,00'],
            ['07/10', 'CB POINT P 06/10', '1 234,56', ''],
            ['12/10', 'CB TOTAL ENERGIES', '62,15', ''],
        ];
        $html = '<html><body style="font-family: DejaVu Sans; font-size: 9pt"><p>Période du 01/10/2026 au 31/10/2026</p><table style="width:100%">'
            .'<tr><th align="left">Date</th><th align="left">Valeur</th><th align="left">Opération</th><th align="right">Débit</th><th align="right">Crédit</th></tr>';
        foreach ($rows as $r) {
            $html .= '<tr><td>'.$r[0].'</td><td>'.$r[0].'</td><td>'.$r[1].'</td><td align="right">'.$r[2].'</td><td align="right">'.$r[3].'</td></tr>';
        }
        $html .= '<tr><td></td><td></td><td>TOTAL DES OPERATIONS</td><td align="right">1 341,91</td><td align="right">1 200,00</td></tr></table></body></html>';
        $dompdf = new Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->render();

        return UploadedFile::fake()->createWithContent('releve-octobre.pdf', $dompdf->output());
    }

    public function test_a_pdf_statement_is_read_and_each_line_classified(): void
    {
        $this->account->update(['mixed' => true]);
        $materiaux = MoneyCategory::query()->where('name', 'Matériaux')->value('id');
        MoneyScopeRule::query()->create(['keyword' => 'total energies', 'scope' => 'pro']);

        $this->post(route('import.preview'), ['account_id' => $this->account->id, 'files' => [$this->pdf()]])->assertRedirect(route('import.show'));
        $page = $this->get(route('import.show'))->assertOk()->assertSee('le sens de chaque opération', false)->assertSee('Perso')->assertSee('Inverser');
        $rows = $page->viewData('rows');
        $this->assertSame(['CB CARREFOUR MARKET 01/10', 'VIR SEPA RECU /DE DURAND PAUL', 'CB POINT P 06/10', 'CB TOTAL ENERGIES'], array_column($rows, 'label'));
        $this->assertSame([-4520, 120000, -123456, -6215], array_column($rows, 'amount'));
        $this->assertSame('2026-10-07', $rows[2]['date']);
        $this->assertTrue($rows[0]['guessed']);
        // Appris avant : Total Energies → pro ; sinon le compte (à vérifier).
        $this->assertSame(['pro', 'rule'], [$rows[3]['scope'], $rows[3]['scope_hint']]);
        $this->assertSame(['perso', 'account'], [$rows[0]['scope'], $rows[0]['scope_hint']]);

        // Point P en pro (catégorie Matériaux) ; le virement reçu en pro ; une ligne inversée.
        $this->post(route('import.store'), [
            'import' => [0 => 1, 1 => 1, 2 => 1, 3 => 1],
            'category' => [2 => $materiaux],
            'scope' => [0 => 'perso', 1 => 'pro', 2 => 'pro', 3 => 'pro'],
            'flip' => [1 => 1],
        ])->assertSessionHas('status');

        $this->assertSame(4, MoneyTransaction::query()->where('source', 'import')->count());
        $this->assertSame('pro', MoneyTransaction::query()->where('label', 'CB POINT P 06/10')->value('scope'));
        $this->assertSame('perso', MoneyTransaction::query()->where('label', 'CB CARREFOUR MARKET 01/10')->value('scope'));
        $this->assertSame(-120000, MoneyTransaction::query()->where('label', 'like', 'VIR SEPA%')->value('amount'));
        // Les choix sont retenus pour les prochains relevés.
        $this->assertSame('pro', MoneyScopeRule::query()->where('keyword', 'point')->value('scope'));
        $this->assertSame('perso', MoneyScopeRule::query()->where('keyword', 'carrefour market')->value('scope'));

        $stats = app(MoneyStatsService::class);
        $from = Carbon::parse('2026-10-01');
        $this->assertSame(4520, $stats->totals('perso', $from, today())['expense']);
        $this->assertSame(123456 + 6215 + 120000, $stats->totals('pro', $from, today())['expense']);

        // Même relevé une 2e fois : rien en double ; les suggestions viennent de ce qui a été appris.
        $this->post(route('import.preview'), ['account_id' => $this->account->id, 'files' => [$this->pdf()]]);
        $rows = $this->get(route('import.show'))->viewData('rows');
        $this->assertSame(['known', 'known', 'known', 'known'], array_column($rows, 'status'));
        $this->assertSame('pro', $rows[2]['scope']);
    }

    public function test_several_overlapping_files_give_each_operation_once(): void
    {
        $september = "Date;Libellé;Montant\n28/09/2026;CB BOULANGERIE;-3,20\n30/09/2026;CB BOULANGERIE;-3,20\n";
        $overlap = "Date;Libellé;Montant\n30/09/2026;CB BOULANGERIE;-3,20\n02/10/2026;PRLV EDF;-80,00\n";
        $this->post(route('import.preview'), ['account_id' => $this->account->id, 'files' => [
            UploadedFile::fake()->createWithContent('a.csv', $september),
            UploadedFile::fake()->createWithContent('b.csv', $overlap),
        ]])->assertRedirect(route('import.show'));

        $rows = $this->get(route('import.show'))->assertOk()->viewData('rows');
        $this->assertSame(['2026-09-28', '2026-09-30', '2026-10-02'], array_column($rows, 'date'));
    }

    public function test_statement_lines_can_be_put_on_a_chantier(): void
    {
        $dupont = MoneyTag::query()->create(['name' => 'Dupont', 'color' => '#2563EB']);
        MoneyTag::query()->create(['name' => 'Ancien chantier', 'color' => '#475569', 'archived_at' => now()]);
        $csv = "Date;Libellé;Montant\n02/10/2026;CB POINT P;-412,30\n03/10/2026;CB BRICO DEPOT;-89,90\n05/10/2026;CB LECLERC;-54,10\n09/10/2026;VIR MARTIN ACOMPTE;1500,00\n";
        $this->post(route('import.preview'), ['account_id' => $this->account->id, 'files' => [UploadedFile::fake()->createWithContent('octobre.csv', $csv)]]);
        $this->get(route('import.show'))->assertOk()
            ->assertSee('Sur toutes les sorties pro cochées')
            ->assertSee('<option value="Dupont">', false)
            ->assertDontSee('<option value="Ancien chantier">', false);

        $this->post(route('import.store'), [
            'import' => [0 => 1, 1 => 1, 2 => 1, 3 => 1],
            'scope' => [0 => 'pro', 1 => 'pro', 2 => 'perso', 3 => 'pro'],
            // Chantier existant (majuscules différentes), nouveau chantier créé à la volée, rien, et une entrée.
            'tag' => [0 => 'dupont', 1 => 'Martin', 2 => '', 3 => 'Martin'],
        ])->assertSessionHas('status', fn ($status) => str_contains($status, '3 mises sur un chantier'));

        $tagsOf = fn (string $label) => MoneyTransaction::query()->where('label', $label)->sole()->tags->pluck('name')->all();
        $this->assertSame(['Dupont'], $tagsOf('CB POINT P'));
        $this->assertSame(['Martin'], $tagsOf('CB BRICO DEPOT'));
        $this->assertSame([], $tagsOf('CB LECLERC'));
        $this->assertSame(['Martin'], $tagsOf('VIR MARTIN ACOMPTE'));
        $this->assertSame(2, MoneyTag::query()->whereNull('archived_at')->count());
        $this->assertSame(1, $dupont->transactions()->count());

        // La page du chantier compte ces frais et l'acompte.
        $martin = MoneyTag::query()->where('name', 'Martin')->sole();
        $this->get(route('tags.show', $martin))->assertOk()->assertSee('CB BRICO DEPOT')->assertSee('VIR MARTIN ACOMPTE');
    }

    public function test_unreadable_files_are_explained(): void
    {
        $this->post(route('import.preview'), ['account_id' => $this->account->id, 'files' => [
            UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%%EOF"),
        ]])->assertSessionHasErrors('file');
    }

    public function test_mixed_account_operations_are_classified_afterwards(): void
    {
        $courses = MoneyCategory::query()->where('name', 'Courses')->value('id');
        $outillage = MoneyCategory::query()->where('name', 'Outillage')->value('id');
        foreach ([['CB LECLERC', $courses], ['CB BRICO DEPOT', $outillage], ['CB INCONNU', null]] as [$label, $category]) {
            MoneyTransaction::query()->create(['account_id' => $this->account->id, 'occurred_on' => '2026-10-10', 'amount' => -5000, 'kind' => 'expense', 'category_id' => $category, 'label' => $label, 'source' => 'import']);
        }
        // Compte pas mixte : rien à classer.
        $this->get(route('dashboard'))->assertDontSee('à classer');

        $this->put(route('accounts.update', $this->account), [
            'name' => 'Compte courant', 'kind' => 'courant', 'scope' => 'perso', 'opening_on' => '2026-01-01', 'opening_balance' => '2 000', 'mixed' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->account->fresh()->mixed);

        $this->get(route('dashboard'))->assertSee('3 opérations à classer');
        $page = $this->get(route('transactions.classify'))->assertOk()->assertSee('CB BRICO DEPOT');
        $suggestions = $page->viewData('transactions')->mapWithKeys(fn ($item) => [$item['t']->label => $item['suggestion'][0]]);
        $this->assertSame('pro', $suggestions['CB BRICO DEPOT']);   // Outillage : pro
        $this->assertSame('perso', $suggestions['CB LECLERC']);     // Courses : perso
        $this->get(route('transactions.index', ['type' => 'a-classer', 'periode' => 'tout']))->assertOk()->assertSee('CB INCONNU')->assertSee('À classer');

        $ids = MoneyTransaction::query()->pluck('id', 'label');
        $this->post(route('transactions.classify.store'), ['scope' => [
            $ids['CB LECLERC'] => 'perso', $ids['CB BRICO DEPOT'] => 'pro', $ids['CB INCONNU'] => 'pro',
        ]])->assertSessionHas('status', '3 opérations classées. Tout est classé 👍');

        $this->assertSame('pro', MoneyTransaction::query()->where('label', 'CB INCONNU')->value('scope'));
        $this->assertSame('pro', MoneyScopeRule::query()->where('keyword', 'inconnu')->value('scope'));
        $this->get(route('dashboard'))->assertDontSee('à classer');
        $this->assertSame(10000, app(MoneyStatsService::class)->totals('pro', Carbon::parse('2026-10-01'), today())['expense']);
    }

    public function test_quick_add_and_edit_choose_perso_or_pro(): void
    {
        $this->account->update(['mixed' => true]);
        $this->post(route('transactions.store'), ['type' => 'expense', 'amount' => '89', 'account_id' => $this->account->id, 'label' => 'Gasoil camion', 'occurred_on' => '2026-10-17', 'scope' => 'pro'])
            ->assertSessionHasNoErrors();
        $t = MoneyTransaction::query()->where('label', 'Gasoil camion')->sole();
        $this->assertSame('pro', $t->scope);

        $this->get(route('transactions.edit', $t))->assertOk()->assertSee('Compté en');
        $this->put(route('transactions.update', $t), ['type' => 'expense', 'amount' => '89', 'label' => 'Gasoil camion', 'occurred_on' => '2026-10-17', 'account_id' => $this->account->id, 'scope' => 'perso'])
            ->assertSessionHasNoErrors();
        $this->assertSame('perso', $t->fresh()->scope);

        // Compte pas mixte : perso sur un compte perso = rien de spécial gardé.
        $this->account->update(['mixed' => false]);
        $this->put(route('transactions.update', $t), ['type' => 'expense', 'amount' => '89', 'label' => 'Gasoil camion', 'occurred_on' => '2026-10-17', 'account_id' => $this->account->id, 'scope' => 'perso']);
        $this->assertNull($t->fresh()->scope);
    }
}
