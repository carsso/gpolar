<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/helpers.php';

use GPolar\PolarstepsClient;

$token  = requireAuth();
$tripId = $_GET['id'] ?? null;

if (!$tripId || !ctype_digit((string) $tripId)) {
    header('Location: /');
    exit;
}

$client   = new PolarstepsClient($token);
$trip     = [];
$error    = null;
$debugLog = [];
function dbg(string $label, mixed $value = null): void {
    global $debugLog;
    $debugLog[] = ['label' => $label, 'value' => $value, 'time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000) . 'ms'];
}

try {
    $trip = $client->getTrip($tripId);
    dbg('getTrip() OK', count($trip['steps'] ?? []) . ' steps');
} catch (\Throwable $e) {
    $error = $e->getMessage();
    dbg('getTrip() FAIL', $e->getMessage());
}

// ── Data processing ───────────────────────────────────────────────────────────
$steps = $trip['steps'] ?? $trip['all_steps'] ?? [];
dbg('steps raw', count($steps));

$visibleSteps = 0;
$sortOrder    = $_GET['order'] ?? 'asc'; // asc = oldest first (default)

// Build users map (UUID → profile) for resolving likes
$usersMap = [];
foreach ($trip['users'] ?? [] as $u) {
    if (!empty($u['uuid'])) $usersMap[$u['uuid']] = $u;
}
// Assign permanent chronological step numbers before display sort
usort($steps, fn($a, $b) => parseTs($a['start_time']) <=> parseTs($b['start_time']));
foreach ($steps as $chrono => &$step) { $step['_num'] = $chrono + 1; }
unset($step);
$stepsChronological = $steps; // kept for map — always start→end regardless of display order

if ($sortOrder === 'desc') {
    usort($steps, fn($a, $b) => parseTs($b['start_time']) <=> parseTs($a['start_time']));
}

$user       = $trip['user'] ?? [];
$tripStart  = parseTs($trip['start_date'] ?? 0);
$totalKm    = (int) round($trip['total_km'] ?? 0);
$ongoing    = empty($trip['end_date']);
$tripName   = $trip['name'] ?? 'Voyage';
$summary    = $trip['summary'] ?? '';
$coverPhoto = $trip['cover_photo_path']
           ?? $trip['cover_photo']['large_thumbnail_path']
           ?? '';
$userName   = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$userAvatar = $user['profile_image_path'] ?? $user['profile_image_thumb_path'] ?? null;
$likeCount  = $trip['like_count'] ?? 0;

// Countries: extract unique country codes from steps (visited_countries no longer in API)
$countries = array_values(array_unique(array_filter(
    array_map(fn($s) => $s['location']['country_code'] ?? '', $steps)
)));

// Trip participants: owner + buddies
$participants = [$user];
foreach ($trip['trip_buddies'] ?? [] as $b) {
    if (!empty($b['buddy']) && ($b['status'] ?? '') === 'accepted') {
        $participants[] = $b['buddy'];
    }
}

// Logged-in user for nav
$me = [];
try { $me = $client->getMe(); } catch (\Throwable) {}

$latestStepTs = array_reduce($steps, fn($m, $s) => max($m, parseTs($s['start_time'])), 0);
$daysOnRoad   = ($latestStepTs && $tripStart) ? dayNum($latestStepTs, $tripStart) : 0;

// Distance from each step to the next (for connector line labels)
$distToNext = [];
$nextLat = $nextLon = null;
for ($j = count($steps) - 1; $j >= 0; $j--) {
    $lat = $steps[$j]['location']['lat'] ?? null;
    $lon = $steps[$j]['location']['lon'] ?? null;
    if ($lat !== null && $nextLat !== null) {
        $distToNext[$j] = haversineKm((float)$lat, (float)$lon, (float)$nextLat, (float)$nextLon);
    }
    if ($lat !== null) { $nextLat = $lat; $nextLon = $lon; }
}

// Photos per step (for JS lightbox)
$stepPhotosJs = [];
foreach ($steps as $i => $step) {
    $thumbs  = [];
    $fullRes = [];
    foreach ($step['media'] ?? [] as $m) {
        if (($m['is_deleted'] ?? false) || empty($m['large_thumbnail_path'])) continue;
        $thumbs[]  = $m['large_thumbnail_path'];
        $fullRes[] = $m['path'] ?: ($m['cdn_path'] ?: $m['large_thumbnail_path']);
    }
    $stepPhotosJs[$i] = ['thumbs' => $thumbs, 'full' => $fullRes];
}

// Map data — always chronological so bikes always go start→end
$mapSteps  = [];
$stepCoords = [];

foreach ($stepsChronological as $i => $step) {
    $lat = $step['location']['lat'] ?? null;
    $lon = $step['location']['lon'] ?? null;
    if (!$lat || !$lon) continue;
    $stepCoords[] = [(float)$lat, (float)$lon];
    $desc      = trim($step['description'] ?? '');
    $hasPhotos = !empty(array_filter($step['media'] ?? [], fn($m) => !($m['is_deleted'] ?? false) && !empty($m['large_thumbnail_path'])));
    if ($desc || $hasPhotos) {
        $mapSteps[] = ['i' => $i, 'name' => $step['display_name'] ?? $step['name'] ?? '', 'lat' => $lat, 'lon' => $lon];
    }
}

// Initial route: straight lines between steps, JS will upgrade to road routing via OSRM
$mapRoute = $stepCoords;

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<?= htmlHead(esc($tripName), withLeaflet: true) ?>
<?= htmlNav($me, backUrl: '/') ?>

<?php if ($error): ?>
<div class="max-w-lg mx-auto mt-16 text-center px-4">
  <div class="bg-gray-900 rounded-2xl border border-red-900 shadow-sm p-8">
    <div class="text-4xl mb-4">🚴</div>
    <h2 class="font-semibold text-gray-100 mb-2">Impossible de charger le voyage</h2>
    <p class="text-sm text-gray-500"><?= esc($error) ?></p>
  </div>
</div>
<?php else: ?>

<!-- ── Hero ── -->
<div class="relative h-36 sm:h-48 bg-gray-700 bg-cover bg-center overflow-hidden"
     style="<?= $coverPhoto ? 'background-image:url(' . esc($coverPhoto) . ')' : '' ?>">
  <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
  <div class="absolute bottom-0 left-0 right-0 p-5 sm:p-8">
    <?php if (!empty($participants)): ?>
    <div class="flex items-center gap-2 mb-2">
      <div class="flex -space-x-1.5">
        <?php foreach ($participants as $p):
          $pa = $p['profile_image_thumb_path'] ?? $p['profile_image_path'] ?? null;
          $pn = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
        ?>
        <?php if ($pa): ?>
        <img src="<?= esc($pa) ?>" alt="<?= esc($pn) ?>" title="<?= esc($pn) ?>"
             class="w-7 h-7 rounded-full border-2 border-white/40 object-cover">
        <?php else: ?>
        <div class="w-7 h-7 rounded-full border-2 border-white/40 bg-amber-400 flex items-center justify-center text-[11px] font-bold text-white">
          <?= mb_strtoupper(mb_substr($pn ?: '?', 0, 1)) ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <span class="text-white/75 text-sm"><?= esc(implode(' & ', array_map(fn($p) => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')), $participants))) ?></span>
    </div>
    <?php endif; ?>
    <h1 class="text-2xl sm:text-4xl font-bold text-white leading-tight"><?= esc($tripName) ?></h1>
    <?php if ($summary): ?>
    <p class="text-white/65 text-sm mt-1.5 max-w-2xl leading-relaxed"><?= esc($summary) ?></p>
    <?php endif; ?>
  </div>
  <?php if ($ongoing): ?>
  <div class="absolute top-4 right-4 flex items-center gap-1.5 bg-red-500 text-white text-[11px] font-bold px-2.5 py-1 rounded-full">
    <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span> En cours
  </div>
  <?php endif; ?>
</div>

<!-- ── Stats bar ── -->
<div class="bg-gray-900 border-b border-gray-800 shadow-sm sticky top-12 z-20">
  <div class="max-w-7xl mx-auto px-4">
    <div class="grid grid-cols-4 divide-x divide-gray-800">
      <?php
      $stats = [
        [number_format($totalKm), 'km'],
        [count($steps), 'Étapes'],
        [$daysOnRoad . ($ongoing ? '+' : ''), 'Jours'],
        [implode(' ', array_map(fn($c) => flag($c), $countries)), count($countries) . ' Pays'],
      ];
      foreach ($stats as [$val, $label]):
      ?>
      <div class="py-2.5 px-2 text-center">
        <div class="text-base sm:text-xl font-bold text-amber-500"><?= $val ?></div>
        <div class="text-[10px] text-gray-500 uppercase tracking-widest"><?= $label ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ── Main layout ── -->
<div class="max-w-7xl mx-auto px-4 py-6">
  <div class="flex flex-col lg:flex-row gap-6">

    <!-- Steps timeline -->
    <div class="lg:w-3/5 space-y-0" id="steps-container">
      <?php
        $toggleUrl = '/trip/' . (int)$tripId . '?order=' . ($sortOrder === 'asc' ? 'desc' : 'asc');
      ?>
      <?php
      $prevDay = null;
      foreach ($steps as $i => $step):
        $dayN    = $tripStart ? dayNum(parseTs($step['start_time']), $tripStart) : ($i + 1);
        $isLast  = $i === array_key_last($steps);
        $photos  = $stepPhotosJs[$i]['thumbs'] ?? [];
        $loc     = $step['location'] ?? [];
        $cc      = $loc['country_code'] ?? '';
        $desc    = trim($step['description'] ?? '');
        $wTemp   = $step['weather_temperature'] ?? null;
        $wCond   = $step['weather_condition']   ?? '';
        $showDay = $dayN !== $prevDay;
        $prevDay = $dayN;
        $likes    = count($step['user_likes'] ?? []);
        $comments = $step['comment_count'] ?? 0;
        $stepId   = $step['id'] ?? null;
      ?>

      <?php if ($showDay): ?>
      <div class="day-sep flex items-center gap-3 pt-<?= $i === 0 ? '2' : '6' ?> pb-3 px-1">
        <div class="h-px flex-1 bg-gray-700"></div>
        <a href="<?= esc($toggleUrl) ?>" class="flex items-center gap-1 text-xs font-semibold text-gray-500 hover:text-amber-500 uppercase tracking-widest whitespace-nowrap transition-colors" title="Inverser l'ordre">
          Jour <?= $dayN ?> · <?= fmtDate(parseTs($step['start_time'])) ?>
          <svg class="w-3 h-3 opacity-40 <?= $sortOrder === 'desc' ? 'rotate-180' : '' ?>" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </a>
        <div class="h-px flex-1 bg-gray-700"></div>
      </div>
      <?php endif; ?>

      <?php if (!$desc && empty($photos)): continue; endif; $visibleSteps++; ?>

      <!-- Full step card -->
      <div class="step-card group relative flex gap-3 pb-6 px-1"
           id="step-<?= $step['_num'] - 1 ?>" data-step="<?= $step['_num'] - 1 ?>">

        <!-- Dot + line -->
        <div class="flex-shrink-0 flex flex-col items-center" style="width:38px">
          <button
            onclick="flyToStep(<?= $step['_num'] - 1 ?>)"
            class="w-9 h-9 rounded-full bg-amber-500 flex items-center justify-center shadow ring-4 ring-gray-900 z-10 transition-transform group-hover:scale-110 text-white text-xs font-bold"
          ><?= $step['_num'] ?></button>
          <?php if (!$isLast): ?>
          <div class="relative flex flex-col items-center mt-2 flex-1 min-h-8">
            <div class="w-px flex-1 bg-gradient-to-b from-amber-400 to-amber-300/50"></div>
            <?php if (isset($distToNext[$i]) && $distToNext[$i] > 0.3): ?>
            <span class="text-[10px] text-amber-500 tabular-nums leading-none py-1"><?= round($distToNext[$i]) ?>km</span>
            <?php endif; ?>
            <div class="w-px flex-1 bg-gradient-to-b from-amber-300/50 to-amber-200"></div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Card -->
        <div class="flex-1 bg-gray-900 rounded-2xl shadow-sm border border-gray-800 overflow-hidden hover:shadow-md transition-shadow min-w-0">

          <!-- Header -->
          <div class="flex items-start justify-between p-4 pb-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <h2 class="text-sm font-semibold text-gray-50 leading-tight">
                  <?= esc($step['display_name'] ?? $step['name'] ?? '') ?>
                </h2>
                <?php if ($cc): ?>
                <span title="<?= esc($loc['full_detail'] ?? $cc) ?>"><?= flag($cc) ?></span>
                <?php endif; ?>
              </div>
              <div class="text-xs text-gray-500 mt-0.5 truncate"><?= esc($loc['full_detail'] ?? '') ?></div>
            </div>
            <?php if ($wTemp !== null): ?>
            <div class="flex-shrink-0 flex flex-col items-center bg-gray-800 rounded-xl px-2.5 py-1.5 ml-2 text-center">
              <span class="text-lg leading-none"><?= weatherIcon($wCond) ?></span>
              <span class="text-xs font-semibold text-gray-400 mt-0.5"><?= (int) $wTemp ?>°C</span>
            </div>
            <?php endif; ?>
          </div>

          <!-- Photos -->
          <?php if (!empty($photos)): ?>
          <div class="flex gap-2 overflow-x-auto photo-scroll px-4 pb-3">
            <?php foreach ($photos as $j => $thumb): ?>
            <img src="<?= esc($thumb) ?>"
                 class="h-32 sm:h-40 w-auto flex-shrink-0 rounded-xl object-cover cursor-pointer hover:opacity-90 transition-opacity"
                 loading="lazy"
                 onclick="openLightbox(<?= $i ?>, <?= $j ?>)"
                 alt="">
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- Description -->
          <?php if ($desc): ?>
          <div class="px-4 pb-4">
            <p class="text-sm text-gray-300 leading-relaxed whitespace-pre-line"><?= esc($desc) ?></p>
          </div>
          <?php endif; ?>

          <!-- Footer: likes + comments -->
          <?php if ($likes > 0 || $comments > 0): ?>
          <div class="flex items-center gap-4 px-4 py-2 border-t border-gray-800 text-gray-400">
            <?php if ($likes > 0): ?>
            <?php
              $likerProfiles = array_values(array_filter(
                  array_map(fn($uuid) => $usersMap[$uuid] ?? null, $step['user_likes'] ?? [])
              ));
            ?>
            <div class="relative">
              <button onclick="toggleLikers(this)"
                class="likers-btn text-xs flex items-center gap-1 hover:text-amber-500 transition-colors">
                <svg class="w-3 h-3 fill-current" viewBox="0 0 20 20"><path d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z"/></svg>
                <?= $likes ?>
              </button>
              <div class="likers-popup hidden absolute bottom-6 left-0 bg-gray-800 border border-gray-700 rounded-xl shadow-xl p-3 z-20 w-max max-w-56 space-y-2">
                <?php foreach ($likerProfiles as $liker):
                  $ln = trim(($liker['first_name'] ?? '') . ' ' . ($liker['last_name'] ?? '')) ?: ($liker['username'] ?? '?');
                  $la = $liker['profile_image_thumb_path'] ?? $liker['profile_image_path'] ?? null;
                ?>
                <div class="flex items-center gap-2">
                  <?php if ($la): ?>
                  <img src="<?= esc($la) ?>" class="w-5 h-5 rounded-full object-cover flex-shrink-0">
                  <?php else: ?>
                  <div class="w-5 h-5 rounded-full bg-amber-400 flex items-center justify-center text-[9px] font-bold text-white flex-shrink-0"><?= mb_strtoupper(mb_substr($ln, 0, 1)) ?></div>
                  <?php endif; ?>
                  <span class="text-xs text-gray-300"><?= esc($ln) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (count($likerProfiles) < $likes): ?>
                <p class="text-[10px] text-gray-500">+<?= $likes - count($likerProfiles) ?> autres</p>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>
            <?php if ($comments > 0 && $stepId): ?>
            <button
              onclick="loadComments(this, <?= (int)$stepId ?>)"
              class="text-xs flex items-center gap-1 hover:text-amber-500 transition-colors"
            >
              <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
              </svg>
              <?= $comments ?> commentaire<?= $comments > 1 ? 's' : '' ?>
            </button>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <!-- Comments zone (lazy loaded) -->
          <?php if ($comments > 0 && $stepId): ?>
          <div id="comments-<?= (int)$stepId ?>" class="hidden px-4 pb-4 space-y-3 border-t border-gray-800 pt-3"></div>
          <?php endif; ?>

        </div>
      </div>

      <?php endforeach; ?>

      <?php if ($likeCount > 0): ?>
      <div class="text-center pt-2 pb-4 text-xs text-gray-400">
        ❤️ <?= $likeCount ?> personnes aiment ce voyage
      </div>
      <?php endif; ?>
    </div>

    <!-- Map -->
    <div class="lg:w-2/5">
      <div class="sticky top-28">
        <div id="map" class="w-full rounded-2xl overflow-hidden shadow-sm border border-gray-800"
             style="height:calc(100vh - 8rem); min-height:380px; max-height:780px"></div>
      </div>
    </div>

  </div>
</div>

<!-- Lightbox -->
<div id="lightbox" class="fixed inset-0 bg-black/92 z-50 flex items-center justify-center" onclick="closeLightbox()">
  <button class="absolute top-4 right-5 text-white/60 hover:text-white text-3xl" onclick="closeLightbox()">✕</button>
  <button class="absolute left-2 sm:left-5 text-white/60 hover:text-white text-5xl px-2 py-6"
          onclick="event.stopPropagation(); lightboxNav(-1)">‹</button>
  <img id="lightbox-img" src="" alt=""
       class="max-h-screen max-w-full object-contain px-14 select-none"
       onclick="event.stopPropagation()"
       onerror="this.style.display='none';document.getElementById('lightbox-err').classList.remove('hidden')"
       onload="this.style.display='';document.getElementById('lightbox-err').classList.add('hidden')">
  <div id="lightbox-err" class="hidden text-center px-14">
    <div class="text-4xl mb-3">🚫</div>
    <p class="text-white/50 text-sm">Image non disponible</p>
  </div>
  <a id="lightbox-dl" href="" download target="_blank" onclick="event.stopPropagation()"
     class="absolute top-4 left-5 text-white/40 hover:text-white transition-colors"
     title="Télécharger l'original">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
    </svg>
  </a>
  <button class="absolute right-2 sm:right-5 text-white/60 hover:text-white text-5xl px-2 py-6"
          onclick="event.stopPropagation(); lightboxNav(1)">›</button>
  <div id="lightbox-counter" class="absolute bottom-4 text-white/40 text-sm"></div>
</div>

<?php endif; ?>

<script>
const STEP_PHOTOS = <?= json_encode($stepPhotosJs, $jsonFlags) ?>;
const MAP_STEPS   = <?= json_encode($mapSteps,    $jsonFlags) ?>;
const MAP_ROUTE   = <?= json_encode($mapRoute,    $jsonFlags) ?>;

<?php if (!$error && !empty($mapRoute)): ?>
// ── Map ───────────────────────────────────────────────────────────────────────
const map = L.map('map');
L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
  attribution: '© <a href="https://www.esri.com/">Esri</a>, Maxar, Earthstar Geographics',
  maxZoom: 19,
  className: 'map-sat',
}).addTo(map);
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_only_labels/{z}/{x}/{y}{r}.png', {
  attribution: '',
  maxZoom: 19,
  pane: 'shadowPane',
}).addTo(map);

