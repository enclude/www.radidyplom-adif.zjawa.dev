<?php
declare(strict_types=1);

$error    = '';
$callsign = trim($_POST['callsign'] ?? 'SP3M');
$ses_id   = trim($_POST['ses_id']   ?? '216');

// --- Generowanie ADIF ---
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

function generate_adif(array $qsos, string $myCall, string $sesId): string {
    $ts  = date('Ymd His');
    $out = "RadioDyplom.pl ADIF Export\nCreated: {$ts}\nStation: {$myCall}\n"
         . "<ADIF_VER:5>3.1.4\n<PROGRAMID:12>RadioDyplom\n<EOH>\n\n";

    foreach ($qsos as $q) {
        [$mode, $submode] = normalize_mode($q['mode'] ?? '');
        $date = str_replace('-', '', $q['qso_date'] ?? '');
        $time = substr(str_replace(':', '', $q['time_on'] ?? ''), 0, 6);

        $out .= adif_field('CALL',             $q['operator']         ?? '')
              . adif_field('QSO_DATE',          $date)
              . adif_field('TIME_ON',           $time)
              . adif_field('BAND',              $q['band']             ?? '')
              . adif_field('MODE',              $mode)
              . ($submode ? adif_field('SUBMODE', $submode) : '')
              . adif_field('RST_SENT',          $q['report_sent']      ?? '')
              . adif_field('RST_RCVD',          $q['report_received']  ?? '')
              . adif_field('STATION_CALLSIGN',  $q['callsign']         ?? $myCall)
              . (!empty($q['comment']) ? adif_field('COMMENT', $q['comment']) : '')
              . "<EOR>\n";
    }
    return $out;
}

// --- Obsługa formularza ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $callsign && $ses_id) {
    $url = sprintf(
        'https://radiodyplom.pl/ajax_participant_qso.php?ses_id=%s&callsign=%s&page=1&limit=1000',
        urlencode($ses_id),
        urlencode($callsign)
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (RadioDyplom ADIF Exporter)',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        $error = "cURL error: $curlError";
    } elseif ($httpCode !== 200) {
        $error = "Serwer zwrócił HTTP $httpCode";
    } else {
        $data = json_decode($response, true);
        if (!$data) {
            $error = 'Błąd parsowania JSON.';
        } else {
            $qsos = $data['qso'] ?? $data['qsos'] ?? $data['data'] ?? [];
            if (empty($qsos)) {
                $error = 'Brak łączności w odpowiedzi. Sprawdź ses_id i callsign.';
            } else {
                $adif     = generate_adif($qsos, $callsign, $ses_id);
                $filename = "radiodyplom_ses{$ses_id}_{$callsign}.adi";

                header('Content-Type: text/plain; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"$filename\"");
                header('Content-Length: ' . strlen($adif));
                echo $adif;
                exit;
            }
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
  input[type=text] {
    width: 100%; padding: .5rem .75rem; border: 1px solid #ccc; border-radius: 6px;
    font-size: 1rem; margin-bottom: 1rem;
  }
  input:focus { outline: none; border-color: #4a90d9; box-shadow: 0 0 0 3px rgba(74,144,217,.15); }
  .row { display: flex; gap: 1rem; }
  .row > div { flex: 1; }
  button[type=submit] {
    width: 100%; padding: .7rem; background: #2563eb; color: #fff;
    border: none; border-radius: 6px; font-size: 1rem; font-weight: 700; cursor: pointer;
  }
  button[type=submit]:hover { background: #1d4ed8; }
  .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; border-radius: 6px; padding: .6rem .9rem; margin-bottom: 1rem; font-size: .9rem; }
</style>
</head>
<body>
<div class="card">
  <h1>📡 RadioDyplom → ADIF Export</h1>
  <p class="subtitle">Serwer PHP pobiera dane z radiodyplom.pl i generuje plik ADIF</p>

  <?php if ($error): ?>
  <div class="error">⚠ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <div class="row">
      <div>
        <label for="callsign">Znak wywoławczy</label>
        <input type="text" id="callsign" name="callsign"
               value="<?= htmlspecialchars($callsign) ?>" placeholder="np. SP3M" required>
      </div>
      <div>
        <label for="ses_id">ID sesji (ses_id)</label>
        <input type="text" id="ses_id" name="ses_id"
               value="<?= htmlspecialchars($ses_id) ?>" placeholder="np. 216" required>
      </div>
    </div>
    <button type="submit">⬇ Pobierz plik ADIF</button>
  </form>
</div>
</body>
</html>
