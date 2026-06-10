# CLAUDE.md — RadioDyplom ADIF Exporter

## Opis projektu

Pojedynczy plik PHP (`index.php`) działający jako proxy między przeglądarką a API radiodyplom.pl. Pobiera dane o łączności krótkofalowców i eksportuje je do formatu ADIF.

## Architektura

Wszystko w jednym pliku `index.php`. Obsługa różnych akcji przez `$_GET['action']` i `$_GET['page']`:

| Endpoint | Opis |
|----------|------|
| `GET /` | Formularz główny |
| `GET /?action=sessions&callsign=X` | AJAX — lista sesji (scraping myAwards.php) |
| `GET /?action=stats&callsign=X` | AJAX — liczba unikalnych QSO (fetchuje wszystkie sesje) |
| `POST /` | Generowanie pliku ADIF (jedna sesja lub wszystkie) |
| `GET /?page=stats` | Strona statystyk z SQLite |

## Zewnętrzne API (radiodyplom.pl)

Nie wymaga autoryzacji — oba endpointy są publiczne:

- `myAwards.php?callsign=X` — zwraca HTML ze strukturą sesji (scraping regexem)
- `ajax_participant_qso.php?ses_id=X&callsign=Y&page=1&limit=1000` — zwraca JSON z łączności. Uwaga: serwer ignoruje `limit` powyżej 100 (`per_page` zawsze 100) — `fetch_qsos()` iteruje po stronach wg `pagination.total_pages`

### Struktura JSON z ajax_participant_qso.php
```json
{
  "qso": [
    {
      "id": 123,
      "qso_date": "2026-05-15",
      "time_on": "18:36:21",
      "callsign": "SP3M",
      "operator": "SQ8BWA",
      "band": "70cm",
      "mode": "DMR",
      "report_sent": "59",
      "report_received": "59",
      "comment": "",
      "points": 5
    }
  ],
  "pagination": { "current_page": 1, "total_pages": 1, "total_qso": 35, "per_page": 100 }
}
```

### Parsowanie myAwards.php

Regex wyciągający ID sesji, nazwę, status i liczbę QSO z jednego przebiegu:
```
/<h2 class="ses-title"[^>]*>\s*<a href="\/ses\/(\d+)">\s*(.*?)\s*<\/a>.*?status-label\s+(status-\w+)[^>]*>\s*(.*?)\s*<\/span>.*?(\d+)\s*QSO/s
```

Klasy statusów: `status-ongoing` (trwa), `status-finished` (zakończona), `status-upcoming` (nadchodząca).

## SQLite

Plik: `stats.db` w katalogu aplikacji (`__DIR__ . '/stats.db'`).

```sql
CREATE TABLE queries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    callsign   TEXT    NOT NULL,
    sessions   INTEGER NOT NULL DEFAULT 0,
    qso_total  INTEGER NOT NULL DEFAULT 0,
    queried_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);
```

Logowanie: przy każdym udanym `?action=sessions` (czyli przy każdym wpisaniu znaku).

## Deduplikacja QSO

Klucz: `operator|qso_date|time_on|band|mode`

Stosowana przy eksporcie "wszystkich sesji" — ten sam QSO w różnych akcjach pojawi się tylko raz.

## Format ADIF

Wersja 3.1.4. Każde pole: `<NAZWA:długość>wartość`. Rekord kończy `<EOR>`.

Mapowanie trybów cyfrowych: FM→FM, DMR/C4FM/DSTAR→DIGITALVOICE z SUBMODE.

## Środowisko Docker

```
webdevops/php-nginx:8.3-alpine
document root: /app
port: 80
```

Zalecane: zablokowanie `/app/stats.db` przez nginx (`deny all` dla `*.db`).

## Pliki projektu

```
index.php       — cała aplikacja
favicon.svg     — ikona SVG (antena + fale radiowe, białe tło)
stats.db        — baza SQLite (auto-tworzona, nie commitować)
README.md       — dokumentacja dla użytkownika
CLAUDE.md       — dokumentacja techniczna dla Claude
```
