# RadioDyplom ADIF Exporter

Narzędzie webowe do eksportu łączności z [radiodyplom.pl](https://radiodyplom.pl) do formatu **ADIF** (Amateur Data Interchange Format), który można zaimportować do logów takich jak QRZ.com, HAMLOG, Log4OM itp.

## Funkcje

- Wpisanie znaku wywoławczego automatycznie ładuje listę sesji (akcji dyplomowych)
- Eksport pojedynczej sesji do pliku `.adi`
- Eksport wszystkich sesji naraz (z deduplikacją — te same QSO w różnych akcjach są scalane)
- Podsumowanie: liczba akcji, wszystkie QSO, unikalne QSO, najaktywniejsza akcja
- Statystyki zapytań zapisywane w SQLite (`?page=stats`)
- Timestamp w nazwie pliku wg czasu warszawskiego

## Uruchomienie (Docker)

```bash
mkdir -p /opt/nginx/radidyplom-adif.zjawa.dev
cp index.php favicon.svg /opt/nginx/radidyplom-adif.zjawa.dev/

docker run -d \
  --name radidyplom-adif_zjawa_dev \
  -p 8135:80 \
  -v /opt/nginx/radidyplom-adif.zjawa.dev:/app:rw \
  webdevops/php-nginx:8.3-alpine
```

Strona dostępna pod: `http://localhost:8135/`  
Statystyki: `http://localhost:8135/?page=stats`

## Uruchomienie lokalne (PHP CLI)

```bash
cd /ścieżka/do/plików
php -S localhost:8080
```

Strona dostępna pod: `http://localhost:8080/`

## Pliki

| Plik | Opis |
|------|------|
| `index.php` | Główna aplikacja |
| `favicon.svg` | Ikona (antena + fale radiowe) |
| `stats.db` | Baza SQLite z logiem zapytań (tworzona automatycznie) |

## Bezpieczeństwo

Plik `stats.db` jest dostępny jako plik statyczny przez nginx. Zaleca się zablokowanie dostępu do niego przez konfigurację nginx:

```nginx
location ~* \.db$ {
    deny all;
    return 403;
}
```

Dla obrazu `webdevops/php-nginx` utwórz plik `nginx-deny-db.conf` i zamontuj go:

```bash
-v /opt/nginx/radidyplom-adif.zjawa.dev/nginx-deny-db.conf:/opt/docker/etc/nginx/vhost.common.d/deny-db.conf:ro
```

Zawartość pliku `nginx-deny-db.conf`:
```nginx
location ~* \.db$ {
    deny all;
    return 403;
}
```

## Format ADIF — mapowanie trybów

| Tryb na radiodyplom.pl | ADIF MODE | ADIF SUBMODE |
|------------------------|-----------|--------------|
| FM | FM | — |
| DMR | DIGITALVOICE | DMR |
| C4FM | DIGITALVOICE | C4FM |
| DSTAR | DIGITALVOICE | D-STAR |
| SSB | SSB | — |
| CW | CW | — |

## Deduplikacja

Klucz unikalności QSO: `operator + data + czas + pasmo + tryb`.  
QRZ.com dodatkowo nie doda ponownie łączności, która już istnieje w logu.

## Autor

Zjawa.IT — [GitHub](https://github.com/enclude/www.radidyplom-adif.zjawa.dev)
