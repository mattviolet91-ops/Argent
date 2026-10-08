<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyPurchase;
use App\Models\MoneyTransaction;
use App\Services\MoneyAlertService;
use App\Services\PrivateFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Garanties et factures d'achat : la facture (photo ou PDF) est gardée dans le
 * dossier privé du serveur et ne s'ouvre qu'une fois l'app déverrouillée. Un
 * rappel arrive 30 jours avant la fin de la garantie.
 */
class PurchaseController extends Controller
{
    use ReadsMoneyInput;

    private const FOLDER = 'argent-factures';

    public function __construct(private readonly PrivateFiles $files) {}

    public function index(Request $request): View
    {
        $purchases = MoneyPurchase::query()->orderByRaw('warranty_until IS NULL')->orderBy('warranty_until')->latest('purchased_on')->get();
        $from = $request->integer('mouvement') ? MoneyTransaction::query()->whereKey($request->integer('mouvement'))->where('amount', '<', 0)->first() : null;

        return view('purchases.index', [
            'covered' => $purchases->filter(fn (MoneyPurchase $p) => in_array($p->status(), ['covered', 'soon'], true))->values(),
            'others' => $purchases->filter(fn (MoneyPurchase $p) => in_array($p->status(), ['ended', 'none'], true))->sortByDesc('purchased_on')->values(),
            'soon' => $purchases->filter(fn (MoneyPurchase $p) => $p->status() === 'soon')->count(),
            'fromTransaction' => $from,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $purchase = new MoneyPurchase($data);
        if ($request->hasFile('file')) {
            $this->attach($purchase, $request->file('file'));
        }
        $purchase->save();
        app(MoneyAlertService::class)->check();

        return redirect()->route('purchases.show', $purchase)->with('status', 'Achat enregistré.'.($purchase->warranty_until ? ' Vous serez prévenu un mois avant la fin de la garantie.' : ''));
    }

    public function show(MoneyPurchase $purchase): View
    {
        return view('purchases.show', ['purchase' => $purchase->load('transaction.account')]);
    }

    public function update(Request $request, MoneyPurchase $purchase): RedirectResponse
    {
        $purchase->fill($this->validated($request, $purchase));
        if ($request->hasFile('file')) {
            $old = $purchase->file_path;
            $this->attach($purchase, $request->file('file'));
            $this->files->delete($old);
        } elseif ($request->boolean('remove_file') && $purchase->file_path) {
            $this->files->delete($purchase->file_path);
            $purchase->fill(['file_path' => null, 'file_name' => null, 'file_mime' => null, 'file_size' => null]);
        }
        $purchase->save();
        app(MoneyAlertService::class)->check();

        return redirect()->route('purchases.show', $purchase)->with('status', 'Achat enregistré.');
    }

    public function destroy(MoneyPurchase $purchase): RedirectResponse
    {
        $this->files->delete($purchase->file_path);
        $purchase->delete();

        return redirect()->route('purchases.index')->with('status', 'Achat et facture supprimés.');
    }

    /** La facture : affichée dans le navigateur, ou téléchargée (?telecharger=1). */
    public function file(Request $request, MoneyPurchase $purchase): StreamedResponse
    {
        abort_unless($this->files->exists($purchase->file_path, $purchase->file_mime), 404);

        return $this->files->response($purchase->file_path, $purchase->file_mime, $purchase->file_name ?: $purchase->name, $request->boolean('telecharger'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?MoneyPurchase $purchase = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'shop' => ['nullable', 'string', 'max:120'],
            'purchased_on' => ['required', 'date', 'before_or_equal:'.today()->addDay()->toDateString()],
            'warranty' => ['nullable', Rule::in(array_keys(MoneyPurchase::WARRANTIES))],
            'warranty_until' => ['nullable', 'required_if:warranty,date', 'date', 'after_or_equal:purchased_on'],
            'details' => ['nullable', 'string', 'max:500'],
            'transaction_id' => ['nullable', 'integer', Rule::exists('money_transactions', 'id')],
            'file' => ['nullable', ...PrivateFiles::rules()],
        ], PrivateFiles::messages('file') + [
            'purchased_on.before_or_equal' => 'La date d\'achat ne peut pas être dans le futur.',
            'warranty_until.required_if' => 'Indiquez la date de fin de garantie.',
            'warranty_until.after_or_equal' => 'La fin de garantie doit être après la date d\'achat.',
        ], ['name' => 'nom', 'shop' => 'magasin', 'purchased_on' => 'date d\'achat', 'warranty_until' => 'fin de garantie', 'details' => 'note']);

        $warranty = (string) ($data['warranty'] ?? '');
        $until = match (true) {
            $warranty === 'date' => Carbon::parse($data['warranty_until']),
            $warranty !== '' => Carbon::parse($data['purchased_on'])->addMonthsNoOverflow((int) $warranty),
            default => null,
        };

        return [
            'name' => $data['name'],
            'shop' => $data['shop'] ?? null,
            'purchased_on' => $data['purchased_on'],
            'amount' => $this->amount($request, 'price', required: false),
            'warranty_until' => $until?->toDateString(),
            'notes' => $data['details'] ?? null,
            'transaction_id' => $data['transaction_id'] ?? $purchase?->transaction_id,
        ];
    }

    private function attach(MoneyPurchase $purchase, UploadedFile $file): void
    {
        $stored = $this->files->store($file, self::FOLDER);
        $purchase->fill([
            'file_path' => $stored['path'], 'file_name' => $stored['name'],
            'file_mime' => $stored['mime'], 'file_size' => $stored['size'],
        ]);
    }
}
