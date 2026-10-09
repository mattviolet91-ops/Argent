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
- Abonnements repérés tout seuls dans les relevés (même commerçant, même montant,
  rythme régulier), proposés pour les Fixes.
- Tendances : « ce mois-ci, vous dépensez 30 % de plus en restaurants que
  d'habitude » (comparé aux 3 mois d'avant, aux mêmes jours).
- Calendrier : dépenses de chaque jour, échéances à venir (fixes, garanties,
  remboursements).
- Garanties et factures d'achat (photo ou PDF gardés en privé), rappel un mois
  avant la fin de la garantie.
- « Qui me doit quoi » : prêts, avances, emprunts et remboursements par personne,
  rappel si un remboursement tarde.
- Justificatifs (photo du ticket, PDF) sur n'importe quel mouvement, gardés en privé.
- Chantiers et projets : une étiquette sur des mouvements de toutes catégories donne
  le coût total, l'encaissé, la marge et le budget prévu.
- Notes de frais : dépense pro payée avec un compte perso, comptée en pro, suivie
  jusqu'au virement de remboursement.
- Hausse de prix d'un abonnement repérée à l'import du relevé (les Fixes suivent).
- « Puis-je me le permettre ? » : solde le plus bas à venir avec un achat (en une
  ou plusieurs fois), budget, épargne habituelle.
- Crédits : capital restant, date de fin, intérêts restants, tableau d'amortissement,
  mensualité dans les Fixes.
- Patrimoine net sur 12 mois, comparaison avec le même mois l'an dernier, bilan
  du mois en PDF (pour le comptable).
- Mode hors ligne (à activer par appareil dans Réglages) : sans réseau, l'app
  s'ouvre avec le code sur un résumé gardé chiffré sur le téléphone (AES-GCM, clé
  de l'appareil chiffrée avec le code), et les dépenses notées sont ajoutées au
  retour du réseau, sans doublon. 10 codes faux hors ligne effacent ces données.
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
