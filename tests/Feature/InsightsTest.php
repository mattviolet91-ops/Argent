<?php

namespace Tests\Feature;

use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyLoanEntry;
use App\Models\MoneyPerson;
use App\Models\MoneyPurchase;
use App\Models\MoneyRecurring;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\MoneyAlertService;
use App\Services\MoneyReportService;
use App\Services\MoneyStatsService;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/** Tendances, calendrier, garanties, abonnements repérés, « Qui me doit quoi ». */
class InsightsTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $push;

    private MoneyAccount $perso;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00');
        $this->push = Mockery::spy(PushService::class);
        $this->app->instance(PushService::class, $this->push);
        $this->actingAs(User::factory()->create());
        $this->post(route('setup.store'), ['code' => '482913', 'code_confirmation' => '482913', 'perso_balance' => '5 000'])->assertSessionHasNoErrors();
        $this->perso = MoneyAccount::query()->where('name', 'Compte perso')->sole();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function spend(string $date, int $cents, ?string $category = null, string $label = 'Dépense', string $source = 'manual'): MoneyTransaction
    {
        return MoneyTransaction::query()->create([
            'account_id' => $this->perso->id, 'occurred_on' => $date, 'amount' => -$cents, 'kind' => 'expense',
            'category_id' => $category ? MoneyCategory::query()->where('name', $category)->value('id') : null,
            'label' => $label, 'source' => $source,
        ]);
    }

    public function test_trends_compare_this_month_with_the_usual(): void
    {
        // D'habitude : 100 € de restaurants du 1er au 14, 60 € de carburant.
        foreach (['2026-07-05', '2026-08-05', '2026-09-05'] as $date) {
            $this->spend($date, 10000, 'Restaurants, sorties');
            $this->spend($date, 6000, 'Carburant, péage, parking');
        }
        // Après le 14 : pas compté dans « à cette date ».
        $this->spend('2026-09-20', 50000, 'Restaurants, sorties');
        // Ce mois-ci : 130 € de restaurants (+30 %), 20 € de carburant (−67 %).
        $this->spend('2026-10-03', 13000, 'Restaurants, sorties');
        $this->spend('2026-10-10', 2000, 'Carburant, péage, parking');
        // Un poste nouveau ne fait pas une tendance.
        $this->spend('2026-10-11', 9000, 'Cadeaux');

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Tendances du mois')
            ->assertSee('Ce mois-ci, vous dépensez 30 % de plus en restaurants, sorties que d&#039;habitude.', false)
            ->assertSee('Ce mois-ci, vous dépensez 67 % de moins en carburant, péage, parking que d&#039;habitude.', false)
            ->assertDontSee('en cadeaux');

        $this->get(route('trends'))->assertOk()
            ->assertSee('du 1er au 14 octobre')
            ->assertSee('Restaurants, sorties')->assertSee('+30 %')
            ->assertSee('nouveau');
    }

    public function test_weekly_notification_mentions_a_strong_trend(): void
    {
        foreach (['2026-07-05', '2026-08-05', '2026-09-05'] as $date) {
            $this->spend($date, 10000, 'Restaurants, sorties');
        }
        $this->spend('2026-10-06', 20000, 'Restaurants, sorties');

        $report = app(MoneyReportService::class)->build(Carbon::parse('2026-10-05'));
        app(MoneyReportService::class)->notify($report);

        $this->push->shouldHaveReceived('send')->once()->withArgs(fn ($title, $body) => $title === 'Bilan de la semaine'
            && str_contains($body, 'Ce mois-ci, vous dépensez 100 % de plus en restaurants, sorties que d\'habitude.')
            && ! str_contains($body, '€'));
    }

    public function test_calendar_shows_each_day_and_what_is_coming(): void
    {
        $this->spend('2026-10-03', 4550, 'Courses', 'Supermarché');
        MoneyRecurring::query()->create([
            'label' => 'Loyer', 'amount' => -80000, 'account_id' => $this->perso->id, 'frequency' => 'mensuel', 'next_on' => '2026-10-28', 'active' => true,
        ]);
        MoneyPurchase::query()->create(['name' => 'Perceuse', 'purchased_on' => '2024-10-25', 'warranty_until' => '2026-10-25']);

        $this->get(route('calendar'))->assertOk()
            ->assertSee('Octobre 2026')
            ->assertSee('−46')                       // le 3 : 45,50 € arrondis
            ->assertSee('Loyer')->assertSee('Fin de garantie · Perceuse')
            ->assertSee('Mercredi 14 octobre');      // aujourd'hui sélectionné

        $this->get(route('calendar', ['mois' => '2026-10', 'jour' => '2026-10-03']))->assertOk()
            ->assertSee('Samedi 3 octobre')->assertSee('Supermarché');

        // Mois suivant : le loyer revient le 28 novembre.
        $this->get(route('calendar', ['mois' => '2026-11', 'jour' => '2026-11-28']))->assertOk()
            ->assertSee('Novembre 2026')->assertSee('Loyer');

        // Mois invalide : mois en cours.
        $this->get(route('calendar', ['mois' => '2026-13']))->assertOk()->assertSee('Octobre 2026');

        $this->assertCount(3, app(MoneyStatsService::class)->occurrences(Carbon::parse('2026-10-15'), Carbon::parse('2026-12-31')));
    }

    public function test_purchase_with_invoice_and_warranty_reminder(): void
    {
        Storage::fake('local');

        $this->post(route('purchases.store'), [
            'name' => 'Lave-linge', 'shop' => 'Darty', 'purchased_on' => '2024-11-05', 'price' => '499,90',
            'warranty' => '24', 'file' => UploadedFile::fake()->image('facture.jpg', 800, 1100),
        ])->assertSessionHasNoErrors();

        $purchase = MoneyPurchase::query()->sole();
        $this->assertSame('2026-11-05', $purchase->warranty_until->toDateString());
        $this->assertSame(49990, $purchase->amount);
        $this->assertSame('image/jpeg', $purchase->file_mime);
        Storage::disk('local')->assertExists($purchase->file_path);
        $this->assertStringStartsWith('argent-factures/', $purchase->file_path);

        $this->get(route('purchases.show', $purchase))->assertOk()->assertSee('La garantie se termine le')->assertSee('05/11/2026');
        $this->get(route('purchases.index'))->assertOk()->assertSee('Lave-linge')->assertSee('plus que 22 jours');

        $file = $this->get(route('purchases.file', $purchase));
        $file->assertOk();
        $this->assertSame('image/jpeg', $file->headers->get('Content-Type'));
        $this->assertStringContainsString("default-src 'none'", (string) $file->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('inline', (string) $file->headers->get('Content-Disposition'));

        // Rappel : une seule notification, et sur le résumé.
        app(MoneyAlertService::class)->check();
        app(MoneyAlertService::class)->check();
        $this->push->shouldHaveReceived('send')->once()->withArgs(fn ($title) => $title === 'Fin de garantie');
        $this->get(route('dashboard'))->assertSee('Fin de garantie')->assertSee('« Lave-linge » n&#039;est plus garanti après le 05/11/2026', false);

        // Un fichier qui n'est ni une photo ni un PDF est refusé.
        $this->put(route('purchases.update', $purchase), [
            'name' => 'Lave-linge', 'purchased_on' => '2024-11-05', 'warranty' => 'date', 'warranty_until' => '2026-11-05',
            'file' => UploadedFile::fake()->createWithContent('facture.html', '<script>alert(1)</script>'),
        ])->assertSessionHasErrors('file');

        // Suppression : la facture part avec.
        $path = $purchase->file_path;
        $this->delete(route('purchases.destroy', $purchase))->assertRedirect(route('purchases.index'));
        Storage::disk('local')->assertMissing($path);
    }

    public function test_invoice_needs_the_app_unlocked(): void
    {
        Storage::fake('local');
        $this->post(route('purchases.store'), [
            'name' => 'Téléphone', 'purchased_on' => '2026-10-01', 'warranty' => '24', 'file' => UploadedFile::fake()->create('facture.pdf', 50, 'application/pdf'),
        ]);
        $purchase = MoneyPurchase::query()->sole();
        $this->post(route('lock'));

        $this->get(route('purchases.file', $purchase))->assertRedirect(route('unlock'));
    }

    public function test_purchase_from_a_transaction_is_prefilled(): void
    {
        $t = $this->spend('2026-10-02', 12900, 'Outillage', 'Perceuse Makita');

        $this->get(route('transactions.edit', $t))->assertSee('Garder la facture et la garantie');
        $this->get(route('purchases.index', ['mouvement' => $t->id]))->assertOk()
            ->assertSee('value="Perceuse Makita"', false)->assertSee('value="129,00"', false)
            ->assertSee('name="transaction_id" value="'.$t->id.'"', false);
    }

    public function test_subscriptions_are_detected_and_added_to_the_fixes(): void
    {
        foreach (['2026-06-05', '2026-07-05', '2026-08-05', '2026-09-04', '2026-10-05'] as $date) {
            $this->spend($date, 1349, 'Abonnements', 'PRLV SEPA NETFLIX.COM '.$date, 'import');
        }
        // Courses : montants différents, pas un abonnement.
        foreach (['2026-07-02' => 8530, '2026-08-01' => 6012, '2026-09-03' => 11240, '2026-10-02' => 4321] as $date => $cents) {
            $this->spend($date, $cents, 'Courses', 'CB CARREFOUR MARKET', 'import');
        }
        // Salle de sport : 3 fois, puis plus rien depuis juin (résilié).
        foreach (['2026-04-10', '2026-05-10', '2026-06-10'] as $date) {
            $this->spend($date, 2999, null, 'PRLV BASIC FIT', 'import');
        }

        $this->get(route('dashboard'))->assertSee('1 abonnement repéré');
        $this->get(route('recurrings.index'))->assertOk()
            ->assertSee('Abonnements repérés')->assertSee('value="Netflix"', false)
            ->assertSee('vu 5 fois')->assertDontSee('Carrefour')->assertDontSee('Basic Fit');

        $this->post(route('recurrings.adopt'), ['key' => $this->perso->id.'|netflix com|mensuel', 'sub_label' => 'Netflix'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $recurring = MoneyRecurring::query()->sole();
        $this->assertSame('Netflix', $recurring->label);
        $this->assertSame(-1349, $recurring->amount);
        $this->assertSame('2026-11-05', $recurring->next_on->toDateString());
        $this->assertSame(MoneyCategory::query()->where('name', 'Abonnements')->value('id'), $recurring->category_id);
        $this->assertSame(5, MoneyTransaction::query()->where('recurring_id', $recurring->id)->count());
        // Rien n'est ajouté en double.
        $this->assertSame(0, MoneyTransaction::query()->where('source', 'recurring')->count());
        $this->get(route('recurrings.index'))->assertDontSee('Abonnements repérés');
    }

    public function test_a_subscription_can_be_dismissed(): void
    {
        foreach (['2026-07-12', '2026-08-12', '2026-09-12', '2026-10-12'] as $date) {
            $this->spend($date, 2500, null, 'VIR PERMANENT EPARGNE ENFANT', 'import');
        }
        $this->get(route('recurrings.index'))->assertSee('Abonnements repérés');

        $this->post(route('recurrings.dismiss'), ['key' => $this->perso->id.'|permanent epargne|mensuel'])->assertSessionHasNoErrors();

        $this->get(route('recurrings.index'))->assertDontSee('Abonnements repérés');
        $this->get(route('dashboard'))->assertDontSee('abonnement repéré');
    }

    public function test_who_owes_me_what(): void
    {
        $this->perso->update(['opening_on' => '2026-09-01']);
        $this->post(route('loans.store'), [
            'name' => 'Paul', 'relation' => 'ami', 'loan_type' => 'pret', 'loan_amount' => '200', 'loan_on' => '2026-10-01',
            'loan_account' => $this->perso->id, 'note' => 'Réparation voiture', 'due_on' => '2026-10-10',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Enregistré. Paul vous doit 200,00 €.');

        $paul = MoneyPerson::query()->sole();
        $this->assertSame(20000, $paul->balance());
        // Sur le compte : l'argent est sorti, mais ce n'est pas une dépense.
        $this->assertSame(500000 - 20000, $this->perso->balance());
        $this->assertSame(0, app(MoneyStatsService::class)->totals('all', today()->startOfMonth(), today())['expense']);

        // Même nom (majuscules) : même personne. Remboursement de 50 €.
        $this->post(route('loans.store'), ['name' => 'PAUL', 'loan_type' => 'rembourse', 'loan_amount' => '50', 'loan_on' => '2026-10-12'])->assertSessionHasNoErrors();
        $this->assertSame(1, MoneyPerson::query()->count());
        $this->assertSame(15000, $paul->fresh()->balance());

        // Il devait rembourser le 10 : en retard, une seule notification.
        app(MoneyAlertService::class)->check();
        app(MoneyAlertService::class)->check();
        $this->push->shouldHaveReceived('send')->once()->withArgs(fn ($title) => $title === 'Remboursement en retard');
        $this->get(route('loans.index'))->assertOk()->assertSee('Paul')->assertSee('en retard depuis le 10/10')->assertSee('150,00');
        $this->get(route('dashboard'))->assertSee('Qui me doit quoi')->assertSee('Remboursement en retard');

        // Le mouvement du prêt ne se modifie pas en dehors de la fiche.
        $loanTransaction = MoneyTransaction::query()->where('source', 'loan')->sole();
        $this->assertSame('Prêt à Paul', $loanTransaction->label);
        $this->get(route('transactions.edit', $loanTransaction))->assertOk()->assertSee('Noté depuis « Qui me doit quoi »', false);
        $this->put(route('transactions.update', $loanTransaction), ['label' => 'Prêt Paul', 'amount' => '999', 'occurred_on' => '2026-10-02']);
        $this->assertSame(-20000, $loanTransaction->fresh()->amount);

        // Supprimer la ligne retire aussi le mouvement du compte.
        $entry = MoneyLoanEntry::query()->where('type', 'pret')->sole();
        $this->delete(route('loans.entry.destroy', $entry))->assertRedirect(route('loans.show', $paul));
        $this->assertNull($loanTransaction->fresh());
        $this->assertSame(-5000, $paul->fresh()->balance());
        $this->get(route('loans.show', $paul))->assertOk()->assertSee('Vous lui devez');
    }

    public function test_reminders_can_be_turned_off(): void
    {
        MoneyPurchase::query()->create(['name' => 'Écran', 'purchased_on' => '2024-10-20', 'warranty_until' => '2026-10-20']);
        $this->put(route('settings.update'), ['lock_minutes' => 15, 'alerts_enabled' => '1', 'lock_on_leave' => '1'])->assertSessionHasNoErrors();

        app(MoneyAlertService::class)->check();
        $this->push->shouldNotHaveReceived('send');
    }
}
