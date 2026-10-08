<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyCategory;
use App\Models\MoneyTag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Chantiers et projets : une étiquette (« Dupont », « Salle de bain ») posée sur
 * des mouvements de toutes catégories donne le coût total, ce qui est encaissé,
 * la marge, et le budget prévu.
 */
class TagController extends Controller
{
    use ReadsMoneyInput;

    public function index(): View
    {
        $tags = MoneyTag::query()->orderByRaw('archived_at IS NOT NULL')->orderByDesc('id')->get();
        $stats = $this->stats();
        $rows = $tags->map(fn (MoneyTag $tag) => ['tag' => $tag] + ($stats[$tag->id] ?? ['income' => 0, 'expense' => 0, 'count' => 0, 'first_on' => null, 'last_on' => null]));

        return view('tags.index', [
            'active' => $rows->filter(fn ($row) => ! $row['tag']->archived_at)->sortByDesc(fn ($row) => $row['last_on'] ?? '0')->values(),
            'archived' => $rows->filter(fn ($row) => $row['tag']->archived_at)->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $tag = MoneyTag::query()->create($data + ['color' => MoneyTag::COLORS[MoneyTag::query()->count() % count(MoneyTag::COLORS)]]);

        return redirect()->route('tags.show', $tag)->with('status', 'Projet créé. Ajoutez l\'étiquette « '.$tag->name.' » à ses dépenses et paiements (champ « Chantier / projet »).');
    }

    public function show(MoneyTag $tag): View
    {
        $stats = $this->stats($tag->id)[$tag->id] ?? ['income' => 0, 'expense' => 0, 'count' => 0, 'first_on' => null, 'last_on' => null];
        $byCategory = DB::table('money_tag_transaction as tt')
            ->join('money_transactions as t', 't.id', '=', 'tt.transaction_id')
            ->where('tt.tag_id', $tag->id)->where('t.kind', '!=', 'transfer')->where('t.amount', '<', 0)
            ->groupBy('t.category_id')->selectRaw('t.category_id, SUM(-t.amount) as total')
            ->pluck('total', 'category_id');
        $categories = MoneyCategory::query()->whereIn('id', $byCategory->keys()->filter())->get()->keyBy('id');

        return view('tags.show', [
            'tag' => $tag,
            'stats' => $stats,
            'categories' => $byCategory->map(fn ($total, $id) => [
                'name' => $categories->get($id)?->name ?? 'Sans catégorie',
                'color' => $categories->get($id)?->color ?? '#B0BEC5',
                'amount' => (int) $total,
            ])->sortByDesc('amount')->values(),
            'transactions' => $tag->transactions()->with(['account', 'category'])->latest('occurred_on')->latest('money_transactions.id')->limit(100)->get(),
        ]);
    }

    public function update(Request $request, MoneyTag $tag): RedirectResponse
    {
        $data = $this->validated($request, $tag);
        $request->validate(['color' => ['nullable', Rule::in(MoneyTag::COLORS)]]);
        $data['color'] = $request->input('color') ?: $tag->color;
        $data['archived_at'] = $request->boolean('archived') ? ($tag->archived_at ?? now()) : null;
        $tag->update($data);

        return redirect()->route('tags.show', $tag)->with('status', 'Enregistré.');
    }

    public function destroy(MoneyTag $tag): RedirectResponse
    {
        $tag->delete();

        return redirect()->route('tags.index')->with('status', 'Projet supprimé (ses mouvements restent, sans cette étiquette).');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?MoneyTag $tag = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('money_tags', 'name')->ignore($tag?->id)],
            'tag_notes' => ['nullable', 'string', 'max:500'],
        ], ['name.unique' => 'Ce nom existe déjà.'], ['name' => 'nom', 'tag_notes' => 'note']);

        return [
            'name' => trim($data['name']),
            'budget' => $this->amount($request, 'budget', required: false),
            'notes' => $data['tag_notes'] ?? null,
        ];
    }

    /**
     * Encaissé, dépensé, nombre de mouvements et dates, par étiquette (virements exclus).
     *
     * @return Collection<int, array{income: int, expense: int, count: int, first_on: ?string, last_on: ?string}>
     */
    private function stats(?int $tagId = null): Collection
    {
        return DB::table('money_tag_transaction as tt')
            ->join('money_transactions as t', 't.id', '=', 'tt.transaction_id')
            ->where('t.kind', '!=', 'transfer')
            ->when($tagId, fn ($q) => $q->where('tt.tag_id', $tagId))
            ->groupBy('tt.tag_id')
            ->selectRaw('tt.tag_id, COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 0) as income')
            ->selectRaw('COALESCE(SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END), 0) as expense')
            ->selectRaw('COUNT(*) as n, MIN(t.occurred_on) as first_on, MAX(t.occurred_on) as last_on')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->tag_id => [
                'income' => (int) $row->income, 'expense' => (int) $row->expense, 'count' => (int) $row->n,
                'first_on' => $row->first_on, 'last_on' => $row->last_on,
            ]]);
    }
}
