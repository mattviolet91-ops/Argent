<?php

namespace App\Http\Controllers;

use App\Models\MoneyAccount;
use App\Services\ActivityLogger;
use App\Services\DevisClient;
use App\Services\FaceIdService;
use App\Services\MoneyLockService;
use App\Services\MoneySyncService;
use App\Services\Settings;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Création du code Argent, déverrouillage, verrouillage et code oublié. */
class LockController extends Controller
{
    private const CODE_RULES = ['required', 'string', 'regex:/^\d{4,8}$/', 'confirmed'];

    private const CODE_MESSAGES = [
        'code.required' => 'Choisissez un code.',
        'code.regex' => 'Le code doit faire de 4 à 8 chiffres.',
        'code.confirmed' => 'Les deux codes ne sont pas identiques.',
    ];

    public function __construct(private readonly MoneyLockService $lock) {}

    /** Première ouverture : code Argent et comptes de départ. */
    public function setup(Request $request): View|RedirectResponse
    {
        if ($this->lock->isConfigured()) {
            return redirect()->route('dashboard');
        }

        return view('lock.setup');
    }

    public function storeSetup(Request $request, Settings $settings): RedirectResponse
    {
        if ($this->lock->isConfigured()) {
            return redirect()->route('dashboard');
        }
        $request->validate([
            'code' => self::CODE_RULES,
            'devis_url' => ['nullable', 'url:https', 'max:200'],
            'devis_token' => ['nullable', 'string', 'max:200'],
        ], self::CODE_MESSAGES + ['devis_url.url' => 'Adresse invalide (ex. https://test.matts-couverture.fr).']);
        $balances = [];
        foreach (['perso_balance', 'pro_balance'] as $field) {
            $raw = trim((string) $request->input($field, ''));
            $balances[$field] = $raw === '' ? 0 : Money::parse(str_replace(['−', '–'], '-', $raw));
            if ($balances[$field] === null) {
                throw ValidationException::withMessages([$field => 'Montant invalide (ex. 1 250,00 ou -80).']);
            }
        }

        DB::transaction(function () use ($request, $settings, $balances) {
            $this->lock->setCode($request->user(), (string) $request->input('code'));
            if (! MoneyAccount::query()->exists()) {
                MoneyAccount::query()->create(['name' => 'Compte perso', 'kind' => 'courant', 'scope' => 'perso', 'opening_balance' => $balances['perso_balance'], 'opening_on' => today(), 'color' => '#2E7DBA', 'position' => 1]);
                $pro = MoneyAccount::query()->create(['name' => 'Compte pro', 'kind' => 'courant', 'scope' => 'pro', 'opening_balance' => $balances['pro_balance'], 'opening_on' => today(), 'color' => '#1E7F4F', 'position' => 2]);
                $settings->set(['argent.sync_account_id' => $pro->id]);
            }
        });
        ActivityLogger::log('argent.code', 'App Argent ouverte pour la première fois (code Argent choisi)');
        $this->lock->unlock($request);

        // Premier remplissage : tous les paiements et frais déjà saisis dans les devis.
        $token = trim((string) $request->input('devis_token', ''));
        if ($request->filled('devis_url') && $token !== '') {
            app(DevisClient::class)->configure((string) $request->input('devis_url'), $token);
            try {
                $result = app(MoneySyncService::class)->run();

                return redirect()->route('dashboard')->with('status', 'App Argent prête. '.($result['added'] ?? 0).' paiement(s) et frais de l\'app de devis ajoutés.');
            } catch (\RuntimeException $e) {
                return redirect()->route('settings')->withErrors(['devis_token' => $e->getMessage()]);
            }
        }

        return redirect()->route('dashboard')->with('status', 'App Argent prête.');
    }

