# Alertes d'ouverture de billetterie (Ticketmaster)

Un utilisateur suit un événement à venir et reçoit une alerte **24 h** puis **1 h**
avant l'ouverture de sa billetterie. La date d'ouverture est connue à l'avance et
stockée localement : l'échéance est *planifiée*, pas détectée — la latence du
flux n'entre pas en jeu. Hors périmètre : achat, réservation, revente, places, prix.

À côté, les **annonces d'artistes** : l'utilisateur tient une wishlist et/ou
demande à être prévenu des artistes qu'il a déjà vus ; dès qu'une date d'un de
ces artistes entre au catalogue, il reçoit une notification (§3, F5).

Code : `src/Ticketmaster/`, entités `TmEvent`, `TmSaleWindow`, `EventWatch`,
`WatchNotification`, `ArtistWatch`, contrôleur `AlertsController` (`/alerts`),
commandes `app:ticketmaster:sync`, `app:notifications:dispatch`,
`app:ticketmaster:check-dst`.

---

## 1. Source : Ticketmaster Discovery Feed, flux France

```
GET https://app.ticketmaster.com/discovery-feed/v2/events.json?countryCode=FR&apikey={KEY}
```

Répond **303** vers un fichier S3 horodaté (le client HTTP suit). Corps : un gzip
d'environ 400 Mo décompressés, **un seul objet JSON** `{"events":[…]}` — pas du
JSON Lines. Régénéré environ toutes les heures. ~61 000 événements France, dont
7 539 en `classificationSegment = Music`.

Quota : 5 000 appels/jour ; le module en consomme 24 (0,5 %).

Clé : `TICKETMASTER_API_KEY` dans `.env.local`.

### Champs utilisés

`eventId` (clé naturelle, stable), `eventName`, `eventStatus` (`onsale` /
`offsale` / `cancelled` / `rescheduled`), **`onsaleStartDateTime`**,
`onsaleEndDateTime`, `eventStartDateTime`, `eventStartLocalDate`,
`eventStartLocalTime`, `primaryEventUrl`, `venue.venueName`, `venue.venueCity`,
`venue.venueTimezone`, `classificationSegment`, `classificationGenre`, `source`.

`onsaleStartDateTime` est la pièce maîtresse : présent sur 100 % du catalogue,
en UTC, vérifié contre l'affichage du site.

**`eventId` est sensible à la casse** (constaté sur le flux du 19/09/2026 :
7 539 ids Music distincts, 7 140 en ignorant la casse). Les colonnes `event_id`
sont en collation `utf8mb4_bin` — avec la collation par défaut, 399 événements
en écrasaient d'autres à chaque passage.

Ordres de grandeur relevés le 19/09/2026 : 27,8 Mo compressés, 60 960 événements
(Arts & Theatre 49 741, Music 7 539, Miscellaneous 3 193, Film 374, Sports 113),
statuts onsale 56 190 / offsale 3 916 / rescheduled 551 / cancelled 303.
Synchronisation complète en **21 s**. À un instant donné, seule une mince
tranche du catalogue Music a une ouverture encore à venir (17 ce jour-là) : la
vue « ouverture à venir » est courte par nature, c'est la vue « déjà en vente »
qui porte le volume.

### Champs à ne pas utiliser (`EventProjector` les ignore)

- **`apiOnsaleStartDateTime`** — décalé de +1 h sur les 60 998 événements, sans
  exception : bug de conversion de fuseau côté Ticketmaster.
- **`presales[]`** — vide sur 100 % du catalogue France (voir §2).
- `resaleEventUrl` (`null` partout), `transactable` et `hotEvent` (`false`
  partout).

### `attractions[]` — finalement exploitable

Le cahier des charges le donnait pour inexploitable ; sur le flux du 19/09/2026,
**5 112 des 7 539 événements Music (68 %)** portent
`attractions[].attraction.attractionName` — le nom d'artiste propre (« Muse »
sous « MUSE », « PRESTATION HOSPITALITE MUSE », « UPGRADE HAIDEN HENDERSON »).
`tm_event.artist_name` et `artists_normalized` (« | muse | ») le stockent ; c'est
la base du mode de recherche « Artiste ».

### Une offre = un événement

