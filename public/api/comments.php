<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use GPolar\PolarstepsClient;
use GPolar\ShareStore;

header('Content-Type: application/json');

$stepId = $_GET['step_id'] ?? null;
if (!$stepId || !ctype_digit((string) $stepId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

// Auth: session token or share token
$token = getToken();
if (!$token) {
    $shareToken = $_GET['share'] ?? '';
    $share = $shareToken ? ShareStore::get($shareToken) : null;
    $token = $share['ps_token'] ?? null;
}

if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

try {
    $client = new PolarstepsClient($token);
    $data   = $client->getStepComments((int) $stepId);
    echo json_encode($data);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
