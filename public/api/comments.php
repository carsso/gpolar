<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use GPolar\PolarstepsClient;

header('Content-Type: application/json');

$token  = getToken();
$stepId = $_GET['step_id'] ?? null;

if (!$token || !$stepId || !ctype_digit((string) $stepId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
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