Ticketmaster publie **un événement par type de billet** pour un même concert :
vente standard, vente co-promue, loges premium, hospitalité… avec le même nom,
la même salle et la même date (quatre « MUSE » le 27/11/2026 à Nanterre,
`idmanif` différents, `eventInfo` le dit). La recherche regroupe par
artiste (ou nom) + salle + date + heure et ne montre qu'une carte, « n offres
regroupées », qui représente l'offre dont la billetterie ouvre en premier (à
égalité : nom commençant par l'artiste, puis le plus court — la vente standard).

---

## 2. Préventes

Elles ne sont pas exposées sur le marché français : `presales[]` est vide sur
tout le catalogue, et `apiOnsaleStartDateTime` n'est pas une date de prévente.

Le module alerte donc sur la **mise en vente générale** uniquement, et
l'interface le dit (bandeau sur `/alerts`, pied des e-mails).

Le schéma prévoit quand même `tm_sale_window` (`type` = `public` | `presale`) :
les alertes se planifient sur les *fenêtres*, pas sur l'événement. Si le flux
alimente un jour `presales[]`, `EventProjector::presales()` écrit des lignes
supplémentaires et `WatchScheduler` planifie dessus sans refonte.

Contrôle trimestriel, un appel :

```bash
php bin/console app:ticketmaster:sync --keep    # garde le .gz dans var/tmp/ticketmaster
gunzip -c var/tmp/ticketmaster/fr-*.json.gz | jq '[.events[] | select(.presales != null and (.presales|length) > 0)] | length'
```

---

## 3. Fonctionnalités

| | Où |
|---|---|
| **F1** Recherche et sélection | `/alerts/search` — mode **Artiste** (défaut : nom entier ou préfixe dans `artists_normalized`, « muse » ne renvoie pas les musées) ou **Nom d'événement ou salle** (plein texte MariaDB sur `name_normalized` + LIKE sur la salle) ; filtres segment (Music par défaut), ville, plage de dates, « ouverture à venir » (défaut) / « déjà en vente » ; une carte par concert (offres regroupées). Bouton **Suivre**. Une veille sur une ouverture passée est acceptée mais signalée. |
| **F2** Alerte J-1 | 24 h avant `starts_at_utc`. Ouverture à moins de 24 h au moment de la veille : « ouverture imminente » tout de suite s'il reste plus de 2 h, rien en deçà. (`AlertPlanner`) |
| **F3** Alerte H-1 | 1 h avant, même contenu, activée par défaut, désactivable par veille. L'interface rappelle que la page Ticketmaster affiche un compte à rebours et redirige vers la réservation. |
| **F4** Gestion des veilles | `/alerts` — liste, échéances à venir, réglage J-1 / H-1 / canal par veille, suppression. `cancelled` → veille désactivée + notification ; `rescheduled` → notification, veille maintenue ; ouverture déplacée de plus d'une heure → échéances reprogrammées + notification. |
| **F5** Annonces d'artistes | `/alerts`, bloc « Artistes attendus » — **wishlist** (`artist_watch`, ajout / retrait, aussi depuis la recherche par artiste : les noms exacts trouvés sont proposés) et bascule **« artistes que j'ai déjà vus »** (`user.alert_seen_artists`, opt-in, relit les concerts passés du journal). À chaque synchronisation, les événements *nouveaux* au catalogue sont rapprochés de ces artistes (`ArtistAnnouncer`, `ArtistAnnouncementMatcher`) : une notification par utilisateur et par artiste, qui liste les dates et mène à la recherche — de là, « Suivre » pose la veille. Type `artist_announced` (push réglable dans Paramètres → Notifications). |

Entrées : carte sur l'accueil, carte sur le profil, rubrique dans Paramètres.

### F5 — règles de rapprochement

- Nom d'artiste **entier** dans `artists_normalized` (« | muse | ») : « Muse » ne
  déclenche pas sur « Muse by Soaked ». Les événements sans `attractions[]`
  (32 % du Music) ne peuvent pas être rapprochés.
- Seuls les événements **nouveaux** (absents du catalogue avant ce passage)
  comptent ; les offres d'un même concert (artiste + salle + date) sont réduites
  à une, les annulés et les concerts déjà passés sont ignorés.
- Une notification par (utilisateur, artiste, passage), dédupliquée sur
  l'ensemble des concerts annoncés (`dedupe_key` = `artist:` + hash) : un
  événement purgé puis revenu ne fait pas re-sonner, une date de plus si.
- **Garde-fous** : pas d'annonce au premier passage (catalogue vide avant) ni
  quand plus de 2 000 événements entrent d'un coup (segment ajouté, catalogue
  reconstruit) — voir `TicketmasterSyncCommand::MAX_NEW_FOR_ANNOUNCEMENTS`.
