<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/helpers.php';

use GPolar\PolarstepsClient;

$token  = requireAuth();
$client = new PolarstepsClient($token);

$debugLog = [];
function dbg(string $label, mixed $value = null): void {
    global $debugLog;
    $debugLog[] = ['label' => $label, 'value' => $value, 'time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000) . 'ms'];
}

// ── Fetch own profile ─────────────────────────────────────────────────────────
$me    = [];
$error = null;

try {
    $me = $client->getMe();
    dbg('getMe() OK', array_keys($me));
} catch (\Throwable $e) {
    $error = $e->getMessage();
    dbg('getMe() FAIL', $e->getMessage());
}

// ── Extract own trips ─────────────────────────────────────────────────────────
$myTrips = [];
if (!empty($me)) {
    $myTrips = $me['trips'] ?? $me['all_trips'] ?? [];
    usort($myTrips, fn($a, $b) => parseTs($b['start_date'] ?? 0) <=> parseTs($a['start_date'] ?? 0));
    dbg('myTrips', count($myTrips) . ' trips');
}

// ── Extract followees and fetch their trips ───────────────────────────────────
$friendTrips  = [];
$rawFollowees = $me['followees'] ?? $me['following'] ?? [];
dbg('followees raw', count($rawFollowees));

if (!empty($rawFollowees)) {
    $followeeIds = array_filter(array_map(fn($f) => is_array($f) ? ($f['id'] ?? null) : (is_int($f) ? $f : null), $rawFollowees));
    dbg('followeeIds to fetch', array_values($followeeIds));

    try {
        $profiles = $client->getUsersParallel(array_slice(array_values($followeeIds), 0, 20));
        dbg('profiles fetched', count($profiles));
    } catch (\Throwable $e) {
        $profiles = [];
        dbg('getUsersParallel() FAIL', $e->getMessage());
    }

    foreach ($profiles as $fUser) {
        $fTrips = $fUser['trips'] ?? $fUser['all_trips'] ?? [];
        usort($fTrips, fn($a, $b) => parseTs($b['start_date'] ?? 0) <=> parseTs($a['start_date'] ?? 0));
        dbg('trips for ' . ($fUser['username'] ?? $fUser['id']), count($fTrips));

        foreach (array_slice($fTrips, 0, 3) as $t) {
            if (!empty($t['id'])) {
                $followeeObj = current(array_filter($rawFollowees, fn($f) => is_array($f) && ($f['id'] ?? null) === $fUser['id']));
                $friendTrips[] = ['user' => $followeeObj ?: $fUser, 'trip' => $t];
            }
        }
    }

    usort($friendTrips, fn($a, $b) => parseTs($b['trip']['start_date'] ?? 0) <=> parseTs($a['trip']['start_date'] ?? 0));
}

// ── Activity feed ─────────────────────────────────────────────────────────────
$currentTrips  = [];
$upcomingTrips = [];
try {
    $feed          = $client->getActivityFeed();
    $currentTrips  = $feed['current_trips']  ?? [];
    $upcomingTrips = $feed['upcoming_trips'] ?? [];
    dbg('activityFeed OK', count($currentTrips) . ' current');
} catch (\Throwable $e) {
    dbg('activityFeed FAIL', $e->getMessage());
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$totalKm = array_sum(array_map(fn($t) => $t['total_km'] ?? 0, $myTrips));
?>
<?= htmlHead('Mes voyages') ?>
<?= htmlNav($me) ?>

<?php if ($error): ?>
<div class="max-w-lg mx-auto mt-16 text-center px-4">
  <div class="bg-gray-900 rounded-2xl border border-red-900 shadow-sm p-8">
    <div class="text-4xl mb-4">😬</div>
    <h2 class="font-semibold text-gray-100 mb-2">Impossible de charger le profil</h2>
    <p class="text-sm text-gray-500 mb-6"><?= esc($error) ?></p>
    <a href="/logout.php" class="text-sm text-amber-500 hover:underline">Se reconnecter</a>
  </div>
</div>
<?php else: ?>

<!-- ── Profile hero ── -->
<?php
$avatar   = $me['profile_image_path'] ?? $me['profile_image_thumb_path'] ?? null;
$name     = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
$username = $me['username'] ?? '';
$desc     = $me['description'] ?? '';
$stats    = $me['stats'] ?? [];
?>
<div class="bg-gray-900 border-b border-gray-800">
  <div class="max-w-7xl mx-auto px-4 py-6 flex items-center gap-4">
    <?php if ($avatar): ?>
    <img src="<?= esc($avatar) ?>" alt="" class="w-14 h-14 rounded-full object-cover border-2 border-amber-400 flex-shrink-0">
    <?php else: ?>
    <div class="w-14 h-14 rounded-full bg-amber-400 flex items-center justify-center text-2xl text-white font-bold flex-shrink-0">
      <?= mb_strtoupper(mb_substr($name ?: '?', 0, 1)) ?>
    </div>
    <?php endif; ?>
    <div class="flex-1 min-w-0">
      <h1 class="text-xl font-bold text-gray-50"><?= esc($name) ?></h1>
      <?php if ($username): ?><p class="text-sm text-gray-400">@<?= esc($username) ?></p><?php endif; ?>
      <?php if ($desc): ?><p class="text-sm text-gray-500 mt-0.5 truncate"><?= esc($desc) ?></p><?php endif; ?>
    </div>
    <div class="hidden sm:flex gap-6 flex-shrink-0 text-center">
      <div>
        <div class="text-xl font-bold text-amber-500"><?= (int)($stats['trip_count'] ?? count($myTrips)) ?></div>
        <div class="text-xs text-gray-400">Voyages</div>
      </div>
      <div>
        <div class="text-xl font-bold text-amber-500"><?= number_format((int) round($stats['km_count'] ?? $totalKm)) ?></div>
        <div class="text-xs text-gray-400">km</div>
      </div>
      <?php if (!empty($rawFollowees)): ?>
      <div>
        <div class="text-xl font-bold text-amber-500"><?= count($rawFollowees) ?></div>
        <div class="text-xs text-gray-400">Amis</div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Content ── -->
<div class="max-w-7xl mx-auto px-4 py-6 space-y-10">

  <!-- Activity feed: voyages en cours -->
  <?php if (!empty($currentTrips) || !empty($upcomingTrips)): ?>
  <section>
    <h2 class="text-lg font-bold text-gray-100 mb-4 flex items-center gap-2">
      <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse inline-block"></span> Voyages en cours
    </h2>
    <div class="space-y-2">
      <?php foreach (array_merge($currentTrips, $upcomingTrips) as $item):
        $trip     = $item['trip'] ?? [];
        $tripId   = $trip['id']   ?? null;
        if (!$tripId) continue;
        $tripName = $trip['display_name'] ?? $trip['name'] ?? 'Voyage';
        $summary  = $trip['summary'] ?? '';
        $user     = $trip['user']   ?? [];
        $uName    = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['username'] ?? '');
        $avatar   = $user['profile_image_thumb_path'] ?? $user['profile_image_path'] ?? null;
        $cover    = $trip['cover_photo']['large_thumbnail_path'] ?? $trip['cover_photo_path'] ?? null;
        $totalKmTrip = (int) round($trip['total_km'] ?? 0);
        $stepCount   = $trip['step_count'] ?? 0;
        $lastUpdate  = $item['last_update'] ?? null;
        $locality    = $item['map_step_locality'] ?? null;
        $cc          = $item['map_step_country_code'] ?? null;
        // Co-travelers
        $buddies = array_filter(
            array_map(fn($b) => $b['buddy'] ?? null, $trip['trip_buddies'] ?? []),
            fn($b) => $b && ($b['id'] ?? null) !== ($user['id'] ?? null)
        );
      ?>
      <a href="/trip.php?id=<?= (int)$tripId ?>" class="flex items-center gap-4 bg-gray-900 rounded-2xl border border-gray-800 px-4 py-3 hover:border-amber-200 hover:shadow-sm transition-all group">
        <!-- Cover -->
        <?php if ($cover): ?>
        <img src="<?= esc($cover) ?>" alt="" class="w-16 h-16 rounded-xl object-cover flex-shrink-0 opacity-90 group-hover:opacity-100 transition-opacity">
        <?php else: ?>
        <div class="w-16 h-16 rounded-xl bg-amber-900 flex items-center justify-center text-2xl flex-shrink-0">🗺️</div>
        <?php endif; ?>

        <!-- Info -->
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-gray-50 truncate group-hover:text-amber-600 transition-colors"><?= esc($tripName) ?></p>

          <!-- Authors -->
          <div class="flex items-center gap-1.5 mt-0.5">
            <?php if ($avatar): ?>
            <img src="<?= esc($avatar) ?>" alt="" class="w-4 h-4 rounded-full object-cover border border-gray-700">
            <?php endif; ?>
            <?php foreach ($buddies as $buddy): ?>
              <?php if ($ba = $buddy['profile_image_thumb_path'] ?? $buddy['profile_image_path'] ?? null): ?>
              <img src="<?= esc($ba) ?>" alt="" class="w-4 h-4 rounded-full object-cover border border-gray-700 -ml-1">
              <?php endif; ?>
            <?php endforeach; ?>
            <span class="text-xs text-gray-400 truncate ml-0.5"><?= esc($uName) ?></span>
          </div>

          <!-- Current position + stats -->
          <div class="flex items-center gap-2 mt-1 text-xs text-gray-400 flex-wrap">
            <?php if ($locality && $cc): ?>
            <span><?= flag($cc) ?> <?= esc($locality) ?></span>
            <span class="text-gray-600">·</span>
            <?php endif; ?>
            <span><?= number_format($totalKmTrip) ?> km</span>
            <span class="text-gray-600">·</span>
            <span><?= $stepCount ?> étapes</span>
            <?php if ($lastUpdate): ?>
            <span class="text-gray-600">·</span>
            <span>màj <?= esc(fmtDate(strtotime($lastUpdate))) ?></span>
            <?php endif; ?>
          </div>
        </div>

        <svg class="w-4 h-4 text-gray-600 group-hover:text-amber-400 flex-shrink-0 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- My trips -->
  <?php if (!empty($myTrips)): ?>
  <section>
    <h2 class="text-lg font-bold text-gray-100 mb-4 flex items-center gap-2">
      <span>🗺️</span> Mes voyages
    </h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
      <?php foreach ($myTrips as $trip): ?>
        <?= renderTripCard($trip) ?>
      <?php endforeach; ?>
    </div>
  </section>
  <?php elseif (empty($error)): ?>
  <div class="text-center py-12 text-gray-400">
    <div class="text-4xl mb-2">🏕️</div>
    <p class="text-sm">Aucun voyage trouvé dans ton profil.</p>
  </div>
  <?php endif; ?>

  <!-- Friends' trips -->
  <?php if (!empty($friendTrips)): ?>
  <section>
    <h2 class="text-lg font-bold text-gray-100 mb-4 flex items-center gap-2">
      <span>👥</span> Voyages de mes amis
    </h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
      <?php foreach ($friendTrips as ['user' => $fUser, 'trip' => $fTrip]): ?>
        <?= renderTripCard($fTrip, $fUser) ?>
      <?php endforeach; ?>
    </div>
  </section>
  <?php elseif (!empty($rawFollowees)): ?>
  <div class="text-center py-8 text-gray-400">
    <div class="text-3xl mb-2">👥</div>
    <p class="text-sm">Tes amis n'ont pas encore de voyages.</p>
  </div>
  <?php endif; ?>

</div>
<?php endif; ?>

<!-- ── Debug panel ── -->
<div class="fixed bottom-4 right-4 z-50">
  <button onclick="this.nextElementSibling.classList.toggle('hidden')"
    class="bg-gray-800 text-gray-300 text-xs px-3 py-1.5 rounded-lg shadow-lg hover:bg-gray-700 transition-colors">
    🐛 Debug
  </button>
  <div class="hidden absolute bottom-8 right-0 w-96 max-h-80 overflow-y-auto bg-gray-900 rounded-xl shadow-2xl border border-gray-700 text-xs font-mono">
    <div class="sticky top-0 bg-gray-800 px-3 py-2 text-gray-400 font-sans font-semibold text-[11px]">Journal de bord</div>
    <?php foreach ($debugLog as $entry): ?>
    <div class="px-3 py-1.5 border-b border-gray-800 flex gap-2">
      <span class="text-gray-500 flex-shrink-0"><?= esc($entry['time']) ?></span>
      <span class="<?= str_contains($entry['label'], 'FAIL') ? 'text-red-400' : 'text-green-400' ?> flex-shrink-0"><?= esc($entry['label']) ?></span>
      <?php if ($entry['value'] !== null): ?>
      <span class="text-gray-400 truncate"><?= esc(is_array($entry['value']) ? json_encode($entry['value']) : (string)$entry['value']) ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

</body>
</html>
