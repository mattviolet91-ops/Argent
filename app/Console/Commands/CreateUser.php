<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Crée le compte (une seule personne utilise l'app). À lancer une fois, dans le terminal du serveur. */
class CreateUser extends Command
{
    protected $signature = 'app:create-user {--name= : Nom affiché} {--email= : Adresse email de connexion} {--si-absent : Ne rien faire si le compte existe déjà}';

    protected $description = 'Crée le compte qui utilise l\'app Argent';

    public function handle(): int
    {
        if (User::query()->exists()) {
            if ($this->option('si-absent')) {
                $this->info('Le compte existe déjà.');

                return self::SUCCESS;
            }
            $this->error('Un compte existe déjà. Pour changer le mot de passe : php artisan app:password');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Nom', 'Matt');
        $email = $this->option('email') ?: $this->ask('Email de connexion');
        $password = (string) $this->secret('Mot de passe (12 caractères minimum, lettres et chiffres)');
        $confirmation = (string) $this->secret('Confirmez le mot de passe');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            ['name' => ['required', 'max:80'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()]],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::query()->create(['name' => $name, 'email' => mb_strtolower($email), 'password' => $password]);
        $this->info('Compte créé. Ouvrez l\'app dans le navigateur et connectez-vous.');

        return self::SUCCESS;
    }
}
