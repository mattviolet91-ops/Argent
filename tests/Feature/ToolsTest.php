<?php

namespace Tests\Feature;

use App\Models\MoneyAccount;
use App\Models\MoneyAttachment;
use App\Models\MoneyCategory;
use App\Models\MoneyCredit;
use App\Models\MoneyRecurring;
use App\Models\MoneyTag;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\MoneyAlertService;
use App\Services\MoneyStatsService;
use App\Services\MoneySyncService;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/** Justificatifs, chantiers, notes de frais, hausses de prix, simulateur, crédits, patrimoine, an dernier, PDF. */
class ToolsTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $push;

    private MoneyAccount $perso;

    private MoneyAccount $pro;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00');
        $this->push = Mockery::spy(PushService::class);
        $this->app->instance(PushService::class, $this->push);
        $this->actingAs(User::factory()->create());
        $this->post(route('setup.store'), ['code' => '482913', 'code_confirmation' => '482913', 'perso_balance' => '3 000', 'pro_balance' => '10 000'])->assertSessionHasNoErrors();
        $this->perso = MoneyAccount::query()->where('scope', 'perso')->firstOrFail();
        $this->pro = MoneyAccount::query()->where('scope', 'pro')->firstOrFail();
        MoneyAccount::query()->update(['opening_on' => '2025-01-01']);
        $this->perso->refresh();
        $this->pro->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function category(string $name): int
    {
        return (int) MoneyCategory::query()->where('name', $name)->value('id');
    }

    private function add(array $data): void
    {
        $this->post(route('transactions.store'), $data + ['type' => 'expense', 'account_id' => $this->perso->id, 'occurred_on' => '2026-10-10'])->assertSessionHasNoErrors();
    }

    public function test_menu_is_grouped_and_tabs_follow_the_section(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertSee('Au quotidien')->assertSee('Analyses')->assertSee('Chantiers et projets');
        $tabs = $this->get(route('credits.index'))->assertOk()->getContent();
        $nav = substr($tabs, strpos($tabs, 'money-tabs'), 1500);
        $this->assertStringContainsString('Patrimoine', $nav);
        $this->assertStringNotContainsString('Mouvements', $nav);
    }

    public function test_attachments_on_any_transaction(): void
    {
        Storage::fake('local');
        $this->add(['amount' => '42,90', 'label' => 'Resto client', 'attachment' => UploadedFile::fake()->image('ticket.jpg', 600, 900)]);
        $t = MoneyTransaction::query()->where('label', 'Resto client')->sole();
        $file = $t->attachments()->sole();
        Storage::disk('local')->assertExists($file->path);
        $this->assertStringStartsWith('argent-justificatifs/', $file->path);

        $this->get(route('transactions.index'))->assertSee('justificatif');
        $this->get(route('transactions.edit', $t))->assertOk()->assertSee('Justificatifs')->assertSee(route('attachments.show', $file));
        $response = $this->get(route('attachments.show', $file))->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));

        // Jusqu'à 5 par mouvement.
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('attachments.store', $t), ['justificatif' => UploadedFile::fake()->create('facture.pdf', 30, 'application/pdf')])->assertSessionHasNoErrors();
        }
        $this->post(route('attachments.store', $t), ['justificatif' => UploadedFile::fake()->create('encore.pdf', 30, 'application/pdf')])->assertSessionHasErrors('justificatif');
        $this->assertSame(5, $t->attachments()->count());

        // Mouvement supprimé : ses fichiers aussi.
        $paths = $t->attachments()->pluck('path');
        $this->delete(route('transactions.destroy', $t));
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertSame(0, MoneyAttachment::query()->count());
    }

    public function test_tags_give_the_cost_and_margin_of_a_job(): void
    {
        $this->add(['amount' => '1 200', 'label' => 'Tuiles', 'category_id' => $this->category('Matériaux'), 'account_id' => $this->pro->id, 'tags' => 'Chantier Dupont']);
        $this->add(['amount' => '300', 'label' => 'Nacelle', 'category_id' => $this->category('Location matériel'), 'account_id' => $this->pro->id, 'tags' => 'chantier dupont, Toiture 2026']);
        $this->add(['type' => 'income', 'amount' => '4 500', 'label' => 'Paiement Dupont', 'account_id' => $this->pro->id, 'tags' => 'Chantier Dupont']);
        $this->add(['amount' => '80', 'label' => 'Autre chose', 'account_id' => $this->pro->id]);

        $this->assertSame(2, MoneyTag::query()->count());
        $dupont = MoneyTag::query()->where('name', 'Chantier Dupont')->sole();
        $dupont->update(['budget' => 200000]);

        $this->get(route('tags.index'))->assertOk()->assertSee('Chantier Dupont')->assertSee('3 mouvements');
        $this->get(route('tags.show', $dupont))->assertOk()
            ->assertSee("1\u{202F}500,00", false)->assertSee("4\u{202F}500,00", false)->assertSee("3\u{202F}000,00", false)
            ->assertSee('Matériaux')->assertSee('75 %');
        $this->get(route('transactions.index', ['projet' => $dupont->id, 'periode' => 'tout']))->assertOk()
            ->assertSee('Tuiles')->assertDontSee('Autre chose');

        // Les paiements venus de l'app de devis s'étiquettent aussi.
        $devis = MoneyTransaction::query()->create(['account_id' => $this->pro->id, 'occurred_on' => '2026-10-12', 'amount' => 50000, 'kind' => 'income', 'label' => 'Acompte', 'source' => 'devis', 'source_ref' => 'payment:9']);
        $this->put(route('transactions.update', $devis), ['label' => 'Acompte', 'tags' => 'Chantier Dupont'])->assertSessionHasNoErrors();
        $this->assertTrue($devis->tags()->whereKey($dupont->id)->exists());
    }

    public function test_expense_claims_count_as_pro_until_reimbursed(): void
    {
        $this->add(['amount' => '64,50', 'label' => 'Visserie', 'category_id' => $this->category('Matériaux'), 'claim' => '1', 'occurred_on' => '2026-09-01']);
        $claim = MoneyTransaction::query()->where('label', 'Visserie')->sole();
        $this->assertSame('a_rembourser', $claim->claim);
        $this->assertSame('pro', $claim->scope);

        $stats = app(MoneyStatsService::class);
        $this->assertSame(0, $stats->totals('perso', Carbon::parse('2026-09-01'), today())['expense']);
        $this->assertSame(6450, $stats->totals('pro', Carbon::parse('2026-09-01'), today())['expense']);
        // Payé avec le compte perso : son solde baisse quand même.
        $this->assertSame(300000 - 6450, $this->perso->balance());

        // Plus d'un mois : rappel.
        app(MoneyAlertService::class)->check();
        $this->push->shouldHaveReceived('send')->once()->withArgs(fn ($title) => $title === 'Notes de frais');

        $this->get(route('claims.index'))->assertOk()->assertSee('Visserie')->assertSee('64,50');
        $this->post(route('claims.settle'), ['ids' => [$claim->id], 'settled_on' => '2026-10-13', 'from_account' => $this->pro->id, 'to_account' => $this->perso->id])
            ->assertSessionHasNoErrors();
        $this->assertSame('rembourse', $claim->fresh()->claim);
        $this->assertSame(300000, $this->perso->fresh()->balance());
        $this->assertSame(1000000 - 6450, $this->pro->fresh()->balance());
        // Le remboursement n'est ni gagné ni dépensé.
        $this->assertSame(0, $stats->totals('perso', Carbon::parse('2026-10-01'), today())['income']);
        $this->get(route('claims.index'))->assertSee('Déjà remboursées');
    }

    public function test_a_subscription_price_rise_is_spotted_at_import(): void
    {
        MoneyRecurring::query()->create(['label' => 'Netflix', 'amount' => -1349, 'account_id' => $this->perso->id, 'category_id' => $this->category('Abonnements'), 'frequency' => 'mensuel', 'next_on' => '2026-10-05', 'active' => true]);
        app(MoneySyncService::class)->runRecurring();
        $planned = MoneyTransaction::query()->where('source', 'recurring')->sole();

        $csv = implode("\n", ['Date;Libellé;Montant', '06/10/2026;PRLV SEPA NETFLIX.COM 4512;-15,99', '07/10/2026;CB BOULANGERIE;-4,20']);
        $this->post(route('import.preview'), ['account_id' => $this->perso->id, 'file' => UploadedFile::fake()->createWithContent('releve.csv', $csv)]);
        $rows = $this->get(route('import.show'))->assertOk()->assertSee('Nouveau prix')->viewData('rows');
        $this->assertSame(['price', 'new'], array_column($rows, 'status'));

        $this->post(route('import.store'), ['import' => [0 => 1, 1 => 1]])->assertSessionHas('status');

        $this->assertNull($planned->fresh());
        $recurring = MoneyRecurring::query()->sole();
        $this->assertSame(-1599, $recurring->amount);
        $this->assertSame($recurring->id, MoneyTransaction::query()->where('amount', -1599)->value('recurring_id'));
        $this->push->shouldHaveReceived('send')->once()->withArgs(fn ($title, $body) => $title === 'Abonnement plus cher' && ! str_contains($body, '€'));
        $this->get(route('dashboard'))->assertSee('Abonnement plus cher')->assertSee('« Netflix » change de prix (+19 %)', false);
    }

    public function test_can_i_afford_it(): void
    {
        MoneyRecurring::query()->create(['label' => 'Loyer', 'amount' => -90000, 'account_id' => $this->perso->id, 'frequency' => 'mensuel', 'next_on' => '2026-11-01', 'active' => true]);

        $this->get(route('afford', ['montant' => '500', 'compte' => $this->perso->id]))->assertOk()
            ->assertSee('Oui, sans souci')->assertSee('il resterait')->assertSee('700,00', false);
        $this->get(route('afford', ['montant' => '2 500', 'compte' => $this->perso->id]))->assertOk()
            ->assertSee('Mieux vaut attendre')->assertSee('après « Loyer »', false);
        $this->get(route('afford', ['montant' => '2 400', 'compte' => $this->perso->id, 'fois' => 3, 'le' => '2026-10-20']))->assertOk()
            ->assertSee('20/10 : 800,00 €')->assertSee('20/12 : 800,00 €');
        $this->get(route('afford'))->assertOk()->assertDontSee('Mieux vaut attendre');
    }

    public function test_credit_tracking_and_monthly_payment_in_the_fixes(): void
    {
        $this->post(route('credits.store'), [
            'name' => 'Crédit camion', 'principal' => '18 000', 'rate' => '3,45', 'months' => 60, 'first_due_on' => '2025-01-05',
            'insurance' => '9', 'credit_account' => $this->pro->id,
        ])->assertSessionHasNoErrors();
        $credit = MoneyCredit::query()->sole();
        $this->assertSame(345, $credit->rate);
        $this->assertEqualsWithDelta(32703, $credit->monthly, 2);
        $this->assertSame(22, $credit->paidCount());
        $schedule = $credit->schedule();
        $this->assertSame($schedule[21]['remaining'], $credit->remaining());
        $this->assertSame(0, end($schedule)['remaining']);
        $this->assertSame('2029-12-05', $credit->endDate()->toDateString());

        $this->get(route('credits.show', $credit))->assertOk()->assertSee('Décembre 2029')->assertSee('38')->assertSee('sur 60');
        $this->get(route('credits.index'))->assertOk()->assertSee('Crédit camion');

        $this->post(route('credits.recurring', $credit))->assertSessionHasNoErrors();
        $recurring = MoneyRecurring::query()->sole();
        $this->assertSame('2026-11-05', $recurring->next_on->toDateString());
        $this->assertSame(-($credit->monthly + 900), $recurring->amount);

        // Avec la mensualité au lieu de la durée.
        $this->post(route('credits.store'), ['name' => 'Prêt travaux', 'principal' => '10 000', 'rate' => '0', 'monthly' => '250', 'first_due_on' => '2026-11-10'])->assertSessionHasNoErrors();
        $this->assertSame(40, MoneyCredit::query()->where('name', 'Prêt travaux')->value('months'));
        $this->post(route('credits.store'), ['name' => 'Trop peu', 'principal' => '10 000', 'rate' => '12', 'monthly' => '50', 'first_due_on' => '2026-11-10'])->assertSessionHasErrors('monthly');
    }

    public function test_wealth_over_twelve_months(): void
    {
        MoneyTransaction::query()->create(['account_id' => $this->perso->id, 'occurred_on' => '2026-06-10', 'amount' => 50000, 'kind' => 'income', 'label' => 'Prime', 'source' => 'manual']);
        MoneyCredit::query()->create(['name' => 'Voiture', 'principal' => 600000, 'rate' => 0, 'months' => 12, 'monthly' => 50000, 'first_due_on' => '2026-05-01']);

        $response = $this->get(route('wealth'))->assertOk()->assertSee('Patrimoine net');
        $history = $response->viewData('history');
        $this->assertCount(12, $history);
        $today = end($history);
        // 3 000 + 500 (perso) + 10 000 (pro) − 6 crédits restants × 500.
        $this->assertSame(1350000 - 300000, $today['net']);
        $this->assertSame(1300000, $history[0]['net']);
    }

    public function test_same_month_last_year(): void
    {
        foreach (['2025-10-05' => 20000, '2026-10-05' => 30000] as $date => $cents) {
            MoneyTransaction::query()->create(['account_id' => $this->perso->id, 'occurred_on' => $date, 'amount' => -$cents, 'kind' => 'expense', 'category_id' => $this->category('Courses'), 'label' => 'Courses', 'source' => 'manual']);
        }

        $this->get(route('dashboard'))->assertOk()->assertSee('vs oct. 2025');
        $this->get(route('trends'))->assertOk()->assertSee('Comparé à octobre 2025')->assertSee('+50 %');
        $this->get(route('reports.index'))->assertOk()->assertSee('Dépensé vs 2025');
    }

    public function test_monthly_pdf_report(): void
    {
        $this->add(['amount' => '120', 'label' => 'Tuiles', 'account_id' => $this->pro->id, 'category_id' => $this->category('Matériaux'), 'tags' => 'Dupont']);

        $response = $this->get(route('reports.pdf', ['mois' => '2026-10', 'vue' => 'pro', 'detail' => 1]))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('bilan-argent-2026-10-pro.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->get(route('reports.pdf', ['mois' => '2027-01']))->assertNotFound();
        $this->get(route('reports.index'))->assertSee('Bilan d\'un mois en PDF', false);
    }
}
