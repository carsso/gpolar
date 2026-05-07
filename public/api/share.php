<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use GPolar\PolarstepsClient;
use GPolar\ShareStore;

header('Content-Type: application/json');

$token = getToken();
if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $tripId = (string) ($body['trip_id'] ?? '');
    if (!$tripId) {
        http_response_code(400);
        echo json_encode(['error' => 'trip_id manquant']);
        exit;
    }
    try {
        $me         = (new PolarstepsClient($token))->getMe();
        $userId     = (string) $me['id'];
        $shareToken = ShareStore::create($tripId, $userId, $token);
        $url        = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/share/' . $shareToken;
        echo json_encode(['token' => $shareToken, 'url' => $url]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }

} elseif ($method === 'DELETE') {
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $shareToken = (string) ($body['token'] ?? '');
    if (!$shareToken) {
        http_response_code(400);
        echo json_encode(['error' => 'token manquant']);
        exit;
    }
    try {
        $me     = (new PolarstepsClient($token))->getMe();
        $userId = (string) $me['id'];
        echo json_encode(['success' => ShareStore::delete($shareToken, $userId)]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non supportée']);
}
