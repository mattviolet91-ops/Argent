<?php

namespace App\Http\Controllers;

use App\Models\MoneyAttachment;
use App\Models\MoneyTransaction;
use App\Services\PrivateFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Justificatifs joints aux mouvements (photo du ticket, facture PDF), gardés dans le dossier privé. */
class AttachmentController extends Controller
{
    public const FOLDER = 'argent-justificatifs';

    public function __construct(private readonly PrivateFiles $files) {}

    public function store(Request $request, MoneyTransaction $transaction): RedirectResponse
    {
        // « justificatif » (et non « attachment ») : une erreur ici ne rouvre pas l'ajout rapide.
        $request->validate(['justificatif' => ['required', ...PrivateFiles::rules()]], PrivateFiles::messages('justificatif') + [
            'justificatif.required' => 'Choisissez une photo ou un PDF.',
        ]);
        self::attach($transaction, $request->file('justificatif'), $this->files, 'justificatif');

        return redirect()->route('transactions.edit', $transaction)->with('status', 'Justificatif ajouté.');
    }

    public function show(Request $request, MoneyAttachment $attachment): StreamedResponse
    {
        abort_unless($this->files->exists($attachment->path, $attachment->mime), 404);

        return $this->files->response($attachment->path, $attachment->mime, $attachment->name, $request->boolean('telecharger'));
    }

    public function destroy(MoneyAttachment $attachment): RedirectResponse
    {
        $this->files->delete($attachment->path);
        $attachment->delete();

        return redirect()->route('transactions.edit', $attachment->transaction_id)->with('status', 'Justificatif retiré.');
    }

    /** Joint un fichier à un mouvement (5 au plus). */
    public static function attach(MoneyTransaction $transaction, UploadedFile $file, PrivateFiles $files, string $field = 'attachment'): MoneyAttachment
    {
        if ($transaction->attachments()->count() >= MoneyAttachment::MAX_PER_TRANSACTION) {
            throw ValidationException::withMessages([$field => 'Déjà '.MoneyAttachment::MAX_PER_TRANSACTION.' justificatifs sur ce mouvement : retirez-en un d\'abord.']);
        }

        return $transaction->attachments()->create($files->store($file, self::FOLDER, $field));
    }
}
