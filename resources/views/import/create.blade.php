@extends('layouts.app', ['title' => 'Importer un relevé'])

@section('content')
    @include('_nav')

    <div class="page-head">
        <div>
            <h1>Importer un relevé bancaire</h1>
            <p>Toutes les opérations de la banque d'un coup, sans les retaper : le relevé PDF envoyé chaque mois, ou un export CSV / OFX. Vous classez ensuite chaque opération en perso ou pro.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('import.preview') }}" enctype="multipart/form-data" class="card" data-busy="Lecture du fichier…">
        @csrf
        <div class="form-grid cols-2">
            <div class="field @error('account_id') has-error @enderror">
                <label for="account_id">Dans quel compte ?</label>
                <select id="account_id" name="account_id" required>
                    @foreach ($accountOptions as $account)
                        <option value="{{ $account->id }}" @selected((string) old('account_id', request('compte')) === (string) $account->id)>{{ $account->name }} ({{ $account->mixed ? 'perso + pro' : $account->scopeLabel() }})</option>
                    @endforeach
                </select>
                @error('account_id')<span class="error">{{ $message }}</span>@enderror
            </div>
            <div class="field @error('file') has-error @enderror @error('files_count') has-error @enderror">
                <label for="files">Relevés (PDF, CSV ou OFX) <span class="muted small">— plusieurs d'un coup possible</span></label>
                <input id="files" type="file" name="files[]" accept=".pdf,.csv,.txt,.ofx,.qfx,application/pdf,text/csv" multiple required>
                @error('file')<span class="error">{{ $message }}</span>@enderror
                @error('files_count')<span class="error">{{ $message }}</span>@enderror
                @error('files.*')<span class="error">{{ $message }}</span>@enderror
            </div>
        </div>
        <details class="import-password" @if (session('import_issue') === 'password') open @endif>
            <summary class="small">Le PDF demande un mot de passe ?</summary>
            <div class="field">
                <label for="pdf_password">Mot de passe du relevé <span class="muted small">(seulement pour le lire, il n'est pas gardé)</span></label>
                <input id="pdf_password" type="password" name="pdf_password" maxlength="100" autocomplete="off">
            </div>
        </details>
        <div class="form-actions"><button class="btn" type="submit"><x-icon name="upload" /> Voir l'aperçu</button></div>
        <p class="money-note">Rien n'est enregistré avant d'avoir vérifié l'aperçu. Le fichier n'est pas gardé.</p>
        @if (session('import_report'))
            <details class="import-report">
                <summary class="small">Détails techniques (sans vos montants ni libellés)</summary>
                <textarea id="import-report" readonly rows="8" spellcheck="false" aria-label="Détails techniques">{{ session('import_report') }}</textarea>
                <button class="btn btn-sm btn-secondary" type="button" data-copy="#import-report" data-copied="Copié ✓"><x-icon name="copy" /> Copier les détails</button>
            </details>
        @endif
    </form>

    <div class="card">
        <h2>Où trouver le fichier ?</h2>
        <ol class="howto-list">
            <li><strong>Le plus simple :</strong> dans l'app de votre banque, ouvrez « Relevés » (ou « Documents ») et enregistrez le relevé PDF du mois dans Fichiers. Ici, choisissez-le (plusieurs mois d'un coup si vous voulez). Les relevés protégés par la banque se lisent aussi.</li>
            <li><strong>Encore plus fiable :</strong> sur le site de la banque, « Télécharger » ou « Exporter » les opérations au format <strong>CSV</strong> (« Excel / tableur ») ou <strong>OFX</strong> (« Money »).</li>
        </ol>
        <p class="money-note">Les opérations déjà importées sont écartées toutes seules (même si deux relevés se recouvrent), et celles qui ressemblent à un paiement des devis déjà noté sont décochées. Vos catégories et vos choix perso / pro sont retenus pour les prochains imports.
            Un compte où vous payez du perso <strong>et</strong> du pro ? Cochez « Compte mixte » sur ce compte (Comptes → le compte → Modifier).</p>
    </div>
@endsection
