<?php

namespace App\Providers;

use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Ajout rapide (bouton « + ») présent sur toutes les pages de l'app.
        View::composer('layouts.app', function ($view) {
            $view->with([
                'quickAccounts' => auth()->check() ? MoneyAccount::query()->active()->ordered()->get() : collect(),
                'quickCategories' => auth()->check() ? MoneyCategory::query()->active()->ordered()->get() : collect(),
            ]);
        });
    }
}
