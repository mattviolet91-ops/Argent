# Installer l'app Argent sur o2switch

L'app Argent est **séparée** de l'app de devis : sa propre adresse
(`https://argent.matts-couverture.fr`), sa propre icône sur le téléphone, sa propre
connexion et sa propre base. Elle lit chaque semaine les paiements et frais de l'app
de devis avec une clé en lecture seule.

Durée : environ 20 minutes. Il faut l'accès à cPanel (o2switch) et à WordPress.com
(c'est là que se règlent les adresses du domaine).

---

## Étape 1 — Déclarer l'adresse `argent.` (WordPress.com)

Comme pour `test.` : WordPress.com → **Mises à niveau → Domaines** →
`matts-couverture.fr` → **Enregistrements DNS** → **Ajouter un enregistrement** :

| Type | Nom | Valeur |
|---|---|---|
| A | `argent` | la même adresse IP que l'enregistrement `test` |

Ne rien modifier d'autre. La prise en compte peut prendre jusqu'à une heure.

## Étape 2 — Créer le sous-domaine (cPanel)

cPanel → **Domaines** → **Créer un nouveau domaine** :
- Domaine : `argent.matts-couverture.fr`
- Décocher « partager la racine du document »
- Racine du document : `argent/public`

## Étape 3 — Installer (cPanel → Terminal)

Copier-coller cette ligne, puis Entrée :

```bash
git clone https://github.com/mattviolet91-ops/Argent-.git ~/argent-source && bash ~/argent-source/scripts/install.sh
```

Le script vérifie PHP, installe l'app, crée la base, puis **demande votre nom, votre
email et un mot de passe** (12 caractères minimum, lettres et chiffres) : ce sera la
connexion à l'app. Il ajoute aussi les tâches automatiques (sauvegarde chaque nuit,
bilan du lundi, mise à jour automatique depuis GitHub toutes les 10 minutes).

> Si le script dit « Extension pdo_sqlite manquante » : cPanel → **Sélectionner une
> version de PHP** → Extensions → cocher `pdo_sqlite`, puis relancer la ligne
> `bash ~/argent-source/scripts/install.sh`.

## Étape 4 — Activer le HTTPS

cPanel → **Statut SSL/TLS** → cocher `argent.matts-couverture.fr` → **Exécuter AutoSSL**.
Si l'étape 1 n'est pas encore prise en compte, réessayer un peu plus tard.

## Étape 5 — Créer la clé dans l'app de devis

Dans l'app de devis : **Réglages → Accès Claude → « Créer la clé de l'app Argent »**.
Copier la clé affichée (elle ne sera plus jamais montrée).

## Étape 6 — Première ouverture

Ouvrir `https://argent.matts-couverture.fr` sur le téléphone :
1. se connecter (email et mot de passe de l'étape 3) ;
2. choisir le **code Argent** (4 à 8 chiffres) et le solde de vos comptes ;
3. coller la clé de l'étape 5 (adresse : `https://test.matts-couverture.fr`) ;
4. installer l'icône : iPhone → Partager → « Sur l'écran d'accueil » ;
   Android → menu ⋮ → « Installer l'application » ;
5. ouvrir l'app depuis l'icône → Réglages → **Activer les notifications**.

---

## Au quotidien

- **Mises à jour** : automatiques (le serveur regarde GitHub toutes les 10 minutes,
  notification « Argent mis à jour »). Journal : `~/argent-deploy.log`.
- **Sauvegardes** : chaque nuit dans `~/argent/storage/app/private/backups`
  (30 gardées), téléchargeables dans Réglages → Vos données.
- **Mot de passe oublié** : Terminal → `cd ~/argent && php artisan app:password`.
- **Code Argent oublié** : écran du code → « Code oublié ? » (avec le mot de passe).

## Désinstaller

Supprimer les deux lignes `argent` dans cPanel → **Tâches Cron**, le sous-domaine,
les dossiers `argent` et `argent-source`, l'enregistrement DNS `argent` sur
WordPress.com, et la clé « App Argent » dans l'app de devis.
