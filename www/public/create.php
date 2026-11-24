<?php

include_once '../init.php';

use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

$twig = getTwig();
$manager = getMongoDbManager();
$collection = $manager->selectCollection('tp');

$champs = ['cote', 'titre', 'auteur', 'langue', 'siecle', 'edition'];
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $document = [];
    foreach ($champs as $champ) {
        $document[$champ] = trim($_POST[$champ] ?? '');
    }

    if ($document['titre'] === '') {
        $erreur = 'Le titre est obligatoire.';
    } else {
        $result = $collection->insertOne($document);
if ($redis = getRedisClient()) {
    clearListCache();
}
header('Location: get.php?id=' . $result->getInsertedId());
exit;
    }
}

try {
    echo $twig->render('create.html.twig', ['erreur' => $erreur]);
} catch (LoaderError|RuntimeError|SyntaxError $e) {
    echo $e->getMessage();
}