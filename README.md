# Argent

Application privée de gestion d'argent de Matt (à la manière de Money Stats) :
comptes perso et pro, ce qui est gagné et dépensé, budgets, objectifs, dépenses
fixes, bilans de chaque semaine. Reliée à l'app de devis Matt's Couverture, qui lui
envoie chaque lundi les paiements reçus et les frais des chantiers.

- **Installation sur o2switch** : [docs/INSTALLATION.md](docs/INSTALLATION.md)
- **Adresse prévue** : `https://argent.matts-couverture.fr`

## Ce qu'elle fait

- Connexion (email + mot de passe), puis **code Argent** à chaque ouverture et après
  quelques minutes sans activité ; 5 codes faux = blocage 15 minutes + alerte.
- Comptes perso / pro (courant, livret, espèces…) avec leur solde et leur évolution.
- Gagné / dépensé / résultat par semaine, mois, année, vue Tout / Perso / Pro,
  comparaison avec la période d'avant, graphiques sur 12 mois.
- Ajout rapide (dépense, revenu, virement entre comptes), catégories, budgets
  mensuels, objectifs (épargne, encaissé, gain, plafond de dépenses).
- Dépenses et revenus fixes notés tout seuls, solde prévu en fin de mois.
- Import de relevé bancaire CSV ou OFX (doublons écartés, catégories apprises).
- Lien avec l'app de devis : paiements et frais copiés chaque lundi (clé en lecture
  seule), bilan de la semaine figé et notification.
- Mode discret (montants floutés), export Excel (CSV), sauvegarde chaque nuit,
  journal de sécurité, icône sur l'écran d'accueil, notifications.

## Technique

PHP 8.2+, Laravel 12, Blade, CSS et JavaScript maison (aucune compilation),
SQLite. Montants en centimes. Tests : `php artisan test`.

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan app:create-user
php artisan serve
```
