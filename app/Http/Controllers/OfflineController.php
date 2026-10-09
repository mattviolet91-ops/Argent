<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyCategory;
use App\Models\MoneyGoal;
use App\Models\MoneyPerson;
use App\Models\MoneyTransaction;
use App\Services\ActivityLogger;
use App\Services\MoneyAlertService;
use App\Services\MoneyLockService;
use App\Services\MoneyStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Mode hors ligne. Sur le téléphone, un résumé (soldes, derniers mouvements, à venir) est
 * gardé chiffré avec une clé propre à l'appareil, elle-même chiffrée avec le code Argent :
 * sans le code, rien n'est lisible. Le serveur ne garde la clé que dans un cookie chiffré
 * de l'appareil, et ne la donne qu'à l'app déverrouillée. Les mouvements notés hors ligne
 * arrivent ici au retour du réseau (une seule fois chacun).
 */
class OfflineController extends Controller
{
    use ReadsMoneyInput;

    public const COOKIE = 'argent_offline';

    /** Page de l'app sans réseau (gardée par le téléphone) : aucune donnée dedans. */
    public function shell(): View
    {
        return view('offline');
    }

    /** Activer (ou mettre à jour après un changement de code) : le code est vérifié ici. */
    public function activate(Request $request, MoneyLockService $lock): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:8'],
            'key' => ['required', 'string', 'regex:/^[A-Za-z0-9+\/]{43}=$/'],
        ]);
        if ($wait = $lock->blockedFor()) {
            return response()->json(['message' => 'Trop de codes faux. Réessayez dans '.max(1, (int) ceil($wait / 60)).' minute(s).'], 429);
        }
        if (! $lock->checkCode($data['code'])) {
            $left = $lock->failed($request);

            return response()->json(['message' => 'Code faux.'.($left > 0 ? ' Encore '.$left.' essai(s).' : '')], 422);
        }
        $lock->succeeded();
        ActivityLogger::log('argent.offline', 'Mode hors ligne activé sur un appareil');

        return response()->json(['ok' => true, 'version' => $lock->codeVersion()])
            ->withCookie(self::cookie($data['key']));
    }

    /** Désactiver sur cet appareil : la clé est oubliée (le résumé gardé devient illisible). */
    public function deactivate(): JsonResponse
    {
        ActivityLogger::log('argent.offline', 'Mode hors ligne désactivé sur un appareil');

        return response()->json(['ok' => true])->withCookie(Cookie::forget(self::COOKIE));
    }

    /** Le résumé à garder sur le téléphone (chiffré là-bas avant d'être rangé). */
    public function data(MoneyStatsService $stats): JsonResponse
    {
        $from = today()->startOfMonth();
        $accounts = $stats->accounts();
        $people = MoneyPerson::query()->whereNull('archived_at')->withSum('entries', 'amount')->get()->map(fn (MoneyPerson $p) => $p->balance());

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'month' => today()->locale('fr')->isoFormat('MMMM YYYY'),
            'accounts' => $accounts->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->name, 'scope' => $a->scope, 'group' => $a->group(),
                'color' => $a->color ?? '#8A99A6', 'balance' => (int) $a->current_balance,
            ])->values(),
            'totals' => collect(MoneyStatsService::SCOPES)->keys()->mapWithKeys(fn ($scope) => [$scope => $stats->totals($scope, $from, today())]),
            'forecast' => $stats->endOfMonthForecast('all'),
            'upcoming' => $stats->upcoming(today()->addDays(40))->filter(fn ($item) => $item['date']->isAfter(today()))->take(15)
                ->map(fn ($item) => [
                    'date' => $item['date']->toDateString(),
                    'label' => $item['recurring']->label,
                    'amount' => $item['recurring']->isTransfer() ? -abs($item['recurring']->amount) : $item['recurring']->amount,
                    'transfer' => $item['recurring']->isTransfer(),
                ])->values(),
            'latest' => MoneyTransaction::query()->with(['account', 'category'])->whereDate('occurred_on', '<=', today())
                ->latest('occurred_on')->latest('id')->limit(40)->get()
                ->map(fn (MoneyTransaction $t) => [
                    'date' => $t->occurred_on->toDateString(), 'label' => $t->label, 'amount' => $t->amount,
                    'category' => $t->category?->name, 'color' => $t->category?->color, 'account' => $t->account?->name,
                    'transfer' => $t->isTransfer(),
                ]),
            'budgets' => $stats->budgets()->map(fn ($row) => ['name' => $row['category']->name, 'spent' => $row['spent'], 'budget' => $row['budget']])->values(),
            'goals' => MoneyGoal::query()->whereNull('archived_at')->where('kind', 'epargne')->with('account')->get()
                ->map(fn (MoneyGoal $goal) => ['name' => $goal->name] + array_intersect_key($stats->goal($goal), array_flip(['current', 'target', 'percent'])))->values(),
            'owed' => ['to_me' => (int) $people->filter(fn ($b) => $b > 0)->sum(), 'by_me' => (int) -$people->filter(fn ($b) => $b < 0)->sum()],
            'categories' => MoneyCategory::query()->active()->ordered()->get(['id', 'name', 'type']),
        ]);
    }

    /** Mouvements notés hors ligne : ajoutés une seule fois (identifiant unique de chacun). */
    public function sync(Request $request): JsonResponse
    {
        $entries = $request->validate(['entries' => ['required', 'array', 'max:200']])['entries'];
        $categories = MoneyCategory::query()->pluck('type', 'id');
        [$created, $skipped, $refused] = [0, 0, []];

        DB::transaction(function () use ($entries, $categories, &$created, &$skipped, &$refused) {
            foreach ($entries as $entry) {
                $check = Validator::make(is_array($entry) ? $entry : [], [
                    'uid' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
                    'type' => ['required', 'in:expense,income'],
                    'amount' => ['required', 'integer', 'min:1', 'max:99999999999'],
                    'account_id' => ['required', 'integer', 'exists:money_accounts,id'],
                    'category_id' => ['nullable', 'integer'],
                    'label' => ['nullable', 'string', 'max:160'],
                    'notes' => ['nullable', 'string', 'max:500'],
                    'occurred_on' => ['required', 'date_format:Y-m-d', 'after:'.today()->subYear()->toDateString(), 'before:'.today()->addMonth()->toDateString()],
                ]);
                if ($check->fails()) {
                    $refused[] = is_array($entry) ? ($entry['uid'] ?? null) : null;

                    continue;
                }
                $data = $check->validated();
                $ref = 'offline:'.$data['uid'];
                if (MoneyTransaction::query()->where('source_ref', $ref)->exists()) {
                    $skipped++;

                    continue;
                }
                $signed = $data['type'] === 'expense' ? -$data['amount'] : $data['amount'];
                $category = ! empty($data['category_id']) && ($categories[$data['category_id']] ?? null) === $data['type'] ? (int) $data['category_id'] : null;
                MoneyTransaction::query()->create([
                    'account_id' => $data['account_id'],
                    'occurred_on' => Carbon::parse($data['occurred_on'])->toDateString(),
                    'amount' => $signed,
                    'kind' => MoneyTransaction::kindFor($signed),
                    'category_id' => $category,
                    'label' => trim((string) ($data['label'] ?? '')) ?: ($category ? MoneyCategory::query()->whereKey($category)->value('name') : ($signed < 0 ? 'Dépense' : 'Revenu')),
                    'notes' => $data['notes'] ?? null,
                    'source' => 'manual',
                    'source_ref' => $ref,
                ]);
                $created++;
            }
        });
        if ($created) {
            app(MoneyAlertService::class)->check();
            ActivityLogger::log('argent.offline', $created.' mouvement(s) noté(s) hors ligne ajouté(s)');
        }

        return response()->json(['created' => $created, 'skipped' => $skipped, 'refused' => array_values(array_filter($refused))]);
    }

    /** Clé de l'appareil, dans un cookie chiffré que seul le serveur lit (5 ans). */
    public static function cookie(string $key): SymfonyCookie
    {
        return Cookie::make(self::COOKIE, $key, 60 * 24 * 365 * 5, '/', null, null, true, false, 'lax');
    }

    /** La clé de cet appareil (null si le mode hors ligne n'y est pas activé). */
    public static function key(Request $request): ?string
    {
        $key = $request->cookie(self::COOKIE);

        return is_string($key) && preg_match('/^[A-Za-z0-9+\/]{43}=$/', $key) ? $key : null;
    }
}
