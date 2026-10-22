<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Clé d'un widget iPhone : lecture seule du résumé (soldes, mois, budgets, échéances).
 * La clé n'est montrée qu'une fois, à l'installation ; seule son empreinte est gardée.
 */
class MoneyWidgetKey extends Model
{
    public const MAX = 10;

    public const PREFIX = 'argw_';

    protected $fillable = ['name', 'token_hash', 'scope', 'show_amounts', 'last_used_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['show_amounts' => 'boolean', 'last_used_at' => 'datetime'];
    }

    /**
     * Nouvelle clé : le modèle, et la clé en clair (à mettre dans le widget, jamais regardée ensuite).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, string $scope, bool $showAmounts): array
    {
        $token = self::PREFIX.Str::random(40);
        $key = self::query()->create([
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'scope' => $scope,
            'show_amounts' => $showAmounts,
        ]);

        return [$key, $token];
    }

    public static function findByToken(?string $token): ?self
    {
        if (! is_string($token) || ! preg_match('/^'.self::PREFIX.'[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        return self::query()->where('token_hash', hash('sha256', $token))->first();
    }

    /** Vue demandée par le widget (paramètre « perso » ou « pro ») : jamais plus que ce que la clé permet. */
    public function viewFor(?string $asked): string
    {
        $asked = match (Str::lower(trim((string) $asked))) {
            'perso' => 'perso',
            'pro' => 'pro',
            default => null,
        };

        return $this->scope === 'all' && $asked ? $asked : $this->scope;
    }

    public function viewLabel(): string
    {
        return ['all' => 'Tout', 'perso' => 'Perso', 'pro' => 'Pro'][$this->scope] ?? 'Tout';
    }
}
