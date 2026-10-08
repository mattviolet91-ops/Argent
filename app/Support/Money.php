<?php

namespace App\Support;

/**
 * Montants en centimes (entiers) ↔ affichage français.
 */
final class Money
{
    /** 123456 → « 1 234,56 € » (espaces insécables fines). */
    public static function format(int $cents, bool $withSymbol = true): string
    {
        $formatted = number_format(abs($cents) / 100, 2, ',', "\u{202F}");
        $sign = $cents < 0 ? '−' : '';

        return $sign.$formatted.($withSymbol ? "\u{00A0}€" : '');
    }

    /** Pour les SMS, notifications et emails texte : espaces ordinaires. */
    public static function plain(int $cents): string
    {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', self::format($cents));
    }

    /** « 1 234,56 » ou « 1234.5 » → 123456. Retourne null si la saisie est invalide. */
    public static function parse(string|int|float|null $input): ?int
    {
        if ($input === null || $input === '') {
            return null;
        }

        $value = preg_replace('/[\s\x{00A0}\x{202F}€]/u', '', (string) $input);
        $value = str_replace(',', '.', $value);

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $value)) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        [$units, $decimals] = array_pad(explode('.', ltrim($value, '-')), 2, '');
        $cents = (int) $units * 100 + (int) str_pad($decimals, 2, '0');

        return $negative ? -$cents : $cents;
    }
}
