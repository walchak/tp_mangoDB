# Fiche de révision : NoSQL & Big Data appliqués au TP

> Cette fiche reprend les notions utilisées dans le projet (MongoDB, Redis, ElasticSearch, k6/InfluxDB/Grafana, Docker),
> les relie au cours de Big Data et donne des réponses courtes aux questions qu'on peut poser à l'oral.
> Les chiffres « du projet » viennent du code (`www/`), du `docker-compose.yml` et des logs k6 (`test_cache.txt`, `test_no_cache.txt`).

---

## Sommaire

1. [Le projet en 30 secondes](#1-le-projet-en-30-secondes)
2. [Notions générales de Big Data](#2-notions-générales-de-big-data)
3. [Redis : cache](#3-redis--cache)
4. [MongoDB : base documents](#4-mongodb--base-documents)
5. [ElasticSearch : moteur de recherche](#5-elasticsearch--moteur-de-recherche)
6. [Tests de charge : k6, InfluxDB, Grafana](#6-tests-de-charge--k6-influxdb-grafana)
7. [Docker et infrastructure](#7-docker-et-infrastructure)
8. [Points faibles du code (questions pièges)](#8-points-faibles-du-code-questions-pièges)
9. [Questions d'oral : réponses rapides](#9-questions-doral--réponses-rapides)
10. [Commandes utiles](#10-commandes-utiles)
11. [Chiffres à retenir](#11-chiffres-à-retenir)

---

## 1. Le projet en 30 secondes

Application PHP 8.3 (Twig) qui gère le catalogue des manuscrits de la bibliothèque du Patrimoine de Clermont (open data, **2818 documents**, fichier JSON de **~728 Ko**).

```
Navigateur ──HTTP──> Apache/PHP (tpmongo-php:8080)
                        │
                        ├── liste / fiche ──> Redis (cache, TTL 300 s) ──miss──> MongoDB (source de vérité)
                        │
                        └── recherche ?q= ──> ElasticSearch (ids triés par pertinence) ──> MongoDB ($in)

k6 (charge) ──métriques──> InfluxDB (base temporelle) ──> Grafana (tableaux de bord)
```

| Brique | Famille | Rôle | Phrase à retenir |
|---|---|---|---|
| MongoDB | Base **documents** | Stocke | « source de vérité » |
| Redis | Base **clé-valeur en mémoire** | Accélère | « cache-aside, TTL 300 s » |
| ElasticSearch | **Moteur de recherche** (index inversé, Lucene) | Trouve | « analyse fr + fuzziness + BM25 » |
| InfluxDB | Base **séries temporelles** | Mesure | « stocke les métriques k6 » |

Utiliser plusieurs bases spécialisées dans une même application s'appelle la **persistance polyglotte** (*polyglot persistence*).

---

## 2. Notions générales de Big Data

### 2.1 Les « V » du Big Data

| V | Définition | Dans le projet |
|---|---|---|
| **Volume** | Quantité de données (To, Po) | Faible : 2818 docs, 728 Ko, tout tient en RAM |
| **Vélocité** | Vitesse de production / traitement | Faible : ~13 req/s pendant le test k6 |
| **Variété** | Données hétérogènes, structurées ou non | **Oui** : champs absents ou `null` (beaucoup d'`auteur: null`), schéma souple |
| **Véracité** | Fiabilité / qualité des données | Données open data, `edition` souvent `"S. l. ? : [S.n]."` |
| **Valeur** | Ce qu'on en tire | Recherche plein texte pertinente |

> **Question probable : « Votre projet est-il du Big Data ? »**
> Non par le volume (728 Ko), mais il utilise **l'architecture et les outils** du Big Data : bases NoSQL, cache en mémoire, moteur de recherche distribué, tests de charge et supervision. Les mêmes briques passent à l'échelle par **sharding** et **réplication**.

### 2.2 Les familles NoSQL

| Famille | Modèle | Exemples | Usage typique |
|---|---|---|---|
| Clé-valeur | `clé -> valeur` opaque | **Redis**, Memcached, DynamoDB | Cache, sessions, compteurs |
| Documents | JSON/BSON imbriqué | **MongoDB**, CouchDB | Catalogues, contenus à schéma souple |
| Colonnes larges | Familles de colonnes | Cassandra, HBase | Écritures massives, séries |
| Graphe | Nœuds + relations | Neo4j | Réseaux sociaux, recommandations |
| Recherche | Index inversé | **ElasticSearch**, Solr | Plein texte, logs |
| Séries temporelles | (temps, valeur) | **InfluxDB**, Prometheus | Métriques, IoT |

### 2.3 Théorème CAP

Dans un système **distribué**, en cas de **partition réseau (P)**, il faut choisir entre **Cohérence (C)** et **Disponibilité (A)**.

| Système | Tendance | Explication |
|---|---|---|
| MongoDB (replica set) | **CP** | Un seul primaire accepte les écritures. Sans majorité, plus de primaire : on perd la disponibilité en écriture |
| Redis (réplication asynchrone) | **AP** | Les répliques peuvent être en retard. En cas de bascule, des écritures récentes peuvent être perdues |
| ElasticSearch | **CP** côté cluster | Élection du maître par quorum ; les répliques sont mises à jour avant l'acquittement |

> Nuance à dire à l'oral : ces classements dépendent de la configuration (write concern, read preference…). CAP ne parle que du cas de partition.

### 2.4 ACID vs BASE

- **ACID** (SQL) : Atomicité, Cohérence, Isolation, Durabilité.
- **BASE** (NoSQL) : *Basically Available, Soft state, Eventually consistent* : on accepte une **cohérence à terme**.
- Dans le projet : la synchronisation MongoDB → ElasticSearch n'est **même pas « à terme »** : elle n'a lieu que quand on relance `indexation.php` (traitement **batch**).
- MongoDB est atomique **au niveau d'un document** et supporte les transactions multi-documents depuis la version 4.0 (sur replica set).

### 2.5 Passage à l'échelle

| Notion | Définition |
|---|---|
| **Scalabilité verticale** (*scale up*) | Machine plus puissante (plus de RAM/CPU). Simple mais limité |
| **Scalabilité horizontale** (*scale out*) | Plus de machines. C'est la base du Big Data |
| **Sharding / partitionnement** | Répartir les données sur plusieurs nœuds (Mongo : *shard key* ; ES : *shards* ; Redis Cluster : 16384 *hash slots*) |
| **Réplication** | Copier les données sur plusieurs nœuds pour la tolérance aux pannes et la lecture |

### 2.6 Batch vs streaming

- **Batch** : traitement par lots (ex. `indexation.php` qui réindexe tout, Hadoop MapReduce, Spark).
- **Streaming / temps réel** : traitement au fil de l'eau (Kafka, Spark Streaming, Flink).
- **Amélioration possible du projet** : les **Change Streams** de MongoDB (ou un bus Kafka) pour pousser chaque modification vers ElasticSearch en quasi temps réel (principe du **CDC**, *Change Data Capture*).
- Architectures connues : **Lambda** (batch + streaming en parallèle), **Kappa** (tout en streaming).

### 2.7 Écosystème Big Data (pour situer)

| Outil | Rôle |
|---|---|
| Hadoop HDFS | Système de fichiers distribué |
| MapReduce | Modèle de calcul distribué (map → shuffle → reduce) |
| Spark | Calcul distribué en mémoire, plus rapide que MapReduce |
| Kafka | Bus de messages distribué (streaming) |
| ELK / Elastic Stack | **E**lasticSearch + **L**ogstash + **K**ibana (+ Beats) : on utilise ES et Kibana |
| Data lake / data warehouse | Données brutes / données structurées pour l'analyse |

---

## 3. Redis : cache

### 3.1 Caractéristiques

- Base **clé-valeur en mémoire** (RAM), donc très rapide (accès en **microsecondes** côté serveur).
- **Mono-thread** pour l'exécution des commandes (boucle d'événements) : chaque commande est **atomique**. Depuis Redis 6, des threads d'I/O optionnels gèrent le réseau.
- Types de données : `string`, `hash`, `list`, `set`, `sorted set`, `stream`, `bitmap`, `HyperLogLog`, géospatial.
- Version du projet : `redis:7.0.5`, port **6379**, sans mot de passe, client PHP **Predis**.

### 3.2 Ce que le projet met en cache

| Clé | Contenu | TTL | Fichier |
|---|---|---|---|
| `manuscrits:count` | Nombre total de documents | **300 s** | `www/public/app.php` |
| `manuscrits:liste:page:N` | Les 20 documents de la page N (`serialize` PHP) | **300 s** | `www/public/app.php` |
| `manuscrit:{id}` | La fiche d'un manuscrit | **300 s** | `www/public/get.php` |

Écriture avec `SETEX clé 300 valeur` (équivalent de `SET clé valeur EX 300`).

### 3.3 Combien de temps le cache est-il conservé ? (question du prof)

| Niveau | Réponse |
|---|---|
| **Dans notre code** | **300 secondes = 5 minutes** (`setex(..., 300, ...)`), ou moins si une écriture vide le cache (`clearListCache()`) |
| **Par défaut dans Redis** | **Aucune expiration** : une clé sans TTL reste **indéfiniment**, jusqu'à un `DEL`, une **éviction** (si `maxmemory` est atteint) ou une perte des données au redémarrage |
| **Vérifier** | `TTL clé` → secondes restantes ; `-1` = pas d'expiration ; `-2` = clé inexistante |

**Comment Redis supprime les clés expirées :**
1. **Passivement** : quand un client lit une clé expirée, elle est supprimée et Redis répond « absente ».
2. **Activement** : environ 10 fois par seconde, Redis tire au hasard des clés ayant un TTL et supprime celles qui ont expiré ; il recommence si beaucoup étaient expirées.

**Pourquoi 300 s ?** C'est un compromis :
- TTL **court** → données plus fraîches, mais plus de *cache miss* (donc plus de requêtes MongoDB).
- TTL **long** → meilleur taux de succès, mais risque de données **périmées**.
- Ici, la liste est aussi **invalidée explicitement** à chaque écriture : le TTL sert surtout de **filet de sécurité**.

**Et après un redémarrage ?** Redis peut persister sur disque :
- **RDB** : instantanés périodiques (fichier `dump.rdb`), actifs par défaut.
- **AOF** (*Append Only File*) : journal de chaque écriture (`appendfsync everysec` en général), désactivé par défaut.
- Pour un cache, la persistance n'est **pas critique** : MongoDB reste la source de vérité ; au pire le cache se reconstruit (« cache froid »).

### 3.4 Combien d'utilisateurs Redis peut-il gérer ? (question du prof)

Il faut distinguer trois choses :

| Question | Réponse | Paramètre |
|---|---|---|
| Connexions **simultanées** | **10 000 clients** par défaut | `maxclients 10000` (limité aussi par le nombre de descripteurs de fichiers de l'OS, `ulimit -n`) |
| **Débit** | De l'ordre de **100 000 opérations/s** sur une instance (GET/SET simples), bien plus avec le **pipelining** | mesurable avec `redis-benchmark` |
| **Volume** | Jusqu'à **2³² clés** par instance (testé à au moins 250 millions) ; une valeur `string` fait au plus **512 Mo** | limité surtout par la **RAM** |
| Clients inactifs | Jamais déconnectés par défaut | `timeout 0` |

**Dans notre projet :**
- Chaque requête PHP crée sa **propre connexion** Predis, fermée en fin de requête. Le nombre de connexions Redis simultanées est donc ≈ le nombre de requêtes PHP simultanées, plafonné par Apache (avec mod_php/prefork sous Ubuntu, `MaxRequestWorkers` vaut typiquement **150**). On reste très loin des 10 000.
- Pendant le test k6 : **50 utilisateurs virtuels** max, **~13,4 req/s**. Redis est utilisé à moins de 0,1 % de sa capacité.
- **Le goulot d'étranglement n'est pas Redis** mais PHP/Apache (et MongoDB sans cache).

**Au-delà d'une instance :**
- **Réplication** maître → répliques (lectures réparties) + **Redis Sentinel** pour la bascule automatique.
- **Redis Cluster** : données réparties sur **16 384 hash slots** (`CRC16(clé) mod 16384`) entre plusieurs maîtres.

### 3.5 Mémoire et éviction

- `maxmemory` : **0 par défaut** = pas de limite (sur 64 bits) → Redis peut consommer toute la RAM.
- `maxmemory-policy` : **`noeviction` par défaut** = quand la mémoire est pleine, les écritures renvoient une **erreur**.
- Pour un cache, on configure plutôt :

| Politique | Effet |
|---|---|
| `allkeys-lru` | Supprime la clé utilisée il y a le plus longtemps (*Least Recently Used*) : **le choix classique pour un cache** |
| `allkeys-lfu` | Supprime la clé la moins souvent utilisée (*Least Frequently Used*) |
| `volatile-lru` / `volatile-lfu` | Pareil, mais seulement parmi les clés **avec TTL** |
| `volatile-ttl` | Supprime les clés dont le TTL est le plus court |
| `allkeys-random` / `volatile-random` | Au hasard |

- Taille de notre cache : 2818 fiches + 141 pages, quelques centaines d'octets par document → **quelques Mo** au total.

### 3.6 Stratégies de cache

| Pattern | Principe | Projet |
|---|---|---|
| **Cache-aside** (*lazy loading*) | L'application lit le cache ; si absent (*miss*), elle lit la base puis remplit le cache | **Oui** |
| Read-through | Le cache lit lui-même la base | Non |
| Write-through | Chaque écriture va dans le cache **et** la base | Non |
| Write-behind | Écriture dans le cache, base mise à jour plus tard (asynchrone) | Non |

**Invalidation** (« *There are only two hard things in computer science: cache invalidation and naming things* ») :
- par **TTL** (expiration) ;
- **explicite** (`DEL`) à chaque écriture : notre `clearListCache()` ;
- par **versionnement** de clé (`manuscrits:v42:page:3`, on incrémente la version au lieu de supprimer).

**Problèmes classiques :**
- **Cache stampede** (*thundering herd*) : à l'expiration (ou après un vidage), beaucoup de requêtes ratent le cache en même temps et frappent toutes MongoDB. Solutions : verrou (`SET clé NX EX`), TTL avec une part aléatoire (*jitter*), rafraîchissement anticipé.
- **Cache froid** : après un redémarrage, toutes les requêtes ratent. Solution : *cache warming* (préchargement).
- **Taux de succès** (*hit ratio*) = `hits / (hits + misses)`, visible avec `INFO stats` (`keyspace_hits`, `keyspace_misses`). C'est **la** métrique d'un cache.

### 3.7 Pourquoi le gain mesuré est modeste

Résultats k6 (`test_no_cache.txt` vs `test_cache.txt`) :

| Métrique | Sans cache | Avec cache |
|---|---|---|
| Moyenne | 27,25 ms | **14,84 ms** (−46 %) |
| Médiane | 10,46 ms | 10,38 ms (≈) |
| p(95) | 48,94 ms | **36,31 ms** (−26 %) |
| Max | 1,08 s | **437 ms** |
| Min | 1,70 ms | 2,82 ms |

Explications :
1. Les données sont minuscules : MongoDB les garde déjà **en RAM** (cache WiredTiger), il répond en quelques ms.
2. Redis ajoute un aller-retour réseau + `serialize`/`unserialize` + une nouvelle connexion à chaque requête.
3. **10 % d'écritures** dans le scénario → le cache est vidé une trentaine de fois → faible taux de succès.
4. **141 pages** tirées au hasard → une page est rarement servie deux fois avant le prochain vidage.
5. Le cache **réduit surtout les requêtes lentes** (pics de charge) : il **stabilise** plus qu'il n'accélère.

---

## 4. MongoDB : base documents

### 4.1 Caractéristiques

- Stocke des **documents BSON** (JSON binaire, avec des types en plus : `ObjectId`, `Date`, `Decimal128`…).
- Organisation : **base** (`tp`) → **collection** (`tp`) → **documents**.
- **Schéma souple** : deux documents d'une même collection peuvent avoir des champs différents.
- Taille max d'un document : **16 Mo** (au-delà : **GridFS**).
- Port **27017**. Moteur de stockage : **WiredTiger** ; son cache vaut par défaut le plus grand de **50 % de (RAM − 1 Go)** ou **256 Mo**.

### 4.2 L'`ObjectId`

12 octets : **4 octets d'horodatage** (secondes) + **5 octets aléatoires** + **3 octets de compteur**.
→ Trier par `_id` revient (à peu près) à trier par **date d'insertion**, d'où un ordre de pagination stable.
→ `_id` est **indexé automatiquement**.

### 4.3 Opérations utilisées

| Opération | Méthode PHP | Fichier |
|---|---|---|
| Create | `insertOne()` | `create.php` |
| Read | `find()` / `findOne()` | `app.php`, `get.php`, `edit.php` |
| Update | `updateOne(..., ['$set' => ...])` | `update.php` |
| Delete | `deleteOne()` | `delete.php` |
| Comptage | `countDocuments()` | `app.php` |
| Recherche par liste d'ids | `find(['_id' => ['$in' => [...]]])` | `app.php` (après ElasticSearch) |
| Import | `mongoimport --jsonArray` | (ligne de commande) |

### 4.4 Pagination : `skip` / `limit`

- `skip((page − 1) × 20).limit(20)`, tri `_id: 1`. 2818 docs / 20 = **141 pages**.
- **Limite Big Data** : `skip(N)` doit **parcourir N documents** → coût O(N). Sur des millions de documents, la dernière page est lente.
- **Solution** : pagination par curseur (*keyset / range pagination*) : `find({_id: {$gt: dernierIdVu}}).limit(20)` qui utilise l'index.
- `countDocuments()` parcourt les documents ; `estimatedDocumentCount()` lit les **métadonnées** de la collection et est quasi instantané (d'où l'intérêt de mettre le total en cache).

### 4.5 Index et performance

- Sans index, une requête fait un **COLLSCAN** (parcours complet) ; avec index, un **IXSCAN**.
- Vérifier avec `db.tp.find({...}).explain("executionStats")`.
- Types d'index : simple, composé, texte (`$text`, recherche basique), géospatial, TTL (suppression automatique des documents après un délai).

### 4.6 Passage à l'échelle

| Mécanisme | Détails |
|---|---|
| **Replica set** | 1 **primaire** (écritures) + des **secondaires** (copies via l'**oplog**). Si le primaire tombe, **élection** d'un nouveau primaire. Nombre impair conseillé (≥ 3) |
| **Sharding** | Données réparties par **clé de sharding** en *chunks* sur plusieurs *shards* ; un routeur **mongos** et des **config servers** |
| **Write concern** | Combien de nœuds doivent confirmer une écriture (`w: 1`, `w: "majority"`, qui est la valeur par défaut depuis MongoDB 5.0) |
| **Read preference** | Lire sur le primaire (cohérent) ou les secondaires (plus de débit, peut-être en retard) |
| **Aggregation pipeline** | `$match`, `$group`, `$sort`, `$project`… : traitements analytiques côté serveur (remplace MapReduce, déprécié) |

### 4.7 Pourquoi MongoDB plutôt que SQL ici ?

- Données issues d'un **JSON** open data : import direct, pas de schéma à définir.
- Champs **optionnels / hétérogènes** (`auteur` souvent `null`).
- Pas de jointures nécessaires (un manuscrit = un document).

---

## 5. ElasticSearch : moteur de recherche

### 5.1 Caractéristiques

- Moteur de recherche **distribué** basé sur **Apache Lucene**, API **REST/JSON**, port **9200** (HTTP) et 9300 (communication entre nœuds).
- Structure clé : l'**index inversé** : pour chaque terme, la liste des documents qui le contiennent (comme l'index à la fin d'un livre).
- **Quasi temps réel** (*near real-time*) : un document indexé devient cherchable après un **refresh**, toutes les **1 s** par défaut (`refresh_interval`). Le script appelle `refresh()` à la fin pour ne pas attendre.
- Version du projet : **8.12.2**, `discovery.type=single-node`, sécurité désactivée, heap JVM **512 Mo** (`-Xms512m -Xmx512m`).

### 5.2 Index, shards, réplicas

| Notion | Définition |
|---|---|
| Index | Équivalent d'une collection (`manuscrits`) |
| Shard primaire | Morceau de l'index (un index Lucene). **1 par défaut** depuis ES 7. Nombre **fixé à la création** |
| Réplica | Copie d'un shard primaire sur un **autre** nœud. **1 par défaut** |
| Santé du cluster | **green** (tout alloué) / **yellow** (réplicas non alloués) / **red** (shard primaire manquant) |

> **Question piège : « De quelle couleur est votre cluster ? »**
> Probablement **yellow** : un seul nœud, et ES refuse de placer une réplique sur le même nœud que son primaire. Pour être *green* : `number_of_replicas: 0` ou un deuxième nœud.

### 5.3 Mapping

| Champ | Type | Signification |
|---|---|---|
| `titre`, `auteur` | `text` + analyseur `fr` | Découpé en termes, cherchable en plein texte |
| `cote`, `langue`, `siecle` | `keyword` | Valeur exacte (filtres, tris, agrégations) |

### 5.4 L'analyseur français (`command/indexation.php`)

Appliqué **à l'indexation ET à la requête** (sinon les termes ne correspondraient pas) :

| Étape | Filtre | Exemple sur « L'Éloges de Blaise Pascal » |
|---|---|---|
| Tokenizer | `standard` | `L'Éloges` `de` `Blaise` `Pascal` |
| 1 | `elision` | `Éloges` `de` `Blaise` `Pascal` |
| 2 | `lowercase` | `éloges` `de` `blaise` `pascal` |
| 3 | `asciifolding` | `eloges` `de` `blaise` `pascal` |
| 4 | `stop` (`_french_`) | `eloges` `blaise` `pascal` |
| 5 | `stemmer` (`light_french`) | `elog` `blais` `pascal` |

- **Racinisation** (*stemming*) : réduit un mot à sa racine → « éloges » trouve « éloge ».
- Tester : `POST manuscrits/_analyze { "analyzer": "fr", "text": "..." }` dans Kibana.

### 5.5 La requête

```
bool.should = [ match(titre, fuzziness AUTO, boost 2), match(auteur, fuzziness AUTO) ]
```

| Notion | Explication |
|---|---|
| `bool` / `should` | « OU » : au moins une clause doit correspondre ; les scores **s'additionnent** |
| `must` / `filter` / `must_not` | « ET » avec score / « ET » sans score (mis en cache) / exclusion |
| `fuzziness: AUTO` | Distance d'édition de **Levenshtein** (Damerau : une inversion de 2 lettres compte pour 1) : 0 erreur pour 1-2 lettres, **1** pour 3-5 lettres, **2** au-delà (maximum 2) |
| `boost: 2` | Le titre pèse **2 fois** plus que l'auteur dans le score |
| `from` / `size` | Pagination. `from + size` limité à **10 000** par défaut (`index.max_result_window`) ; au-delà : `search_after` ou *point in time* |
| `_source: false` | ES ne renvoie que les ids ; les documents sont lus dans MongoDB (`$in`) puis **remis dans l'ordre de pertinence** |

### 5.6 Le score BM25

Algorithme de pertinence par défaut (successeur de **TF-IDF**) :
- **TF** (*term frequency*) : un terme fréquent dans le document augmente le score, mais avec **saturation** (paramètre `k1 = 1,2`).
- **IDF** (*inverse document frequency*) : un terme **rare** dans l'ensemble des documents vaut plus qu'un terme courant.
- **Normalisation par la longueur** (`b = 0,75`) : un terme dans un titre court compte plus que dans un titre long.
- Voir le détail : `"explain": true` dans la requête.

### 5.7 Indexation en masse : l'API `bulk`

- Les documents sont envoyés par **paquets de 500** (une ligne d'action + une ligne de document) au lieu de 2818 requêtes HTTP séparées.
- L'`_id` MongoDB est réutilisé comme `_id` ElasticSearch → lien entre les deux bases.

### 5.8 Mémoire JVM

- `Xms = Xmx` (pas de redimensionnement du tas), **au plus 50 % de la RAM** (le reste sert au cache disque de l'OS, essentiel pour Lucene) et sous **~31 Go** (pointeurs compressés).
- `bootstrap.memory_lock=true` : empêche le *swap*, qui détruit les performances.

---

## 6. Tests de charge : k6, InfluxDB, Grafana

### 6.1 Le scénario (`k6/load_test.js`)

- **VU** (*Virtual User*) : utilisateur simulé qui exécute la fonction `default` en boucle.
- **Stages** (montée en charge) : 5 → 10 → **50** → 10 → 5 VUs, **35 s** au total.
- Chaque itération : liste → page aléatoire (1..141) → fiche aléatoire → dans 10 % des cas ajout puis suppression ; `sleep(1)` entre les étapes (**temps de réflexion**).
- `check()` : assertions (statut 200) ; `group()` : regroupe les métriques par étape.

### 6.2 Les métriques

| Métrique | Sens |
|---|---|
| `http_req_duration` | Temps de réponse total d'une requête (envoi + attente + réception) |
| `http_req_waiting` | **TTFB** (*Time To First Byte*) : le temps de traitement serveur |
| `http_reqs` | Nombre de requêtes et **débit** (req/s) : 538 req, **13,4 req/s** |
| `iterations` | 166 itérations (≈ 4,05 s chacune, dont 4 s de `sleep`) |
| `checks` | 100 % réussis |

**Pourquoi des percentiles (p90, p95) plutôt que la moyenne ?**
La moyenne est tirée par quelques valeurs extrêmes. **p(95) = 36 ms** veut dire : 95 % des requêtes ont répondu en moins de 36 ms. Les SLA (engagements de service) s'expriment en percentiles.

**Pourquoi le débit est identique avec et sans cache ?**
Il est imposé par les `sleep(1)` du scénario (modèle *closed*) : les VUs attendent, donc le débit ne dépend presque pas du temps de réponse.

**Loi de Little** : `L = λ × W` (nombre moyen de requêtes en cours = débit × temps de réponse).
Ici : 13,4 req/s × 0,015 s ≈ **0,2 requête en cours** en moyenne → le serveur est très peu chargé.

### 6.3 Types de tests

| Test | Objectif |
|---|---|
| **Load test** | Comportement sous charge normale attendue (notre cas) |
| **Stress test** | Monter jusqu'à la rupture pour trouver la limite |
| **Spike test** | Pic brutal de trafic |
| **Soak / endurance test** | Charge modérée pendant des heures (fuites mémoire) |

> Pour répondre « combien d'utilisateurs **notre application** supporte », il faudrait un **stress test** (monter à 500, 1000 VUs) et regarder quand p(95) explose ou quand des erreurs apparaissent.

### 6.4 InfluxDB et Grafana

- **InfluxDB 1.8** : base de **séries temporelles** (TSDB) ; k6 y écrit via `K6_OUT=influxdb=http://tpmongo-influxdb:8086/k6`.
- **Grafana** : visualisation ; la source de données et le tableau de bord sont **provisionnés** automatiquement (`grafana/datasource.yaml`, `grafana/dashboard.yaml`).
- Une seule exécution de 35 s est **bruitée** : il faut répéter les tests et comparer les médianes.

---

## 7. Docker et infrastructure

| Notion | Dans le projet |
|---|---|
| **Conteneur** | 11 services dans `docker-compose.yml` |
| **Réseau** | Réseau `local` : les conteneurs se joignent par **nom de service** (DNS interne) : `tpmongo-mongodb`, `tpmongo-redis`… et **pas** `localhost` |
| **Ports** | `hôte:conteneur`, ex. `8080:80` pour l'application |
| **Volumes** | `./www` monté dans le conteneur PHP ; **aucun volume pour MongoDB** → la base est perdue si le conteneur est recréé (`docker compose down`) |
| **Limites de ressources** | ElasticSearch : 1 CPU / 2 Go max, 0,5 CPU / 1 Go réservés |
| **Configuration** | Variables d'environnement + fichier `.env` (phpdotenv), relu à chaque requête |

---

## 8. Points faibles du code (questions pièges)

Bien connaître ces points montre qu'on a compris ce qu'on a fait.

1. **La fiche détail n'est jamais invalidée.**
   `get.php` met en cache la clé `manuscrit:{id}` (**singulier**), mais `clearListCache()` supprime `manuscrits:*` (**pluriel**). Après une modification ou une suppression, la fiche reste **périmée jusqu'à 5 minutes** (un livre supprimé peut encore s'afficher).
   *Correction :* `DEL manuscrit:{id}` dans `update.php` et `delete.php`.

2. **`KEYS manuscrits:*` est bloquant.**
   `KEYS` parcourt **toutes** les clés en O(N) et bloque Redis (mono-thread) pendant ce temps. Inacceptable avec des millions de clés.
   *Correction :* `SCAN` (itératif), un `SET` qui référence les clés de pages, ou un **numéro de version** dans les clés.

3. **Une nouvelle connexion Redis à chaque appel** de `getRedisClient()` (y compris dans `clearListCache()`).
   *Correction :* une seule instance par requête, ou des connexions persistantes.

4. **Pas de repli si Redis ou ElasticSearch tombe.**
   Aucune gestion d'exception autour des appels Predis/ES : si Redis est arrêté, la page renvoie une **erreur 500** alors que MongoDB fonctionne.
   *Correction :* `try/catch` → lecture directe dans MongoDB (dégradation gracieuse).

5. **ElasticSearch n'est pas synchronisé** avec MongoDB : un livre ajouté n'est pas trouvable tant que `indexation.php` n'est pas relancé.
   *Correction :* `index()` / `delete()` dans create/update/delete, ou Change Streams.

6. **Invalidation trop large** : une écriture vide **toutes** les pages alors qu'un ajout (tri par `_id`) ne change que la dernière page et le total.

7. **Sécurité** (acceptable en TP, pas en production) : identifiants dans `.env` et `docker-compose.yml`, Redis sans mot de passe, ElasticSearch sans authentification (`xpack.security.enabled=false`), suppression par simple `GET` (`delete.php?id=`), `unserialize()` sur des données externes.

8. **Pagination `skip`** coûteuse à grande échelle (voir 4.4).

---

## 9. Questions d'oral : réponses rapides

**Redis**

| Question | Réponse courte |
|---|---|
| Combien d'utilisateurs Redis peut-il gérer ? | **10 000 connexions simultanées** par défaut (`maxclients`), environ **100 000 opérations/s** sur une instance. Dans notre TP, ≤ 50 VUs et 13 req/s : Redis n'est pas le facteur limitant, c'est Apache/PHP |
| Quel est le temps de conservation du cache ? | **300 s (5 min)** dans notre code via `SETEX`. Par défaut Redis n'expire **jamais** une clé : elle reste jusqu'à `DEL`, éviction ou redémarrage sans persistance |
| Que se passe-t-il quand la RAM est pleine ? | Par défaut (`noeviction`) les écritures échouent. Pour un cache on met `maxmemory` + `allkeys-lru` |
| Redis perd-il ses données au redémarrage ? | Pas forcément : snapshots **RDB** et/ou journal **AOF**. Pour un cache ce n'est pas grave, MongoDB est la source de vérité |
| Pourquoi Redis est-il rapide ? | Données en **RAM**, structures simples, mono-thread sans verrous, protocole léger (RESP) |
| Pourquoi Redis est mono-thread et reste rapide ? | Le goulot est le réseau/la mémoire, pas le CPU ; pas de verrous ni de changements de contexte |
| Quel pattern de cache ? | **Cache-aside** (*lazy loading*) |
| Comment savoir si le cache est efficace ? | **Taux de succès** : `INFO stats` → `keyspace_hits / (hits + misses)` |
| Comment invalider ? | TTL + `DEL` explicite à chaque écriture (`clearListCache()`) |
| Pourquoi une clé par page ? | Avec une clé unique, toutes les pages renvoyaient la page 1 : il faut une clé par **variante** de la donnée |
| Pourquoi le cache n'a pas accéléré la médiane ? | MongoDB a déjà tout en RAM (2818 docs) ; Redis ajoute réseau + sérialisation ; 10 % d'écritures vident le cache |
| Comment passer Redis à l'échelle ? | Réplication + **Sentinel** (haute disponibilité), **Redis Cluster** (16 384 hash slots) |

**MongoDB**

| Question | Réponse courte |
|---|---|
| Pourquoi MongoDB ? | Données JSON, schéma souple, champs absents, pas de jointures |
| Taille max d'un document ? | **16 Mo** (sinon GridFS) |
| Qu'y a-t-il dans un `ObjectId` ? | 12 octets : horodatage (4) + aléatoire (5) + compteur (3) |
| Pourquoi trier par `_id` ? | Ordre stable entre les pages, et `_id` est indexé |
| Problème de `skip` ? | Coût proportionnel au décalage ; utiliser une pagination par curseur (`_id > dernier`) |
| Comment scaler MongoDB ? | **Replica set** (disponibilité) + **sharding** (volume) |
| MongoDB dans CAP ? | Plutôt **CP** |
| Où MongoDB garde-t-il les données chaudes ? | Cache **WiredTiger** (≈ 50 % de la RAM − 1 Go) |

**ElasticSearch**

| Question | Réponse courte |
|---|---|
| Qu'est-ce qu'un index inversé ? | Terme → liste des documents qui le contiennent |
| Différence `text` / `keyword` ? | `text` est analysé (plein texte) ; `keyword` est une valeur exacte (filtres, tris) |
| Comment gérez-vous les fautes de frappe ? | `fuzziness: AUTO` (distance de Levenshtein, 2 modifications max) |
| Et le pluriel ? | Stemmer `light_french`, appliqué à l'indexation et à la requête |
| Comment est calculé le score ? | **BM25** (fréquence du terme, rareté, longueur du champ) + `boost` 2 sur le titre |
| Pourquoi garder MongoDB ? | ES sert à **chercher**, MongoDB reste la **source de vérité** (ES n'est pas une base transactionnelle) |
| Le document est-il cherchable tout de suite ? | Après un **refresh** (1 s par défaut) : *near real-time* |
| Combien de shards ? | 1 primaire + 1 réplica par défaut ; sur un seul nœud le cluster est **yellow** |
| Pourquoi `bulk` ? | Envoyer 500 documents par requête au lieu d'un : moins d'allers-retours réseau |
| Limite de pagination ? | `from + size ≤ 10 000` ; au-delà `search_after` |

**Tests de charge**

| Question | Réponse courte |
|---|---|
| Qu'est-ce qu'un VU ? | Un utilisateur virtuel qui rejoue le scénario en boucle |
| Pourquoi p95 et pas la moyenne ? | La moyenne masque les requêtes lentes ; le p95 reflète l'expérience de 95 % des utilisateurs |
| Pourquoi InfluxDB ? | Base de **séries temporelles**, adaptée aux métriques horodatées |
| Les résultats sont-ils fiables ? | Partiellement : un seul passage de 35 s sur un PC (WSL) ; il faut répéter |
| Combien d'utilisateurs votre application supporte-t-elle ? | Testé jusqu'à **50 VUs** sans erreur (p95 = 36 ms avec cache). La vraie limite demande un **stress test** |

---

## 10. Commandes utiles

**Redis** (`docker exec -it tpmongo-redis redis-cli`)

```bash
KEYS manuscrits:*              # lister (à éviter en prod)
SCAN 0 MATCH manuscrit* COUNT 100   # équivalent non bloquant
GET manuscrits:count           # -> "2818"
TTL manuscrits:liste:page:1    # secondes restantes (-1 : pas de TTL, -2 : absente)
DBSIZE                         # nombre de clés
INFO stats                     # keyspace_hits / keyspace_misses
INFO memory                    # used_memory_human
CONFIG GET maxclients          # 10000
CONFIG GET maxmemory*          # 0 / noeviction
MONITOR                        # voir les commandes en direct
FLUSHALL                       # tout vider
redis-benchmark -q -n 100000   # mesurer le débit
```

**MongoDB** (`docker exec -it tpmongo-mongodb mongosh -u zz3f3 -p easyma`)

```js
use tp
db.tp.countDocuments()
db.tp.estimatedDocumentCount()
db.tp.find({ langue: "latin" }).limit(5)
db.tp.find({ siecle: "13" }).explain("executionStats")
db.tp.createIndex({ langue: 1 })
db.tp.aggregate([{ $group: { _id: "$siecle", n: { $sum: 1 } } }, { $sort: { n: -1 } }])
```

**ElasticSearch** (Kibana → Dev Tools, http://localhost:5601)

```
GET _cluster/health
GET _cat/indices?v
GET manuscrits/_count
GET manuscrits/_mapping
POST manuscrits/_analyze { "analyzer": "fr", "text": "L'Éloges de Blaise Pascal" }
GET manuscrits/_search { "query": { "match": { "titre": { "query": "paskal", "fuzziness": "AUTO" } } }, "explain": true }
```

**Projet**

```bash
docker compose up -d
docker compose run --rm tpmongo-k6 run /scripts/load_test.js
docker exec -w /var/www/app tpmongo-php php command/indexation.php
docker compose stop            # et pas "down" (pas de volume MongoDB)
```

---

## 11. Chiffres à retenir

| Élément | Valeur |
|---|---|
| Documents | **2818** manuscrits, ~728 Ko |
| Pagination | **20** par page → **141** pages |
| TTL du cache | **300 s** (5 min) |
| Redis `maxclients` | **10 000** |
| Redis débit | ~**100 000** op/s |
| Redis max clés / valeur | **2³²** clés / **512 Mo** par valeur |
| Redis Cluster | **16 384** hash slots |
| Document MongoDB max | **16 Mo** |
| `ObjectId` | **12** octets |
| ES refresh | **1 s** |
| ES `max_result_window` | **10 000** |
| ES fuzziness max | **2** modifications |
| ES heap du projet | **512 Mo** |
| Bulk | paquets de **500** |
| k6 | **50** VUs max, **35 s**, **538** requêtes, **13,4** req/s |
| Gain du cache | moyenne **−46 %**, p95 **−26 %**, max **1,08 s → 437 ms** |
| Ports | Mongo **27017**, Redis **6379**, ES **9200**, Kibana **5601**, Grafana **3000**, InfluxDB **8086**, app **8080** |
