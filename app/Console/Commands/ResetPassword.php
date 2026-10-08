<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Mot de passe oublié : on le change depuis le terminal du serveur (pas d'envoi d'email). */
class ResetPassword extends Command
{
    protected $signature = 'app:password';

    protected $description = 'Change le mot de passe du compte';

    public function handle(): int
    {
        $user = User::query()->first();
        if (! $user) {
            $this->error('Aucun compte. Créez-le avec : php artisan app:create-user');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Nouveau mot de passe pour '.$user->email.' (12 caractères minimum, lettres et chiffres)');
        $confirmation = (string) $this->secret('Confirmez le mot de passe');
        $validator = Validator::make(['password' => $password, 'password_confirmation' => $confirmation], ['password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // Nouveau mot de passe : tous les appareils sont déconnectés.
        $user->forceFill(['password' => $password, 'remember_token' => null])->save();
        $this->info('Mot de passe changé. Tous les appareils devront se reconnecter.');

        return self::SUCCESS;
    }
}
