# Random-Surprise
Get a random person to gift

## Configuratie via `.env`

Maak een `.env`-bestand in de projectroot op basis van `.env.example`.

Voorbeeld:

```ini
APP_BASE_URL=https://jouw-domein.example
DB_HOST=127.0.0.1
DB_NAME=evenementen_planner
DB_USER=root
DB_PASS=
```

`APP_BASE_URL` wordt gebruikt om absolute links op te bouwen, bijvoorbeeld voor herinneringsmails.

## Foutafhandeling en validatie

POST-formulieren volgen hetzelfde patroon:
- valideer server-side en verzamel concrete foutmeldingen per veld;
- toon bovenaan een samenvatting via `renderErrorSummary()`;
- geef velden en foutdoelen een stabiel id via `fieldErrorId()` zodat de samenvatting naar het juiste veld kan linken;
- markeer individuele velden met `fieldErrorClass()` en `firstFieldError()`;
- log technische details server-side met `logApplicationError()`, maar toon gebruikers alleen veilige meldingen.

Gebruik voor nieuwe formulieren dezelfde aanpak, zodat invoer behouden blijft en gebruikers direct zien wat ontbreekt of fout is.
