<?php
declare(strict_types=1);

// ── Auth ──────────────────────────────────────────────────────────────────────

function getToken(): ?string
{
    return $_COOKIE['ps_token'] ?? null;
}

function setToken(string $token): void
{
    setcookie('ps_token', $token, [
        'expires'  => time() + 30 * 24 * 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function requireAuth(): string
{
    $token = getToken();
    if (!$token) {
        header('Location: /login');
        exit;
    }
    return $token;
}

// ── Output ────────────────────────────────────────────────────────────────────

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function flag(string $code): string
{
    if (strlen($code) !== 2) return '';
    $code = strtoupper($code);
    if (!ctype_alpha($code)) return '';
    $base = 0x1F1E6 - ord('A');
    return mb_chr($base + ord($code[0]), 'UTF-8') . mb_chr($base + ord($code[1]), 'UTF-8');
}

function fmtDate(int $ts): string
{
    $months = ['jan', 'fév', 'mar', 'avr', 'mai', 'juin', 'juil', 'août', 'sep', 'oct', 'nov', 'déc'];
    $d = new DateTimeImmutable("@{$ts}");
    return $d->format('d') . ' ' . $months[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
}

function weatherIcon(string $c): string
{
    return match ($c) {
        'clear-day'                                => '☀️',
        'clear-night'                              => '🌙',
        'partly-cloudy-day', 'partly-cloudy-night' => '⛅',
        'cloudy'                                   => '☁️',
        'rain'                                     => '🌧️',
        'sleet', 'snow'                            => '❄️',
        'wind'                                     => '💨',
        'fog'                                      => '🌫️',
        default                                    => '🌡️',
    };
}

function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 6371 * 2 * asin(sqrt($a));
}

function dayNum(int $stepTs, int $tripStart): int
{
    return max(1, (int) floor(($stepTs - $tripStart) / 86400) + 1);
}

function parseTs(mixed $val): int
{
    if (!$val) return 0;
    if (is_int($val) || is_float($val)) return (int) $val;
    if (is_string($val) && !ctype_digit($val)) {
        try { return (new DateTimeImmutable($val))->getTimestamp(); } catch (\Throwable) { return 0; }
    }
    return (int) $val;
}

// ── Trip card (reused on index + potentially elsewhere) ───────────────────────

function renderTripCard(array $trip, ?array $author = null): string
{
    $id       = $trip['id'] ?? $trip['trip_id'] ?? null;
    $name     = esc($trip['name'] ?? $trip['display_name'] ?? 'Voyage');
    $cover    = $trip['cover_photo_path']
             ?? $trip['cover_photo_thumb_path']
             ?? $trip['cover_photo']['large_thumbnail_path']
             ?? $trip['cover_photo']['small_thumbnail_path']
             ?? '';
    $km       = (int) round($trip['total_km'] ?? 0);
    $steps    = $trip['step_count'] ?? count($trip['all_steps'] ?? []);
    $ongoing  = empty($trip['end_date']);
    $countries = $trip['visited_countries'] ?? [];
    $flags    = implode(' ', array_map(fn($c) => flag($c), $countries));
    $startTs  = parseTs($trip['start_date'] ?? 0);
    $date     = $startTs ? fmtDate($startTs) : '';
    $url      = "/trip/{$id}";

    $coverStyle = $cover ? "background-image:url(" . esc($cover) . ")" : "background-color:#d1d5db";
    $ongoingBadge = $ongoing
        ? '<span class="absolute top-2 right-2 flex items-center gap-1 bg-red-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full shadow">
             <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>En cours
           </span>'
        : '';

    $authorHtml = '';
    if ($author) {
        $avatar  = $author['profile_image_thumb_path'] ?? $author['profile_image_path'] ?? null;
        $aname   = esc(trim(($author['first_name'] ?? '') . ' ' . ($author['last_name'] ?? '')));
        $imgHtml = $avatar
            ? '<img src="' . esc($avatar) . '" class="w-5 h-5 rounded-full object-cover border border-white/60" alt="">'
            : '<div class="w-5 h-5 rounded-full bg-amber-400 flex items-center justify-center text-[10px] text-white font-bold">' . mb_strtoupper(mb_substr($author['first_name'] ?? '?', 0, 1)) . '</div>';
        $authorHtml = "<div class='flex items-center gap-1.5 mt-1'>{$imgHtml}<span class='text-white/70 text-xs'>{$aname}</span></div>";
    }

    return <<<HTML
    <a href="{$url}" class="group block relative rounded-2xl overflow-hidden shadow-sm hover:shadow-lg transition-all duration-200 hover:-translate-y-0.5 bg-gray-200">
      <div class="h-44 bg-cover bg-center" style="{$coverStyle}">
        <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent"></div>
        {$ongoingBadge}
        <div class="absolute bottom-0 left-0 right-0 p-3">
          {$authorHtml}
          <h3 class="text-white font-semibold text-sm leading-tight mt-0.5 line-clamp-2">{$name}</h3>
          <div class="flex items-center gap-3 mt-1.5">
            <span class="text-white/60 text-xs">{$date}</span>
            <span class="text-white/60 text-[10px]">{$flags}</span>
          </div>
        </div>
      </div>
      <div class="bg-gray-800 grid grid-cols-2 divide-x divide-gray-700">
        <div class="py-2 text-center">
          <div class="text-sm font-bold text-amber-500">{$km}</div>
          <div class="text-[10px] text-gray-500 uppercase tracking-wide">km</div>
        </div>
        <div class="py-2 text-center">
          <div class="text-sm font-bold text-amber-500">{$steps}</div>
          <div class="text-[10px] text-gray-500 uppercase tracking-wide">étapes</div>
        </div>
      </div>
    </a>
    HTML;
}

// ── Shared HTML fragments ─────────────────────────────────────────────────────

function htmlHead(string $title, bool $withLeaflet = false): string
{
    $leaflet = $withLeaflet ? '
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css"/>
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css"/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>' : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title} — GPolar</title>
  <script src="https://cdn.tailwindcss.com"></script>{$leaflet}
  <style>
    .photo-scroll{scrollbar-width:thin;scrollbar-color:#4b5563 transparent}
    .photo-scroll::-webkit-scrollbar{height:4px}
    .photo-scroll::-webkit-scrollbar-thumb{background:#4b5563;border-radius:2px}
    .step-card{scroll-margin-top:9.5rem}
    #lightbox{display:none}
    #lightbox.open{display:flex}
    .konami-gate{display:none}
    .map-sat{filter:brightness(.4)}
    #map::after{content:'';position:absolute;inset:0;background:rgba(0,8,40,.75);pointer-events:none;z-index:300}
    .leaflet-bar a,.leaflet-bar a:hover{background:#1f2937;color:#e5e7eb;border-color:#374151}
    .leaflet-bar a:hover{background:#374151;color:#f59e0b}
    .leaflet-bar{border:none;box-shadow:0 2px 8px rgba(0,0,0,.6)}
    .leaflet-control-layers{background:#1f2937;border:1px solid #374151;box-shadow:0 2px 8px rgba(0,0,0,.6);color:#d1d5db;border-radius:.75rem;padding:.25rem}
    .leaflet-control-layers-separator{border-color:#374151}
    .leaflet-control-attribution{background:rgba(17,24,39,.75)!important;color:#6b7280;font-size:10px}
    .leaflet-control-attribution a{color:#9ca3af}
    .live-pulse{position:relative;width:22px;height:22px}
    .live-pulse span{position:absolute;inset:0;border-radius:50%;background:#ef4444;opacity:.45;animation:live-pulse 1.8s ease-out infinite}
    .live-pulse span:nth-child(2){animation-delay:.9s}
    .live-pulse b{position:absolute;top:50%;left:50%;width:12px;height:12px;margin:-6px 0 0 -6px;border-radius:50%;background:#ef4444;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.5)}
    @keyframes live-pulse{0%{transform:scale(.5);opacity:.55}100%{transform:scale(2.4);opacity:0}}
    .gp-popup .leaflet-popup-content-wrapper{background:#111827;color:#e5e7eb;border:1px solid #1f2937;border-radius:14px;box-shadow:0 8px 28px rgba(0,0,0,.65);padding:0;overflow:hidden}
    .gp-popup .leaflet-popup-content{margin:0!important;width:240px!important;line-height:1.4}
    .gp-popup .leaflet-popup-tip{background:#111827;border:1px solid #1f2937;box-shadow:0 8px 28px rgba(0,0,0,.65)}
    .gp-popup .leaflet-popup-close-button{color:#e5e7eb!important;top:6px!important;right:6px!important;width:22px!important;height:22px!important;background:rgba(0,0,0,.5)!important;border-radius:50%!important;line-height:20px!important;font-size:18px!important;font-weight:400!important;text-align:center!important;text-decoration:none!important;backdrop-filter:blur(4px);z-index:2}
    .gp-popup .leaflet-popup-close-button:hover{color:#fff!important;background:rgba(0,0,0,.75)!important}
    .gp-popup-live .leaflet-popup-content-wrapper{border-color:rgba(239,68,68,.35)}
    .gp-popup-live .leaflet-popup-tip{border-color:rgba(239,68,68,.35)}
    .popup-photo{height:130px;background-size:cover;background-position:center;background-color:#1f2937;position:relative}
    .popup-photo::after{content:'';position:absolute;inset:auto 0 0 0;height:50%;background:linear-gradient(to top,rgba(17,24,39,.7),transparent);pointer-events:none}
    .popup-body{padding:10px 12px 12px;position:relative}
    .popup-num{position:absolute;top:-12px;right:10px;background:#f59e0b;color:#111827;font-size:10px;font-weight:800;padding:2px 7px;border-radius:999px;letter-spacing:.02em;box-shadow:0 2px 6px rgba(0,0,0,.4);border:2px solid #111827}
    .popup-meta{font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:.08em;font-weight:700;display:flex;align-items:center;gap:6px}
    .popup-meta-live{color:#ef4444}
    .popup-live-dot{width:6px;height:6px;border-radius:50%;background:#ef4444;display:inline-block;box-shadow:0 0 0 0 rgba(239,68,68,.6);animation:popup-live-pulse 1.6s ease-out infinite}
    @keyframes popup-live-pulse{0%{box-shadow:0 0 0 0 rgba(239,68,68,.6)}70%{box-shadow:0 0 0 8px rgba(239,68,68,0)}100%{box-shadow:0 0 0 0 rgba(239,68,68,0)}}
    .popup-title{font-size:13px;font-weight:600;color:#f3f4f6;margin-top:4px;line-height:1.3}
    .popup-flag{margin-left:5px;font-size:14px;vertical-align:-1px}
    .popup-loc{font-size:11px;color:#9ca3af;margin-top:6px;display:flex;align-items:center;gap:3px}
    .popup-time{font-size:11px;color:#9ca3af;margin-top:6px}
  </style>
  <script>
  (function(){
    const SEQ=['ArrowUp','ArrowUp','ArrowDown','ArrowDown','ArrowLeft','ArrowRight','ArrowLeft','ArrowRight','b','a'];
    let idx=0;
    document.addEventListener('keydown',function(e){
      if(e.key===SEQ[idx]){idx++;if(idx===SEQ.length){idx=0;document.querySelectorAll('.konami-gate').forEach(el=>el.style.display='block');}}
      else idx=e.key===SEQ[0]?1:0;
    });
  })();
  </script>
</head>
<body class="bg-gray-950 min-h-screen text-gray-100 antialiased">
HTML;
}

function htmlNav(array $user = [], string $backUrl = ''): string
{
    $avatar   = $user['profile_image_thumb_path'] ?? $user['profile_image_path'] ?? null;
    $name     = esc(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
    $username = esc($user['username'] ?? '');

    $imgHtml = $avatar
        ? '<img src="' . esc($avatar) . '" class="w-7 h-7 rounded-full object-cover border border-gray-700" alt="">'
        : '<div class="w-7 h-7 rounded-full bg-amber-400 flex items-center justify-center text-sm text-white font-bold">' . mb_strtoupper(mb_substr($user['first_name'] ?? 'G', 0, 1)) . '</div>';

    $back = $backUrl
        ? '<a href="' . esc($backUrl) . '" class="flex items-center gap-1 text-sm text-gray-500 hover:text-gray-100 transition-colors">
             <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
             Voyages
           </a>'
        : '';

    $userSection = $name
        ? '<div class="flex items-center gap-2">
             ' . $imgHtml . '
             <span class="text-sm text-gray-300 hidden sm:block">' . $name . '</span>
             <a href="/logout" class="text-xs text-gray-400 hover:text-red-500 transition-colors ml-1">Déco</a>
           </div>'
        : '<a href="/login" class="text-sm text-amber-500 font-medium">Connexion</a>';

    $tripSearch = !$backUrl ? '
      <form onsubmit="event.preventDefault();var v=this.querySelector(\'input\').value.trim();if(/^\d+$/.test(v))location=\'/trip/\'+v;" class="hidden sm:flex items-center gap-1.5">
        <input type="text" inputmode="numeric" pattern="[0-9]+" placeholder="N° de voyage…"
          class="w-32 bg-gray-800 border border-gray-700 rounded-lg px-2.5 py-1 text-xs text-gray-300 placeholder-gray-600 focus:outline-none focus:ring-1 focus:ring-amber-500 focus:w-40 transition-all font-mono">
      </form>' : '';

    return <<<HTML
<nav class="bg-gray-900 border-b border-gray-800 sticky top-0 z-30 shadow-sm">
  <div class="max-w-7xl mx-auto px-4 py-2.5 flex items-center justify-between gap-4">
    <div class="flex items-center gap-4">
      <a href="/" class="font-bold text-amber-500 text-lg tracking-tight">GPolar 🚴</a>
      {$back}
      {$tripSearch}
    </div>
    {$userSection}
  </div>
</nav>
HTML;
}
