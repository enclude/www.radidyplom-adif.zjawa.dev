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
    echo json_encode($sessions);
    exit;
}

// --- POST: generuj ADIF ---
$error    = '';
$callsign = strtoupper(trim($_POST['callsign'] ?? ''));
$ses_id   = trim($_POST['ses_id']   ?? '');

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $callsign && $ses_id) {
    $url = sprintf(
        'https://radiodyplom.pl/ajax_participant_qso.php?ses_id=%s&callsign=%s&page=1&limit=1000',
        urlencode($ses_id), urlencode($callsign)
    );
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

    if ($curlError) {
        $error = "cURL error: $curlError";
    } elseif ($httpCode !== 200) {
        $error = "Serwer zwrócił HTTP $httpCode";
    } else {
        $data = json_decode($response, true);
        $qsos = $data['qso'] ?? $data['qsos'] ?? $data['data'] ?? [];
        if (empty($qsos)) {
            $error = 'Brak łączności w odpowiedzi. Sprawdź ses_id i callsign.';
        } else {
            $adif     = generate_adif($qsos, $callsign);
            $filename = "radiodyplom_ses{$ses_id}_{$callsign}.adi";
            header('Content-Type: text/plain; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"$filename\"");
            header('Content-Length: ' . strlen($adif));
            echo $adif;
            exit;
        }
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
  button[type=submit] { width: 100%; padding: .7rem; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-size: 1rem; font-weight: 700; cursor: pointer; }
  button[type=submit]:hover { background: #1d4ed8; }
  button[type=submit]:disabled { background: #93c5fd; cursor: default; }
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

    <button type="submit" id="btn-submit" disabled>⬇ Pobierz plik ADIF</button>
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
</footer>

<script>
const callsignInput = document.getElementById('callsign');
const select        = document.getElementById('ses_select');
const spinner       = document.getElementById('spinner');
const btnSubmit     = document.getElementById('btn-submit');
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
    renderSummary(data);
  } catch (e) {
    select.innerHTML = '<option value="">Błąd pobierania sesji</option>';
  } finally {
    spinner.style.display = 'none';
  }
}

function renderSummary(sessions) {
  const totalQso  = sessions.reduce((s, x) => s + x.qso, 0);
  const best      = sessions.reduce((a, b) => b.qso > a.qso ? b : a, sessions[0]);
  const ongoing   = sessions.filter(s => s.status === 'status-ongoing').length;

  document.getElementById('summary-content').innerHTML = `
    <div class="stat">
      <div class="stat-label">Akcje dyplomowe</div>
      <div class="stat-value">${sessions.length}</div>
      <div class="stat-sub">${ongoing > 0 ? `w tym ${ongoing} aktualnie trwające` : 'brak aktualnie trwających'}</div>
    </div>
    <div class="stat">
      <div class="stat-label">Łączności (QSO)</div>
      <div class="stat-value">${totalQso}</div>
      <div class="stat-sub">łącznie na radiodyplom.pl</div>
    </div>
    <div class="stat">
      <div class="stat-label">Najaktywniejsza akcja</div>
      <div class="stat-value" style="font-size:1.1rem; line-height:1.3">${best.name}</div>
      <div class="stat-sub">${best.qso} QSO (ses_id: ${best.id})</div>
    </div>
  `;
}
</script>
</body>
</html>
