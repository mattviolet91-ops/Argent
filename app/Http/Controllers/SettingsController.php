<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\MoneyAccount;
use App\Models\MoneyTransaction;
use App\Services\ActivityLogger;
use App\Services\BackupService;
use App\Services\DevisClient;
use App\Services\MoneyAlertService;
use App\Services\MoneyLockService;
use App\Services\MoneySyncService;
use App\Services\PushService;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Réglages : lien avec l'app de devis, sécurité (code, mot de passe, journal), notifications, sauvegardes, export. */
class SettingsController extends Controller
{
    public function __construct(private readonly Settings $settings, private readonly MoneyLockService $lock) {}

    public function edit(MoneySyncService $sync, DevisClient $devis, PushService $push, BackupService $backups): View
    {
        $last = $this->settings->get('argent.last_sync_at');

        return view('settings', [
            'devisUrl' => $devis->url() ?: 'https://test.matts-couverture.fr',
            'devisLinked' => $devis->isConfigured(),
            'devisError' => $this->settings->get('devis.last_error'),
            'pushKey' => $push->publicKey(),
            'backups' => $backups->list()->take(10),
            'journal' => ActivityLog::query()->latest('id')->limit(15)->get(),
            'accounts' => MoneyAccount::query()->active()->ordered()->get(),
            'syncAccount' => $sync->account(),
            'cashAccount' => $sync->cashAccount(),
            'lastSync' => $last ? Carbon::parse($last) : null,
            'lastResult' => (array) $this->settings->get('argent.last_sync', []),
            'lockMinutes' => $this->lock->lockMinutes(),
            'weeklyPush' => (bool) $this->settings->get('argent.weekly_push', true),
            'pushAmounts' => (bool) $this->settings->get('argent.push_amounts', false),
            'alertsEnabled' => (bool) $this->settings->get('alerts.enabled', true),
            'alertsBudgetWarning' => (bool) $this->settings->get('alerts.budget_warning', true),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'lock_minutes' => ['required', 'integer', Rule::in(array_keys(MoneyLockService::DELAYS))],
            'sync_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')],
            'cash_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')],
        ], [], ['sync_account_id' => 'compte']);

        $this->settings->set([
            'argent.lock_minutes' => (int) $request->input('lock_minutes'),
            'argent.sync_account_id' => $request->integer('sync_account_id') ?: null,
            'argent.cash_account_id' => $request->integer('cash_account_id') ?: null,
            'argent.weekly_push' => $request->boolean('weekly_push'),
            'argent.push_amounts' => $request->boolean('push_amounts'),
            'alerts.enabled' => $request->boolean('alerts_enabled'),
            'alerts.budget_warning' => $request->boolean('alerts_budget_warning'),
        ]);
        $this->lock->unlock($request);

        return redirect()->route('settings')->with('status', 'Réglages enregistrés.');
    }

    /** Adresse de l'app de devis et clé « App Argent » (gardée chiffrée), puis première mise à jour. */
    public function updateDevis(Request $request, DevisClient $devis, MoneySyncService $sync): RedirectResponse
    {
        $request->validate([
            'devis_url' => ['required', 'url:https', 'max:200'],
            'devis_token' => ['nullable', 'string', 'max:200'],
        ], ['devis_url.url' => 'Adresse invalide (ex. https://test.matts-couverture.fr).'], ['devis_url' => 'adresse']);
        $devis->configure((string) $request->input('devis_url'), trim((string) $request->input('devis_token', '')) ?: null);
        ActivityLogger::log('devis.link', 'Lien avec l\'app de devis enregistré');

        try {
            $result = $sync->run();
        } catch (\RuntimeException $e) {
            return redirect()->route('settings')->withErrors(['devis_token' => $e->getMessage()]);
        }
        $this->settings->set(['devis.last_error' => null]);

        return redirect()->route('settings')->with('status', $result === null
            ? 'Enregistré. Choisissez aussi le compte qui reçoit les paiements (ci-dessous).'
            : 'Relié à l\'app de devis : '.$result['added'].' ajouté(s), '.$result['updated'].' modifié(s), '.$result['removed'].' retiré(s).');
    }

    /** Mot de passe de connexion (le code Argent reste à part). */
    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ], ['current_password.current_password' => 'Mot de passe actuel incorrect.']);
        $user = $request->user();
        $user->forceFill(['password' => Hash::make((string) $request->input('password'))])->save();
        // Les autres appareils connectés sont déconnectés.
        auth()->logoutOtherDevices((string) $request->input('password'));
        ActivityLogger::log('auth.password', 'Mot de passe changé');

        return redirect()->route('settings')->with('status', 'Mot de passe changé. Les autres appareils sont déconnectés.');
    }

    /** Une sauvegarde de la base (fichier à garder chez soi). */
    public function backup(string $name): BinaryFileResponse
    {
        $path = BackupService::DIR.'/'.basename($name);
        abort_unless(Storage::disk('local')->exists($path), 404);
        ActivityLogger::log('backup.download', 'Sauvegarde téléchargée');

        return response()->download(Storage::disk('local')->path($path), $name, ['Cache-Control' => 'no-store']);
    }

    public function updateCode(Request $request): RedirectResponse
    {
        $request->validate([
            'current_code' => ['required', 'string'],
            'code' => ['required', 'string', 'regex:/^\d{4,8}$/', 'confirmed'],
        ], [
            'current_code.required' => 'Tapez le code actuel.',
            'code.regex' => 'Le code doit faire de 4 à 8 chiffres.',
            'code.confirmed' => 'Les deux codes ne sont pas identiques.',
        ]);
        if (! $this->lock->checkCode((string) $request->input('current_code'))) {
            $this->lock->failed($request);
            throw ValidationException::withMessages(['current_code' => 'Code actuel incorrect.']);
        }

        $this->lock->setCode($request->user(), (string) $request->input('code'));
        $this->lock->unlock($request);
        ActivityLogger::log('argent.code', 'Code Argent changé');

        return redirect()->route('settings')->with('status', 'Nouveau code Argent enregistré.');
    }

    /** « Mettre à jour maintenant » : paiements et frais des devis, dépenses fixes. */
    public function sync(Request $request, MoneySyncService $sync): RedirectResponse
    {
        $fixed = $sync->runRecurring();
        try {
            $result = $sync->run();
        } catch (\RuntimeException $e) {
            return back()->withErrors(['sync' => $e->getMessage()]);
        }
        if ($result === null) {
            return back()->withErrors(['sync' => 'Reliez d\'abord l\'app de devis et choisissez le compte qui reçoit les paiements (Réglages).']);
        }
        $this->settings->set(['devis.last_error' => null]);
        app(MoneyAlertService::class)->check();

        return back()->with('status', 'À jour : '.$result['added'].' ajouté(s), '.$result['updated'].' modifié(s), '.$result['removed'].' retiré(s)'
            .($fixed ? ', '.$fixed.' dépense(s) fixe(s)' : '').'.');
    }

    /** Tous les mouvements en CSV (ouvrable dans Excel) : une copie à garder. */
    public function export(): StreamedResponse
    {
        ActivityLogger::log('argent.export', 'Export des mouvements de l\'app Argent');

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Compte', 'Perso/Pro', 'Catégorie', 'Libellé', 'Montant', 'Type', 'Origine', 'Note'], ';', '"', '');
            foreach (MoneyTransaction::query()->with(['account', 'category'])->orderBy('occurred_on')->orderBy('id')->lazy(500) as $t) {
                fputcsv($out, [
                    $t->occurred_on->format('d/m/Y'),
                    $t->account?->name,
                    $t->account?->scopeLabel(),
                    $t->category?->name,
                    $t->label,
                    number_format($t->amount / 100, 2, ',', ''),
                    match ($t->kind) {
                        'transfer' => 'Virement', 'income' => 'Entrée', default => 'Sortie'
                    },
                    MoneyTransaction::SOURCES[$t->source] ?? $t->source,
                    $t->notes,
                ], ';', '"', '');
            }
            fclose($out);
        }, 'argent-'.today()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
