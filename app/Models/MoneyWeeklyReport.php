<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bilan figé d'une semaine (lundi → dimanche). */
class MoneyWeeklyReport extends Model
{
    protected $fillable = ['week_start', 'data'];

    protected function casts(): array
    {
        return ['week_start' => 'date', 'data' => 'array'];
    }
}
