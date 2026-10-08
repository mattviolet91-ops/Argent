<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/** Réglages enregistrés dans la table `settings`, clés en notation pointée (« argent.lock_minutes »). */
class Settings
{
    private const CACHE_KEY = 'settings.all';

    private ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $values = [];
        foreach (Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all()) as $key => $value) {
            Arr::set($values, $key, $value);
        }

        return $this->values = $values;
    }

    /** @param  array<string, mixed>  $values */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
        $this->values = null;
    }
}
