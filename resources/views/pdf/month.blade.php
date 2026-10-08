@php
    use App\Services\MoneyStatsService;
    use App\Support\Money;
    // Montants pour le PDF : espace insécable classique (police DejaVu).
    $m = fn (int $cents, bool $signed = false) => str_replace("\u{202F}", "\u{00A0}", ($signed && $cents > 0 ? '+' : '').Money::format($cents));
    $pct = function (int $now, int $before) {
        $change = MoneyStatsService::change($now, $before);
        return $change === null ? '—' : ($change > 0 ? '+' : '').$change.' %';
    };
    $monthName = $month->locale('fr')->isoFormat('MMMM YYYY');
    // « Bilan d'octobre », « Bilan de septembre ».
    $ofMonth = (in_array(mb_substr($monthName, 0, 1), ['a', 'o'], true) ? 'd\'' : 'de ').$monthName;
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Bilan {{ $ofMonth }}</title>
<style>
    @page { margin: 16mm 14mm 18mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2937; }
    h1 { font-size: 18pt; margin: 0; color: #0f172a; }
    h2 { font-size: 11.5pt; margin: 16pt 0 6pt; color: #1d4ed8; border-bottom: 1px solid #dbeafe; padding-bottom: 3pt; }
    .head { border-left: 5pt solid #2563eb; padding-left: 8pt; margin-bottom: 10pt; }
    .sub { color: #64748b; margin-top: 2pt; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 8pt; color: #64748b; font-weight: normal; text-transform: uppercase; padding: 3pt 4pt; border-bottom: 1px solid #cbd5e1; }
    td { padding: 3.5pt 4pt; border-bottom: 1px solid #eef2f7; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .pos { color: #15803d; } .neg { color: #b91c1c; }
    .kpis td { border: 1px solid #e2e8f0; padding: 7pt 8pt; width: 33%; }
    .kpis .label { font-size: 8pt; color: #64748b; }
    .kpis .value { font-size: 14pt; font-weight: bold; margin-top: 2pt; }
    .kpis .delta { font-size: 7.5pt; color: #64748b; margin-top: 2pt; }
    .muted { color: #64748b; }
    .small { font-size: 8pt; }
    .bar { height: 5pt; background: #2563eb; }
    .total td { font-weight: bold; border-top: 1px solid #cbd5e1; }
    .block { page-break-inside: avoid; }
    .foot { position: fixed; bottom: -10mm; left: 0; font-size: 7.5pt; color: #94a3b8; }
</style>
</head>
<body>
    <div class="foot">Argent · bilan {{ $ofMonth }} ({{ $scopeLabel }}) · fait le {{ now()->format('d/m/Y à H:i') }}</div>

    <div class="head">
        <h1>Bilan {{ $ofMonth }}</h1>
        <div class="sub">{{ $scopeLabel }}{{ $month->isSameMonth(today()) ? ' · mois en cours, jusqu\'au '.today()->format('d/m/Y') : '' }}</div>
    </div>

    <table class="kpis">
        <tr>
            <td><div class="label">Gagné (entrées)</div><div class="value pos">{{ $m($totals['income']) }}</div>
                <div class="delta">{{ $pct($totals['income'], $previous['income']) }} vs mois d'avant · {{ $pct($totals['income'], $lastYear['income']) }} vs {{ $month->year - 1 }}</div></td>
            <td><div class="label">Dépensé (sorties)</div><div class="value neg">{{ $m($totals['expense']) }}</div>
                <div class="delta">{{ $pct($totals['expense'], $previous['expense']) }} vs mois d'avant · {{ $pct($totals['expense'], $lastYear['expense']) }} vs {{ $month->year - 1 }}</div></td>
            <td><div class="label">{{ $totals['net'] >= 0 ? 'Gagné' : 'Perdu' }} au final</div><div class="value {{ $totals['net'] >= 0 ? 'pos' : 'neg' }}">{{ $m($totals['net'], true) }}</div>
                <div class="delta">{{ $totals['income'] > 0 ? 'gardé : '.(int) round($totals['net'] * 100 / $totals['income']).' % des entrées' : '' }}</div></td>
        </tr>
    </table>
    <p class="small muted">Les virements entre vos comptes ne comptent ni comme gagnés ni comme dépensés. Une note de frais payée avec un compte perso compte en pro.</p>

    @if ($split)
        <div class="block">
        <h2>Perso et pro</h2>
        <table>
            <tr><th></th><th class="num">Gagné</th><th class="num">Dépensé</th><th class="num">Résultat</th></tr>
            @foreach ($split as $label => $row)
                <tr><td>{{ $label }}</td><td class="num">{{ $m($row['income']) }}</td><td class="num">{{ $m($row['expense']) }}</td><td class="num {{ $row['net'] >= 0 ? 'pos' : 'neg' }}">{{ $m($row['net'], true) }}</td></tr>
            @endforeach
        </table>
        </div>
    @endif

    @if ($expenses->isNotEmpty())
        <div class="block">
        <h2>Dépenses par catégorie</h2>
        <table>
            <tr><th>Catégorie</th><th class="num">Montant</th><th class="num">Part</th><th style="width:30%"></th></tr>
            @foreach ($expenses as $row)
                @php $share = $totals['expense'] > 0 ? $row['amount'] * 100 / $totals['expense'] : 0; @endphp
                <tr><td>{{ $row['name'] }}</td><td class="num">{{ $m($row['amount']) }}</td><td class="num">{{ round($share) }} %</td>
                    <td><div class="bar" style="width:{{ max(1, round($share)) }}%; background: {{ $row['color'] }}"></div></td></tr>
            @endforeach
            <tr class="total"><td>Total</td><td class="num">{{ $m($totals['expense']) }}</td><td></td><td></td></tr>
        </table>
        </div>
    @endif

    @if ($incomes->isNotEmpty())
        <div class="block">
        <h2>Entrées par catégorie</h2>
        <table>
            <tr><th>Catégorie</th><th class="num">Montant</th></tr>
            @foreach ($incomes as $row)
                <tr><td>{{ $row['name'] }}</td><td class="num">{{ $m($row['amount']) }}</td></tr>
            @endforeach
        </table>
        </div>
    @endif

    @if ($budgets->isNotEmpty())
        <div class="block">
        <h2>Budgets du mois</h2>
        <table>
            <tr><th>Catégorie</th><th class="num">Dépensé</th><th class="num">Budget</th><th class="num">Utilisé</th></tr>
            @foreach ($budgets as $row)
                <tr><td>{{ $row['category']->name }}</td><td class="num">{{ $m($row['spent']) }}</td><td class="num">{{ $m($row['budget']) }}</td><td class="num {{ $row['percent'] > 100 ? 'neg' : '' }}">{{ $row['percent'] }} %</td></tr>
            @endforeach
        </table>
        </div>
    @endif

    @if ($quotes)
        <div class="block">
        <h2>Chantiers (app de devis)</h2>
        <table>
            <tr><td>Encaissé (paiements reçus)</td><td class="num">{{ $m($quotes['collected']) }}</td></tr>
            <tr><td>Frais des chantiers</td><td class="num">{{ $m(-$quotes['spent']) }}</td></tr>
            <tr class="total"><td>Gain des chantiers</td><td class="num">{{ $m($quotes['gain'], true) }}</td></tr>
        </table>
        </div>
    @endif

    @if ($tags->isNotEmpty())
        <div class="block">
        <h2>Chantiers et projets (étiquettes)</h2>
        <table>
            <tr><th>Projet</th><th class="num">Dépensé</th><th class="num">Encaissé</th><th class="num">Résultat du mois</th></tr>
            @foreach ($tags as $row)
                <tr><td>{{ $row->name }}</td><td class="num">{{ $m((int) $row->expense) }}</td><td class="num">{{ $m((int) $row->income) }}</td><td class="num">{{ $m((int) $row->income - (int) $row->expense, true) }}</td></tr>
            @endforeach
        </table>
        </div>
    @endif

    @if ($claimsPending || $claimsSettled)
        <div class="block">
        <h2>Notes de frais</h2>
        <table>
            <tr><td>Remboursées ce mois-ci</td><td class="num">{{ $m($claimsSettled) }}</td></tr>
            <tr><td>Encore à se faire rembourser (fin du mois)</td><td class="num">{{ $m($claimsPending) }}</td></tr>
        </table>
        </div>
    @endif

    @if ($top->isNotEmpty())
        <div class="block">
        <h2>Plus grosses dépenses</h2>
        <table>
            <tr><th>Date</th><th>Libellé</th><th>Catégorie</th><th class="num">Montant</th></tr>
            @foreach ($top as $t)
                <tr><td>{{ $t->occurred_on->format('d/m') }}</td><td>{{ $t->label }}</td><td class="muted">{{ $t->category?->name ?? '—' }}</td><td class="num">{{ $m($t->amount) }}</td></tr>
            @endforeach
        </table>
        </div>
    @endif

    @if ($accounts)
        <div class="block">
        <h2>Comptes au {{ $balanceDate->format('d/m/Y') }}</h2>
        <table>
            <tr><th>Compte</th><th>Type</th><th class="num">Solde</th></tr>
            @foreach ($accounts as $row)
                <tr><td>{{ $row['name'] }}</td><td class="muted">{{ $row['group'] }}</td><td class="num {{ $row['balance'] < 0 ? 'neg' : '' }}">{{ $m($row['balance']) }}</td></tr>
            @endforeach
            <tr class="total"><td>Total</td><td></td><td class="num">{{ $m((int) array_sum(array_column($accounts, 'balance'))) }}</td></tr>
        </table>
        </div>
    @endif

    @if ($transactions !== null)
        <h2 style="page-break-before: always">Tous les mouvements ({{ $transactions->count() }})</h2>
        <table>
            <tr><th>Date</th><th>Libellé</th><th>Catégorie</th><th>Compte</th><th class="num">Montant</th></tr>
            @foreach ($transactions as $t)
                <tr>
                    <td>{{ $t->occurred_on->format('d/m') }}</td>
                    <td>{{ $t->label }}@if ($t->claim) <span class="small muted">(note de frais)</span>@endif</td>
                    <td class="muted">{{ $t->category?->name ?? ($t->isTransfer() ? 'Virement' : '—') }}</td>
                    <td class="muted">{{ $t->account?->name }}</td>
                    <td class="num {{ $t->isTransfer() ? 'muted' : ($t->amount >= 0 ? 'pos' : 'neg') }}">{{ $m($t->amount, ! $t->isTransfer()) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
