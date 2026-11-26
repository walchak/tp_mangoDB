# Fiche de révision : Big Data & NoSQL (cours + TP)
*ISIMA F3, Lionel Antraigue. Cours 1 à 5 + TP MongoDB, Redis, ElasticSearch.*

> **Légende**
> 📘 **Cours** : notion des slides, à connaître pour le partiel
> 🛠️ **TP** : ce qu'on a fait concrètement dans le projet (code dans `www/`, `docker-compose.yml`, logs k6)
> ⚠️ **Piège** : erreur fréquente ou incohérence à connaître
> ➕ **Hors cours** : notion absente des slides, utile à l'oral ou en entretien (ne pas en faire le cœur d'une copie)

---

## Sommaire

0. [Le projet en 30 secondes](#0-le-projet-en-30-secondes)
1. [Cours 1 : Introduction au Big Data](#1-cours-1--introduction-au-big-data)
2. [Cours 2 : Protection des données](#2-cours-2--protection-des-données)
3. [Cours 3 : Le NoSQL (ACID, CAP, familles)](#3-cours-3--le-nosql)
4. [MongoDB : base document (cours + TP1)](#4-mongodb--base-document-cours--tp1)
5. [Redis : clé-valeur (cours + TP2)](#5-redis--clé-valeur-cours--tp2)
6. [ElasticSearch : moteur de recherche (cours + TP3)](#6-elasticsearch--moteur-de-recherche-cours--tp3)
7. [Tests de charge : k6, InfluxDB, Grafana (TP2)](#7-tests-de-charge--k6-influxdb-grafana-tp2)
8. [Choisir un SGBD et migrer de SQL vers NoSQL](#8-choisir-un-sgbd-et-migrer-de-sql-vers-nosql)
9. [Cours 4 : L'open data](#9-cours-4--lopen-data)
10. [Cours 5 : Docker](#10-cours-5--docker)
11. [Problèmes rencontrés et points faibles du code](#11-problèmes-rencontrés-et-points-faibles-du-code)
12. [Questions probables : réponses courtes](#12-questions-probables--réponses-courtes)
13. [Commandes utiles](#13-commandes-utiles)
14. [Chiffres à retenir](#14-chiffres-à-retenir)

---

## 0. Le projet en 30 secondes

🛠️ Application **PHP 8.3** (Twig + phpdotenv) qui gère le **catalogue des manuscrits de la bibliothèque du Patrimoine de Clermont Auvergne Métropole** (open data, **2818 documents**, JSON d'environ **728 Ko**).

```
Navigateur ──HTTP──> Apache/PHP (tpmongo-php, port 8080)
                        │
                        ├── liste / fiche ──> Redis (cache, TTL 300 s) ──miss──> MongoDB (source de vérité)
                        │
                        └── recherche ?q= ──> ElasticSearch (ids triés par pertinence) ──> MongoDB ($in)

k6 (charge) ──métriques──> InfluxDB (séries temporelles) ──> Grafana (tableaux de bord)
```

| Brique | Famille NoSQL (cours 3) | Rôle | À retenir |
|---|---|---|---|
| **MongoDB** | Document | **Stocke** | Source de vérité |
| **Redis** | Clé-valeur en RAM | **Accélère** | Cache-aside, TTL 300 s |
| **ElasticSearch** | Moteur de recherche (index inversé, Lucene) | **Trouve** | Analyseur fr + fuzziness + BM25 + boost |
| **InfluxDB** | Séries temporelles | **Historise** | Stocke les métriques k6 |
| **k6 / Grafana** | n/a | **Mesure / visualise** | Test de charge |
| **Docker** | n/a | **Orchestre** | 11 services dans un `docker-compose.yml` |

📘 C'est l'idée centrale du cours 1 : **un SGBD dédié à chaque problématique**.
➕ Utiliser plusieurs bases spécialisées dans une même application s'appelle la **persistance polyglotte** (*polyglot persistence*).

---

## 1. Cours 1 : Introduction au Big Data

### 1.1 Les 3V (Gartner), puis les 5V

| V | 📘 Définition | 🛠️ Dans le projet |
|---|---|---|
| **Volume** | Quantité énorme de données. Le Big Data commence vers **10 To** (le LHC produit environ 15 Po par an) | Faible : 2818 docs, 728 Ko, tout tient en RAM |
| **Variété** | Sources multiples (SQL, NoSQL, fichiers plats, audio/vidéo), plus ou moins structurées | **Oui** : champs absents ou `null` (beaucoup d'`auteur: null`), schéma souple |
| **Vélocité** | Vitesse selon le besoin : lecture rapide, écriture rapide, sources multiples, accès concurrents | Faible : environ 13 req/s pendant le test k6 |
| **Véracité** (5V) | Fiabilité, menacée par les formulaires déclaratifs, les bots, les faux profils et la multiplicité des formats | Données open data incomplètes (`edition` souvent `"S. l. ? : [S.n]."`) |
| **Valeur** (5V) | Face à l'**infobésité**, se concentrer sur les données **actionnables** | Une recherche plein texte pertinente |

> **Question probable : « Votre projet est-il du Big Data ? »**
> Non par le volume (728 Ko), mais il utilise **l'architecture et les outils** du Big Data : bases NoSQL spécialisées, cache en mémoire, moteur de recherche distribué, tests de charge et supervision. Les mêmes briques passent à l'échelle par **sharding** et **réplication**.

### 1.2 Contexte : deux révolutions
📘
1. **L'explosion du Web** (Google, Amazon, Facebook, Twitter). Le modèle **client-serveur avec une base relationnelle centrale et monolithique** (type ERP) ne tient plus. Le trafic internet passe de 100 Go par jour (1992) à 50 000 Go par seconde (2018).
2. **L'IoT** : plusieurs objets connectés par personne et des échanges **M2M** (machine à machine). Problèmes associés : adressage IP (IPv6), volume, valeur et **sensibilité** des données (une montre qui analyse le sommeil).

- **Chaîne de traitement** : acquérir et collecter → stocker et gérer → analyser et croiser.
- **Objectifs** : découvrir des connaissances, prendre des décisions, offrir de nouveaux services, créer de nouveaux usages.
- **Exemples à citer** :
  - **Santé** : prévention, médecine personnalisée, détection de chutes ;
  - **Transport** : trafic en temps réel, parkings, logistique ;
  - **Commerce** : analyse prédictive, ciblage ;
  - **Vélo** : capteurs de vitesse, cadence et puissance, coaching ;
  - **F1** : des centaines de capteurs pour la fiabilité, les réglages, les simulations et les constantes vitales du pilote ;
  - **Défense** : le projet SCAF.

### 1.3 Les solutions : la scalabilité

| | **Verticale** (*scale up*) | **Horizontale** (*scale out*) |
|---|---|---|
| Principe | Un serveur plus puissant | Plus de serveurs |
| ✅ Avantages | Rien à changer dans l'infrastructure ni dans l'application, bon pour tester | Coût **presque linéaire**, ajustable dynamiquement, tolérance aux pannes, mises à jour sans coupure (respect des **SLA**) |
| ❌ Inconvénients | Coût **non proportionnel**, limites physiques (RAM, alimentation), **coupure** pendant le changement | **Réplication** des données, pas toujours transparent (données stockées localement sur un serveur) |

- 📘 **Traitement distribué** : on découpe les données entre les serveurs au lieu de les dupliquer partout. Les calculs sont répartis sans déplacer les données, mais une panne n'est plus transparente.
- 🛠️ **Dans le projet** : un seul nœud par service, donc pas de scalabilité horizontale réelle. Elle passerait par le **replica set** et le **sharding** de MongoDB, **Redis Cluster** et un cluster ElasticSearch (voir sections 4 à 6).

### 1.4 Pourquoi le NoSQL est apparu
📘
- Les **transactions** consomment énormément de ressources, et la **généricité de SQL** coûte cher quand une même requête tourne des milliards de fois.
- Sur un site web, **on écrit une fois et on lit des millions de fois** : la cohérence compte moins que dans un ERP.
- **Relâcher la cohérence** permet le **partitionnement**.
- D'où : **un SGBD dédié à chaque problématique**, conçu dès le départ pour être scalable et souple. *NoSQL* = *Not Only SQL*.

🛠️ Le cache Redis du TP repose exactement sur cet argument : beaucoup de lectures pour peu d'écritures.

### 1.5 La gravité des données (Dave McCrory, 2010)
📘 Plus les données sont volumineuses, plus il est long et risqué de les déplacer. Elles **attirent les applications vers elles**, ce qui pousse les entreprises vers le cloud.
**Formule simplifiée** : gravité = (masse × criticité × vitesse de croissance) / distance (latence).

---

## 2. Cours 2 : Protection des données

### 2.1 L'anonymisation
📘 L'objectif est de rendre **impossible** l'identification d'une personne. Les techniques de remplacement :
- par une donnée **de même type**, sans format ;
- par une donnée **de même format**, aléatoire (date, numéro de carte) ;
- par une valeur **du même dictionnaire** (un prénom par un autre) ;
- avec **conservation partielle** (garder le code banque d'un numéro de compte, par exemple).

**L'exemple du cours (auteur, adresse, livre)** : chaque solution casse un cas d'usage.
- Supprimer l'adresse : on ne peut plus calculer de revenus par pays.
- Supprimer seulement le nom : on retrouve l'auteur grâce à ses livres.
- Créer des auteurs fictifs agrégés par pays : on ne peut plus calculer un revenu moyen par auteur.

⚠️ **Il n'y a pas de solution magique.** Le **croisement de sources** permet de ré-identifier quelqu'un (commune + doctorat + employeur…). Selon Arvind Narayanan, l'anonymat est devenu **algorithmiquement impossible**.

- **La démarche** : définir ce qu'on ne transmet pas, **anticiper les cas d'usage**, livrer seulement le nécessaire, à la bonne granularité.
- **Deux forces s'opposent** : anonymiser fait perdre de l'information (moins de jointures, moins d'*insights*), mais le RGPD protège les citoyens.

### 2.2 Le RGPD (Règlement général sur la protection des données)
📘
- Il **responsabilise** les organismes publics et privés.
- **Donnée personnelle** : toute information sur une personne **identifiée ou identifiable**,
  - **directement** (nom, prénom),
  - ou **indirectement** (n° client, téléphone, biométrie, voix, image…),
  - à partir d'**une seule donnée** (n° de sécurité sociale, ADN) ou d'un **croisement** de données.
- ⚠️ Une base marketing **sans les noms** reste une donnée personnelle si l'on peut remonter à une personne.
- **Finalité** : on ne collecte pas une donnée « au cas où ». Chaque traitement doit avoir un **but légal et légitime**.
- **Traitement** = toute opération sur ces données : collecte, conservation, consultation, transmission, rapprochement… **Les fichiers papier sont aussi concernés**.
- ⚠️ Un fichier ne contenant que des **coordonnées d'entreprises** (standard, e-mail générique) **n'est pas** un traitement de données personnelles.

### 2.3 Points d'attention (enjeux de société)
📘
- La **géolocalisation** révèle le couple, le lieu de travail et même la **santé** (des visites répétées en cancérologie). Croisée avec l'activité physique ou les achats, elle permet d'estimer l'état de santé, donc de cibler la publicité.
- **L'import de contacts** et les photos avec des tiers reviennent à partager les données **d'autres personnes**.
- Les enjeux politiques : surveillance de masse ou ciblée, **Cambridge Analytica**, **bulles cognitives**, notation sociale.

### 2.4 🛠️ Lien avec le TP
- Les données (manuscrits) sont de l'**open data** publié sous licence ouverte. Elles contiennent des **noms d'auteurs**, donc des personnes identifiées, mais il s'agit surtout d'auteurs historiques et d'informations déjà publiées.
- ➕ Le RGPD protège les personnes **vivantes**. À l'oral, dire qu'on vérifierait la **licence** du jeu de données et qu'on ne l'**enrichirait** pas avec des données personnelles.
- La sécurité du projet n'est pas celle d'une production : Redis sans mot de passe, ElasticSearch sans authentification, identifiants dans le `.env` (voir section 11).

---

## 3. Cours 3 : Le NoSQL

### 3.1 ACID : les garanties du relationnel
📘 **A**tomicité (transaction tout ou rien) · **C**ohérence (intégrité) · **I**solation (des appels concurrents) · **D**urabilité (sauvegarde).

➕ **BASE**, la philosophie de beaucoup de bases NoSQL : *Basically Available, Soft state, Eventually consistent*. On accepte une **cohérence à terme** (*eventual consistency*).
🛠️ La synchronisation MongoDB → ElasticSearch n'est même pas « à terme » : elle n'a lieu que quand on relance `indexation.php` (traitement **batch**).

### 3.2 Le théorème CAP ⭐
📘 Trois propriétés :
- **C, cohérence** : tous les nœuds ont la même donnée au même instant.
- **A, disponibilité** : tout client peut toujours lire et écrire (continuité de service).
- **P, tolérance au partitionnement** : le système fonctionne malgré les coupures réseau ou des nœuds éteints.

👉 Une base ne peut satisfaire que **2 des 3**.

| Couple | Comportement |
|---|---|
| **CA** | Cluster sur un seul site : si une partition survient, le système **s'arrête** (pas de gestion du *split-brain*) |
| **CP** | Certaines données peuvent être **inaccessibles**, mais elles restent **cohérentes** |
| **AP** | **Toujours disponible**, mais certaines données renvoyées peuvent être **inexactes** |

**La correction de l'exercice du cours** (triangle OpenClassrooms), **à donner au partiel** :

| CA | CP | AP |
|---|---|---|
| Oracle, MySQL, SQL Server, PostgreSQL, DB2 | HBase, BigTable, **MongoDB\***, CosmosDB\* | **Elasticsearch**, Spark, **Neo4j**, OrientDB, FlockDB, SimpleDB\*, Memcached\*, **Redis**, DynamoDB\*, CouchBase\*, **Cassandra\*** |

\* = le mode de cohérence est réglable (cohérence ↔ disponibilité).

⚠️ **Piège 1** : la slide 9 indique « Moteur de type CP : Cassandra, CouchDB, DynamoDB », mais elle est placée sous la ligne **AP**, et le triangle de correction met ces trois bases en **AP**. Retiens **AP**.
⚠️ **Piège 2** : on trouve d'autres classements ailleurs (Redis ou ElasticSearch parfois présentés comme **CP** en cluster). À l'oral, ajoute la nuance : **ça dépend de la configuration** (réplication, *write concern*, *read preference*), et CAP ne parle que du **cas de partition**.

🛠️ **Le CAP appliqué aux outils du TP** (explication technique, ➕) :
| Outil | Cours | Pourquoi |
|---|---|---|
| MongoDB (replica set) | **CP** | Un seul **primaire** accepte les écritures. Sans majorité, il n'y a plus de primaire, donc plus d'écriture : on perd la disponibilité |
| Redis (réplication asynchrone) | **AP** | Les répliques peuvent être en retard. En cas de bascule, des écritures récentes peuvent être perdues |
| ElasticSearch | **AP** (cours) | En cluster, il élit un maître par quorum, d'où les classements « CP » qu'on trouve parfois |

⚠️ Dans le TP, chaque outil tourne sur **un seul nœud** : il n'y a pas de partition possible. CAP ne devient concret qu'en **cluster**.

### 3.3 Les familles de SGBD

| Famille | 📘 Principe | Exemples | Cas d'usage | 🛠️ TP |
|---|---|---|---|---|
| **Relationnel** | Tables, lignes avec clé, colonnes = attributs, ACID | MySQL, PostgreSQL, Oracle | ERP, finance, transactions | n/a |
| **Document** | Documents JSON/**BSON**, schéma libre | **MongoDB**, CouchDB | Web, mobile, IoT, données variées | **TP1** |
| **Clé-valeur** | Clé → valeur. Très simple, très performant | **Redis**, Memcached, DynamoDB | Cache, sessions, compteurs | **TP2** |
| **Séries temporelles** | Chaque entrée est horodatée | **InfluxDB**, TimescaleDB | Capteurs, métriques, IoT | **k6 → InfluxDB** |
| **Graphe** | Nœuds (entités) + arcs (relations), langage **Cypher** | Neo4j | Réseaux sociaux, recommandation, fraude, logistique | (TP Neo4j) |
| **Moteur de recherche** | Index inversé, plein texte, *stemming*, pertinence, distribué | **ElasticSearch**, Solr | Recherche, logs | **TP3** |
| **Colonnes** (*wide column*) | Milliards de colonnes dynamiques, « clé-valeur à deux dimensions » | Cassandra, HBase | Historiques massifs, haute disponibilité | n/a |
| **Objet** | Héritage, relations, opérations atomiques sur un objet et ses relations | n/a | Langages orientés objet | n/a |
| **RDF** (*triple store*) | Triplets sujet-prédicat-objet, **SPARQL**, web sémantique | n/a | Ontologies | n/a |
| **Index inversé (NF2)** | Attributs **multivalués** (non 1FN) | n/a | n/a | n/a |
| **XML natif** | XPath, XQuery, XSLT | n/a | Données hiérarchiques | n/a |
| **Spatial** | Points, polygones, index spatiaux | n/a | Cartographie, distances | n/a |
| **Événements** | On stocke **tous les changements** et on rejoue pour obtenir l'état actuel (*event sourcing*), sans modification ni suppression, avec des instantanés | Kafka | Historique, systèmes distribués | n/a |
| **Contenu** | Texte, images, vidéos + métadonnées, versions, contrôle d'accès | n/a | Gestion documentaire | n/a |
| **Hiérarchique / réseau** | Accès via des enregistrements liés (années 1960) | n/a | Historique | n/a |

⚠️ À ne pas confondre : un **SGBD orienté colonnes** (*wide column*, NoSQL) n'est pas la même chose que le **stockage en colonnes** interne d'un SGBDR, qui est une optimisation pour l'**OLAP**.
📘 Les classements de popularité viennent de **db-engines.com**.

### 3.4 Batch, streaming, écosystème (➕ hors cours)
- **Batch** : traitement par lots (`indexation.php`, Hadoop MapReduce, Spark). **Streaming** : au fil de l'eau (Kafka, Flink, Spark Streaming).
- Architectures **Lambda** (batch + streaming en parallèle) et **Kappa** (tout en streaming).
- **Écosystème** :
  - **HDFS** : fichiers distribués ;
  - **MapReduce** : map → shuffle → reduce ;
  - **Spark** : calcul en mémoire ;
  - **Kafka** : bus de messages ;
  - **ELK** : ElasticSearch + Logstash + Kibana ;
  - **data lake** (données brutes) et **data warehouse** (données structurées pour l'analyse).

---

## 4. MongoDB : base document (cours + TP1)

### 4.1 📘 Ce que dit le cours
- Écrit en **C++**. Données stockées en **BSON** (JSON binaire avec des types en plus : `ObjectId`, `Date`, `Decimal128`…), réponses en JSON.
- **Base** = conteneur de **collections**. **Collection** = l'équivalent d'une table, **sans schéma fixe**.
- **Scalabilité horizontale** par **sharding** (répartition sur plusieurs serveurs) et **réplication**.
- **Langage de requête riche** + **index**. Idéal pour les applications web, mobiles et IoT, avec des données variées ou évolutives.
- Il propose aussi la **recherche vectorielle (sémantique)**, utilisée dans les **RAG**.

**Deux façons de modéliser les relations** ⭐
| | Principe | Quand l'utiliser |
|---|---|---|
| **Embedding** | Données **imbriquées** dans le même document | Données toujours lues ensemble, relation 1-1 ou 1-peu |
| **Référence** | On stocke un **identifiant** vers un autre document (comme une clé étrangère) | Données partagées, volumineuses ou modifiées indépendamment |

Exemples du cours : `db.books.findOne({ "_id": ObjectId("...") })`, `db.myCollection.deleteOne({ "name": "toto" })`.

### 4.2 🛠️ Dans le TP
**Document type**
```json
{ "_id": ObjectId("6b05e4aa9662b71db0e05621"), "titre": "Evangelia secundum Marcum et secundum Lucam",
  "auteur": null, "edition": "S. l. ? : [S.n].", "langue": "latin", "cote": "MS 18", "siecle": "13", "objectid": 17 }
```
👉 Chaque manuscrit est **autonome**, l'auteur est stocké sous forme de texte dans le document : c'est proche de l'**embedding**. Si les auteurs avaient leurs propres fiches, on utiliserait une **référence** (`auteur_id`).

**Import** avec le `mongoimport` **déjà présent dans le conteneur**, sans rien installer :
```bash
docker cp catalogue-manuscrits-bibliotheque-patrimoine.json tpmongo-mongodb:/tmp/data.json
docker exec tpmongo-mongodb mongoimport -u zz3f3 -p easyma --authenticationDatabase admin \
       --db tp --collection tp --file /tmp/data.json --jsonArray
```

**Connexion** : le `.env` contient `MDB_USER`, `MDB_PASS`, `MDB_SRV=tpmongo-mongodb`, `MDB_PORT` et `MDB_DB`, et la fonction `getMongoDbManager()` est dans `init.php`.

**CRUD**
| Opération | Fichier(s) | Méthode |
|---|---|---|
| Create | `create.php` | `insertOne()` puis redirection vers `get.php?id=<insertedId>` |
| Read | `get.php`, `app.php` | `findOne(['_id' => new ObjectId($id)])` / `find()` |
| Update | `edit.php` (formulaire pré-rempli) → `update.php` | `updateOne([...], ['$set' => [...]])` |
| Delete | `delete.php` | `deleteOne()` |
| Comptage | `app.php` | `countDocuments()` |
| Recherche par ids | `app.php` (après ElasticSearch) | `find(['_id' => ['$in' => [...]]])` |

- `new ObjectId($id)` : l'id arrive dans l'URL sous forme de **chaîne** et doit être reconverti.
- `$set` ne modifie que les champs donnés. `replaceOne` remplacerait **tout** le document.
- `trim()` + `?? ''` : nettoie les saisies, empêche un titre composé seulement d'espaces de passer la validation, et évite `trim(null)` (déprécié en PHP 8.1).
- `init.php` est inclus par toutes les pages. Il active l'affichage des erreurs, charge l'autoload Composer et le `.env`, puis déclare `getTwig()`, `getMongoDbManager()`, `getRedisClient()`, `clearListCache()` et `getElasticSearchClient()`.

**Pagination** (20 par page, 141 pages)
```php
$page = max(1, (int) ($_GET['page'] ?? 1));
$list = $collection->find([], ['sort' => ['_id' => 1], 'skip' => ($page - 1) * 20, 'limit' => 20]);
```
- Le **tri est obligatoire** pour avoir un ordre stable entre les pages. `_id` est **indexé automatiquement**.
- ⚠️ **Limite Big Data** : `skip(N)` parcourt N documents, donc un coût en O(N). La solution est la **pagination par curseur** : `find({_id: {$gt: dernierIdVu}}).limit(20)`.
- `countDocuments()` parcourt les documents, alors que `estimatedDocumentCount()` lit les métadonnées (quasi instantané). D'où l'intérêt de mettre le total en cache.

### 4.3 ➕ Pour aller plus loin (oral, entretien)
- **`ObjectId`** : 12 octets = horodatage (4) + aléatoire (5) + compteur (3). Trier par `_id` revient donc à peu près à trier par **date d'insertion**.
- **Taille maximale d'un document** : **16 Mo** (au-delà : **GridFS**).
- **WiredTiger** : le moteur de stockage. Son cache vaut par défaut le plus grand de 50 % de (RAM − 1 Go) ou 256 Mo. Nos 2818 documents tiennent entièrement en RAM.
- **Index** : sans index, la requête fait un **COLLSCAN** (parcours complet), avec index un **IXSCAN**. On le vérifie avec `explain("executionStats")`. Il existe des index simples, composés, texte, géospatiaux et **TTL**.
- **Atomicité** au niveau d'**un document**. Les transactions multi-documents existent depuis la version 4.0, sur replica set.
- **Passage à l'échelle** :
  - **replica set** : 1 primaire et des secondaires, copies via l'**oplog**, élection en cas de panne, nombre impair (≥ 3) ;
  - **sharding** : *shard key*, *chunks*, routeur **mongos**, *config servers* ;
  - **write concern** (`majority` par défaut depuis la 5.0) et **read preference**.
- **Aggregation pipeline** : `$match`, `$group`, `$sort`, `$project`.

---

## 5. Redis : clé-valeur (cours + TP2)

### 5.1 📘 Ce que dit le cours
- Les bases **clé-valeur** sont très simples, très performantes et parfaites pour les applications exigeantes, comme un **cache applicatif**. Elles sont en théorie peu adaptées aux usages complexes.
- **Redis** : écrit en **C**, **données en RAM** (éphémères, taille limitée), clés de type **string**, clients dans beaucoup de langages. Il gère une mémoire virtuelle, mais il vaut mieux avoir assez de RAM physique.
- Trois commandes de base : `SET`, `GET`, `DEL`.
- **Types** : strings (**512 Mo** max), hashes, lists, sets, **sorted sets**, bitmaps, **HyperLogLog** (estimation du nombre de valeurs distinctes avec peu de mémoire), **streams**, index **géospatiaux**, extensions (JSON, séries temporelles), **vector sets** (IA).
- ⚠️ **Licence** : depuis mars 2024, Redis abandonne la licence **BSD** à partir de la version **7.4**. D'où les **forks** :
  - **Valkey** : Linux Foundation, AWS, Google Cloud, Oracle. Recherche vectorielle via `valkey-search` ;
  - **Redict** : licence LGPL 3.0, axé sur la fiabilité, basé sur Redis 7.2.3 ;
  - **KeyDB** : multithread ;
  - **Dragonfly** : optimisé pour le cloud.

### 5.2 🛠️ Dans le TP
- Librairie **Predis** (`composer require predis/predis`). Image `redis:7.0.5`, port **6379**, sans mot de passe.
- `.env` : `REDIS_HOST=tpmongo-redis`, `REDIS_PORT=6379`, `REDIS_ENABLE=true`.
- `getRedisClient()` renvoie `null` si `REDIS_ENABLE` ne vaut pas `true`. C'est un **interrupteur** pour comparer avec et sans cache sans toucher au code (le `.env` est relu à chaque requête).

**Le pattern cache-aside** (*lazy loading*)
```php
$cached = $redis ? $redis->get($cacheKey) : null;
if ($cached !== null) {
    $list = unserialize($cached);                               // HIT : pas de MongoDB
} else {
    $list = $collection->find(...)->toArray();                  // MISS : MongoDB
    if ($redis) $redis->setex($cacheKey, 300, serialize($list)); // TTL 300 s
}
```

| Clé | Contenu | TTL | Fichier |
|---|---|---|---|
| `manuscrits:count` | Nombre total de documents | 300 s | `app.php` |
| `manuscrits:liste:page:N` | Les 20 documents de la page N | 300 s | `app.php` |
| `manuscrit:{id}` | La fiche d'un manuscrit | 300 s | `get.php` |

- **Une clé par variante** : avec une clé unique, toutes les pages renvoyaient la page 1.
- **Le préfixe** (`manuscrits:`) sert d'espace de noms (l'énoncé parlait de « branche », `tp02:var1`) et permet de vider un groupe de clés.
- **`serialize` / `unserialize`** : Redis ne stocke que des **chaînes**. `serialize` transforme le tableau PHP en texte en **gardant les types** (`ObjectId` compris), et `unserialize` le reconstruit. `json_encode` serait lisible par d'autres langages, mais il perd les types PHP. ⚠️ Il ne faut jamais faire `unserialize` sur une donnée venant d'un utilisateur.

**Invalidation**
```php
function clearListCache(): void {
    $redis = getRedisClient();
    if ($redis && ($keys = $redis->keys('manuscrits:*'))) { $redis->del($keys); }
}
```
- Elle est appelée **après chaque écriture** (create, update, delete) et **avant la redirection**. On ne vide pas le cache à chaque lecture.
- **Pourquoi ?** Le cache est une **copie**. Après une écriture, il serait **périmé** jusqu'à 5 minutes.
- **Pourquoi tout vider ?** Une suppression **décale toutes les pages suivantes** et un ajout change le total. Tout vider est simple et **toujours correct**.
- **Pourquoi ne pas mettre à jour sur place (*write-through*) ?** Pour une liste paginée, mettre à jour toutes les pages décalées coûte plus cher que de les supprimer. Et c'est **risqué** : deux écritures simultanées ou un échec partiel rendent le cache incohérent. Supprimer une clé est sûr : au pire, on relit MongoDB.
- Une mise à jour sur place est en revanche simple pour une **clé par livre** (`SETEX manuscrit:{id}`) ou un **compteur** (`INCR` / `DECR`, atomiques).

### 5.3 ➕ Questions avancées du prof : durée de conservation, capacité, mémoire

**Combien de temps le cache est-il conservé ?**
| Niveau | Réponse |
|---|---|
| Notre code | **300 s = 5 min** (`SETEX`), ou moins si une écriture vide le cache |
| Par défaut dans Redis | **Aucune expiration** : la clé reste jusqu'à un `DEL`, une **éviction** ou un redémarrage sans persistance |
| Vérifier | `TTL clé` → secondes restantes, `-1` = pas de TTL, `-2` = clé absente |

- Redis supprime les clés expirées de deux façons : **passivement** (à la lecture) et **activement** (environ 10 fois par seconde, il échantillonne des clés ayant un TTL).
- **Le TTL est un compromis** : court = données fraîches mais plus de *miss*, long = meilleur taux de succès mais risque de données périmées. Ici, l'invalidation explicite fait l'essentiel et le TTL sert de **filet de sécurité**.
- **Persistance** : **RDB** (instantanés, actifs par défaut) et **AOF** (journal des écritures, désactivé par défaut). Pour un cache, ce n'est pas critique : MongoDB est la source de vérité, et au pire on repart d'un « cache froid ».

**Combien d'utilisateurs Redis peut-il gérer ?**
| Question | Réponse |
|---|---|
| Connexions simultanées | **10 000** par défaut (`maxclients`), limitées aussi par `ulimit -n` |
| Débit | De l'ordre de **100 000 opérations/s** par instance, davantage avec le *pipelining* (`redis-benchmark`) |
| Volume | Jusqu'à **2³²** clés, limité surtout par la RAM |

🛠️ Dans le TP, chaque requête PHP ouvre sa connexion Predis, et Apache plafonne le nombre de requêtes simultanées (`MaxRequestWorkers`, typiquement 150). Le test monte à 50 VUs et environ 13 req/s. **Le goulot n'est pas Redis**, mais PHP/Apache (et MongoDB sans cache).

**Mémoire et éviction**
- `maxmemory` vaut **0 par défaut** (pas de limite), et `maxmemory-policy` vaut **`noeviction`** : quand la mémoire est pleine, les écritures renvoient une **erreur**.
- Pour un cache, on configure `maxmemory` + **`allkeys-lru`** (supprime la clé utilisée il y a le plus longtemps). Il existe aussi `allkeys-lfu`, `volatile-lru`, `volatile-ttl` et `*-random`.
- Taille de notre cache : quelques Mo.

**Stratégies et problèmes de cache**
| Pattern | Principe | Projet |
|---|---|---|
| **Cache-aside** | L'application lit le cache et, en cas de *miss*, lit la base puis remplit le cache | ✅ |
| Read-through | Le cache lit lui-même la base | ❌ |
| Write-through | Chaque écriture va dans le cache **et** dans la base | ❌ |
| Write-behind | Écriture dans le cache, base mise à jour plus tard | ❌ |

- **Invalidation** : par TTL, de façon explicite (`DEL`) ou par **versionnement** des clés (`manuscrits:v42:page:3`).
- **Cache stampede** : après un vidage, beaucoup de requêtes ratent en même temps et frappent MongoDB. Les solutions : un verrou (`SET clé NX EX`), un TTL avec une part aléatoire (*jitter*), un rafraîchissement anticipé.
- **Taux de succès** = hits / (hits + misses), visible avec `INFO stats`. C'est **la** métrique d'un cache.
- **Mono-thread** : chaque commande est atomique, sans verrous. Il reste rapide parce que le goulot est le réseau et la mémoire, pas le CPU.
- **Passage à l'échelle** : réplication + **Sentinel** (bascule automatique), **Redis Cluster** (**16 384 hash slots**).

---

## 6. ElasticSearch : moteur de recherche (cours + TP3)

### 6.1 📘 Ce que dit le cours
- **Moteurs de recherche** : optimisés pour le contenu. Recherche multicritère, **plein texte**, **stemming** (racine des mots), classement et regroupement des résultats, recherche distribuée.
- **ElasticSearch** : basé sur **Lucene**, écrit en **Java**, **distribué**, avec une **API REST**, au cœur de la suite Elastic. Ses points forts sont les performances du plein texte et le **classement par pertinence**.
- **Phase d'indexation** : filtrage des caractères (`html_strip`) → **découpage en termes** (tokenizer) → **filtrage des termes** (stopwords, lowercase…). C'est le développeur qui choisit les filtres.
- ⚠️ **Les analyseurs s'appliquent aussi à la requête de l'utilisateur.**
- **Pertinence** : le **poids des champs** (boost : titre > sous-titre > texte), les **analyseurs** (le stemmer a beaucoup d'impact) et les **synonymes**.

### 6.2 🛠️ Dans le TP
- Image `elasticsearch:8.12.2`, `discovery.type=single-node`, sécurité désactivée, tas JVM de **512 Mo**.
- `composer require elasticsearch/elasticsearch:^8.0`, puis `ELASTIC_HOST="http://tpmongo-elasticsearch:9200"`.
- `getElasticSearchClient()` construit le client avec `ClientBuilder::create()->setHosts([...])->build()`.

**Indexation** (`command/indexation.php`)
1. Il supprime puis recrée l'index `manuscrits` avec un **analyseur français personnalisé `fr`**.
2. Il lit MongoDB et envoie les documents par paquets de **500** avec l'**API bulk** (6 requêtes au lieu de 2818).
3. Il réutilise l'**`_id` MongoDB comme id ElasticSearch**, ce qui relie les deux bases.
4. Il appelle `refresh()` pour que les documents soient cherchables tout de suite.

**Mapping**
| Champ | Type | Sens |
|---|---|---|
| `titre`, `auteur` | `text` + analyseur `fr` | Découpé en termes, cherchable en plein texte |
| `cote`, `langue`, `siecle` | `keyword` | Valeur exacte (filtres, tris, agrégations) |

**La chaîne de l'analyseur `fr`**, à comparer avec l'exemple du cours :
| Étape | Filtre | « L'Éloges de Blaise Pascal » |
|---|---|---|
| Tokenizer | `standard` | `L'Éloges` `de` `Blaise` `Pascal` |
| 1 | `elision` | `Éloges` `de` `Blaise` `Pascal` |
| 2 | `lowercase` | `éloges` `de` `blaise` `pascal` |
| 3 | `asciifolding` | `eloges` `de` `blaise` `pascal` |
| 4 | `stop` (`_french_`) | `eloges` `blaise` `pascal` |
| 5 | `stemmer` (`light_french`) | `elog` `blais` `pascal` |

**La requête**
```php
'query' => ['bool' => ['should' => [
    ['match' => ['titre'  => ['query' => $q, 'fuzziness' => 'AUTO', 'boost' => 2]]],
    ['match' => ['auteur' => ['query' => $q, 'fuzziness' => 'AUTO']]],
]]]
```
| Exigence du TP | Solution |
|---|---|
| Rechercher sur le titre **et** l'auteur | `bool` / `should` (un « OU » où les scores **s'additionnent**) |
| Tolérer les fautes de frappe | `fuzziness: AUTO` = **distance de Levenshtein** : 0 erreur pour 1-2 lettres, 1 pour 3-5, 2 pour 6 et plus (maximum 2). Une inversion de deux lettres compte pour 1 |
| Singulier depuis le pluriel | **Stemmer**, appliqué à l'indexation **et** à la requête |
| `boost` (le prof insiste) | Le titre pèse **×2** dans le score |

- **Le lien avec MongoDB** : ElasticSearch renvoie **seulement les ids** de la page (`_source: false`, `from` / `size`). On charge ensuite les documents dans MongoDB avec `$in`, puis on les **remet dans l'ordre de pertinence**. 👉 **MongoDB reste la source de vérité**, et ElasticSearch n'est pas une base transactionnelle.
- **Démonstrations** :
  - `Missale` (titre), `Lafuma` (auteur) ;
  - `paskal` → « pascal », 30 résultats, 1 substitution ;
  - `éloges` → « Éloge de Blaise Pascal » ;
  - « Chez les anciens du Lycée Blaise Pascal » arrive en tête parce que le mot est dans le titre **et** dans l'auteur : les scores s'additionnent.
- **Cas `pasqalsss`** : la requête est **analysée d'abord**. Le stemmer retire le `s` final et réduit les lettres doublées, ce qui donne `pasqals`. Il reste 2 modifications pour obtenir `pascal`, tolérées pour 7 lettres. 👉 **La tolérance s'applique après l'analyse.** Pour être plus strict : `fuzziness: "AUTO:4,8"`.
- ⚠️ **Limite** : l'index n'est **pas synchronisé**. Un livre ajouté n'est pas trouvable tant qu'on ne relance pas `indexation.php`.

### 6.3 ➕ Pour aller plus loin
- **Index inversé** : terme → liste des documents, comme l'index à la fin d'un livre.
- **Score BM25** (successeur de TF-IDF) : la **fréquence du terme** (avec saturation), la **rareté** du terme dans la collection (IDF) et la **longueur du champ** (un champ court pèse plus). On le voit avec `"explain": true`.
- **Quasi temps réel** : un document devient cherchable après un **refresh** (1 s par défaut).
- **Shards et réplicas** : 1 shard primaire et 1 réplica par défaut. Le nombre de shards est fixé à la création. Santé du cluster : green, yellow ou red.
  - ⚠️ **Question piège** : notre cluster est probablement **yellow**, car sur un seul nœud ElasticSearch refuse de placer une réplique à côté de son primaire.
- **Bool** : `should` (OU), `must` (ET avec score), `filter` (ET sans score, mis en cache), `must_not` (exclusion).
- **Pagination** : `from + size ≤ 10 000` par défaut (`max_result_window`). Au-delà : `search_after`.
- **JVM** : `Xms = Xmx`, au plus 50 % de la RAM (le reste sert au cache disque de l'OS, essentiel pour Lucene), `memory_lock` pour éviter le *swap*.
- **Synchronisation en temps réel** : les **Change Streams** de MongoDB ou Kafka (principe du **CDC**, *Change Data Capture*).

---

## 7. Tests de charge : k6, InfluxDB, Grafana (TP2)

### 7.1 Le scénario (`k6/load_test.js`)
- **VU** (*Virtual User*) : un utilisateur simulé qui rejoue la fonction `default` en boucle.
- **Stages** : 5 → 10 → **50** → 10 → 5 VUs, environ 35 s.
- **Chaque itération** :
  1. la liste, avec extraction des ids par **regex** sur le HTML ;
  2. une page aléatoire (1 à 141) ;
  3. une fiche aléatoire ;
  4. dans **10 %** des cas, un ajout puis la suppression du **même** livre (id lu dans `res.url` après la redirection).
- `sleep(1)` entre les étapes représente le **temps de réflexion**. `check()` vérifie le statut 200, et `group()` regroupe les métriques par étape.
- ⚠️ **Piège** : le script de départ appelait `http://php:80`, mais le service s'appelle `tpmongo-php` (réseau Docker).

```bash
docker compose run --rm tpmongo-k6 run /scripts/load_test.js | tee test_no_cache.txt   # REDIS_ENABLE=false
docker compose run --rm tpmongo-k6 run /scripts/load_test.js | tee test_cache.txt      # REDIS_ENABLE=true
```

### 7.2 Résultats
| Métrique | Sans cache | Avec cache | Écart |
|---|---|---|---|
| Checks | 100 % | 100 % | n/a |
| Moyenne | 27,25 ms | **14,84 ms** | **−46 %** |
| Médiane | 10,46 ms | 10,38 ms | ≈ 0 |
| p(90) | 29,11 ms | **20,82 ms** | −28 % |
| p(95) | 48,94 ms | **36,31 ms** | −26 % |
| Max | 1,08 s | **437 ms** | −60 % |
| Min | **1,70 ms** | 2,82 ms | +66 % |
| Débit | 13,4 req/s | 13,4 req/s | n/a |

### 7.3 Interprétation (à savoir expliquer)
- Le cache **réduit les requêtes lentes sous charge**. Au pic de 50 VUs, les requêtes de liste et de comptage n'arrivent plus toutes en même temps sur MongoDB : le cache **stabilise** plus qu'il n'accélère.
- Il **n'accélère pas la requête typique** (médiane identique, minimum plus élevé). MongoDB a déjà tout en RAM, et Redis ajoute un aller-retour réseau, la sérialisation et une nouvelle connexion.
- **Le gain est limité** par :
  - 10 % d'écritures, soit une trentaine de vidages complets du cache ;
  - 141 pages tirées au hasard, rarement servies deux fois ;
  - une charge modérée.
- **Le débit est identique** parce qu'il est imposé par les `sleep(1)` (modèle *closed*).
- **Variabilité** : une première campagne avait donné la tendance inverse. Une seule exécution de 35 s sur un PC (WSL) est bruitée, il faut **répéter** et comparer les médianes.
- 👉 **Leçon** : un cache est rentable si le **taux de succès est élevé** (beaucoup de lectures, peu d'écritures) et si la **source est lente**. Une optimisation se **mesure**.

### 7.4 ➕ Notions
- **p(95)** : 95 % des requêtes sont plus rapides que cette valeur. La moyenne est tirée par les valeurs extrêmes, et les **SLA** s'expriment en percentiles.
- `http_req_duration` mesure le temps total, et `http_req_waiting` le **TTFB** (temps de traitement serveur).
- **Loi de Little** : L = λ × W. Ici, 13,4 × 0,015 ≈ 0,2 requête en cours en moyenne : le serveur est très peu chargé.
- **Types de tests** :
  - **load** : la charge normale, notre cas ;
  - **stress** : jusqu'à la rupture ;
  - **spike** : un pic brutal ;
  - **soak** : des heures, pour détecter les fuites mémoire.
  - Pour savoir combien d'utilisateurs l'application supporte, il faut un **stress test**.
- 📘 **InfluxDB 1.8** = base de **séries temporelles** (cours 3). k6 y écrit via `K6_OUT=influxdb=http://tpmongo-influxdb:8086/k6`. **Grafana** est provisionné automatiquement : source de données et tableau de bord. Certains panneaux du tableau de bord importé restaient vides avec cette version.

---

## 8. Choisir un SGBD et migrer de SQL vers NoSQL

### 8.1 📘 Les exercices du cours ⭐
| Projet | Choix |
|---|---|
| **Réseau social d'entreprise** (profils, posts, relations, suggestions) | **Neo4j** (relations) + **MongoDB** (profils et posts flexibles) |
| **E-commerce** (catalogue de millions de produits, recommandations, stocks très disponibles) | **Cassandra ou HBase** (historiques massifs, haute disponibilité) + **ElasticSearch** (recherche) + **Redis** (recommandations en temps réel) |
| **Capteurs IoT d'une ville** (millions de mesures par jour, moyennes par heure) | **Séries temporelles** (InfluxDB, TimescaleDB) |

**Méthode de réponse** : identifier le **besoin dominant** (relations, recherche, volume d'écriture, temps, cache, cohérence), puis lui associer une **famille**, puis **justifier**. Souvent, la bonne réponse combine plusieurs bases.

### 8.2 ➕ Migrer une base SQL vers NoSQL
1. **Se demander s'il faut vraiment migrer** : souvent, on garde SQL pour les transactions (paiements, stocks) et on **ajoute** une base NoSQL pour un besoin précis. C'est ce qu'on fait dans le TP.
2. **Analyser** : le schéma, les cardinalités, les **requêtes réelles** (lesquelles sont fréquentes, ce qui est lu ensemble) et les besoins transactionnels.
3. **Remodéliser selon les requêtes**, pas table par table. On passe de la **normalisation** à la **dénormalisation** :
   | Relation SQL | Modèle document |
   |---|---|
   | 1-1 (auteur → adresse) | Embedding |
   | 1-N limité (commande → ses lignes) | Embedding dans un tableau |
   | 1-N sans limite (auteur → des milliers d'avis) | Référence |
   | N-N | Tableau de références |
   | Donnée partagée et souvent modifiée | Référence |
4. **ETL** : *Extract* (requête SQL avec `JOIN`), *Transform* (script qui construit les documents), *Load* (`insertMany` par lots, `mongoimport`). Notre `indexation.php` est un ETL MongoDB → ElasticSearch. Outils : MongoDB Relational Migrator, Logstash JDBC, Debezium + Kafka (CDC).
5. **Bascule** : *big bang* (avec coupure, risqué, voir la gravité des données) ou **progressive** (double écriture ou CDC). Puis on **vérifie** : nombre d'enregistrements, échantillons, requêtes clés.
6. **Pièges** :
   - plus de jointures faciles (`$lookup` est coûteux) ;
   - des **doublons** à maintenir ;
   - plus de clés étrangères (on peut ajouter `$jsonSchema`) ;
   - des transactions plus coûteuses ;
   - la limite de 16 Mo par document ;
   - une cohérence relâchée (CAP).

---

## 9. Cours 4 : L'open data

📘
- **Définition** : une donnée numérique, **publique ou privée** (collectivité, service public, entreprise), diffusée de façon **structurée** sous **licence ouverte**, en **libre accès et réutilisable par tous**, sans restriction technique, juridique ni financière.
- **Producteurs** : État, régions, villes, recherche, entreprises publiques (**SNCF**), parfois le privé.
- **Portails** : **data.gouv.fr**, les portails des villes (avec des espaces développeurs), New York, data.gov (États-Unis).
- **Accès** : une **API documentée**, ou un **téléchargement**, le plus souvent en **CSV**.
- **Usages** : créer des applications, faire des statistiques et des prédictions, **entraîner des modèles d'IA**. C'est une « mine d'or ».

🛠️ **Dans le TP** : le catalogue des manuscrits vient du portail open data de **Clermont Métropole**. Il a été téléchargé en **JSON** (environ 728 Ko) puis importé avec `mongoimport --jsonArray`. C'est un exemple concret de la chaîne du cours 1 : **collecter → stocker → analyser** (rechercher).

---

## 10. Cours 5 : Docker

### 10.1 📘 Les définitions ⭐
| Terme | Définition |
|---|---|
| **Image** | Le paquet **immuable** des dépendances et de la configuration, construit **en couches** |
| **Conteneur** | Une **instance** d'une image en cours d'exécution. Pour monter en charge, on lance plusieurs conteneurs depuis la même image |
| **Dépôt** (*repository*) | Les versions d'une image, identifiées par des **tags** |
| **Registre** (*registry*) | Le service qui héberge les dépôts : **Docker Hub**, des registres privés, Azure Container Registry |
| **Tag** | La version : `redis:7.0.5`, `mariadb:10.3` |
| **Dockerfile** | Les instructions de construction (image de base, installations, copies) |
| **Build** | `docker build` : crée une image à partir du Dockerfile |
| **Compose** | Un fichier **YAML** qui décrit une application multi-conteneurs, lancée en **une seule commande** |
| **Docker Desktop** | L'outil pour Windows et Mac (hôte Linux dans une VM Hyper-V ou WSL2) |
| **Portainer** | Une interface web de gestion de Docker, qui tourne elle-même dans un conteneur |

- **VM ou conteneur** : une VM embarque **un système d'exploitation complet** (lourde). Un conteneur **partage le noyau** de l'hôte (léger, démarre en quelques secondes, portable).

### 10.2 📘 Volumes et réseaux ⭐
- **Volume** : un espace géré par Docker sur l'hôte. **Les données ne sont pas perdues** quand on supprime le conteneur.
- **Bind mount** : on monte **un dossier précis de l'hôte** dans le conteneur, ce qui permet d'éditer les fichiers depuis l'hôte.

| Réseau | Comportement |
|---|---|
| **none** | Pas de réseau (seulement le loopback) |
| **bridge** (par défaut) | Les conteneurs communiquent **par leur nom ou leur IP**, mais ils ne sont pas accessibles directement depuis l'hôte (il faut exposer les ports) |
| **host** | Le conteneur utilise le réseau de l'hôte, avec la même IP |
| **macvlan** | Chaque conteneur a sa propre adresse MAC |
| **overlay** | Communication entre conteneurs sur plusieurs hôtes (Swarm) |

### 10.3 🛠️ Dans le TP
| Notion | Dans le projet |
|---|---|
| Compose | **11 services** dans `docker-compose.yml` |
| Réseau bridge | Le réseau `local` : on joint les autres conteneurs **par le nom du service** (`tpmongo-mongodb`, `tpmongo-redis`…), **jamais par `localhost`** |
| Ports | `hôte:conteneur`, par exemple `8080:80`. Depuis l'hôte on passe par `localhost:8080`, depuis un autre conteneur par `tpmongo-php:80` |
| Bind mount | `./www:/var/www/app` : le code modifié sur l'hôte est visible tout de suite |
| ⚠️ Volumes | **Aucun volume pour MongoDB ni pour ElasticSearch**, donc `docker compose down` **efface les données**. Il faut utiliser `stop` |
| Limites | ElasticSearch : 1 CPU / 2 Go maximum, 0,5 CPU / 1 Go réservés |
| Configuration | Variables d'environnement + `.env` (phpdotenv) |

### 10.4 📘 Orchestration et clusters
- **Cluster** : plusieurs hôtes Docker vus comme un seul. **Orchestration** : gérer et coordonner de nombreux conteneurs pour assurer la **haute disponibilité** et la **scalabilité**.

| | **Kubernetes** (K8s) | **Docker Swarm** |
|---|---|---|
| ✅ | Très puissant : autoscaling, monitoring, standard du marché | **Intégré à Docker**, simple, faible latence |
| ❌ | Complexe, long à prendre en main | Fonctions avancées limitées |

- **Swarm** :
  - **Nœuds** : un **manager** coordonne (services, secrets, surveillance), les **workers** exécutent. Le premier manager devient le **leader**, les autres sont des *followers*.
  - Un **service** = une **image** + un **nombre de réplicas**.
  - **Commandes** : `docker swarm init --advertise-addr <IP>`, `docker swarm join --token <TOKEN> <IP>:<PORT>`, `docker stack deploy --compose-file docker-compose.yml mystack`.
  - Interfaces graphiques : Portainer, Swarmpit.
- **Cluster de bases de données** : il apporte la **haute disponibilité**, la **haute performance** et l'**adaptabilité**. Exemple du cours : **MariaDB + Galera**, avec trois instances qui se répliquent.

---

## 11. Problèmes rencontrés et points faibles du code

### 11.1 🛠️ Problèmes rencontrés (et la méthode de diagnostic)
| Symptôme | Cause | Solution / réflexe |
|---|---|---|
| `ERR_CONNECTION_REFUSED` sur 8080 | Ports 8080 et 8081 occupés par un autre projet | `docker ps -a`, puis `docker stop` des autres conteneurs |
| Tableau vide | Base effacée (pas de volume) | Vérifier **les données avant le code** (`findOne`), puis ré-importer |
| `R�f�rence` | Mauvais encodage | Enregistrer en **UTF-8** |
| Le cache renvoie toujours la page 1 | Clé unique | Une clé par page |
| Liste périmée après une modification | Cache vidé seulement dans `create.php` | `clearListCache()` dans create, update et delete |
| Code PHP affiché comme du texte | `<?php` manquant ou abîmé | Vérifier la première ligne du fichier |
| Erreur 500 sans message | **Erreur de syntaxe** (le parseur échoue avant `display_errors`) | `php -l` + `var/log/error.log` |
| k6 ne joint pas l'application | `http://php:80` | Utiliser le nom du service `tpmongo-php` |
| `git push` en erreur 504 | Un `.deb` de 69 Mo commité | `git reset --soft`, `git rm --cached`, `.gitignore` |

### 11.2 Points faibles du code (questions pièges)
Les connaître montre qu'on a compris ce qu'on a fait.
1. **La fiche détail n'est jamais invalidée.** `get.php` met en cache `manuscrit:{id}` (**singulier**), mais `clearListCache()` supprime `manuscrits:*` (**pluriel**). Correction : `DEL manuscrit:{id}` dans `update.php` et `delete.php`.
2. **`KEYS` est bloquant** : il est en O(N) sur Redis, qui est mono-thread. Correction : `SCAN`, un SET qui référence les clés, ou des clés **versionnées**.
3. **Une nouvelle connexion Redis à chaque appel** de `getRedisClient()`. Correction : une seule instance par requête, ou des connexions persistantes.
4. **Pas de repli si Redis ou ElasticSearch tombe** : la page renvoie une erreur 500 alors que MongoDB fonctionne. Correction : `try/catch` puis lecture directe dans MongoDB (**dégradation gracieuse**).
5. **ElasticSearch n'est pas synchronisé** avec MongoDB. Correction : `index()` / `delete()` dans les écritures, ou des Change Streams.
6. **Invalidation trop large** : un ajout ne change que la dernière page et le total.
7. **Sécurité** (acceptable en TP) : identifiants en clair, Redis sans mot de passe, ElasticSearch sans authentification, suppression par simple `GET`, `unserialize`.
8. **La pagination par `skip`** devient coûteuse à grande échelle.

---

## 12. Questions probables : réponses courtes

### 📘 Cours
| Question | Réponse courte |
|---|---|
| Définir le Big Data | La règle des **3V** (volume, variété, vélocité), étendue aux **5V** (+ véracité, valeur) |
| Pourquoi le Big Data est-il apparu ? | L'explosion du **Web** puis de l'**IoT** : le modèle client-serveur à base centrale ne suffit plus |
| Scalabilité verticale ou horizontale ? | Vertical = une machine plus puissante (simple, limité, coupure). Horizontal = plus de machines (coût linéaire, haute disponibilité, mais réplication à gérer) |
| Pourquoi le NoSQL ? | Le coût des transactions et de la généricité SQL. On écrit une fois et on lit des millions de fois. Relâcher la cohérence permet le partitionnement |
| C'est quoi la gravité des données ? | Les grosses données attirent les applications : (masse × criticité × vitesse) / distance |
| Énoncer CAP | On ne garantit que 2 des 3 : Cohérence, Disponibilité, Tolérance au partitionnement |
| Placer MongoDB, Redis, ES, MySQL, Cassandra, Neo4j | MongoDB **CP** · Redis **AP** · ElasticSearch **AP** · MySQL **CA** · Cassandra **AP** · Neo4j **AP** (correction du cours) |
| ACID ? | Atomicité, Cohérence, Isolation, Durabilité |
| Embedding ou référence ? | Embedding = imbriqué, une seule lecture. Référence = identifiant vers un autre document, pour les données partagées ou volumineuses |
| Qu'est-ce qu'une donnée personnelle ? | Toute info sur une personne identifiée ou **identifiable**, directement ou indirectement, y compris par croisement |
| Principe de finalité ? | Pas de collecte « au cas où » : un but légal et légitime |
| L'anonymisation est-elle fiable ? | Non : le croisement de sources permet la ré-identification, et anonymiser fait perdre de l'information |
| Définir l'open data | Des données publiques ou privées, structurées, sous licence ouverte, en libre accès et réutilisation |
| Image ou conteneur ? | L'image est le modèle immuable, le conteneur est son instance en cours d'exécution (comme une classe et un objet) |
| VM ou conteneur ? | La VM embarque un OS complet. Le conteneur partage le noyau : il est léger et rapide |
| Volume ou bind mount ? | Le volume est géré par Docker (persistance). Le bind mount monte un dossier précis de l'hôte |
| Kubernetes ou Swarm ? | K8s est puissant mais complexe. Swarm est intégré à Docker et simple, avec moins de fonctions |
| Redis et sa licence ? | Plus BSD depuis la 7.4 (mars 2024). Forks : **Valkey**, Redict, KeyDB, Dragonfly |

### 🛠️ TP
| Question | Réponse courte |
|---|---|
| Pourquoi MongoDB ici ? | Données JSON, schéma souple (`auteur` souvent `null`), pas de jointures |
| Comment marche la pagination ? | `sort _id` + `skip` + `limit`, 20 par page, `countDocuments()`. Limite : `skip` en O(N), alternative : pagination par curseur |
| Comment marche votre cache ? | Cache-aside : on lit Redis, en cas de *miss* on lit MongoDB puis on fait `SETEX` 300 s. Une clé par page |
| Pourquoi vider le cache à chaque écriture ? | Le cache est une copie qui deviendrait périmée. Une écriture décale les pages et change le total |
| Pourquoi ne pas mettre à jour le cache sur place ? | Coûteux pour une liste paginée, risqué avec des écritures simultanées. Supprimer est sûr |
| Pourquoi `serialize` ? | Redis ne stocke que des chaînes, et `serialize` garde les types PHP |
| Combien de temps dure le cache ? | 300 s dans notre code. Par défaut Redis n'expire jamais une clé |
| Combien d'utilisateurs Redis gère-t-il ? | 10 000 connexions par défaut, environ 100 000 opérations/s. Dans le TP, le goulot est PHP/Apache |
| Interprétez k6 | Moyenne −46 %, max divisé par 2,5, médiane identique : le cache **stabilise** sous charge, mais MongoDB est déjà rapide |
| Pourquoi p(95) ? | La moyenne masque les requêtes lentes, et les SLA s'expriment en percentiles |
| Pourquoi InfluxDB ? | Une base de séries temporelles, adaptée aux métriques horodatées |
| Pourquoi ElasticSearch plutôt qu'une regex MongoDB ? | Fautes de frappe, stemming, pertinence (BM25), index inversé rapide |
| Comment gérez-vous les fautes de frappe ? | `fuzziness: AUTO` = Levenshtein, **après** l'analyse |
| Et le pluriel ? | Le stemmer `light_french`, appliqué à l'indexation et à la requête |
| À quoi sert le `boost` ? | Le titre pèse ×2 dans le score |
| Pourquoi garder MongoDB ? | Source de vérité. ElasticSearch sert à trouver et classer |
| Limite du TP3 ? | L'index n'est pas synchronisé avec MongoDB |
| Pourquoi la base a disparu ? | Pas de **volume**, et `down` supprime le conteneur |
| Comment un conteneur en joint un autre ? | Par le nom du service sur le réseau bridge, pas par `localhost` |
| Couleur de votre cluster ElasticSearch ? | Probablement **yellow** (un seul nœud, réplique non allouée) |

---

## 13. Commandes utiles

**Docker**
```bash
docker compose up -d / stop / down     # down = suppression des conteneurs
docker ps -a ; docker logs <c> --tail 30 ; docker exec -it <c> bash
docker cp fichier.json tpmongo-mongodb:/tmp/
docker exec -w /var/www/app tpmongo-php php command/indexation.php
docker exec tpmongo-php php -l /var/www/app/public/delete.php   # vérifier la syntaxe
```

**Redis** (`docker exec -it tpmongo-redis redis-cli`)
```bash
SET / GET / DEL / SETEX cle 300 valeur
KEYS manuscrits:*                  # à éviter en production
SCAN 0 MATCH manuscrit* COUNT 100  # non bloquant
TTL manuscrits:liste:page:1        # -1 : pas de TTL, -2 : absente
DBSIZE ; INFO stats ; INFO memory ; CONFIG GET maxclients ; CONFIG GET maxmemory*
MONITOR ; FLUSHALL ; redis-benchmark -q -n 100000
```

**MongoDB** (`docker exec -it tpmongo-mongodb mongosh -u zz3f3 -p easyma`)
```js
use tp
db.tp.findOne() ; db.tp.countDocuments() ; db.tp.estimatedDocumentCount()
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
POST manuscrits/_analyze { "analyzer": "fr", "text": "L'Éloges de Blaise Pasqalsss", "explain": true }
GET manuscrits/_search { "explain": true, "query": { "match": { "titre": { "query": "paskal", "fuzziness": "AUTO" } } } }
```

**k6**
```bash
docker compose run --rm tpmongo-k6 run /scripts/load_test.js | tee resultat.txt
```

**Docker Swarm** (cours)
```bash
docker swarm init --advertise-addr <IP>
docker swarm join --token <TOKEN> <IP>:<PORT>
docker stack deploy --compose-file docker-compose.yml mystack
```

---

## 14. Chiffres à retenir

| Élément | Valeur |
|---|---|
| 📘 Début du Big Data | environ **10 To** |
| 📘 Volume annuel du LHC | environ **15 Po** |
| 📘 Trafic internet | 100 Go/jour (1992) → **50 000 Go/s** (2018) |
| 📘 Taille max d'une string Redis | **512 Mo** |
| 📘 Licence Redis | Fin de la BSD en **mars 2024** (version **7.4**) |
| 📘 Gravité des données | **Dave McCrory, 2010** |
| 🛠️ Documents | **2818** manuscrits, environ 728 Ko |
| 🛠️ Pagination | **20** par page → **141** pages |
| 🛠️ TTL du cache | **300 s** |
| 🛠️ Bulk | paquets de **500** |
| 🛠️ k6 | **50** VUs max, environ 35 s, **13,4** req/s, 166 itérations |
| 🛠️ Gain du cache | moyenne **−46 %**, p95 **−26 %**, max **1,08 s → 437 ms** |
| 🛠️ Tas JVM ElasticSearch | **512 Mo** |
| ➕ Redis `maxclients` / débit | **10 000** / environ **100 000** op/s |
| ➕ Redis Cluster | **16 384** hash slots |
| ➕ Document MongoDB max | **16 Mo** |
| ➕ `ObjectId` | **12** octets |
| ➕ Refresh / `max_result_window` ElasticSearch | **1 s** / **10 000** |
| ➕ Fuzziness max | **2** modifications |
| 🛠️ Ports | Mongo **27017**, Redis **6379**, ES **9200**, Kibana **5601**, Grafana **3000**, InfluxDB **8086**, app **8080** |

---

**En une ligne :** MongoDB **stocke** · Redis **accélère** · ElasticSearch **trouve** · InfluxDB **historise** · k6 **mesure** · Docker **orchestre**. Chaque besoin a son SGBD (cours 3), et une optimisation se **mesure**, elle ne se suppose pas.