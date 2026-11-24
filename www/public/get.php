<?php

include_once '../init.php';

use MongoDB\BSON\ObjectId;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

$twig = getTwig();
$manager = getMongoDbManager();
$collection = $manager->selectCollection('tp');

try {
    $id = new ObjectId($_GET['id'] ?? '');
} catch (\Throwable $e) {
    http_response_code(400);
    exit('Identifiant invalide');
}

$redis = getRedisClient();
$cacheKey = 'manuscrit:' . $id;

$cached = $redis ? $redis->get($cacheKey) : null;

if ($cached !== null) {
    $document = unserialize($cached);
} else {
    $document = $collection->findOne(
        ['_id' => $id],
        ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]
    );

    if ($redis && $document !== null) {
        $redis->setex($cacheKey, 300, serialize($document));
    }
    
}

if ($document === null) {
    http_response_code(404);
    exit('Manuscrit introuvable');
}

try {
    echo $twig->render('get.html.twig', ['document' => $document]);
} catch (LoaderError|RuntimeError|SyntaxError $e) {
    echo $e->getMessage();
}