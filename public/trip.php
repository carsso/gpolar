<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/helpers.php';

use GPolar\PolarstepsClient;

$token  = requireAuth();
$tripId = $_GET['id'] ?? null;

if (!$tripId || !ctype_digit((string) $tripId)) {
    header('Location: /index.php');
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
    dbg('getTrip() OK', 'keys: ' . implode(', ', array_keys($trip)));
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
usort($steps, fn($a, $b) =>
    $sortOrder === 'desc'
        ? parseTs($b['start_time']) <=> parseTs($a['start_time'])
        : parseTs($a['start_time']) <=> parseTs($b['start_time'])
);

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

// Logged-in user for nav (fetch separately so nav shows ME, not the trip owner)
$me = [];
try { $me = $client->getMe(); } catch (\Throwable) {}

$latestStepTs = array_reduce($steps, fn($m, $s) => max($m, parseTs($s['start_time'])), 0);
$daysOnRoad   = ($latestStepTs && $tripStart) ? dayNum($latestStepTs, $tripStart) : 0;

// Photos per step (for JS lightbox)
$stepPhotosJs = [];
foreach ($steps as $i => $step) {
    $thumbs  = [];
    $fullRes = [];
    foreach ($step['media'] ?? [] as $m) {
        if (($m['is_deleted'] ?? false) || empty($m['large_thumbnail_path'])) continue;
        $thumbs[]  = $m['large_thumbnail_path'];
        $fullRes[] = $m['path'] ?? $m['cdn_path'] ?? $m['large_thumbnail_path'];
    }
    $stepPhotosJs[$i] = ['thumbs' => $thumbs, 'full' => $fullRes];
}

// Map data
$mapSteps = [];
foreach ($steps as $i => $step) {
    $lat  = $step['location']['lat'] ?? null;
    $lon  = $step['location']['lon'] ?? null;
    $desc = trim($step['description'] ?? '');
    $hasPhotos = !empty(array_filter($step['media'] ?? [], fn($m) => !($m['is_deleted'] ?? false) && !empty($m['large_thumbnail_path'])));
    if ($lat && $lon && ($desc || $hasPhotos)) {
        $mapSteps[] = ['i' => $i, 'name' => $step['display_name'] ?? $step['name'] ?? '', 'lat' => $lat, 'lon' => $lon];
    }
}

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<?= htmlHead(esc($tripName), withLeaflet: true) ?>
<?= htmlNav($me, backUrl: '/index.php') ?>

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
<div class="relative h-56 sm:h-72 md:h-88 bg-gray-700 bg-cover bg-center overflow-hidden"
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
<div class="bg-gray-900 border-b border-gray-800 shadow-sm sticky top-14 z-20">
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
        $toggleUrl   = '?id=' . (int)$tripId . '&order=' . ($sortOrder === 'asc' ? 'desc' : 'asc');
        $toggleLabel = $sortOrder === 'asc' ? 'Plus récent en premier' : 'Plus ancien en premier';
      ?>
      <div class="flex justify-end mb-3">
        <a href="<?= esc($toggleUrl) ?>"
          class="flex items-center gap-1.5 text-xs text-gray-400 hover:text-amber-600 transition-colors px-3 py-1.5 rounded-lg hover:bg-amber-950 border border-gray-800">
          <svg class="w-3.5 h-3.5 <?= $sortOrder === 'desc' ? '[transform:scaleY(-1)]' : '' ?>" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4h13M3 8h9M3 12h5m8 0l4-4m0 0l-4-4m4 4H11"/>
          </svg>
          <?= esc($toggleLabel) ?>
        </a>
      </div>
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
        <span class="text-xs font-semibold text-gray-500 uppercase tracking-widest whitespace-nowrap">
          Jour <?= $dayN ?> · <?= fmtDate(parseTs($step['start_time'])) ?>
        </span>
        <div class="h-px flex-1 bg-gray-700"></div>
      </div>
      <?php endif; ?>

      <?php if (!$desc && empty($photos)): continue; endif; $visibleSteps++; ?>

      <!-- Full step card -->
      <div class="step-card group relative flex gap-3 pb-6 px-1"
           id="step-<?= $i ?>" data-step="<?= $i ?>">

        <!-- Dot + line -->
        <div class="flex-shrink-0 flex flex-col items-center" style="width:38px">
          <button
            onclick="flyToStep(<?= $i ?>)"
            class="w-9 h-9 rounded-full bg-amber-500 flex items-center justify-center shadow ring-4 ring-gray-900 z-10 transition-transform group-hover:scale-110 text-white text-xs font-bold"
          ><?= $i + 1 ?></button>
          <?php if (!$isLast): ?>
          <div class="w-0.5 flex-1 bg-gradient-to-b from-amber-300 to-amber-100 mt-2 min-h-8"></div>
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
       onclick="event.stopPropagation()">
  <button class="absolute right-2 sm:right-5 text-white/60 hover:text-white text-5xl px-2 py-6"
          onclick="event.stopPropagation(); lightboxNav(1)">›</button>
  <div id="lightbox-counter" class="absolute bottom-4 text-white/40 text-sm"></div>
</div>

<?php endif; ?>

<script>
const STEP_PHOTOS = <?= json_encode($stepPhotosJs, $jsonFlags) ?>;
const MAP_STEPS   = <?= json_encode($mapSteps,    $jsonFlags) ?>;

<?php if (!$error && !empty($mapSteps)): ?>
// ── Map ───────────────────────────────────────────────────────────────────────
const map = L.map('map');
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
  attribution: '© <a href="https://www.openstreetmap.org/copyright">OSM</a> contributors, © <a href="https://carto.com/">CARTO</a>',
  maxZoom: 19,
}).addTo(map);

const latlngs = MAP_STEPS.map(s => [s.lat, s.lon]);

L.polyline(latlngs, { color: '#f59e0b', weight: 3, opacity: 0.8, dashArray: '8 8' }).addTo(map);

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

map.fitBounds(latlngs, { padding: [30, 30] });

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

  const res  = await fetch(`/api/comments.php?step_id=${stepId}`);
  const data = await res.json();

  btn.classList.remove('opacity-50', 'pointer-events-none');

  const comments = data.comments ?? (Array.isArray(data) ? data : []);

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
  const photos = STEP_PHOTOS[lbStep]?.full ?? [];
  lbIdx = (lbIdx + dir + photos.length) % photos.length;
  renderLightbox();
}
function renderLightbox() {
  const photos = STEP_PHOTOS[lbStep]?.full ?? [];
  document.getElementById('lightbox-img').src = photos[lbIdx] ?? '';
  document.getElementById('lightbox-counter').textContent =
    photos.length > 1 ? `${lbIdx + 1} / ${photos.length}` : '';
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
<div class="fixed bottom-4 right-4 z-50">
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