const routeLine = L.polyline(MAP_ROUTE, { color: '#f59e0b', weight: 3, opacity: 0.5, dashArray: '6 6' }).addTo(map);

// Animate bikes along the route in a loop, 33% apart
function startBikeAnimation(latlngs) {
  if (latlngs.length < 2) return;
  const TARGET_PX_S = 80;  // screen pixels per second — constant regardless of zoom
  const OFFSET_PX   = 14;
  const LOOKAHEAD   = 20;

  // Pre-simplify: keep only points >= 0.003° apart to remove micro-jitter
  const route = [latlngs[0]];
  for (const p of latlngs) {
    const last = route[route.length - 1];
    if (Math.hypot(p[0]-last[0], p[1]-last[1]) >= 0.003) route.push(p);
  }
  if (route.length < 2) return;

  const svgBike = `<svg viewBox="0 0 30 18" width="22" height="13" xmlns="http://www.w3.org/2000/svg">
    <g fill="none" stroke="#38bdf8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="5" cy="13" r="4.5"/>
      <circle cx="25" cy="13" r="4.5"/>
      <polyline points="5,13 13,13 12,6 5,13"/>
      <polyline points="12,6 20,6 13,13"/>
      <line x1="20" y1="6" x2="25" y2="13"/>
      <line x1="12" y1="6" x2="12" y2="3"/>
      <line x1="10" y1="3" x2="14" y2="3"/>
      <line x1="20" y1="6" x2="21" y2="3"/>
      <line x1="19" y1="3" x2="23" y2="3"/>
    </g>
  </svg>`;

  // Pixel-simplified route and segment lengths — rebuilt on every zoom
  const MIN_SEG_PX = 4;
  let pxRoute    = route.slice();
  let segPxLens  = [];
  let totalPxLen = 1;

  function rebuildPxRoute() {
    // Keep only points >= MIN_SEG_PX apart in screen space
    pxRoute = [route[0]];
    let lastP = map.latLngToContainerPoint(L.latLng(route[0][0], route[0][1]));
    for (let i = 1; i < route.length; i++) {
      const p = map.latLngToContainerPoint(L.latLng(route[i][0], route[i][1]));
      if (Math.hypot(p.x - lastP.x, p.y - lastP.y) >= MIN_SEG_PX) {
        pxRoute.push(route[i]);
        lastP = p;
      }
    }
    if (pxRoute[pxRoute.length - 1] !== route[route.length - 1])
      pxRoute.push(route[route.length - 1]);
    // Recompute segment lengths on the simplified route
    segPxLens  = [];
    totalPxLen = 0;
    for (let i = 0; i < pxRoute.length - 1; i++) {
      const p1 = map.latLngToContainerPoint(L.latLng(pxRoute[i][0],   pxRoute[i][1]));
      const p2 = map.latLngToContainerPoint(L.latLng(pxRoute[i+1][0], pxRoute[i+1][1]));
      const d  = Math.hypot(p2.x - p1.x, p2.y - p1.y);
      segPxLens.push(d);
      totalPxLen += d;
    }
    if (totalPxLen === 0) totalPxLen = 1;
  }

  function updateBikeCount() {
    const n = Math.max(1, Math.min(6, Math.floor(totalPxLen / 160)));
    bikes.forEach((b, idx) => {
      b.active = idx < n;
      if (b.active) b.phase = idx / n;  // redistribute evenly, no gap
      b.marker.setOpacity(b.active ? 1 : 0);
    });
  }

  rebuildPxRoute();
  map.on('zoom', () => { rebuildPxRoute(); updateBikeCount(); });

  // Position along pxRoute by pixel offset; returns {latlng:[lat,lon], i:segIdx}
  function atPx(px) {
    const len = totalPxLen;
    px = ((px % len) + len) % len;
    let acc = 0;
    for (let i = 0; i < segPxLens.length; i++) {
      const d = segPxLens[i];
      if (px <= acc + d) {
        const t = (px - acc) / d;
        return { latlng: [pxRoute[i][0] + t*(pxRoute[i+1][0]-pxRoute[i][0]),
                           pxRoute[i][1] + t*(pxRoute[i+1][1]-pxRoute[i][1])], i };
      }
      acc += d;
    }
    return { latlng: pxRoute[pxRoute.length - 1], i: pxRoute.length - 2 };
  }

  // Find the first pxRoute point >= LOOKAHEAD px away (adapts to zoom)
  function nextStablePoint(posLL, fromIdx) {
    const p0 = map.latLngToContainerPoint(L.latLng(posLL[0], posLL[1]));
    for (let j = fromIdx + 1; j < pxRoute.length; j++) {
      const p = map.latLngToContainerPoint(L.latLng(pxRoute[j][0], pxRoute[j][1]));
      if (Math.hypot(p.x - p0.x, p.y - p0.y) >= LOOKAHEAD) return pxRoute[j];
    }
    return pxRoute[pxRoute.length - 1];
  }

  // Compute lateral offset position and rotation angle in px space
  function computePosAngle(posLL, nextLL, side) {
    const p1  = map.latLngToContainerPoint(L.latLng(posLL[0], posLL[1]));
    const p2  = map.latLngToContainerPoint(L.latLng(nextLL[0], nextLL[1]));
    const dx  = p2.x - p1.x, dy = p2.y - p1.y;
    const len = Math.sqrt(dx*dx + dy*dy) || 1;
    const off = map.containerPointToLatLng(L.point(p1.x + (-dy/len)*OFFSET_PX*side, p1.y + (dx/len)*OFFSET_PX*side));
    return { latlng: off, angle: Math.atan2(dy, dx) * 180 / Math.PI };
  }

  function bikeTransform(angle, flipY) {
    let t;
    if (Math.abs(angle) > 90) {
      const a = angle > 0 ? -(angle - 180) : -(angle + 180);
      t = `scaleX(-1) rotate(${a.toFixed(1)}deg)`;
    } else {
      t = `rotate(${angle.toFixed(1)}deg)`;
    }
    if (flipY) t += ' scaleY(-1)';
    return t;
  }

  const bikeConfigs = [
    { phase: 0/6, side: +1 }, { phase: 1/6, side: -1 },
    { phase: 2/6, side: +1 }, { phase: 3/6, side: -1 },
    { phase: 4/6, side: +1 }, { phase: 5/6, side: -1 },
  ];

  const bikes = bikeConfigs.map(({ phase, side }) => {
    const el = document.createElement('div');
    el.style.cssText = 'user-select:none;pointer-events:none;transform-origin:center;line-height:0';
    el.innerHTML = svgBike;
    const marker = L.marker(route[0], {
      icon: L.divIcon({ className: '', html: el, iconSize: [22, 13], iconAnchor: [11, 6] }),
      interactive: false, zIndexOffset: 500
    }).addTo(map);
    return { marker, el, phase, side, active: true };
  });
  updateBikeCount();

  let lastTs   = null;
  let pxOffset = 0;
  function tick(ts) {
    if (lastTs !== null) {
      // Cap speed so one full loop takes at least 12 seconds
      const speed = Math.min(TARGET_PX_S, totalPxLen / 12);
      pxOffset += speed * (ts - lastTs) / 1000;
    }
    lastTs = ts;
    const len = totalPxLen || 1;
    for (const b of bikes) {
      if (!b.active) continue;
      const bPx                = (pxOffset + b.phase * len) % len;
      const { latlng: pos, i } = atPx(bPx);
      const next               = nextStablePoint(pos, i);
      const { latlng, angle }  = computePosAngle(pos, next, b.side);
      b.marker.setLatLng(latlng);
      const belowRoute = Math.abs(angle) < 90 ? (b.side === 1) : (b.side === -1);
      b.el.style.transform = bikeTransform(angle, belowRoute);
    }
    requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);
}

