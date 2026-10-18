<?php

namespace App\Http\Controllers;

use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyTransaction;
use App\Services\MoneyImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « À classer » : les opérations d'un compte mixte (perso et pro sur le même compte)
 * pas encore classées. Chacune arrive avec une proposition (déjà appris, catégorie,
 * compte) : un tour d'œil, on corrige ce qui ne va pas, on enregistre la page.
 */
class ClassifyController extends Controller
{
    public const PER_PAGE = 40;

    public function __construct(private readonly MoneyImportService $importer) {}

    /** Opérations à classer (comptes mixtes, ni virement ni déjà classées). */
    public static function pending(): Builder
    {
        return MoneyTransaction::query()->real()->whereNull('scope')
            ->whereIn('account_id', MoneyAccount::query()->where('mixed', true)->select('id'));
    }

    public function index(): View
    {
        $transactions = self::pending()->with(['account', 'category'])->latest('occurred_on')->latest('id')->limit(self::PER_PAGE)->get();
        $rules = $this->importer->scopeRules();
        $categoryScopes = MoneyCategory::query()->pluck('scope', 'id');

        return view('transactions.classify', [
            'transactions' => $transactions->map(fn (MoneyTransaction $t) => [
                't' => $t,
                'suggestion' => $this->importer->guessScope($t->label, $t->category_id, $t->account, $rules, $categoryScopes),
            ]),
            'total' => self::pending()->count(),
            'mixedAccounts' => MoneyAccount::query()->where('mixed', true)->active()->ordered()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $choices = array_filter((array) $request->input('scope', []), fn ($scope) => in_array($scope, ['perso', 'pro'], true));
        $transactions = self::pending()->whereIn('id', array_map('intval', array_keys($choices)))->get();
        foreach ($transactions as $t) {
            $t->update(['scope' => $choices[$t->id]]);
            $this->importer->learnScope($t->label, $choices[$t->id]);
        }
        $left = self::pending()->count();

        return redirect()->route('transactions.classify')->with('status', $transactions->count().' opération'.($transactions->count() > 1 ? 's classées' : ' classée').'.'
            .($left ? ' Encore '.$left.' à classer.' : ' Tout est classé 👍'));
    }
}
