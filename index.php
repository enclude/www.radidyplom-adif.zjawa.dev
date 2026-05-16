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
$callsign = trim($_POST['callsign'] ?? '');
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
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', sans-serif; background: #f0f4f8; color: #222; padding: 2rem; }
  .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.1); padding: 1.5rem; max-width: 600px; margin: 0 auto; }
  h1 { font-size: 1.4rem; margin-bottom: .25rem; }
  .subtitle { color: #666; font-size: .9rem; margin-bottom: 1.5rem; }
  label { display: block; font-weight: 600; font-size: .9rem; margin-bottom: .3rem; }
  .input-wrap { position: relative; margin-bottom: 1rem; }
  input[type=text] { width: 100%; padding: .5rem .75rem; border: 1px solid #ccc; border-radius: 6px; font-size: 1rem; }
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
             placeholder="np. SP3M" autocomplete="off" required>
      <div class="spinner" id="spinner"></div>
    </div>

    <label for="ses_select">Sesja</label>
    <select id="ses_select" name="ses_id" disabled required>
      <option value="">— wpisz znak wywoławczy —</option>
    </select>

    <button type="submit" id="btn-submit" disabled>⬇ Pobierz plik ADIF</button>
  </form>
</div>

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
      opt.textContent = `${s.name} — ${s.qso} QSO (${status})`;
      select.appendChild(opt);
    });

    select.disabled    = false;
    btnSubmit.disabled = false;
  } catch (e) {
    select.innerHTML = '<option value="">Błąd pobierania sesji</option>';
  } finally {
    spinner.style.display = 'none';
  }
}
</script>
</body>
</html>
