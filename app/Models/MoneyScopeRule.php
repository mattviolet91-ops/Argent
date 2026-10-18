<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Mot du libellé bancaire → perso ou pro (appris à chaque classement). */
class MoneyScopeRule extends Model
{
    protected $fillable = ['keyword', 'scope'];
}
