<?php
declare(strict_types=1);

// Kanoniczny adres witryny — wejście z innego hosta przekierowujemy na rdadi.zjawa.dev.
// Pomijamy lokalne uruchomienia (localhost / 127.0.0.1) oraz CLI, by nie psuć dev/Dockera.
(function (): void {
    if (PHP_SAPI === 'cli') return;
    $canonical = 'rdadi.zjawa.dev';
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || $host === $canonical) return;
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') return;
    // Tylko bezpieczne metody — żeby nie gubić danych z formularza POST.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $canonical . $uri, true, 301);
    exit;
})();

// radiodyplom.pl od 2026-06 blokuje "boty" (myAwards.php zwraca 403 BOT_DETECTED).
// Detekcja wymaga przeglądarkowego User-Agent ORAZ nagłówka Accept-Language
// (brak Accept-Language = blokada, sam UA nie wystarcza). Patrz CURLOPT_HTTPHEADER niżej.
define('HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

define('DB_PATH', (function(): string {
    $candidates = [
        getenv('STATS_DB_PATH') ?: '',
        '/data/stats.db',
        sys_get_temp_dir() . '/radiodyplom_stats.db',
    ];
    foreach ($candidates as $path) {
        if (!$path) continue;
        $dir = dirname($path);
        if (is_dir($dir) && is_writable($dir)) return $path;
    }
    return sys_get_temp_dir() . '/radiodyplom_stats.db';
})());

// --- Warstwa prezentacji: wspólne style (tokeny + dark mode) dla obu stron ---
function app_styles(): string {
    return <<<'CSS'
<style>
  :root{
    --font:system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    --mono:ui-monospace,"SFMono-Regular","Cascadia Code","Segoe UI Mono",monospace;

    --bg:#eef2f7;
    --bg-grad:radial-gradient(1200px 600px at 50% -10%,#ffffff 0%,rgba(255,255,255,0) 60%);
    --surface:#ffffff;
    --surface-2:#f8fafc;
    --text:#0f172a;
    --text-muted:#64748b;
    --text-faint:#94a3b8;
    --border:#e2e8f0;
    --border-subtle:#eef2f6;

    --accent:#2563eb;
    --accent-strong:#1d4ed8;
    --accent-contrast:#ffffff;
    --accent-soft:rgba(37,99,235,.10);

    --success:#16a34a;
    --success-strong:#15803d;

    --danger-bg:#fef2f2;
    --danger-text:#b91c1c;
    --danger-border:#fecaca;

    --radius-sm:8px;
    --radius:12px;
    --shadow-sm:0 1px 2px rgba(15,23,42,.04),0 1px 3px rgba(15,23,42,.06);
    --shadow-md:0 6px 20px rgba(15,23,42,.08),0 2px 6px rgba(15,23,42,.05);
    --ring:0 0 0 3px rgba(37,99,235,.30);
  }
  [data-theme="dark"]{
    --bg:#0a0f1c;
    --bg-grad:radial-gradient(1200px 600px at 50% -10%,rgba(59,130,246,.10) 0%,rgba(59,130,246,0) 60%);
    --surface:#121a2b;
    --surface-2:#1a2438;
    --text:#e6edf6;
    --text-muted:#9aa7bd;
    --text-faint:#6b7a93;
    --border:#243049;
    --border-subtle:#1b2538;

    --accent:#3b82f6;
    --accent-strong:#60a5fa;
    --accent-soft:rgba(59,130,246,.16);

    --success:#22c55e;
    --success-strong:#16a34a;

    --danger-bg:rgba(239,68,68,.12);
    --danger-text:#fca5a5;
    --danger-border:rgba(239,68,68,.32);

    --shadow-sm:0 1px 2px rgba(0,0,0,.4);
    --shadow-md:0 8px 24px rgba(0,0,0,.45),0 2px 6px rgba(0,0,0,.3);
    --ring:0 0 0 3px rgba(59,130,246,.40);
  }

  *{box-sizing:border-box;margin:0;padding:0;}
  html{color-scheme:light dark;}
  body{
    font-family:var(--font);
    background:var(--bg-grad),var(--bg);
    background-attachment:fixed;
    color:var(--text);
    padding:2.5rem 1.25rem;
    line-height:1.5;
    -webkit-font-smoothing:antialiased;
    transition:background-color .3s ease,color .3s ease;
  }
  a{color:var(--accent);}
  h1{font-size:1.5rem;font-weight:800;letter-spacing:-.01em;margin-bottom:.25rem;}
  h2{font-size:1rem;font-weight:700;color:var(--text);margin-bottom:.75rem;}
  .subtitle{color:var(--text-muted);font-size:.92rem;margin-bottom:1.5rem;}

  .wrap{max-width:960px;margin:0 auto;}
  .layout{display:flex;gap:1.5rem;max-width:960px;margin:0 auto;align-items:flex-start;}

  .card,.summary-card,.table-card{
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow-sm);
    transition:background .3s,border-color .3s;
  }
  .card{padding:1.5rem;flex:1;}
  .summary-card{padding:1.5rem;width:260px;flex-shrink:0;}
  .table-card{padding:1.3rem 1.5rem;margin-bottom:1.5rem;overflow-x:auto;}
  .summary-card h2{margin-bottom:1rem;}

  .cards{display:flex;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;}
  .cards .card{min-width:160px;}
  .card-label,.stat-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-faint);margin-bottom:.35rem;}
  .card-value{font-size:2.1rem;font-weight:800;color:var(--accent);letter-spacing:-.02em;}

  .stat{margin-bottom:1rem;}
  .stat-value{font-size:1.7rem;font-weight:800;color:var(--accent);line-height:1.1;}
  .stat-sub{font-size:.8rem;color:var(--text-muted);margin-top:.25rem;}
  .summary-empty,.chart-empty{color:var(--text-faint);font-size:.85rem;}
  .chart-empty{padding:2rem 0;text-align:center;}

  label{display:block;font-weight:600;font-size:.9rem;margin-bottom:.35rem;}
  .input-wrap{position:relative;margin-bottom:1rem;}
  input[type=text],select{
    width:100%;padding:.6rem .8rem;
    border:1px solid var(--border);border-radius:var(--radius-sm);
    font-size:1rem;background:var(--surface);color:var(--text);
    transition:border-color .15s,box-shadow .15s,background .3s;
  }
  input[type=text]{text-transform:uppercase;}
  input[type=text]:focus,select:focus{outline:none;border-color:var(--accent);box-shadow:var(--ring);}
  select:disabled{opacity:.55;cursor:not-allowed;}
  #ses_select{margin-bottom:1rem;}
  select.cs-select{width:auto;font-size:.95rem;}

  .spinner{display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);width:18px;height:18px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite;}
  @keyframes spin{to{transform:translateY(-50%) rotate(360deg);}}

  button[type=submit]{
    width:100%;padding:.75rem;
    background:var(--accent);color:var(--accent-contrast);
    border:none;border-radius:var(--radius-sm);
    font-size:1rem;font-weight:700;cursor:pointer;margin-bottom:.5rem;
    transition:background .2s,transform .1s,box-shadow .2s;
  }
  button[type=submit]:hover{background:var(--accent-strong);}
  button[type=submit]:active{transform:translateY(1px);}
  button[type=submit]:focus-visible{outline:none;box-shadow:var(--ring);}
  button[type=submit]:disabled{opacity:.5;cursor:default;transform:none;}
  .btn-all{background:var(--success)!important;}
  .btn-all:hover{background:var(--success-strong)!important;}

  .error{background:var(--danger-bg);color:var(--danger-text);border:1px solid var(--danger-border);border-radius:var(--radius-sm);padding:.7rem .95rem;margin-bottom:1rem;font-size:.9rem;}
  .note{margin-top:1rem;font-size:.82rem;color:var(--text-muted);line-height:1.55;border-top:1px solid var(--border);padding-top:.9rem;}
  .note strong{color:var(--text);}

  table{width:100%;border-collapse:collapse;font-size:.88rem;}
  th{text-align:left;padding:.6rem .8rem;background:var(--surface-2);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);border-bottom:1px solid var(--border);}
  td{padding:.6rem .8rem;border-bottom:1px solid var(--border-subtle);}
  tr:last-child td{border-bottom:none;}
  tbody tr{transition:background .12s;}
  tr:hover td{background:var(--accent-soft);}
  .cs{font-weight:700;color:var(--accent);font-family:var(--mono);cursor:pointer;}
  .cs:hover{text-decoration:underline;}

  .chart-row{display:flex;gap:1rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap;}
  .chart-row .ts-wrapper{min-width:240px;flex:0 0 auto;}

  .back{display:inline-block;margin-bottom:1.2rem;font-size:.85rem;color:var(--accent);text-decoration:none;}
  .back:hover{text-decoration:underline;}
  footer{text-align:center;margin-top:1.75rem;font-size:.8rem;color:var(--text-faint);}
  footer a{color:var(--text-muted);text-decoration:underline;}
  footer strong{color:var(--text-muted);}

  .theme-toggle{
    position:fixed;top:1rem;right:1rem;z-index:50;
    width:40px;height:40px;border-radius:50%;
    border:1px solid var(--border);background:var(--surface);color:var(--text);
    font-size:1.05rem;cursor:pointer;box-shadow:var(--shadow-sm);
    display:flex;align-items:center;justify-content:center;
    transition:transform .15s ease,box-shadow .2s ease,background .3s;
  }
  .theme-toggle:hover{transform:translateY(-1px);box-shadow:var(--shadow-md);}
  .theme-toggle:focus-visible{outline:none;box-shadow:var(--ring);}

  /* Tom Select — dopasowanie do ciemnego motywu */
  [data-theme="dark"] .ts-wrapper .ts-control{background:var(--surface);border-color:var(--border);color:var(--text);box-shadow:none;}
  [data-theme="dark"] .ts-control input,[data-theme="dark"] .ts-control .item{color:var(--text);}
  [data-theme="dark"] .ts-dropdown{background:var(--surface-2);border-color:var(--border);color:var(--text);}
  [data-theme="dark"] .ts-dropdown .option.active,[data-theme="dark"] .ts-dropdown .option:hover{background:var(--accent-soft);color:var(--text);}

  @media (max-width:700px){.layout{flex-direction:column;}.summary-card{width:100%;}body{padding:1.5rem 1rem;}}
  @media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important;}}