- Le flux est **France** uniquement : « annonce une date en France ». Le jour où
  d'autres pays sont synchronisés, il faudra un pays sur le profil pour filtrer.

---

## 4. Collecte — `app:ticketmaster:sync`

```cron
17 * * * * /usr/bin/php /chemin/bin/console app:ticketmaster:sync --env=prod >> /chemin/var/log/tm-sync.log 2>&1
```

Minute décalée : à l'heure pile le fichier S3 peut être en cours de régénération.
Le verrou est interne (`var/tm-sync.lock`), pas besoin de `flock` dans le cron.

Déroulé :

1. verrou — abandon immédiat si un passage est en cours ;
2. espace disque (`FeedDownloader::MIN_FREE_BYTES`, 512 Mo) ;
3. téléchargement du `.gz` dans `var/tmp/ticketmaster/` ;
4. lecture en flux (`halaxa/json-machine`, pointeur `/events`, via
   `compress.zlib://` — jamais décompressé sur disque), projection, filtrage sur
   les segments suivis par au moins une veille active + `Music`. **Tout est lu
   avant la première écriture** : un flux tronqué ou illisible lève et rien n'est
   écrit — un flux partiel marquerait des milliers d'événements comme disparus ;
5. upsert par lots de 500 dans `tm_event` / `tm_sale_window`, dans une
   transaction ; les lignes au hash inchangé ne voient que `last_seen_at` bouger ;
6. détection des changements sur les événements suivis (`ChangeDetector`), puis
   replanification de toutes les veilles actives (`WatchScheduler`) ; annonces
   d'artistes sur les événements nouveaux (`ArtistAnnouncer`, §3 F5) ;
7. nettoyage du temporaire, même en cas d'erreur ;
8. purge : événements absents du flux depuis 7 jours et non suivis, échéances
   envoyées depuis plus de 90 jours.

Cible : moins de 10 minutes (avertissement au-delà). Au-delà de 45 minutes, une
erreur est journalisée dans `var/log/prod.log` — l'alerte d'exploitation.

Options : `--file=chemin.gz` (flux local, pour tester), `--segments=Music,Sports`
ou `all`, `--dry-run`, `--keep`.