    public function unlockForm(Request $request): View|RedirectResponse
    {
        if (! $this->lock->isConfigured()) {
            return redirect()->route('setup');
        }
        if ($this->lock->isUnlocked($request)) {
            return redirect()->route('dashboard');
        }

        return view('lock.unlock', [
            'blockedFor' => $this->lock->blockedFor(),
            'faceId' => app(FaceIdService::class)->enabledFor($request->user()),
        ]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        if (! $this->lock->isConfigured()) {
            return redirect()->route('setup');
        }
        if ($seconds = $this->lock->blockedFor()) {
            throw ValidationException::withMessages(['code' => 'Trop de codes faux. Réessayez dans '.max(1, (int) ceil($seconds / 60)).' minute(s).']);
        }
        // Face ID activé : le code seul ne suffit plus, il faut aussi le mot de passe du compte.
        $faceId = app(FaceIdService::class)->enabledFor($request->user());
        $request->validate([
            'code' => ['required', 'string', 'max:8'],
            'password' => [$faceId ? 'required' : 'nullable', 'string'],
        ], ['code.required' => 'Tapez votre code Argent.', 'password.required' => 'Tapez aussi le mot de passe de votre compte.']);

        if ($faceId && ! Hash::check((string) $request->input('password'), $request->user()->password)) {
            $left = $this->lock->failed($request);
            throw ValidationException::withMessages(['password' => $left > 0
                ? 'Mot de passe incorrect. Encore '.$left.' essai'.($left > 1 ? 's' : '').'.'
                : 'Mot de passe incorrect. App bloquée '.MoneyLockService::BLOCK_MINUTES.' minutes.']);
        }
        if (! $this->lock->checkCode((string) $request->input('code'))) {
            $left = $this->lock->failed($request);
            throw ValidationException::withMessages(['code' => $left > 0
                ? 'Code faux. Encore '.$left.' essai'.($left > 1 ? 's' : '').'.'
                : 'Code faux. App bloquée '.MoneyLockService::BLOCK_MINUTES.' minutes.']);
        }

        $this->lock->succeeded();
        $this->lock->unlock($request, fresh: true);

        return redirect()->to($this->intended($request));
    }

    /** Face ID : options pour le téléphone. */
    public function faceIdOptions(Request $request, FaceIdService $faceId): JsonResponse
    {
        if (! $faceId->enabledFor($request->user())) {
            return response()->json(['message' => 'Face ID n\'est pas activé.'], 404);
        }
        if ($seconds = $this->lock->blockedFor()) {
            return response()->json(['message' => 'Trop d\'essais. Réessayez dans '.max(1, (int) ceil($seconds / 60)).' minute(s).'], 429);
        }

        return response()->json($faceId->unlockOptions($request->user(), $request));
    }

    /** Face ID : vérification de la signature du téléphone, puis ouverture. */
    public function faceIdUnlock(Request $request, FaceIdService $faceId): JsonResponse
    {
        if ($seconds = $this->lock->blockedFor()) {
            return response()->json(['message' => 'Trop d\'essais. Réessayez dans '.max(1, (int) ceil($seconds / 60)).' minute(s).'], 429);
        }
        if (! $faceId->verify($request->user(), $request, (array) $request->json()->all())) {
            $left = $this->lock->failed($request);

            return response()->json(['message' => $left > 0 ? 'Face ID non reconnu. Réessayez.' : 'App bloquée '.MoneyLockService::BLOCK_MINUTES.' minutes.'], 422);
        }

        $this->lock->succeeded();
        $this->lock->unlock($request, fresh: true);

        return response()->json(['redirect' => $this->intended($request)]);
    }

    private function intended(Request $request): string
    {
        $intended = (string) $request->session()->pull('argent.intended', '');

        return $intended !== '' && str_starts_with($intended.'/', url('/').'/') ? $intended : route('dashboard');
    }

    public function lock(Request $request): RedirectResponse
    {
        $this->lock->lock($request);

        return redirect()->route('unlock')->with('status', 'App verrouillée.');
    }

    public function forgot(): View|RedirectResponse
    {
        return $this->lock->isConfigured() ? view('lock.forgot') : redirect()->route('setup');
    }

    /** Code oublié : le mot de passe du compte permet d'en choisir un nouveau. */
    public function reset(Request $request): RedirectResponse
    {
        if ($seconds = $this->lock->blockedFor()) {
            throw ValidationException::withMessages(['password' => 'Trop d\'essais faux. Réessayez dans '.max(1, (int) ceil($seconds / 60)).' minute(s).']);
        }
        $request->validate(['password' => ['required', 'string'], 'code' => self::CODE_RULES], self::CODE_MESSAGES + ['password.required' => 'Tapez le mot de passe de votre compte.']);
        if (! Hash::check((string) $request->input('password'), $request->user()->password)) {
            $this->lock->failed($request);
            throw ValidationException::withMessages(['password' => 'Mot de passe incorrect.']);
        }

        $this->lock->setCode($request->user(), (string) $request->input('code'));
        $this->lock->succeeded();
        $this->lock->unlock($request);
        ActivityLogger::log('argent.code', 'Code Argent changé (code oublié)');

        return redirect()->route('dashboard')->with('status', 'Nouveau code Argent enregistré.');
    }
}
