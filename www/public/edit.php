<?php

include_once '../init.php';

use MongoDB\BSON\ObjectId;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

$twig = getTwig();
$collection = getMongoDbManager()->selectCollection('tp');

try {
    $entity = $collection->findOne(
        ['_id' => new ObjectId($_GET['id'] ?? '')],
        ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]
    );
} catch (Exception $e) {
    $entity = null; // id absent ou invalide
}

if (!$entity) {
    header('Location: app.php');
    exit;
}
$entity['_id'] = (string) $entity['_id'];

try {
    echo $twig->render('update.html.twig', ['entity' => $entity]);
} catch (LoaderError|RuntimeError|SyntaxError $e) {
    echo $e->getMessage();
}