<?php

use App\Http\Controllers\WidgetController;
use Illuminate\Support\Facades\Route;

// Résumé lu par les widgets iPhone (Scriptable) : la clé du widget seule, lecture seule.
Route::get('/widget', [WidgetController::class, 'data'])->middleware('throttle:60,1')->name('widgets.data');
