<?php
declare(strict_types=1);

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
        CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0 (RadioDyplom ADIF Exporter)'],
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
define('DB_PATH', __DIR__ . '/stats.db');

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
    } catch (Exception $e) { /* silent */ }
}

// --- Strona statystyk ---
if (isset($_GET['page']) && $_GET['page'] === 'stats') {
    try {
        $db      = db_connect();
        $summary = $db->query("SELECT COUNT(*) AS queries, COUNT(DISTINCT callsign) AS callsigns, SUM(qso_total) AS qsos FROM queries")->fetch(PDO::FETCH_ASSOC);
        $top     = $db->query("SELECT callsign, COUNT(*) AS cnt, MAX(sessions) AS sessions, MAX(qso_total) AS qso_total, MAX(queried_at) AS last_seen FROM queries GROUP BY callsign ORDER BY cnt DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        $recent  = $db->query("SELECT callsign, sessions, qso_total, queried_at FROM queries ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        die('Błąd bazy danych: ' . htmlspecialchars($e->getMessage()));
    }
    ?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Statystyki — RadioDyplom ADIF</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', sans-serif; background: #f0f4f8; color: #222; padding: 2rem; }
  h1 { font-size: 1.4rem; margin-bottom: 1.5rem; }
  h2 { font-size: 1rem; font-weight: 700; margin-bottom: .75rem; color: #374151; }
  .wrap { max-width: 960px; margin: 0 auto; }
  .cards { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
  .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.1); padding: 1.2rem 1.5rem; flex: 1; min-width: 160px; }
  .card-label { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; margin-bottom: .3rem; }
  .card-value { font-size: 2rem; font-weight: 800; color: #2563eb; }
  .table-card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.1); padding: 1.2rem 1.5rem; margin-bottom: 1.5rem; overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: .88rem; }
  th { text-align: left; padding: .5rem .75rem; background: #f8fafc; font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
  td { padding: .5rem .75rem; border-bottom: 1px solid #f3f4f6; }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: #f9fafb; }
  .cs { font-weight: 700; color: #1d4ed8; font-family: monospace; cursor: pointer; }
  .cs:hover { text-decoration: underline; }
  .back { display: inline-block; margin-bottom: 1.2rem; font-size: .85rem; color: #2563eb; text-decoration: none; }
  .back:hover { text-decoration: underline; }
  footer { text-align: center; margin-top: 1.5rem; font-size: .8rem; color: #999; }
  .chart-row { display: flex; gap: 1rem; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; }
  select.cs-select { padding: .4rem .7rem; border: 1px solid #ccc; border-radius: 6px; font-size: .95rem; background: #fff; }
  .chart-empty { color: #9ca3af; font-size: .85rem; padding: 2rem 0; text-align: center; }
</style>
</head>
<body>
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
        <?php foreach ($top as $r): ?>
        <option value="<?= htmlspecialchars($r['callsign']) ?>"><?= htmlspecialchars($r['callsign']) ?></option>
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
  <a href="https://github.com/enclude/www.radidyplom-adif.zjawa.dev" target="_blank" rel="noopener" style="color:#6b7280;">GitHub</a>
</footer>
<script>
let chart = null;

const sel   = document.getElementById('chart-cs');
const empty = document.getElementById('chart-empty');
const canvas= document.getElementById('myChart');

sel.addEventListener('change', () => loadChart(sel.value));

// klik na znak w tabeli → wybiera go w selekcie i ładuje wykres
document.querySelectorAll('.cs[data-cs]').forEach(el => {
  el.addEventListener('click', () => {
    sel.value = el.dataset.cs;
    loadChart(el.dataset.cs);
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

function renderChart(callsign, data) {
  empty.style.display  = 'none';
  canvas.style.display = 'block';
  if (chart) chart.destroy();
  chart = new Chart(canvas, {
    type: 'line',
    data: {
      labels: data.map(d => d.day),
      datasets: [{
        label: `QSO — ${callsign}`,
        data:  data.map(d => parseInt(d.qso)),
        borderColor:     '#2563eb',
        backgroundColor: 'rgba(37,99,235,.1)',
        borderWidth: 2,
        pointRadius: 4,
        pointBackgroundColor: '#2563eb',
        tension: 0.3,
        fill: true,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 } } },
        y: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 }, precision: 0 }, beginAtZero: true }
      }
    }
  });
}
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
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: Mozilla/5.0 (RadioDyplom ADIF Exporter)'],
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
    $url = sprintf(
        'https://radiodyplom.pl/ajax_participant_qso.php?ses_id=%d&callsign=%s&page=1&limit=1000',
        $ses_id, urlencode($callsign)
    );
    [$response, $httpCode, $curlError] = curl_get($url);
    if ($curlError || $httpCode !== 200) return [];
    $data = json_decode($response, true);
    return $data['qso'] ?? $data['qsos'] ?? $data['data'] ?? [];
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
<title>RadioDyplom → ADIF Export</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', sans-serif; background: #f0f4f8; color: #222; padding: 2rem; }
  .layout { display: flex; gap: 1.5rem; max-width: 960px; margin: 0 auto; align-items: flex-start; }
  .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.1); padding: 1.5rem; flex: 1; }
  .summary-card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.1); padding: 1.5rem; width: 260px; flex-shrink: 0; }
  .summary-card h2 { font-size: 1rem; margin-bottom: 1rem; color: #374151; }
  .stat { margin-bottom: .9rem; }
  .stat-label { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; margin-bottom: .2rem; }
  .stat-value { font-size: 1.6rem; font-weight: 800; color: #2563eb; line-height: 1; }
  .stat-sub { font-size: .8rem; color: #6b7280; margin-top: .2rem; }
  .summary-empty { color: #9ca3af; font-size: .85rem; margin-top: .5rem; }
  @media (max-width: 700px) { .layout { flex-direction: column; } .summary-card { width: 100%; } }
  h1 { font-size: 1.4rem; margin-bottom: .25rem; }
  .subtitle { color: #666; font-size: .9rem; margin-bottom: 1.5rem; }
  label { display: block; font-weight: 600; font-size: .9rem; margin-bottom: .3rem; }
  .input-wrap { position: relative; margin-bottom: 1rem; }
  input[type=text] { width: 100%; padding: .5rem .75rem; border: 1px solid #ccc; border-radius: 6px; font-size: 1rem; text-transform: uppercase; }
  input[type=text]:focus { outline: none; border-color: #4a90d9; box-shadow: 0 0 0 3px rgba(74,144,217,.15); }
  .spinner { display: none; position: absolute; right: 10px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; border: 2px solid #ccc; border-top-color: #2563eb; border-radius: 50%; animation: spin .7s linear infinite; }
  @keyframes spin { to { transform: translateY(-50%) rotate(360deg); } }
  select { width: 100%; padding: .5rem .75rem; border: 1px solid #ccc; border-radius: 6px; font-size: .95rem; margin-bottom: 1rem; background: #fff; }
  select:focus { outline: none; border-color: #4a90d9; }
  select:disabled { background: #f5f5f5; color: #aaa; }
  button[type=submit] { width: 100%; padding: .7rem; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-size: 1rem; font-weight: 700; cursor: pointer; margin-bottom: .5rem; }
  button[type=submit]:hover { background: #1d4ed8; }
  button[type=submit]:disabled { background: #93c5fd; cursor: default; }
  .btn-all { background: #16a34a !important; }
  .btn-all:hover { background: #15803d !important; }
  .btn-all:disabled { background: #86efac !important; }
  .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; border-radius: 6px; padding: .6rem .9rem; margin-bottom: 1rem; font-size: .9rem; }
</style>
</head>
<body>
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

  <p style="margin-top:1rem; font-size:.82rem; color:#6b7280; line-height:1.5; border-top:1px solid #e5e7eb; padding-top:.9rem;">
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

<footer style="text-align:center; margin-top:1.5rem; font-size:.8rem; color:#999;">
  Stworzono przez <strong>Zjawa.IT</strong> &mdash;
  <a href="https://github.com/enclude/www.radidyplom-adif.zjawa.dev" target="_blank" rel="noopener"
     style="color:#6b7280; text-decoration:underline;">GitHub</a>
  &mdash;
  <a href="?page=stats" style="color:#6b7280; text-decoration:underline;">Statystyki</a>
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
  debounceTimer = setTimeout(() => fetchSessions(cs), 500);
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
