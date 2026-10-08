<?php

use App\Http\Controllers;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Middleware\MoneyGate;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [LoginController::class, 'create'])->name('login');
    Route::post('/connexion', [LoginController::class, 'store'])->middleware('throttle:20,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/deconnexion', [LoginController::class, 'destroy'])->name('logout');

    // Code Argent : création, déverrouillage, verrouillage, code oublié.
    Route::middleware(MoneyGate::class.':lock')->group(function () {
        Route::get('/code', [Controllers\LockController::class, 'setup'])->name('setup');
        Route::post('/code', [Controllers\LockController::class, 'storeSetup'])->middleware('throttle:10,1')->name('setup.store');
        Route::get('/verrou', [Controllers\LockController::class, 'unlockForm'])->name('unlock');
        Route::post('/verrou', [Controllers\LockController::class, 'unlock'])->middleware('throttle:20,1')->name('unlock.store');
        Route::post('/verrou/faceid/options', [Controllers\LockController::class, 'faceIdOptions'])->middleware('throttle:20,1')->name('unlock.faceid.options');
        Route::post('/verrou/faceid', [Controllers\LockController::class, 'faceIdUnlock'])->middleware('throttle:20,1')->name('unlock.faceid');
        Route::post('/verrouiller', [Controllers\LockController::class, 'lock'])->name('lock');
        Route::get('/code-oublie', [Controllers\LockController::class, 'forgot'])->name('forgot');
        Route::post('/code-oublie', [Controllers\LockController::class, 'reset'])->middleware('throttle:5,1')->name('forgot.store');
    });

    Route::middleware(MoneyGate::class)->group(function () {
        Route::get('/', Controllers\DashboardController::class)->name('dashboard');

        Route::get('/mouvements', [Controllers\TransactionController::class, 'index'])->name('transactions.index');
        Route::post('/mouvements', [Controllers\TransactionController::class, 'store'])->name('transactions.store');
        Route::get('/mouvements/{transaction}/modifier', [Controllers\TransactionController::class, 'edit'])->whereNumber('transaction')->name('transactions.edit');
        Route::put('/mouvements/{transaction}', [Controllers\TransactionController::class, 'update'])->whereNumber('transaction')->name('transactions.update');
        Route::delete('/mouvements/{transaction}', [Controllers\TransactionController::class, 'destroy'])->whereNumber('transaction')->name('transactions.destroy');

        Route::get('/comptes', [Controllers\AccountController::class, 'index'])->name('accounts.index');
        Route::post('/comptes', [Controllers\AccountController::class, 'store'])->name('accounts.store');
        Route::get('/comptes/{account}', [Controllers\AccountController::class, 'show'])->whereNumber('account')->name('accounts.show');
        Route::put('/comptes/{account}', [Controllers\AccountController::class, 'update'])->whereNumber('account')->name('accounts.update');
        Route::delete('/comptes/{account}', [Controllers\AccountController::class, 'destroy'])->whereNumber('account')->name('accounts.destroy');

        Route::get('/budgets', [Controllers\CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [Controllers\CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [Controllers\CategoryController::class, 'update'])->whereNumber('category')->name('categories.update');
        Route::delete('/categories/{category}', [Controllers\CategoryController::class, 'destroy'])->whereNumber('category')->name('categories.destroy');

        Route::get('/objectifs', [Controllers\GoalController::class, 'index'])->name('goals.index');
        Route::post('/objectifs', [Controllers\GoalController::class, 'store'])->name('goals.store');
        Route::put('/objectifs/{goal}', [Controllers\GoalController::class, 'update'])->whereNumber('goal')->name('goals.update');
        Route::get('/objectifs/{goal}', [Controllers\GoalController::class, 'show'])->whereNumber('goal')->name('goals.show');
        Route::post('/objectifs/{goal}/versement', [Controllers\GoalController::class, 'contribute'])->whereNumber('goal')->name('goals.contribute');
        Route::post('/objectifs/{goal}/depot', [Controllers\GoalController::class, 'deposit'])->whereNumber('goal')->name('goals.deposit');
        Route::post('/objectifs/{goal}/retrait', [Controllers\GoalController::class, 'withdraw'])->whereNumber('goal')->name('goals.withdraw');
        Route::post('/objectifs/{goal}/automatique', [Controllers\GoalController::class, 'automatic'])->whereNumber('goal')->name('goals.automatic');
        Route::post('/objectifs/{goal}/compte', [Controllers\GoalController::class, 'useAccount'])->whereNumber('goal')->name('goals.account');
        Route::delete('/objectifs/{goal}', [Controllers\GoalController::class, 'destroy'])->whereNumber('goal')->name('goals.destroy');

        Route::get('/fixes', [Controllers\RecurringController::class, 'index'])->name('recurrings.index');
        Route::post('/fixes', [Controllers\RecurringController::class, 'store'])->name('recurrings.store');
        Route::put('/fixes/{recurring}', [Controllers\RecurringController::class, 'update'])->whereNumber('recurring')->name('recurrings.update');
        Route::delete('/fixes/{recurring}', [Controllers\RecurringController::class, 'destroy'])->whereNumber('recurring')->name('recurrings.destroy');

        Route::get('/releve', [Controllers\ImportController::class, 'create'])->name('import.create');
        Route::post('/releve/apercu', [Controllers\ImportController::class, 'preview'])->middleware('throttle:20,1')->name('import.preview');
        Route::get('/releve/apercu', [Controllers\ImportController::class, 'show'])->name('import.show');
        Route::post('/releve', [Controllers\ImportController::class, 'store'])->name('import.store');

        Route::get('/bilans', [Controllers\ReportController::class, 'index'])->name('reports.index');
        Route::get('/bilans/{report}', [Controllers\ReportController::class, 'show'])->whereNumber('report')->name('reports.show');

        Route::get('/reglages', [Controllers\SettingsController::class, 'edit'])->name('settings');
        Route::put('/reglages', [Controllers\SettingsController::class, 'update'])->name('settings.update');
        Route::put('/reglages/devis', [Controllers\SettingsController::class, 'updateDevis'])->middleware('throttle:10,1')->name('settings.devis');
        Route::put('/reglages/code', [Controllers\SettingsController::class, 'updateCode'])->middleware('throttle:10,1')->name('settings.code');
        Route::put('/reglages/mot-de-passe', [Controllers\SettingsController::class, 'updatePassword'])->middleware('throttle:10,1')->name('settings.password');
        Route::post('/mise-a-jour', [Controllers\SettingsController::class, 'sync'])->middleware('throttle:10,1')->name('sync');
        Route::get('/export', [Controllers\SettingsController::class, 'export'])->middleware('throttle:10,1')->name('export');
        Route::get('/sauvegardes/{name}', [Controllers\SettingsController::class, 'backup'])->where('name', 'argent-[\d-]+\.(sqlite|json)')->middleware('throttle:10,1')->name('backups.download');

        Route::post('/faceid/options', [Controllers\FaceIdController::class, 'options'])->middleware('throttle:10,1')->name('faceid.options');
        Route::post('/faceid', [Controllers\FaceIdController::class, 'store'])->middleware('throttle:10,1')->name('faceid.store');
        Route::delete('/faceid/{credential}', [Controllers\FaceIdController::class, 'destroy'])->whereNumber('credential')->name('faceid.destroy');

        Route::post('/notifications/abonnement', [Controllers\PushController::class, 'subscribe'])->middleware('throttle:20,1')->name('push.subscribe');
        Route::post('/notifications/desabonnement', [Controllers\PushController::class, 'unsubscribe'])->name('push.unsubscribe');
        Route::post('/notifications/test', [Controllers\PushController::class, 'test'])->middleware('throttle:5,1')->name('push.test');
    });
});
