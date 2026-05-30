# IWasThere — Espace Administrateur

## Accès à l'interface admin

L'interface d'administration est accessible à l'URL :

```
/admin
```

L'accès est restreint aux utilisateurs ayant le rôle `ROLE_ADMIN`.

---

## Comment créer un compte administrateur

### Méthode 1 — Via la console Symfony (recommandée)

```bash
php bin/console app:create-admin --email=admin@example.com --password=motdepasse
```

> Si cette commande n'existe pas, utilise la méthode 2.

### Méthode 2 — Via la base de données directement

1. Crée d'abord un compte utilisateur normal (via `/register` ou Google OAuth).

2. Ouvre phpMyAdmin ou une console SQL, et exécute :

```sql
UPDATE user
SET roles = '["ROLE_ADMIN"]'
WHERE email = 'ton.email@example.com';
```

Remplace `ton.email@example.com` par l'adresse email du compte à promouvoir.

### Méthode 3 — Via Symfony Console (Doctrine)

```bash
# Dans le container Docker
docker compose exec php php bin/console doctrine:query:sql \
  "UPDATE user SET roles='[\"ROLE_ADMIN\"]' WHERE email='ton.email@example.com'"
```

---

## Promouvoir un utilisateur existant via l'interface admin

Une fois connecté en tant qu'admin :

1. Va dans **Utilisateurs** (`/admin/users`)
2. Clique sur le nom de l'utilisateur à promouvoir
3. Dans la fiche utilisateur, clique sur **Promouvoir en Admin**

---

## Fonctionnalités de l'espace admin

| Section | URL | Description |
|---|---|---|
| Dashboard | `/admin` | Vue d'ensemble : compteurs globaux, dernières activités |
| Utilisateurs | `/admin/users` | Liste, recherche, détail, promotion/rétrogradation |
| Événements | `/admin/events` | Tous les événements de la base commune |
| Lieux | `/admin/venues` | Gestion des salles/stades/venues |
| Journal d'audit | `/admin/audit` | Historique des actions sensibles (RGPD) |

---

## Sécurité

- Seuls les comptes avec `ROLE_ADMIN` peuvent accéder à `/admin/*`
- La configuration se trouve dans `config/packages/security.yaml` :

```yaml
access_control:
    - { path: ^/admin, roles: ROLE_ADMIN }
```

- Toutes les actions admin sont tracées dans la table `audit_log`
- L'admin ne peut pas se supprimer lui-même

---

## Variables d'environnement utiles

```env
# .env.local
DATABASE_URL="mysql://user:password@127.0.0.1:3306/iwasthere"
APP_ENV=prod
APP_SECRET=votre_secret_32_caracteres
```

---

## Démarrage en local (Docker)

```bash
docker compose up -d
php bin/console doctrine:migrations:migrate
```

phpMyAdmin est accessible sur `http://localhost:8081`
