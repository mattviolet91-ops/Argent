<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyPurchase;
use App\Models\MoneyTransaction;
use App\Services\MoneyAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    /** Types de fichiers acceptés (lus d'après le contenu du fichier, pas son nom). */
    private const MIMES = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif',
    ];

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
            $old && Storage::disk('local')->delete($old);
        } elseif ($request->boolean('remove_file') && $purchase->file_path) {
            Storage::disk('local')->delete($purchase->file_path);
            $purchase->fill(['file_path' => null, 'file_name' => null, 'file_mime' => null, 'file_size' => null]);
        }
        $purchase->save();
        app(MoneyAlertService::class)->check();

        return redirect()->route('purchases.show', $purchase)->with('status', 'Achat enregistré.');
    }

    public function destroy(MoneyPurchase $purchase): RedirectResponse
    {
        $purchase->file_path && Storage::disk('local')->delete($purchase->file_path);
        $purchase->delete();

        return redirect()->route('purchases.index')->with('status', 'Achat et facture supprimés.');
    }

    /** La facture : affichée dans le navigateur, ou téléchargée (?telecharger=1). */
    public function file(Request $request, MoneyPurchase $purchase): StreamedResponse
    {
        abort_unless($purchase->file_path && isset(self::MIMES[$purchase->file_mime]) && Storage::disk('local')->exists($purchase->file_path), 404);
        $name = Str::slug(pathinfo((string) $purchase->file_name, PATHINFO_FILENAME) ?: $purchase->name).'.'.self::MIMES[$purchase->file_mime];

        return Storage::disk('local')->response($purchase->file_path, $name, [
            'Content-Type' => $purchase->file_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            // Rien d'actif dans le fichier ; le lecteur PDF du navigateur reste permis.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ], $request->boolean('telecharger') ? 'attachment' : 'inline');
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
            'file' => ['nullable', 'file', 'max:10240', 'mimetypes:'.implode(',', array_keys(self::MIMES))],
        ], [
            'file.mimetypes' => 'La facture doit être une photo (JPG, PNG, HEIC) ou un PDF.',
            'file.max' => 'Fichier trop lourd (10 Mo maximum).',
            'file.uploaded' => 'Le fichier n\'a pas pu être envoyé (trop lourd ?). Essayez une photo plus légère ou un PDF.',
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
        $mime = (string) $file->getMimeType();
        if (! isset(self::MIMES[$mime])) {
            throw ValidationException::withMessages(['file' => 'La facture doit être une photo (JPG, PNG, HEIC) ou un PDF.']);
        }
        $path = $file->storeAs(self::FOLDER, Str::random(40).'.'.self::MIMES[$mime], 'local');
        if (! $path) {
            throw ValidationException::withMessages(['file' => 'Le fichier n\'a pas pu être enregistré. Réessayez.']);
        }
        $purchase->fill([
            'file_path' => $path,
            'file_name' => mb_substr($file->getClientOriginalName() ?: 'facture', 0, 160),
            'file_mime' => $mime,
            'file_size' => $file->getSize(),
        ]);
    }
}
