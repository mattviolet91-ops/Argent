<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Factures et justificatifs : rangés dans le dossier privé du serveur
 * (storage/app/private, jamais servi directement), et ouverts seulement par
 * l'app déverrouillée, sans rien d'actif dans la réponse.
 */
class PrivateFiles
{
    /** Types acceptés (lus d'après le contenu du fichier, pas son nom) => extension. */
    public const MIMES = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif',
    ];

    public const MAX_KB = 10240;

    /** Règles de validation d'un fichier envoyé. */
    public static function rules(): array
    {
        return ['file', 'max:'.self::MAX_KB, 'mimetypes:'.implode(',', array_keys(self::MIMES))];
    }

    public static function messages(string $field): array
    {
        return [
            $field.'.mimetypes' => 'Le fichier doit être une photo (JPG, PNG, HEIC) ou un PDF.',
            $field.'.max' => 'Fichier trop lourd (10 Mo maximum).',
            $field.'.uploaded' => 'Le fichier n\'a pas pu être envoyé (trop lourd ?). Essayez une photo plus légère ou un PDF.',
        ];
    }

    /** @return array{path: string, name: string, mime: string, size: int} */
    public function store(UploadedFile $file, string $folder, string $field = 'file'): array
    {
        $mime = (string) $file->getMimeType();
        if (! isset(self::MIMES[$mime])) {
            throw ValidationException::withMessages([$field => 'Le fichier doit être une photo (JPG, PNG, HEIC) ou un PDF.']);
        }
        $path = $file->storeAs($folder, Str::random(40).'.'.self::MIMES[$mime], 'local');
        if (! $path) {
            throw ValidationException::withMessages([$field => 'Le fichier n\'a pas pu être enregistré. Réessayez.']);
        }

        return [
            'path' => $path,
            'name' => mb_substr($file->getClientOriginalName() ?: 'fichier', 0, 160),
            'mime' => $mime,
            'size' => (int) $file->getSize(),
        ];
    }

    public function exists(?string $path, ?string $mime): bool
    {
        return $path !== null && isset(self::MIMES[(string) $mime]) && Storage::disk('local')->exists($path);
    }

    /** Le fichier : affiché dans le navigateur, ou téléchargé. */
    public function response(string $path, string $mime, string $name, bool $download = false): StreamedResponse
    {
        $filename = (Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'fichier').'.'.self::MIMES[$mime];

        return Storage::disk('local')->response($path, $filename, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            // Rien d'actif dans le fichier ; le lecteur PDF du navigateur reste permis.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ], $download ? 'attachment' : 'inline');
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
