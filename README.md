# Random-Surprise
Get a random person to gift

## Foutafhandeling en validatie

POST-formulieren volgen hetzelfde patroon:
- valideer server-side en verzamel concrete foutmeldingen per veld;
- toon bovenaan een samenvatting via `renderErrorSummary()`;
- markeer individuele velden met `fieldErrorClass()` en `firstFieldError()`;
- log technische details server-side met `logApplicationError()`, maar toon gebruikers alleen veilige meldingen.

Gebruik voor nieuwe formulieren dezelfde aanpak, zodat invoer behouden blijft en gebruikers direct zien wat ontbreekt of fout is.
