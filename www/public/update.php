<?php

include_once '../init.php';

use MongoDB\BSON\ObjectId;

$collection = getMongoDbManager()->selectCollection('tp');

if (!empty($_POST['id'])) {
    $collection->updateOne(
        ['_id' => new ObjectId($_POST['id'])],
        ['$set' => [
            'cote'    => trim($_POST['cote'] ?? ''),
            'titre'   => trim($_POST['titre'] ?? ''),
            'auteur'  => trim($_POST['auteur'] ?? ''),
            'langue'  => trim($_POST['langue'] ?? ''),
            'siecle'  => trim($_POST['siecle'] ?? ''),
            'edition' => trim($_POST['edition'] ?? ''),
        ]]
    );
    clearListCache();
}

header('Location: app.php');
exit;