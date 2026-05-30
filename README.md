# IWasThere

Application web Symfony 8 de type PWA pour tracker et partager sa présence à des événements (concerts, festivals, événements sportifs…).

---

## Prérequis

- [Docker](https://www.docker.com/get-started) et Docker Compose
- [Git](https://git-scm.com/)

Aucune installation locale de PHP, Composer ou Node n'est nécessaire — tout tourne dans les conteneurs.

---

## Installation

### 1. Cloner le dépôt

```bash
git clone <url-du-repo> iwasthere
cd iwasthere
```

### 2. Configurer les variables d'environnement

Copier le fichier `.env` en `.env.local` pour y ajouter les secrets :

```bash
cp .env .env.local
```

Renseigner au minimum dans `.env.local` :

```dotenv
# OAuth Google (optionnel pour le dev)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=

# Setlist.fm (optionnel pour le dev)
SETLISTFM_API_KEY=

# Notifications push VAPID (générer avec la commande ci-dessous)
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:ton@email.com
```

> Les identifiants de base de données sont préconfigurés dans `compose.yaml` avec des valeurs par défaut (`iwasthere` / `iwasthere`). Aucune modification n'est nécessaire pour le développement local.

### 3. Démarrer les conteneurs

```bash
docker compose up -d
```

Les services suivants sont lancés :

| Service    | Description                   | URL locale                   |
|------------|-------------------------------|------------------------------|
| `php`      | PHP 8.4-FPM (application)     | —                            |
| `nginx`    | Serveur web                   | http://localhost:8080        |
| `database` | MariaDB 11.4                  | localhost:3306               |
| `phpmyadmin` | Interface base de données  | http://localhost:8081        |
| `mailer`   | Mailpit (catch-all SMTP)      | http://localhost:8025        |

### 4. Installer les dépendances PHP

```bash
docker compose exec php composer install
```

### 5. Créer la base de données et lancer les migrations

```bash
docker compose exec php php bin/console doctrine:database:create --if-not-exists
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

### 6. Générer les clés VAPID (notifications push)

```bash
docker compose exec php php bin/console app:generate-vapid-keys
```

Copier les clés affichées dans `.env.local`.

### 7. Accéder à l'application

Ouvrir http://localhost:8080 dans le navigateur.

---

## Commandes utiles

```bash
# Afficher les logs en temps réel
docker compose logs -f

# Ouvrir un shell dans le conteneur PHP
docker compose exec php bash

# Vider le cache Symfony
docker compose exec php php bin/console cache:clear

# Créer une migration après modification d'une entité
docker compose exec php php bin/console make:migration

# Appliquer les migrations
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# Lancer les tests
docker compose exec php php bin/phpunit
```

---

## Structure Docker

```
docker/
├── nginx/
│   └── default.conf       # Configuration Nginx
├── php/
│   ├── Dockerfile         # Image PHP 8.4-FPM Alpine
│   └── php.ini            # Configuration PHP
└── phpmyadmin/
    └── config.user.inc.php
compose.yaml               # Services Docker
compose.override.yaml      # Ports exposés (dev uniquement)
```

---

## Variables d'environnement

| Variable              | Description                              | Requis  |
|-----------------------|------------------------------------------|---------|
| `APP_SECRET`          | Clé secrète Symfony (auto en dev)        | Non     |
| `DATABASE_URL`        | URL de connexion BDD (auto en Docker)    | Non     |
| `GOOGLE_CLIENT_ID`    | OAuth Google — identifiant client        | Non     |
| `GOOGLE_CLIENT_SECRET`| OAuth Google — secret client             | Non     |
| `SETLISTFM_API_KEY`   | Clé API Setlist.fm                       | Non     |
| `VAPID_PUBLIC_KEY`    | Clé publique VAPID (push notifications)  | Non     |
| `VAPID_PRIVATE_KEY`   | Clé privée VAPID (push notifications)    | Non     |
| `VAPID_SUBJECT`       | Contact VAPID (`mailto:...`)             | Non     |

---

## Arrêter l'environnement

```bash
# Arrêter les conteneurs (données conservées)
docker compose down

# Arrêter et supprimer les volumes (repart de zéro)
docker compose down -v
```
