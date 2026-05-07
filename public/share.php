<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/helpers.php';

use GPolar\PolarstepsClient;
use GPolar\ShareStore;

$shareToken = $_GET['token'] ?? '';
$share      = $shareToken ? ShareStore::get($shareToken) : null;

if (!$share) {
    http_response_code(404);
    echo htmlHead('Lien invalide');
    echo htmlNav();
    echo <<<HTML
<div class="max-w-lg mx-auto mt-16 text-center px-4">
  <div class="bg-gray-900 rounded-2xl border border-red-900 p-8">
    <div class="text-4xl mb-4">🔗</div>
    <h2 class="font-semibold text-gray-100 mb-2">Lien invalide ou expiré</h2>
    <p class="text-sm text-gray-500">Ce lien de partage n'existe pas ou a été supprimé.</p>
  </div>
</div>
</body></html>
HTML;
    exit;
}

// Inject vars for trip.php
$token         = $share['ps_token'];
$tripId        = $share['trip_id'];
$isPublicShare = true;

require __DIR__ . '/trip.php';