// Upgrade to road routing via OSRM then start bike animation
(async () => {
  const waypoints = MAP_STEPS.map(s => [s.lat, s.lon]);
  if (waypoints.length < 2) { startBikeAnimation(MAP_ROUTE); return; }
  const routed = [];
  for (let i = 0; i < waypoints.length - 1; i++) {
    const [la, lo] = waypoints[i];
    const [lb, lr] = waypoints[i + 1];
    try {
      const res  = await fetch(`https://router.project-osrm.org/route/v1/bike/${lo},${la};${lr},${lb}?overview=full&geometries=geojson`);
      const data = await res.json();
      const coords = data?.routes?.[0]?.geometry?.coordinates;
      if (coords) coords.forEach(([lon, lat]) => routed.push([lat, lon]));
    } catch {}
  }
  if (routed.length) routeLine.setLatLngs(routed).setStyle({ opacity: 0.8, dashArray: null });
  startBikeAnimation(routed.length ? routed : MAP_ROUTE);
})();

const markers = MAP_STEPS.map((step, idx) => {
  const isLast = idx === MAP_STEPS.length - 1;
  const size   = isLast ? 14 : 11;
  const color  = isLast ? '#ef4444' : '#f59e0b';
  const icon   = L.divIcon({
    className: '',
    iconSize: [size, size], iconAnchor: [size / 2, size / 2],
    html: `<div style="width:${size}px;height:${size}px;background:${color};border-radius:50%;border:2.5px solid white;box-shadow:0 1px 5px rgba(0,0,0,.35)"></div>`,
  });
  return L.marker([step.lat, step.lon], { icon })
    .addTo(map)
    .bindPopup(`<strong style="font-size:13px">${step.name}</strong>`, { offset: [0, -4] })
    .on('click', () => scrollToStep(step.i));
});

