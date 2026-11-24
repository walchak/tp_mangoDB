<?php



include_once '../init.php';

use MongoDB\BSON\ObjectId;

$manager = getMongoDbManager();
$collection = $manager->selectCollection('tp');

try {
    $id = new ObjectId($_GET['id'] ?? '');
} catch (\Throwable $e) {
    http_response_code(400);
    exit('Identifiant invalide');
}

$result = $collection->deleteOne(['_id' => $id]);
clearListCache();

if ($result->getDeletedCount() === 0) {
    http_response_code(404);
    exit('Manuscrit introuvable');
}

clearListCache();
header('Location: /');
exit;