Segments : `TICKETMASTER_SEGMENTS` (défaut `Music`) fixe ce que la
synchronisation garde ; un segment suivi par une veille active reste gardé
même s'il en sort. Pour ouvrir la recherche aux autres segments (`Arts &
Theatre` fait l'essentiel du reste du catalogue), les ajouter là — ou `all`.

---

## 5. Envoi — `app:notifications:dispatch`

```cron
* * * * * /usr/bin/php /chemin/bin/console app:notifications:dispatch --env=prod --quiet >> /chemin/var/log/tm-dispatch.log 2>&1
```

Indépendant de la synchronisation : prend les `watch_notification` en attente
dont `scheduled_for` est atteint et les pousse dans Messenger (le worker
`messenger:consume async` existant les envoie). La minute est la granularité
qui fait tomber la H-1 juste.

- **Regroupement** : les échéances du même type, pour le même utilisateur et le
  même canal, qui tombent dans une fenêtre de 2 minutes partent dans un seul
  push listant les événements (beaucoup d'ouvertures à 10:00).
- **Plafond** : 10 pushes par utilisateur et par jour (jour de Paris) ; au-delà,
  un résumé unique et les échéances passent en `capped`.
- **Canal** : Web Push en principal. Le push porte une URL d'accusé de réception
  que le service worker appelle (`POST /push/ack/{lot}/{signature}`) ; sans
  accusé sous 5 minutes, l'**e-mail de secours** part (`SendWatchAlertEmail`,
  `DelayStamp`). Sans abonnement push, ou si l'utilisateur a choisi « e-mail »
  pour la veille, l'e-mail part directement.
- Chaque alerte porte `primaryEventUrl` en action directe. **Pas d'URL, pas
  d'envoi** (statut `skipped`).
- **Journalisation seule** (lot 3) : `--log-only` ou `TICKETMASTER_ALERTS_LOG_ONLY=1`
  — rien ne part, l'échéance est consignée (`logged`) à l'heure prévue. Pour
  vérifier le calage sur quelques ouvertures réelles avant d'alerter les
  utilisateurs.

Vérifier sans rien écrire :

```bash
php bin/console app:notifications:dispatch --dry-run
```

---

## 6. Modèle de données

| Table | Contenu |
|---|---|
| `tm_event` | `event_id` (clé), `name`, `name_normalized` (plein texte), `status`, `event_start_utc`, `event_start_local_date/time`, `venue_name`, `venue_city`, `venue_timezone`, `segment`, `genre`, `url`, `source`, `first_seen_at`, `last_seen_at`, `payload_hash`. Index sur `venue_city`, `segment`, `event_start_utc`, `last_seen_at`. |
| `tm_sale_window` | `event_id`, `type` (`public` \| `presale`), `label`, `starts_at_utc`, `ends_at_utc`, `url`. Unique (`event_id`, `type`, `label`), index sur `starts_at_utc`. |
| `event_watch` | `user_id`, `event_id`, `notify_j1`, `notify_h1`, `channel` (`push` \| `email`), `created_at`, `active`. Unique (`user_id`, `event_id`). |
| `watch_notification` | `watch_id`, `sale_window_id` (nul pour les changements), `type` (`J1` \| `H1` \| `CANCELLED` \| `RESCHEDULED` \| `DATE_CHANGED`), `scheduled_for`, `sent_at`, `acknowledged_at`, `status`, `push_batch`. **Unique (`watch_id`, `sale_window_id`, `type`, `scheduled_for`)** : une reprogrammation ne produit jamais de doublon — une ligne annulée retrouvée à la même heure est ranimée. |
| `artist_watch` | `user_id`, `artist_name` (graphie saisie), `artist_normalized` (clé de rapprochement), `created_at`. Unique (`user_id`, `artist_normalized`). Les annonces elles-mêmes sont des `notification` ordinaires (type `artist_announced`). |

Statuts d'une échéance : `pending` → `queued` → `sent` ; ou `logged`, `capped`,
`skipped`, `cancelled`, `failed` (voir `WatchNotificationStatus`).

### Fuseaux horaires

Les colonnes datetime de ces cinq tables utilisent le type Doctrine
`datetime_utc_immutable` (`App\Doctrine\UtcDateTimeImmutableType`) : **stockage
en UTC** quel que soit le fuseau PHP (le kernel pose Europe/Paris pour le reste
de l'app). Affichage via `DateTimeZone('Europe/Paris')` (`ParisTime`, filtres
Twig `paris`, `paris_day`, `paris_time`) — jamais un décalage fixe.

Contrôle de cohérence après le changement d'heure du 25 octobre 2026 :

```bash
php bin/console app:ticketmaster:check-dst            # histogramme des heures d'ouverture, 7 jours avant / après
php bin/console app:ticketmaster:check-dst --list     # + la liste UTC → Paris
```

Les ouvertures tombent très majoritairement à 10:00 ; une bascule vers 9:00 ou
11:00 après la date signalerait un décalage du flux.

---

## 7. Conformité

Les CGU de l'API interdisent d'en tirer un revenu : pas de publicité sur les
pages portant du contenu Ticketmaster. Le contenu n'est pas conservé au-delà du
nécessaire (purge §4) ; sur demande d'un ayant droit, retirer sous 24 h :

```bash
php bin/console dbal:run-sql "DELETE FROM tm_event WHERE event_id = '…'"   # veilles et échéances partent en cascade
```

---

## 8. Lotissement et mise en service

1. **Lot 1** — migration, `TICKETMASTER_API_KEY`, cron horaire. Valider sur un
   cycle complet de 24 h (`var/log/tm-sync.log` : durée, volumes, purge) avant
   d'exposer l'interface.
2. **Lot 2** — `/alerts` (recherche, veilles). Aucun envoi tant que le cron
   d'envoi n'est pas posé.
3. **Lot 3** — cron d'envoi avec `TICKETMASTER_ALERTS_LOG_ONLY=1` sur quelques
   ouvertures réelles ; comparer `sent_at` des échéances `logged` aux heures
   attendues ; puis passer à `0`.

Tests unitaires : `tests/Unit/Ticketmaster/` (projection, normalisation,
planification, détection des changements, rédaction, replanification, lecture
en flux d'un gzip tronqué, rapprochement des annonces d'artistes).

---

## 9. À re-vérifier

- Fréquence réelle de régénération du feed sur plusieurs jours ouvrés (le log de
  synchronisation donne les compteurs « nouveaux / modifiés / inchangés »).
- Cohérence des horaires après le 25 octobre 2026 (`app:ticketmaster:check-dst`).
- Alimentation éventuelle de `presales[]` sur la France — contrôle trimestriel (§2).
