@extends('layouts.app', ['title' => 'Importer un relevé'])

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Importer un relevé bancaire</h1>
            <p>Gagnez du temps : toutes les opérations de la banque d'un coup, sans les retaper.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('import.preview') }}" enctype="multipart/form-data" class="card" data-busy="Lecture du fichier…">
        @csrf
        <div class="form-grid cols-2">
            <div class="field @error('account_id') has-error @enderror">
                <label for="account_id">Dans quel compte ?</label>
                <select id="account_id" name="account_id" required>
                    @foreach ($accountOptions as $account)
                        <option value="{{ $account->id }}" @selected((string) old('account_id', request('compte')) === (string) $account->id)>{{ $account->name }} ({{ $account->scopeLabel() }})</option>
                    @endforeach
                </select>
                @error('account_id')<span class="error">{{ $message }}</span>@enderror
            </div>
            <div class="field @error('file') has-error @enderror">
                <label for="file">Fichier du relevé (CSV ou OFX)</label>
                <input id="file" type="file" name="file" accept=".csv,.txt,.ofx,.qfx,text/csv" required>
                @error('file')<span class="error">{{ $message }}</span>@enderror
            </div>
        </div>
        <div class="form-actions"><button class="btn" type="submit"><x-icon name="upload" /> Voir l'aperçu</button></div>
        <p class="money-note">Rien n'est enregistré avant d'avoir vérifié l'aperçu. Le fichier n'est pas gardé.</p>
    </form>

    <div class="card">
        <h2>Où trouver le fichier ?</h2>
        <ol class="howto-list">
            <li>Ouvrez l'espace client de votre banque sur un ordinateur (ou le site sur le téléphone).</li>
            <li>Allez dans le compte, puis « Télécharger », « Exporter » ou « Mes opérations ».</li>
            <li>Choisissez le format <strong>CSV</strong> (ou « Excel / tableur ») ou <strong>OFX</strong> (« Money »), et la période.</li>
            <li>Choisissez ce fichier ici.</li>
        </ol>
        <p class="money-note">Les opérations déjà importées sont écartées toutes seules, et celles qui ressemblent à un paiement des devis déjà noté sont décochées. Les catégories choisies sont retenues pour les prochains imports.</p>
    </div>
@endsection
