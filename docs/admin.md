# IWasThere — Espace Administrateur

## Accès à l'interface admin

L'interface d'administration est accessible à l'URL :

```
/admin
```

L'accès est restreint aux comptes dont la colonne `user.role` vaut `superAdmin`
(traduit en `ROLE_SUPER_ADMIN` par `User::getRoles()`).

---

## Comment créer un compte administrateur

1. Crée d'abord un compte utilisateur normal (via `/register` ou Google).
2. Promeus-le depuis la console :

```bash
php bin/console app:user:promote ton.email@example.com   # ou @pseudo
php bin/console app:user:promote @pseudo --demote        # pour retirer le rôle
```

---

## Promouvoir un utilisateur existant via l'interface admin

Une fois connecté en tant qu'admin :

1. Va dans **Utilisateurs** (`/admin/users`)
2. Clique sur le nom de l'utilisateur à promouvoir
3. Dans la fiche utilisateur, **Modifier**, puis le champ **Rôle** (`Super Admin`)

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

- Seuls les comptes avec `ROLE_SUPER_ADMIN` peuvent accéder à `/admin/*`
- La configuration se trouve dans `config/packages/security.yaml` :

```yaml
access_control:
    - { path: ^/admin, roles: ROLE_SUPER_ADMIN }
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