</style>
CSS;
}

// Wstrzykiwane w <head> przed renderem — ustawia motyw bez mignięcia (FOUC).
function theme_init_script(): string {
    return "<script>(function(){try{var t=localStorage.getItem('theme');"
         . "if(t!=='light'&&t!=='dark')t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';"
         . "document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>";
}

// Pływający przełącznik motywu + jego obsługa (samowystarczalny dla każdej strony).
function theme_toggle_html(): string {
    return '<button type="button" id="theme-toggle" class="theme-toggle" aria-label="Przełącz motyw">☾</button>'
         . "<script>(function(){var b=document.getElementById('theme-toggle');if(!b)return;"
         . "function s(){var d=document.documentElement.getAttribute('data-theme')==='dark';"
         . "b.textContent=d?'☀':'☾';b.setAttribute('aria-label',d?'Tryb jasny':'Tryb ciemny');}s();"
         . "b.addEventListener('click',function(){var d=document.documentElement.getAttribute('data-theme')==='dark';"
         . "var n=d?'light':'dark';document.documentElement.setAttribute('data-theme',n);"
         . "try{localStorage.setItem('theme',n);}catch(e){}s();});})();</script>";
}

// --- AJAX: pobierz sesje dla callsign ---
if (isset($_GET['action']) && $_GET['action'] === 'sessions') {
    header('Content-Type: application/json; charset=utf-8');

    $callsign = trim($_GET['callsign'] ?? '');
    if (!$callsign) { echo json_encode(['error' => 'Brak callsign']); exit; }

    $url = 'https://radiodyplom.pl/myAwards.php?callsign=' . urlencode($callsign);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . HTTP_USER_AGENT, 'Accept-Language: pl-PL,pl;q=0.9,en;q=0.8'],
    ]);
    $html      = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) { echo json_encode(['error' => $curlError]); exit; }

    // Wyciągnij bloki sesji: ID, nazwa, status, liczba QSO
    preg_match_all(
        '/<h2 class="ses-title"[^>]*>\s*<a href="\/ses\/(\d+)">\s*(.*?)\s*<\/a>.*?status-label\s+(status-\w+)[^>]*>\s*(.*?)\s*<\/span>.*?(\d+)\s*QSO/s',
        $html,
        $m,
        PREG_SET_ORDER
    );

    $sessions = [];
    foreach ($m as $r) {
        $sessions[] = [
            'id'     => (int) $r[1],
            'name'   => html_entity_decode(trim($r[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'status' => trim($r[3]),
            'label'  => html_entity_decode(trim($r[4]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'qso'    => (int) $r[5],
        ];
    }

    usort($sessions, fn($a, $b) => $b['id'] <=> $a['id']);
    $qsoTotal = array_sum(array_column($sessions, 'qso'));
    db_log($callsign, count($sessions), $qsoTotal);
    echo json_encode($sessions);
    exit;
}

// --- AJAX: statystyki unikalnych QSO ---
if (isset($_GET['action']) && $_GET['action'] === 'stats') {
    header('Content-Type: application/json; charset=utf-8');

    $callsign = strtoupper(trim($_GET['callsign'] ?? ''));
    if (!$callsign) { echo json_encode(['error' => 'Brak callsign']); exit; }

    $ids = fetch_session_ids($callsign);
    if (is_string($ids)) { echo json_encode(['error' => $ids]); exit; }

    $allQsos = [];
    foreach ($ids as $id) {
        $allQsos = array_merge($allQsos, fetch_qsos($callsign, $id));
    }

    $total  = count($allQsos);
    $unique = [];
    foreach ($allQsos as $q) {
        $key = implode('|', [
            $q['operator']  ?? '',
            $q['qso_date']  ?? '',
            $q['time_on']   ?? '',
            $q['band']      ?? '',
            $q['mode']      ?? '',
        ]);
        $unique[$key] = $q;
    }

    echo json_encode([
        'total'      => $total,
        'unique'     => count($unique),
        'duplicates' => $total - count($unique),
    ]);
    exit;
}

// --- AJAX: dane wykresu dla callsign ---
if (isset($_GET['action']) && $_GET['action'] === 'chart_data') {
    header('Content-Type: application/json; charset=utf-8');
    $callsign = strtoupper(trim($_GET['callsign'] ?? ''));
    if (!$callsign) { echo json_encode([]); exit; }
    try {
        $db   = db_connect();
        $stmt = $db->prepare("SELECT date(queried_at) AS day, MAX(qso_total) AS qso FROM queries WHERE callsign = ? GROUP BY date(queried_at) ORDER BY day ASC");
        $stmt->execute([$callsign]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// --- SQLite ---
function db_connect(): PDO {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS queries (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        callsign   TEXT    NOT NULL,
        sessions   INTEGER NOT NULL DEFAULT 0,
        qso_total  INTEGER NOT NULL DEFAULT 0,
        queried_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    return $db;
}

function db_log(string $callsign, int $sessions, int $qsoTotal): void {
    try {
        $db = db_connect();
        $db->prepare("INSERT INTO queries (callsign, sessions, qso_total) VALUES (?, ?, ?)")
           ->execute([strtoupper($callsign), $sessions, $qsoTotal]);
    } catch (\Throwable $e) { /* silent */ }
}

// --- Strona statystyk ---
if (isset($_GET['page']) && $_GET['page'] === 'stats') {
    try {
        $db      = db_connect();
        $summary      = $db->query("SELECT COUNT(*) AS queries, COUNT(DISTINCT callsign) AS callsigns, SUM(qso_total) AS qsos FROM queries")->fetch(PDO::FETCH_ASSOC);
        $top          = $db->query("SELECT callsign, COUNT(*) AS cnt, MAX(sessions) AS sessions, MAX(qso_total) AS qso_total, MAX(queried_at) AS last_seen FROM queries GROUP BY callsign ORDER BY cnt DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        $recent       = $db->query("SELECT callsign, sessions, qso_total, queried_at FROM queries ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $allCallsigns = $db->query("SELECT DISTINCT callsign FROM queries ORDER BY callsign ASC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        die('Błąd bazy danych: ' . htmlspecialchars($e->getMessage()));
    }
    ?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Statystyki — RadioDyplom ADIF</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<?= theme_init_script() ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js"></script>
<?= app_styles() ?>
</head>
<body>
<?= theme_toggle_html() ?>
<div class="wrap">
  <a class="back" href="/">← Powrót do eksportu</a>
  <h1>📊 Statystyki zapytań</h1>

  <div class="cards">
    <div class="card">
      <div class="card-label">Zapytania łącznie</div>
      <div class="card-value"><?= (int)($summary['queries'] ?? 0) ?></div>
    </div>
    <div class="card">
      <div class="card-label">Unikalne znaki</div>
      <div class="card-value"><?= (int)($summary['callsigns'] ?? 0) ?></div>
    </div>
    <div class="card">
      <div class="card-label">QSO odczytane łącznie</div>
      <div class="card-value"><?= (int)($summary['qsos'] ?? 0) ?></div>
    </div>
  </div>

  <div class="table-card">
    <h2>📈 Historia QSO dla znaku</h2>
    <div class="chart-row">
      <label for="chart-cs" style="font-size:.85rem;font-weight:600;">Znak:</label>
      <select id="chart-cs" class="cs-select">
        <option value="">— wybierz —</option>
        <?php foreach ($allCallsigns as $cs): ?>
        <option value="<?= htmlspecialchars($cs) ?>"><?= htmlspecialchars($cs) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div id="chart-wrap" style="position:relative; height:260px;">
      <p class="chart-empty" id="chart-empty">Wybierz znak wywoławczy, aby zobaczyć wykres.</p>
      <canvas id="myChart" style="display:none;"></canvas>
    </div>
  </div>

  <div class="table-card">
    <h2>Top znaki wywoławcze</h2>
    <table>
      <thead><tr><th>Znak</th><th>Zapytania</th><th>Sesje (max)</th><th>QSO (max)</th><th>Ostatnio widziany</th></tr></thead>
      <tbody>
        <?php foreach ($top as $r): ?>
        <tr>
          <td class="cs" data-cs="<?= htmlspecialchars($r['callsign']) ?>" title="Kliknij, aby zobaczyć wykres"><?= htmlspecialchars($r['callsign']) ?></td>
          <td><?= (int)$r['cnt'] ?></td>
          <td><?= (int)$r['sessions'] ?></td>
          <td><?= (int)$r['qso_total'] ?></td>
          <td><?= htmlspecialchars($r['last_seen']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="table-card">
    <h2>Ostatnie 50 zapytań</h2>
    <table>
      <thead><tr><th>Znak</th><th>Sesje</th><th>QSO</th><th>Data odczytu</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $r): ?>
        <tr>
          <td class="cs"><?= htmlspecialchars($r['callsign']) ?></td>
          <td><?= (int)$r['sessions'] ?></td>
          <td><?= (int)$r['qso_total'] ?></td>
          <td><?= htmlspecialchars($r['queried_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<footer>Stworzono przez <strong>Zjawa.IT</strong> &mdash;
  <a href="https://github.com/enclude/www.radidyplom-adif.zjawa.dev" target="_blank" rel="noopener">GitHub</a>
</footer>
<script>
let chart = null;

const empty  = document.getElementById('chart-empty');
const canvas = document.getElementById('myChart');

const tomSel = new TomSelect('#chart-cs', {
  placeholder: 'Wyszukaj znak…',
  allowEmptyOption: true,
  sortField: 'text',
});

tomSel.on('change', val => loadChart(val));

// klik na znak w tabeli → wybiera go w Tom Select i ładuje wykres
document.querySelectorAll('.cs[data-cs]').forEach(el => {
  el.addEventListener('click', () => {
    tomSel.setValue(el.dataset.cs);
  });
});

async function loadChart(callsign) {
  if (!callsign) { showEmpty('Wybierz znak wywoławczy, aby zobaczyć wykres.'); return; }
  showEmpty('⏳ Ładowanie…');
  try {
    const resp = await fetch(`?action=chart_data&callsign=${encodeURIComponent(callsign)}`);
    const data = await resp.json();
    if (!data.length) { showEmpty('Brak danych dla tego znaku.'); return; }
    renderChart(callsign, data);
  } catch { showEmpty('Błąd ładowania danych.'); }
}

function showEmpty(msg) {
  canvas.style.display = 'none';
  empty.style.display  = 'block';
  empty.textContent    = msg;
  if (chart) { chart.destroy(); chart = null; }
}

let lastCallsign = null, lastData = null;

function themeColors() {
  const cs = getComputedStyle(document.documentElement);
  return {
    accent: cs.getPropertyValue('--accent').trim() || '#2563eb',
    soft:   cs.getPropertyValue('--accent-soft').trim() || 'rgba(37,99,235,.1)',
    grid:   cs.getPropertyValue('--border-subtle').trim() || '#f3f4f6',
    text:   cs.getPropertyValue('--text-muted').trim() || '#6b7280',
  };
}

function renderChart(callsign, data) {
  lastCallsign = callsign; lastData = data;
  empty.style.display  = 'none';
  canvas.style.display = 'block';
  if (chart) chart.destroy();
  const c = themeColors();
  chart = new Chart(canvas, {
    type: 'line',
    data: {
      labels: data.map(d => d.day),
      datasets: [{
        label: `QSO — ${callsign}`,
        data:  data.map(d => parseInt(d.qso)),
        borderColor:     c.accent,
        backgroundColor: c.soft,
        borderWidth: 2,
        pointRadius: 4,
        pointBackgroundColor: c.accent,
        tension: 0.3,
        fill: true,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { color: c.grid }, ticks: { color: c.text, font: { size: 11 } } },
        y: { grid: { color: c.grid }, ticks: { color: c.text, font: { size: 11 }, precision: 0 }, beginAtZero: true }
      }
    }
  });
}

// Przerysuj wykres po zmianie motywu (kolory zależą od zmiennych CSS).
new MutationObserver(() => {
  if (chart && lastCallsign && lastData) renderChart(lastCallsign, lastData);
}).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
</script>
</body>
</html>
    <?php
    exit;
}

// --- POST: generuj ADIF ---
$error    = '';
$callsign = strtoupper(trim($_POST['callsign'] ?? ''));
$ses_id   = trim($_POST['ses_id']   ?? '');

function warsaw_ts(): string {
    return (new DateTime('now', new DateTimeZone('Europe/Warsaw')))->format('YmdHis');
}

function curl_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: ' . HTTP_USER_AGENT, 'Accept-Language: pl-PL,pl;q=0.9,en;q=0.8'],
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return [$response, $httpCode, $curlError];
}

function fetch_session_ids(string $callsign): array|string {
    [$html, , $curlError] = curl_get('https://radiodyplom.pl/myAwards.php?callsign=' . urlencode($callsign));
    if ($curlError) return $curlError;
    preg_match_all('/<a href="\/ses\/(\d+)">/', $html, $m);
    return array_unique(array_map('intval', $m[1]));
}

function fetch_qsos(string $callsign, int $ses_id): array {
    // API ignoruje limit > 100 (per_page=100), więc trzeba iterować po stronach
    $qsos       = [];
    $page       = 1;
    $totalPages = 1;
    do {
        $url = sprintf(
            'https://radiodyplom.pl/ajax_participant_qso.php?ses_id=%d&callsign=%s&page=%d&limit=1000',
            $ses_id, urlencode($callsign), $page
        );
        [$response, $httpCode, $curlError] = curl_get($url);
        if ($curlError || $httpCode !== 200) break;
        $data  = json_decode($response, true);
        $batch = $data['qso'] ?? $data['qsos'] ?? $data['data'] ?? [];
        if (!$batch) break;
        $qsos       = array_merge($qsos, $batch);
        $totalPages = (int) ($data['pagination']['total_pages'] ?? 1);
        $page++;
    } while ($page <= $totalPages && $page <= 100);
    return $qsos;
}

function adif_field(string $name, ?string $value): string {
    if ($value === null || $value === '') return '';
    return sprintf('<%s:%d>%s', strtoupper($name), strlen($value), $value);
}

function normalize_mode(string $mode): array {
    return match(strtoupper($mode)) {
        'FM'    => ['FM',           ''],
        'DMR'   => ['DIGITALVOICE', 'DMR'],
        'C4FM'  => ['DIGITALVOICE', 'C4FM'],
        'DSTAR' => ['DIGITALVOICE', 'D-STAR'],
        'SSB'   => ['SSB',          ''],
        'CW'    => ['CW',           ''],
        default => [strtoupper($mode), ''],
    };
}

function generate_adif(array $qsos, string $myCall): string {
    $ts  = date('Ymd His');
    $out = "RadioDyplom.pl ADIF Export\nCreated: {$ts}\nStation: {$myCall}\n"
         . "<ADIF_VER:5>3.1.4\n<PROGRAMID:12>RadioDyplom\n<EOH>\n\n";
    foreach ($qsos as $q) {
        [$mode, $submode] = normalize_mode($q['mode'] ?? '');
        $out .= adif_field('CALL',            $q['operator']        ?? '')
              . adif_field('QSO_DATE',         str_replace('-', '', $q['qso_date'] ?? ''))
              . adif_field('TIME_ON',          substr(str_replace(':', '', $q['time_on'] ?? ''), 0, 6))
              . adif_field('BAND',             $q['band']            ?? '')
              . adif_field('MODE',             $mode)
              . ($submode ? adif_field('SUBMODE', $submode) : '')
              . adif_field('RST_SENT',         $q['report_sent']     ?? '')
              . adif_field('RST_RCVD',         $q['report_received'] ?? '')
              . adif_field('STATION_CALLSIGN', $q['callsign']        ?? $myCall)
              . (!empty($q['comment']) ? adif_field('COMMENT', $q['comment']) : '')
              . "<EOR>\n";
    }
    return $out;
}

// Pobierz wszystkie sesje
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $callsign && isset($_POST['download_all'])) {
    $ids = fetch_session_ids($callsign);
    if (is_string($ids)) {
        $error = "cURL error: $ids";
    } elseif (empty($ids)) {
        $error = 'Nie znaleziono sesji dla tego znaku.';
    } else {
        $raw = [];
        foreach ($ids as $id) {
            $raw = array_merge($raw, fetch_qsos($callsign, $id));
        }
        $seen    = [];
        $allQsos = [];
        foreach ($raw as $q) {
            $key = implode('|', [$q['operator'] ?? '', $q['qso_date'] ?? '', $q['time_on'] ?? '', $q['band'] ?? '', $q['mode'] ?? '']);
            if (!isset($seen[$key])) { $seen[$key] = true; $allQsos[] = $q; }
        }
        if (empty($allQsos)) {
            $error = 'Brak łączności we wszystkich sesjach.';
        } else {
            $adif     = generate_adif($allQsos, $callsign);
            $filename = "radiodyplom_ALL_{$callsign}_" . warsaw_ts() . '.adi';
            header('Content-Type: text/plain; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"$filename\"");
            header('Content-Length: ' . strlen($adif));
            echo $adif;
            exit;
        }
    }
}

// Pobierz jedną sesję
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $callsign && $ses_id) {
    $qsos = fetch_qsos($callsign, (int) $ses_id);
    if (empty($qsos)) {
        $error = 'Brak łączności w odpowiedzi. Sprawdź ses_id i callsign.';
    } else {
        $adif     = generate_adif($qsos, $callsign);
        $filename = "radiodyplom_ses{$ses_id}_{$callsign}_" . warsaw_ts() . '.adi';
        header('Content-Type: text/plain; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header('Content-Length: ' . strlen($adif));
        echo $adif;
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>RadioDyplom → ADIF Export</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<?= theme_init_script() ?>
<?= app_styles() ?>
</head>
<body>
<?= theme_toggle_html() ?>
<div class="layout">
<div class="card">
  <h1>📡 RadioDyplom → ADIF Export</h1>
  <p class="subtitle">Wpisz znak — sesje załadują się automatycznie</p>

  <?php if ($error): ?>
  <div class="error">⚠ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" id="form">
    <label for="callsign">Znak wywoławczy</label>
    <div class="input-wrap">
      <input type="text" id="callsign" name="callsign"
             value="<?= htmlspecialchars($callsign) ?>"
             placeholder="np. SP0ABC" autocomplete="off" required>
      <div class="spinner" id="spinner"></div>
    </div>

    <label for="ses_select">Sesja</label>
    <select id="ses_select" name="ses_id" disabled required>
      <option value="">— wpisz znak wywoławczy —</option>
    </select>

    <input type="hidden" id="download_all_input" name="download_all" value="" disabled>
    <button type="submit" id="btn-submit" disabled>⬇ Pobierz sesję (ADIF)</button>
    <button type="submit" id="btn-all" class="btn-all" disabled
            onclick="document.getElementById('download_all_input').disabled=false; document.getElementById('ses_select').removeAttribute('required');">
      ⬇ Pobierz wszystkie sesje (ADIF)
    </button>
  </form>

  <p class="note">
    ℹ️ <strong>QRZ.com</strong> nie doda ponownie łączności, która już istnieje w logu — import ADIF jest odporny na duplikaty. Możesz bezpiecznie importować plik wielokrotnie.
  </p>
</div>

<div class="summary-card">
  <h2>📊 Podsumowanie</h2>
  <div id="summary-content">
    <p class="summary-empty">Wpisz znak wywoławczy, aby zobaczyć statystyki.</p>
  </div>
</div>

</div><!-- /layout -->

<footer>
  Stworzono przez <strong>Zjawa.IT</strong> &mdash;
  <a href="https://github.com/enclude/www.radidyplom-adif.zjawa.dev" target="_blank" rel="noopener">GitHub</a>
  &mdash;
  <a href="?page=stats">Statystyki</a>
</footer>

<script>
const callsignInput = document.getElementById('callsign');
const select        = document.getElementById('ses_select');
const spinner       = document.getElementById('spinner');
const btnSubmit     = document.getElementById('btn-submit');
const btnAll        = document.getElementById('btn-all');
let debounceTimer   = null;

callsignInput.addEventListener('input', () => {
  clearTimeout(debounceTimer);
  const cs = callsignInput.value.trim();
  resetSelect();
  if (cs.length < 3) return;
  // Odpytujemy serwer dopiero 3,5 s po ostatnim znaku — ogranicza liczbę zapytań
  // do radiodyplom.pl (mniejsze ryzyko blokady BOT_DETECTED) zamiast strzelać przy każdym znaku.
  debounceTimer = setTimeout(() => fetchSessions(cs), 3500);
});

window.addEventListener('DOMContentLoaded', () => {
  const cs = callsignInput.value.trim();
  if (cs.length >= 3) fetchSessions(cs);
});

function resetSelect() {
  select.innerHTML   = '<option value="">— wpisz znak wywoławczy —</option>';
  select.disabled    = true;
  btnSubmit.disabled = true;
  btnAll.disabled    = true;
  document.getElementById('summary-content').innerHTML =
    '<p class="summary-empty">Wpisz znak wywoławczy, aby zobaczyć statystyki.</p>';
}

async function fetchSessions(callsign) {
  spinner.style.display = 'block';
  select.innerHTML      = '<option value="">⏳ Ładowanie...</option>';

  try {
    const resp = await fetch(`?action=sessions&callsign=${encodeURIComponent(callsign)}`);
    const data = await resp.json();

    if (data.error || !Array.isArray(data) || !data.length) {
      select.innerHTML = '<option value="">Brak sesji dla tego znaku</option>';
      return;
    }

    select.innerHTML = '';
    data.forEach(s => {
      const opt = document.createElement('option');
      opt.value = s.id;
      const status = s.status === 'status-ongoing'
        ? 'trwa'
        : s.status === 'status-upcoming'
          ? 'nadchodzi'
          : 'zakończona';
      opt.textContent = `${s.name} (ses_id: ${s.id}) — ${s.qso} QSO (${status})`;
      select.appendChild(opt);
    });

    select.disabled    = false;
    btnSubmit.disabled = false;
    btnAll.disabled    = false;
    renderSummary(data);
    fetchStats(callsign);
  } catch (e) {
    select.innerHTML = '<option value="">Błąd pobierania sesji</option>';
  } finally {
    spinner.style.display = 'none';
  }
}

function renderSummary(sessions) {
  const totalQso = sessions.reduce((s, x) => s + x.qso, 0);
  const best     = sessions.reduce((a, b) => b.qso > a.qso ? b : a, sessions[0]);
  const ongoing  = sessions.filter(s => s.status === 'status-ongoing').length;

  document.getElementById('summary-content').innerHTML = `
    <div class="stat">
      <div class="stat-label">Akcje dyplomowe</div>
      <div class="stat-value">${sessions.length}</div>
      <div class="stat-sub">${ongoing > 0 ? `w tym ${ongoing} aktualnie trwające` : 'brak aktualnie trwających'}</div>
    </div>
    <div class="stat">
      <div class="stat-label">Wszystkie QSO</div>
      <div class="stat-value">${totalQso}</div>
      <div class="stat-sub">suma ze wszystkich akcji</div>
    </div>
    <div class="stat">
      <div class="stat-label">Unikalne QSO</div>
      <div class="stat-value" id="stat-unique">⏳</div>
      <div class="stat-sub" id="stat-unique-sub">trwa liczenie…</div>
    </div>
    <div class="stat">
      <div class="stat-label">Najaktywniejsza akcja</div>
      <div class="stat-value" style="font-size:1.1rem; line-height:1.3">${best.name}</div>
      <div class="stat-sub">${best.qso} QSO (ses_id: ${best.id})</div>
    </div>
  `;
}

async function fetchStats(callsign) {
  try {
    const resp = await fetch(`?action=stats&callsign=${encodeURIComponent(callsign)}`);
    const s    = await resp.json();
    const elV  = document.getElementById('stat-unique');
    const elS  = document.getElementById('stat-unique-sub');
    if (!elV) return;
    if (s.error) { elV.textContent = '—'; elS.textContent = s.error; return; }
    elV.textContent = s.unique;
    elS.textContent = s.duplicates > 0
      ? `⚠ ${s.duplicates} duplikat${s.duplicates === 1 ? '' : s.duplicates < 5 ? 'y' : 'ów'} w różnych akcjach`
      : 'brak duplikatów między akcjami';
  } catch (e) {
    const el = document.getElementById('stat-unique');
    if (el) el.textContent = '—';
  }
}
</script>
</body>
</html>
