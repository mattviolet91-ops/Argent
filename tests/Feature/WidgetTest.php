<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyRecurring;
use App\Models\MoneyTransaction;
use App\Models\MoneyWidgetKey;
use App\Models\User;
use App\Services\MoneyWidgetService;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

/** Widgets iPhone (Scriptable) : clé créée avec le code, résumé en lecture seule, retrait. */
class WidgetTest extends TestCase
{
    use RefreshDatabase;

    private MoneyAccount $perso;

    private MoneyAccount $pro;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 10:00');
        $this->app->instance(PushService::class, Mockery::spy(PushService::class));
        $this->actingAs(User::factory()->create());
        $this->post(route('setup.store'), ['code' => '482913', 'code_confirmation' => '482913', 'perso_balance' => '2 000', 'pro_balance' => '5 000'])->assertSessionHasNoErrors();
        $this->perso = MoneyAccount::query()->where('scope', 'perso')->firstOrFail();
        $this->pro = MoneyAccount::query()->where('scope', 'pro')->firstOrFail();
        MoneyAccount::query()->update(['opening_on' => '2026-09-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function spend(MoneyAccount $account, string $on, int $amount, string $label, ?string $category = null): void
    {
        MoneyTransaction::query()->create([
            'account_id' => $account->id, 'occurred_on' => $on, 'amount' => $amount, 'kind' => MoneyTransaction::kindFor($amount),
            'category_id' => $category ? MoneyCategory::query()->where('name', $category)->value('id') : null, 'label' => $label, 'source' => 'manual',
        ]);
    }

    /** Appel du widget, comme Scriptable : la clé seule, sans session. */
    private function widget(?string $token, string $query = '')
    {
        return $this->withHeaders($token ? ['Authorization' => 'Bearer '.$token] : [])
            ->getJson(route('widgets.data').$query);
    }

    public function test_a_widget_is_created_with_the_code_and_installed_once(): void
    {
        $this->get(route('settings'))->assertOk()->assertSee('Widgets iPhone')->assertSee('Créer le widget');

        $this->post(route('settings.widgets.store'), ['widget_name' => 'Mon iPhone', 'widget_view' => 'all', 'widget_amounts' => '1', 'widget_code' => '111111'])
            ->assertSessionHasErrors('widget_code');
        $this->assertSame(0, MoneyWidgetKey::query()->count());

        $response = $this->post(route('settings.widgets.store'), ['widget_name' => 'Mon iPhone', 'widget_view' => 'all', 'widget_amounts' => '1', 'widget_code' => '482913']);
        $key = MoneyWidgetKey::query()->sole();
        $response->assertRedirect(route('settings.widgets.install', $key));
        $this->assertSame(['Mon iPhone', 'all', true], [$key->name, $key->scope, $key->show_amounts]);

        $page = $this->get(route('settings.widgets.install', $key))->assertOk()->assertSee('Copier le script')->assertSee('scriptable:///add', false);
        $script = $page->viewData('script');
        $this->assertMatchesRegularExpression("/const API = '".preg_quote(route('widgets.data'), '/')."';/", $script);
        $this->assertMatchesRegularExpression("/const KEY = 'argw_[A-Za-z0-9]{40}';/", $script);
        $this->assertStringContainsString('const SCRIPT_VERSION = '.MoneyWidgetService::SCRIPT_VERSION.';', $script);
        $this->assertStringNotContainsString('%%', $script);
        // Seule l'empreinte de la clé est gardée.
        preg_match("/const KEY = '([^']+)'/", $script, $m);
        $this->assertSame(hash('sha256', $m[1]), MoneyWidgetKey::query()->value('token_hash'));
        $this->assertTrue(ActivityLog::query()->where('action', 'argent.widget')->exists());

        // « C'est installé » : la clé ne s'affiche plus.
        $this->post(route('settings.widgets.done'))->assertRedirect(route('settings').'#widgets');
        $this->get(route('settings.widgets.install', $key))->assertOk()->assertSee('n\'est plus affiché', false)->assertDontSee($m[1]);
        $this->get(route('settings'))->assertSee('Mon iPhone')->assertSee('pas encore utilisé');
    }

    public function test_the_widget_reads_the_summary_with_its_key_only(): void
    {
        $this->spend($this->perso, '2026-10-05', -12000, 'CB LECLERC', 'Courses');
        $this->spend($this->pro, '2026-10-10', 250000, 'Paiement devis');
        $this->spend($this->pro, '2026-10-21', -40050, 'CB POINT P');
        MoneyCategory::query()->where('name', 'Courses')->update(['monthly_budget' => 40000]);
        MoneyRecurring::query()->create(['account_id' => $this->perso->id, 'label' => 'EDF', 'amount' => -8000, 'frequency' => 'mensuel', 'next_on' => '2026-10-25', 'active' => true]);
        [$key, $token] = MoneyWidgetKey::issue('iPhone', 'all', true);

        $this->widget(null)->assertStatus(401)->assertJsonPath('error', fn ($e) => str_contains($e, 'retiré'));
        $this->widget('argw_'.str_repeat('x', 40))->assertStatus(401);

        $response = $this->widget($token)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertEmpty($response->headers->getCookies(), 'Pas de session ni de cookie pour un widget.');
        $data = $response->json();
        $this->assertSame('all', $data['view']);
        $this->assertSame("8\u{202F}980\u{00A0}€", $data['balance']['text']);   // 2 000 + 5 000 − 120 + 2 500 − 400,50 = 8 979,50
        $this->assertSame("+1\u{202F}980\u{00A0}€", $data['month']['result']);  // +2 500 − 120 − 400,50 = 1 979,50
        $this->assertSame("−521\u{00A0}€", $data['month']['expense']);   // 520,50
        $this->assertSame('Octobre', $data['month']['label']);
        $this->assertSame(30, $data['budget']['percent']);
        $this->assertSame(['day' => 'dim. 25', 'label' => 'EDF', 'text' => "−80\u{00A0}€", 'income' => false], $data['upcoming'][0]);
        $this->assertCount(MoneyWidgetService::DAYS + 1, $data['history']);
        $this->assertSame(1.0, (float) max($data['history']));
        $this->assertSame('up', $data['trend']['dir']);
        $this->assertNotNull($key->fresh()->last_used_at);

        // Paramètre « pro » sur un widget « Tout » : la partie pro seulement, sans budget perso.
        $pro = $this->widget($token, '?vue=pro')->assertOk()->json();
        $this->assertSame(['pro', 'Pro'], [$pro['view'], $pro['view_label']]);
        $this->assertSame("7\u{202F}100\u{00A0}€", $pro['balance']['text']);
        $this->assertNull($pro['budget']);

        // Widget « Perso » : jamais plus, même si on demande le pro.
        [, $persoToken] = MoneyWidgetKey::issue('Perso', 'perso', true);
        $this->assertSame('perso', $this->widget($persoToken, '?vue=pro')->json('view'));
    }

    public function test_hidden_amounts_show_no_figure(): void
    {
        $this->spend($this->perso, '2026-10-05', -12000, 'CB LECLERC', 'Courses');
        MoneyCategory::query()->where('name', 'Courses')->update(['monthly_budget' => 40000]);
        [, $token] = MoneyWidgetKey::issue('iPhone', 'all', false);

        $data = $this->widget($token)->assertOk()->json();
        $this->assertTrue($data['hidden']);
        $this->assertSame('•••', $data['balance']['text']);
        $this->assertSame('•••', $data['balance']['short']);
        $this->assertSame('En baisse sur 30 jours', $data['trend']['text']);
        $this->assertSame(30, $data['budget']['percent']);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('€', $json);
    }

    public function test_a_removed_widget_stops_working(): void
    {
        [$key, $token] = MoneyWidgetKey::issue('Vieux iPhone', 'all', true);
        $this->widget($token)->assertOk();

        $this->put(route('settings.widgets.update', $key), ['view' => 'perso'])->assertRedirect();
        $this->assertSame(['perso', false], [$key->fresh()->scope, $key->fresh()->show_amounts]);

        $this->delete(route('settings.widgets.destroy', $key))->assertRedirect(route('settings').'#widgets');
        $this->assertSame(0, MoneyWidgetKey::query()->count());
        $this->widget($token)->assertStatus(401);
    }

    public function test_balance_history_and_amount_formats(): void
    {
        $this->spend($this->perso, '2026-09-20', -5000, 'Avant la courbe');
        $this->spend($this->perso, '2026-10-01', -20000, 'Loyer');
        $this->spend($this->perso, '2026-10-22', 30000, 'Remboursement');
        $this->spend($this->perso, '2026-10-30', -99900, 'Plus tard');

        $history = app(MoneyWidgetService::class)->history([$this->perso->id], 205000);
        $this->assertCount(31, $history);
        $this->assertSame(205000, $history[30]);          // aujourd'hui
        $this->assertSame(175000, $history[29]);          // hier : avant le remboursement
        $this->assertSame(195000, $history[0]);           // 22/09 : avant le loyer (le 20/09 est hors de la courbe)
        $shape = MoneyWidgetService::normalize($history);
        $this->assertSame([0.667, 0.0, 1.0], [$shape[0], $shape[29], $shape[30]]);

        $this->assertSame("3\u{202F}245\u{00A0}€", MoneyWidgetService::euros(324512));
        $this->assertSame("+80\u{00A0}€", MoneyWidgetService::euros(8000, true));
        $this->assertSame("−80\u{00A0}€", MoneyWidgetService::euros(-8000, true));
        $this->assertSame("0\u{00A0}€", MoneyWidgetService::euros(-20, true));
        $this->assertSame("32,4\u{00A0}k€", MoneyWidgetService::short(3249999));
        $this->assertSame("−1,2\u{00A0}M€", MoneyWidgetService::short(-125000000));
    }
}