map.fitBounds(MAP_ROUTE, { padding: [30, 30] });

function flyToStep(stepIdx) {
  const s = MAP_STEPS.find(s => s.i === stepIdx);
  if (!s) return;
  map.flyTo([s.lat, s.lon], 12, { duration: 0.8 });
  setTimeout(() => markers[MAP_STEPS.indexOf(s)].openPopup(), 900);
}
function scrollToStep(stepIdx) {
  document.getElementById(`step-${stepIdx}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Auto-highlight marker on scroll
const observer = new IntersectionObserver(entries => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      const idx = parseInt(e.target.dataset.step, 10);
      const s = MAP_STEPS.find(s => s.i === idx);
      if (s) markers[MAP_STEPS.indexOf(s)]?.openPopup();
    }
  });
}, { threshold: 0.6 });
document.querySelectorAll('[data-step]').forEach(el => observer.observe(el));

<?php else: ?>
document.getElementById('map').style.display = 'none';
<?php endif; ?>

// ── Likers popover ───────────────────────────────────────────────────────────
function toggleLikers(btn) {
  const popup = btn.nextElementSibling;
  const isOpen = !popup.classList.contains('hidden');
  document.querySelectorAll('.likers-popup').forEach(p => p.classList.add('hidden'));
  if (!isOpen) popup.classList.remove('hidden');
}
document.addEventListener('click', e => {
  if (!e.target.closest('.likers-popup') && !e.target.closest('.likers-btn')) {
    document.querySelectorAll('.likers-popup').forEach(p => p.classList.add('hidden'));
  }
});

// ── Comments ──────────────────────────────────────────────────────────────────
async function loadComments(btn, stepId) {
  const zone = document.getElementById(`comments-${stepId}`);
  if (!zone) return;

  if (!zone.classList.contains('hidden')) {
    zone.classList.add('hidden');
    return;
  }

  if (zone.dataset.loaded) {
    zone.classList.remove('hidden');
    return;
  }

  btn.classList.add('opacity-50', 'pointer-events-none');

  const res  = await fetch(`/api/comments?step_id=${stepId}`);
  const data = await res.json();

  btn.classList.remove('opacity-50', 'pointer-events-none');

  const comments = data.comments ?? data.items ?? data.results ?? data.all_comments ?? (Array.isArray(data) ? data : []);

  const reactionEmoji = { love: '❤️', like: '👍', haha: '😂', wow: '😮', sad: '😢', angry: '😠' };

  function renderComment(c, isReply = false) {
    const author   = c.user ?? {};
    const name     = [author.first_name, author.last_name].filter(Boolean).join(' ') || author.username || '?';
    const avatar   = author.profile_image_thumb_path ?? author.profile_image_path ?? null;
    const text     = c.text ?? '';
    const replies  = c.replies ?? [];
    const reactions = (c.reactions ?? []).map(r => reactionEmoji[r.reaction_type] ?? '❤️').join('');
    return `
      <div class="${isReply ? 'ml-7 border-l border-gray-700 pl-3 mt-2' : 'mt-0'} flex flex-col gap-1">
        <div class="flex gap-2 items-start">
          ${avatar
            ? `<img src="${avatar}" class="w-6 h-6 rounded-full object-cover flex-shrink-0 mt-0.5">`
            : `<div class="w-6 h-6 rounded-full bg-amber-300 flex items-center justify-center text-[10px] font-bold text-white flex-shrink-0 mt-0.5">${name[0].toUpperCase()}</div>`
          }
          <div class="flex-1 min-w-0">
            <span class="text-xs font-semibold text-gray-300">${name}</span>
            <p class="text-xs text-gray-400 leading-relaxed mt-0.5">${text}</p>
            ${reactions ? `<span class="text-xs mt-0.5 inline-block">${reactions}</span>` : ''}
          </div>
        </div>
        ${replies.map(r => renderComment(r, true)).join('')}
      </div>`;
  }

  if (!comments.length) {
    zone.innerHTML = '<p class="text-xs text-gray-400 italic">Aucun commentaire.</p>';
  } else {
    zone.innerHTML = comments.map(c => renderComment(c)).join('');
  }

  zone.dataset.loaded = '1';
  zone.classList.remove('hidden');
}

// ── Lightbox ──────────────────────────────────────────────────────────────────
let lbStep = 0, lbIdx = 0;

function openLightbox(step, idx) {
  lbStep = step; lbIdx = idx;
  renderLightbox();
  document.getElementById('lightbox').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeLightbox() {
  document.getElementById('lightbox').classList.remove('open');
  document.body.style.overflow = '';
}
function lightboxNav(dir) {
  const thumbs = STEP_PHOTOS[lbStep]?.thumbs ?? [];
  lbIdx = (lbIdx + dir + thumbs.length) % thumbs.length;
  renderLightbox();
}
function renderLightbox() {
  const thumbs = STEP_PHOTOS[lbStep]?.thumbs ?? [];
  const full   = STEP_PHOTOS[lbStep]?.full   ?? [];
  const src = thumbs[lbIdx] || '';
  if (!src) { closeLightbox(); return; }
  const img = document.getElementById('lightbox-img');
  img.style.display = '';
  document.getElementById('lightbox-err').classList.add('hidden');
  img.src = src;
  const dl = document.getElementById('lightbox-dl');
  dl.href = full[lbIdx] || src;
  dl.style.display = full[lbIdx] ? '' : 'none';
  document.getElementById('lightbox-counter').textContent =
    thumbs.length > 1 ? `${lbIdx + 1} / ${thumbs.length}` : '';
}
document.addEventListener('keydown', e => {
  if (!document.getElementById('lightbox').classList.contains('open')) return;
  if (e.key === 'Escape')     closeLightbox();
  if (e.key === 'ArrowLeft')  lightboxNav(-1);
  if (e.key === 'ArrowRight') lightboxNav(1);
});
</script>

<!-- ── Debug panel ── -->
<?php
dbg('visibleSteps', $visibleSteps);
dbg('mapSteps', count($mapSteps));
?>
<div class="konami-gate fixed bottom-4 right-4 z-50">
  <button onclick="this.nextElementSibling.classList.toggle('hidden')"
    class="bg-gray-800 text-gray-300 text-xs px-3 py-1.5 rounded-lg shadow-lg hover:bg-gray-700 transition-colors">
    🐛 Debug
  </button>
  <div class="hidden absolute bottom-8 right-0 w-96 max-h-80 overflow-y-auto bg-gray-900 rounded-xl shadow-2xl border border-gray-700 text-xs font-mono">
    <div class="sticky top-0 bg-gray-800 px-3 py-2 text-gray-400 font-sans font-semibold text-[11px]">Journal de bord — trip #<?= esc($tripId) ?></div>
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
