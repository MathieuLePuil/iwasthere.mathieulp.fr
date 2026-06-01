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

# Notifications push VAPID (voir section dédiée)
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:contact@iwasthere.app
```

### 3. Démarrer les conteneurs

```bash
docker compose up -d
```

| Service      | Description                | URL locale            |
|--------------|----------------------------|-----------------------|
| `php`        | PHP 8.4-FPM (application)  | —                     |
| `nginx`      | Serveur web                | http://localhost:8080 |
| `database`   | MariaDB 11.4               | localhost:3306        |
| `phpmyadmin` | Interface base de données  | http://localhost:8081 |
| `mailer`     | Mailpit (catch-all SMTP)   | http://localhost:8025 |

### 4. Installer les dépendances PHP

```bash
docker compose exec php composer install
```

### 5. Créer la base et lancer les migrations

```bash
docker compose exec php php bin/console doctrine:database:create --if-not-exists
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

### 6. Accéder à l'application

http://localhost:8080

---

## Notifications push (Web Push / VAPID)

Le système de notifications push utilise la spec Web Push (compatible Chrome, Firefox, Edge, Safari iOS 16.4+ en mode PWA installé).

### Comportement côté utilisateur

- À la **première ouverture de la webapp**, une popup propose d'activer les notifications. L'utilisateur peut cliquer « Activer » ou « Plus tard ».
- Dans **Paramètres → Notifications push**, une coche indique si les notifications sont activées sur cet appareil. Un bouton « Activer » apparaît tant que ce n'est pas le cas.
- Pour **désactiver** les notifications, l'utilisateur doit passer par les **Réglages de son téléphone** (iOS : `Réglages → Notifications → IWasThere` ; Android/Chrome : `Paramètres du site`). L'application n'expose pas de bouton de désactivation.
- Sur iOS, le push Web Push ne fonctionne **que si la PWA a été installée** depuis Safari via « Ajouter à l'écran d'accueil ».

### Mise en place en développement

1. Générer une paire de clés VAPID :

   ```bash
   docker compose exec php php bin/console app:generate-vapid-keys
   ```

2. Copier les clés affichées dans `.env.local` :

   ```dotenv
   VAPID_PUBLIC_KEY=...
   VAPID_PRIVATE_KEY=...
   VAPID_SUBJECT=mailto:contact@iwasthere.app
   ```

3. Vider le cache Symfony :

   ```bash
   docker compose exec php php bin/console cache:clear
   ```

4. Sur Chrome/Firefox, autoriser les notifications via la popup, puis envoyer une notif de test :

   ```bash
   docker compose exec php php bin/console app:push:test <username> "Message de test"
   ```

### Déploiement en production — checklist

1. **HTTPS obligatoire.** Web Push ne fonctionne pas en HTTP (sauf `localhost`). Configure un certificat TLS (Let's Encrypt par exemple) avant toute mise en prod.

2. **Variables d'environnement.** Ajouter dans la configuration prod (`.env.local`, secrets Symfony, variables Docker/Kubernetes, etc.) :

   ```dotenv
   VAPID_PUBLIC_KEY=<clé publique générée une seule fois>
   VAPID_PRIVATE_KEY=<clé privée générée une seule fois>
   VAPID_SUBJECT=mailto:contact@iwasthere.app   # ou un mailto valide qui te reçoive
   ```

   ⚠️ **Une fois les clés VAPID en prod, ne JAMAIS les changer.** Les abonnements existants seraient invalidés et chaque utilisateur devrait réactiver les notifications. Sauvegarde la paire dans ton gestionnaire de secrets.

3. **Migration de base.** Le rebuild a (re)créé la table `push_subscription`. À déployer :

   ```bash
   php bin/console doctrine:migrations:migrate --no-interaction
   ```

4. **Service worker.** Le fichier `public/sw.js` doit être servi avec :
   - `Content-Type: application/javascript`
   - **scope `/`** (déjà géré par défaut puisque le fichier est à la racine de `public/`)
   - pas de `Cache-Control: no-store` agressif côté CDN — sinon les nouvelles versions du SW ne sont pas récupérées.

5. **Manifest PWA.** Vérifie que `/manifest.json` (avec `display: standalone`, `start_url: /`, `name`, `icons`) est servi correctement. Sans ça, iOS refuse d'installer la PWA → pas de push possible.

6. **Icônes.** Les notifications utilisent :
   - `/icons/icon-192.png` (icône principale)
   - `/icons/icon-96.png` (badge sur Android)

   Vérifie qu'ils existent en prod (`public/icons/`).

7. **Test final en prod.** Une fois déployé :
   - Sur iPhone : ouvrir Safari → `https://iwasthere.mathieulp.fr` → bouton Partager → « Sur l'écran d'accueil ». Ouvrir l'icône installée. Accepter la popup. Puis :

     ```bash
     php bin/console app:push:test <ton-username> "Push prod OK"
     ```

   - Sur Chrome desktop : accepter la popup, puis lancer la même commande.

8. **Maintenance.** Le `PushService` supprime automatiquement de la base les abonnements expirés (HTTP 410 / 404 du push server). Aucune commande de purge n'est nécessaire.

### Quand un push est-il envoyé ?

| Événement déclencheur                  | Conditions                                                                  |
|----------------------------------------|------------------------------------------------------------------------------|
| Demande d'ami reçue                    | Destinataire a `notifFriendRequestEnabled = true`                            |
| Demande d'ami acceptée                 | Toujours envoyé au demandeur initial                                         |
| Ajouté en ami à un événement (tag)     | Destinataire a `notifFriendRequestEnabled = true`                            |

Ces préférences se règlent dans **Paramètres → Préférences de notifications**.

---

## Commandes utiles

```bash
docker compose logs -f                                          # logs
docker compose exec php bash                                    # shell conteneur PHP
docker compose exec php php bin/console cache:clear             # vider le cache
docker compose exec php php bin/console make:migration          # créer une migration
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/phpunit                         # tests
docker compose exec php php bin/console app:generate-vapid-keys # générer clés VAPID
docker compose exec php php bin/console app:push:test <user>    # envoyer un push de test
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

| Variable               | Description                                  | Requis en prod |
|------------------------|----------------------------------------------|----------------|
| `APP_SECRET`           | Clé secrète Symfony                          | Oui            |
| `DATABASE_URL`         | URL de connexion BDD                         | Oui            |
| `GOOGLE_CLIENT_ID`     | OAuth Google — identifiant client            | Si Google      |
| `GOOGLE_CLIENT_SECRET` | OAuth Google — secret client                 | Si Google      |
| `SETLISTFM_API_KEY`    | Clé API Setlist.fm                           | Recommandé     |
| `VAPID_PUBLIC_KEY`     | Clé publique VAPID (push)                    | Oui            |
| `VAPID_PRIVATE_KEY`    | Clé privée VAPID (push)                      | Oui            |
| `VAPID_SUBJECT`        | Contact VAPID (`mailto:...` valide)          | Oui            |
| `MAILER_DSN`           | DSN du serveur SMTP                          | Oui            |

---

## Arrêter l'environnement

```bash
docker compose down       # arrêter (données conservées)
docker compose down -v    # arrêter et supprimer les volumes
```
