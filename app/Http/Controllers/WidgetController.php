<?php

namespace App\Http\Controllers;

use App\Models\MoneyWidgetKey;
use App\Services\ActivityLogger;
use App\Services\MoneyLockService;
use App\Services\MoneyWidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Widgets iPhone (app gratuite Scriptable : Apple ne permet pas de widget à une app web).
 * Chaque widget a sa clé, créée avec le code Argent, montrée une seule fois, retirable ici :
 * elle ne permet que de lire le résumé choisi (vue, montants visibles ou masqués).
 */
class WidgetController extends Controller
{
    private const SESSION_KEY = 'argent.widget_install';

    /** Le script reste affiché 30 minutes après la création, le temps de l'installer. */
    private const INSTALL_MINUTES = 30;

    public function store(Request $request, MoneyLockService $lock): RedirectResponse
    {
        $data = $request->validate([
            'widget_name' => ['nullable', 'string', 'max:40'],
            'widget_view' => ['required', 'in:all,perso,pro'],
            'widget_code' => ['required', 'string', 'max:8'],
        ], ['widget_code.required' => 'Tapez votre code Argent.']);
        if (MoneyWidgetKey::query()->count() >= MoneyWidgetKey::MAX) {
            throw ValidationException::withMessages(['widget_name' => MoneyWidgetKey::MAX.' widgets au plus : retirez-en un d\'abord.']);
        }
        if ($wait = $lock->blockedFor()) {
            throw ValidationException::withMessages(['widget_code' => 'Trop de codes faux. Réessayez dans '.max(1, (int) ceil($wait / 60)).' minute(s).']);
        }
        if (! $lock->checkCode($data['widget_code'])) {
            $left = $lock->failed($request);
            throw ValidationException::withMessages(['widget_code' => 'Code faux.'.($left > 0 ? ' Encore '.$left.' essai(s).' : '')]);
        }
        $lock->succeeded();

        $name = trim((string) ($data['widget_name'] ?? '')) ?: 'iPhone';
        [$key, $token] = MoneyWidgetKey::issue($name, $data['widget_view'], $request->boolean('widget_amounts'));
        $request->session()->put(self::SESSION_KEY, ['id' => $key->id, 'token' => $token, 'until' => now()->addMinutes(self::INSTALL_MINUTES)->timestamp]);
        ActivityLogger::log('argent.widget', 'Widget iPhone créé (« '.$name.' »)');

        return redirect()->route('settings.widgets.install', $key);
    }

    /** Étapes d'installation, avec le script et sa clé (seulement juste après la création). */
    public function install(Request $request, MoneyWidgetKey $widget): View
    {
        $pending = (array) $request->session()->get(self::SESSION_KEY, []);
        $token = ($pending['id'] ?? null) === $widget->id && ($pending['until'] ?? 0) > now()->timestamp ? (string) $pending['token'] : null;

        return view('widgets.install', [
            'widget' => $widget,
            'script' => $token ? self::script($token) : null,
        ]);
    }

    /** « C'est installé » : la clé n'est plus affichée nulle part. */
    public function done(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->to(route('settings').'#widgets')->with('status', 'Widget installé. Il se met à jour tout seul (l\'iPhone choisit le moment, en général toutes les 15 à 30 minutes).');
    }

    public function update(Request $request, MoneyWidgetKey $widget): RedirectResponse
    {
        $data = $request->validate(['view' => ['required', 'in:all,perso,pro']]);
        $widget->update(['scope' => $data['view'], 'show_amounts' => $request->boolean('amounts')]);

        return redirect()->to(route('settings').'#widgets')->with('status', 'Widget « '.$widget->name.' » modifié : il change à sa prochaine mise à jour.');
    }

    public function destroy(Request $request, MoneyWidgetKey $widget): RedirectResponse
    {
        $widget->delete();
        if (($request->session()->get(self::SESSION_KEY)['id'] ?? null) === $widget->id) {
            $request->session()->forget(self::SESSION_KEY);
        }
        ActivityLogger::log('argent.widget', 'Widget iPhone retiré (« '.$widget->name.' »)');

        return redirect()->to(route('settings').'#widgets')->with('status', 'Widget retiré : sa clé ne marche plus. Supprimez-le aussi de l\'écran d\'accueil.');
    }

    /** Le résumé lu par le widget (sans session ni cookie : la clé seule). */
    public function data(Request $request, MoneyWidgetService $widgets): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store, private, max-age=0'];
        $widget = MoneyWidgetKey::findByToken($request->bearerToken());
        if (! $widget) {
            return response()->json(['error' => 'Ce widget a été retiré. Créez-en un autre dans l\'app Argent : Réglages → Widgets iPhone.'], 401, $headers);
        }
        // Dernière utilisation : notée au plus toutes les 5 minutes.
        if (! $widget->last_used_at || $widget->last_used_at->lt(now()->subMinutes(5))) {
            $widget->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $view = $request->query('vue');

        return response()->json($widgets->payload($widget, $widget->viewFor(is_string($view) ? $view : null)), 200, $headers);
    }

    /** Le script Scriptable de ce widget : adresse du résumé et clé dedans. */
    public static function script(string $token): string
    {
        return strtr((string) file_get_contents(resource_path('widget/argent.js')), [
            '%%API%%' => route('widgets.data'),
            '%%KEY%%' => $token,
            '%%VERSION%%' => (string) MoneyWidgetService::SCRIPT_VERSION,
        ]);
    }
}
