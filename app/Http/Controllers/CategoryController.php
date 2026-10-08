<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyCategory;
use App\Models\MoneyTransaction;
use App\Services\MoneyAlertService;
use App\Services\MoneyStatsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Catégories et budgets du mois (avec la moyenne des 3 derniers mois pour se fixer un budget réaliste). */
class CategoryController extends Controller
{
    use ReadsMoneyInput;

    public function index(MoneyStatsService $stats): View
    {
        $month = today();
        $categories = MoneyCategory::query()->active()->ordered()->get();
        $spent = MoneyTransaction::query()->real()->betweenDates($month->copy()->startOfMonth(), $month->copy()->endOfMonth())
            ->groupBy('category_id')->selectRaw('category_id, SUM(amount) as total')->pluck('total', 'category_id');
        $average = MoneyTransaction::query()->real()
            ->betweenDates($month->copy()->startOfMonth()->subMonthsNoOverflow(3), $month->copy()->startOfMonth()->subDay())
            ->groupBy('category_id')->selectRaw('category_id, SUM(amount) as total')->pluck('total', 'category_id')
            ->map(fn ($total) => (int) round(abs((int) $total) / 3));

        return view('categories.index', [
            'expenses' => $categories->where('type', 'expense'),
            'incomes' => $categories->where('type', 'income'),
            'archived' => MoneyCategory::query()->whereNotNull('archived_at')->ordered()->get(),
            'spent' => $spent->map(fn ($total) => abs((int) $total)),
            'average' => $average,
            'budgets' => $stats->budgets(),
            'month' => $month,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(array_keys(MoneyCategory::TYPES))],
            'scope' => ['required', Rule::in(array_keys(MoneyCategory::SCOPES))],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [], ['name' => 'nom']);
        $data['color'] ??= '#8A99A6';
        $data['monthly_budget'] = $data['type'] === 'expense' ? $this->amount($request, 'monthly_budget', required: false) : null;
        $data['position'] = (int) MoneyCategory::query()->max('position') + 1;
        MoneyCategory::query()->create($data);

        return redirect()->route('categories.index')->with('status', 'Catégorie ajoutée.');
    }

    public function update(Request $request, MoneyCategory $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'scope' => ['sometimes', Rule::in(array_keys(MoneyCategory::SCOPES))],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [], ['name' => 'nom']);
        if ($request->has('monthly_budget') && $category->type === 'expense') {
            $data['monthly_budget'] = $this->amount($request, 'monthly_budget', required: false);
        }
        if ($request->has('restore')) {
            $data['archived_at'] = null;
        }
        $category->update(array_filter($data, fn ($value, $key) => $key !== 'color' || $value, ARRAY_FILTER_USE_BOTH));
        app(MoneyAlertService::class)->check();

        return redirect()->route('categories.index')->with('status', 'Catégorie « '.$category->name.' » enregistrée.');
    }

    /** Une catégorie utilisée (ou utilisée par les devis) est archivée, pas supprimée. */
    public function destroy(MoneyCategory $category): RedirectResponse
    {
        if ($category->system_key || $category->transactions()->exists()) {
            $category->update(['archived_at' => now()]);

            return redirect()->route('categories.index')->with('status', 'Catégorie masquée (les anciens mouvements la gardent).');
        }
        $category->delete();

        return redirect()->route('categories.index')->with('status', 'Catégorie supprimée.');
    }
}
