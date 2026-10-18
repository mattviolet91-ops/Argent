@extends('layouts.app', ['title' => 'À classer'])

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Perso ou pro ?</h1>
            <p>Les opérations de vos comptes mixtes pas encore classées. Chacune a une proposition (ce que vous avez déjà choisi pour ce commerçant, ou la catégorie) : corrigez ce qui ne va pas, puis enregistrez.</p>
        </div>
    </div>

    @if ($mixedAccounts->isEmpty())
        <div class="card empty">
            <x-icon name="wallet" />
            <h2>Aucun compte mixte</h2>
            <p>Un compte où vous payez du perso <strong>et</strong> du pro ? Ouvrez-le dans Comptes → Modifier, et cochez « Compte mixte ». Ses opérations arriveront ici pour être classées.</p>
            <p><a class="btn btn-secondary" href="{{ route('accounts.index') }}"><x-icon name="piggy" /> Mes comptes</a></p>
        </div>
    @elseif ($transactions->isEmpty())
        <div class="card empty">
            <x-icon name="check" />
            <h2>Tout est classé</h2>
            <p>Les prochains relevés importés se classeront à l'import, avec vos choix retenus.</p>
            <p><a class="btn btn-secondary" href="{{ route('import.create') }}"><x-icon name="upload" /> Importer un relevé</a></p>
        </div>
    @else
        <form method="POST" action="{{ route('transactions.classify.store') }}" data-busy="Enregistrement…">
            @csrf
            <div class="card import-tools">
                <span><strong>{{ $total }}</strong> à classer{{ $total > $transactions->count() ? ' · '.$transactions->count().' sur cette page' : '' }}</span>
                <span class="import-bulk">Tout en
                    <button class="btn btn-sm btn-secondary" type="button" data-scope-all="perso">Perso</button>
                    <button class="btn btn-sm btn-secondary" type="button" data-scope-all="pro">Pro</button>
                </span>
            </div>
            <ul class="list import-list">
                @foreach ($transactions as $item)
                    @php [$t, [$suggested, $hint]] = [$item['t'], $item['suggestion']]; @endphp
                    <li>
                        <div class="import-main">
                            <div class="import-top">
                                <a class="import-label" href="{{ route('transactions.edit', $t) }}"><span class="small muted">{{ $t->occurred_on->format('d/m/Y') }}</span> {{ $t->label }}</a>
                                <strong class="import-amount"><x-money-amount :value="$t->amount" signed /></strong>
                            </div>
                            <div class="import-controls">
                                <span class="small muted" style="flex:1">{{ $t->category?->name ?? 'Sans catégorie' }} · {{ $t->account?->name }}
                                    @if ($hint === 'rule') · <span title="Déjà choisi pour ce commerçant">retenu</span>@elseif ($hint === 'account') · <span class="badge badge-warning">à vérifier</span>@endif
                                </span>
                                <div class="scope-switch" role="radiogroup" aria-label="Perso ou pro">
                                    @foreach (['perso' => 'Perso', 'pro' => 'Pro'] as $key => $label)
                                        <label><input type="radio" name="scope[{{ $t->id }}]" value="{{ $key }}" @checked($suggested === $key) data-scope-choice> {{ $label }}</label>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="form-actions"><button class="btn" type="submit"><x-icon name="check" /> Enregistrer {{ $transactions->count() > 1 ? 'ces '.$transactions->count().' opérations' : 'cette opération' }}</button></div>
            <p class="money-note">Vos choix sont retenus : la prochaine fois, le même commerçant sera proposé pareil.</p>
        </form>
    @endif
@endsection
