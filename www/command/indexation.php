<?php

include_once __DIR__.'/../init.php';

$es = getElasticSearchClient();
$collection = getMongoDbManager()->selectCollection('tp');
$index = 'manuscrits';

// 1. (re)cr�ation de l'index avec un analyseur fran�ais
if ($es->indices()->exists(['index' => $index])->asBool()) {
    $es->indices()->delete(['index' => $index]);
}

$es->indices()->create([
    'index' => $index,
    'body'  => [
        'settings' => [
            'analysis' => [
                'filter' => [
                    'fr_elision' => [
                        'type' => 'elision',
                        'articles_case' => true,
                        'articles' => ['l', 'm', 't', 'qu', 'n', 's', 'j', 'd', 'c', 'jusqu', 'quoiqu', 'lorsqu', 'puisqu'],
                    ],
                    'fr_stop'    => ['type' => 'stop', 'stopwords' => '_french_'],
                    'fr_stemmer' => ['type' => 'stemmer', 'language' => 'light_french'],
                ],
                'analyzer' => [
                    'fr' => [
                        'tokenizer' => 'standard',
                        // l'�loge -> �loge -> eloge -> (stop words retir�s) -> elog
                        'filter' => ['fr_elision', 'lowercase', 'asciifolding', 'fr_stop', 'fr_stemmer'],
                    ],
                ],
            ],
        ],
        'mappings' => [
            'properties' => [
                'titre'  => ['type' => 'text', 'analyzer' => 'fr'],
                'auteur' => ['type' => 'text', 'analyzer' => 'fr'],
                'cote'   => ['type' => 'keyword'],
                'langue' => ['type' => 'keyword'],
                'siecle' => ['type' => 'keyword'],
            ],
        ],
    ],
]);

// 2. envoi des documents par paquets de 500 (API bulk)
$params = ['body' => []];
$n = 0;
$cursor = $collection->find([], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);

foreach ($cursor as $doc) {
    $params['body'][] = ['index' => ['_index' => $index, '_id' => (string) $doc['_id']]];
    $params['body'][] = [
        'titre'  => $doc['titre']  ?? null,
        'auteur' => $doc['auteur'] ?? null,
        'cote'   => $doc['cote']   ?? null,
        'langue' => $doc['langue'] ?? null,
        'siecle' => $doc['siecle'] ?? null,
    ];
    $n++;

    if ($n % 500 === 0) {
        $es->bulk($params);
        $params = ['body' => []];
    }
}
if (!empty($params['body'])) {
    $es->bulk($params);
}

$es->indices()->refresh(['index' => $index]);
echo "\n$n documents indexés dans '$index'\n";

return 0;