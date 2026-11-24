<?php

include_once '../init.php';

use MongoDB\BSON\ObjectId;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

$twig = getTwig();
$collection = getMongoDbManager()->selectCollection('tp');
$typeMap = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$q = trim($_GET['q'] ?? '');

if ($q !== '') {
    // ---------- RECHERCHE : ElasticSearch ----------
    $response = getElasticSearchClient()->search([
        'index' => 'manuscrits',
        'body'  => [
            'from'    => ($page - 1) * $perPage,
            'size'    => $perPage,
            '_source' => false,               // on ne veut que les ids
            'query'   => [
                'bool' => [
                    'should' => [             // le titre OU l'auteur
                        ['match' => ['titre' => [
                            'query'     => $q,
                            'fuzziness' => 'AUTO',   // tolère les fautes de frappe
                            'boost'     => 2,        // le titre compte double
                        ]]],
                        ['match' => ['auteur' => [
                            'query'     => $q,
                            'fuzziness' => 'AUTO',
                        ]]],
                    ],
                ],
            ],
        ],
    ]);

    $total = $response['hits']['total']['value'];
    $ids = array_column($response['hits']['hits'], '_id');

    // récupération des livres dans MongoDB
    $docs = $collection->find(
        ['_id' => ['$in' => array_map(fn($id) => new ObjectId($id), $ids)]],
        $typeMap
    )->toArray();

    // on remet les livres dans l'ordre de pertinence d'ElasticSearch
    $byId = [];
    foreach ($docs as $doc) {
        $byId[(string) $doc['_id']] = $doc;
    }
    $list = array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids)));

} else {
    // ---------- LISTE NORMALE : MongoDB + cache Redis ----------
    $redis = getRedisClient();

    $total = $redis ? $redis->get('manuscrits:count') : null;
    if ($total === null) {
        $total = $collection->countDocuments();
        if ($redis) {
            $redis->setex('manuscrits:count', 300, $total);
        }
    }
    $total = (int) $total;
    $page = min($page, max(1, (int) ceil($total / $perPage)));

    $cacheKey = "manuscrits:liste:page:$page";
    $cached = $redis ? $redis->get($cacheKey) : null;

    if ($cached !== null) {
        $list = unserialize($cached);
    } else {
        $list = $collection->find([], [
            'sort'  => ['_id' => 1],
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
        ] + $typeMap)->toArray();

        if ($redis) {
            $redis->setex($cacheKey, 300, serialize($list));
        }
    }
}

$nbPages = max(1, (int) ceil($total / $perPage));

try {
    echo $twig->render('index.html.twig', [
        'list'    => $list,
        'page'    => $page,
        'nbPages' => $nbPages,
        'total'   => $total,
        'q'       => $q,
    ]);
} catch (LoaderError|RuntimeError|SyntaxError $e) {
    echo $e->getMessage();
}