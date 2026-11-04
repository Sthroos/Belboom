# Ledencontact – pilotregistratie

Registratie voor LHV-ambassadeurs om ledencontacten tijdens de pilot (1–2 maanden) objectief vast te leggen:
gesprekstijd, overige tijd, soort contact/reden (startend, opzegging onvrede, opzegging neutraal), inhoud,
energie-impact, meerwaarde, en of het ten koste ging van ander werk.

Rollen:

| Rol | Registreren | Statistieken + CSV-export | Gebruikers beheren |
|---|---|---|---|
| Ambassadeur | ja (alleen eigen registraties zichtbaar) | nee | nee |
| Bureaumedewerker | ja | ja | nee |
| Admin | ja | ja | ja (alleen via `bin/create-admin.php` aan te maken) |

Per registratie: soort contact, uitkomst (bereikt / voicemail / niet bereikt), opzegreden (bij opzeggend lid),
vervolgactie (geen / zelf / regiobureau / ledenadministratie / bestuur), tijd, energie en meerwaarde (alleen bij
gevoerd gesprek), inhoud en indruk. Eigen registraties zijn te bewerken en te verwijderen.

Statistieken en evaluatierapport (admin + bureaumedewerker): filter op regio en periode; per soort, uitkomst,
opzegreden, vervolgactie, regio, medewerker (vs. betaalde uren) en maand. Het rapport (`/report.php`) is printbaar
als PDF en bevat automatisch berekende kernbevindingen en geanonimiseerde indrukken.

Logboek (admin, `/audit.php`): inloggen, mislukte/geblokkeerde pogingen, gebruikersbeheer, exports en
wijzigingen/verwijderingen van registraties; 180 dagen bewaard.
- Elke gebruiker hoort bij een van de 19 LHV-afdelingen.

PHP 8.4 + SQLite, geen externe dependencies, geen JavaScript.

## Deploy (Portainer of docker compose)

1. Zet deze map in een Git-repo (privé) of kopieer hem naar de host.
2. Portainer → Stacks → Add stack → Repository (of `docker compose up -d --build`) met env:
   - `APP_BASE_URL=https://ledencontact.jouwdomein.nl` (exact de publieke origin, zonder pad)
   - `TRUSTED_PROXY=<IP of CIDR van je reverse proxy>`, bijv. `192.168.2.41`
3. Reverse proxy (NPM/Traefik/Caddy) met TLS naar `http://<host>:8080`. Pas in `docker-compose.yml` het
   host-IP in `ports` aan als de proxy niet op dezelfde host draait. **Zet geen access-logging met query strings
   aan op de proxy** (uitnodigingstokens staan in de URL), of zet log-retentie kort.
4. Admin aanmaken (eenmalig; ook gebruiken als je jezelf buitensluit):
   ```
   docker compose exec ledencontact php /var/www/app/bin/create-admin.php sebastiaan "Sebastiaan" 8
   ```
   Open de getoonde link, kies wachtwoord, koppel authenticator-app.
5. Gebruikers → je eigen regio instellen; per ambassadeur naam, gebruikersnaam, regio en betaalde uren invullen →
   uitnodigingslink versturen via een ander kanaal (bijv. Signal). De link opent één keer en verdwijnt daarna uit de adresbalk.

Controle: `curl -I https://ledencontact.jouwdomein.nl/login.php` moet o.a. `content-security-policy`,
`strict-transport-security` en een cookie `__Host-sid` met `Secure; HttpOnly; SameSite=Strict` tonen.

## Back-up / opruimen na de pilot

- Back-up: `docker compose exec ledencontact sh -c 'cd /var/www/data && php -r "(new PDO(\"sqlite:app.sqlite\"))->exec(\"VACUUM INTO \x27/tmp/backup.sqlite\x27\");"'`
  en daarna `docker compose cp ledencontact:/tmp/backup.sqlite .`
- Na de pilot: CSV exporteren, daarna `docker compose down -v` (verwijdert het volume met alle data).

## Beveiliging (samenvatting)

- Argon2id-wachtwoorden (min. 12 tekens, NIST SP 800-63B), verplichte TOTP-2FA met replay-blokkade.
- Geen open registratie: alleen admin maakt accounts, via eenmalige uitnodigingslink (72 u, alleen hash opgeslagen).
- Brute-force-limiet per gebruiker (10 pogingen/15 min), race-vrij (poging wordt vóór verificatie geteld); geen
  IP-limiet omdat alle externe clients achter de OpenVPN port-share hetzelfde IP hebben. Gelijke responstijd bij
  onbekende gebruikers.
- Argon2id 19 MiB/t=2 (OWASP) en max. 12 Apache-workers: parallelle logins kunnen de container niet OOM'en.
- Sessies: `__Host-` cookie, Secure/HttpOnly/SameSite=Strict, ID-rotatie bij login, 30 min inactief / 8 u absoluut,
  alle sessies ongeldig bij wachtwoordwijziging, reset of deactivatie.
- CSRF-token + Origin-check op elke POST; uitloggen alleen via POST.
- Prepared statements, output-escaping, strikte CSP zonder inline script/style, CSV-formula-injectie-escaping.
- Invoer: ongeldige UTF-8 geweigerd, control- en bidi-override-tekens verwijderd.
- Rechten server-side afgedwongen: statistieken, export en gebruikersbeheer alleen admin; verwijderen alleen eigen registraties.
- Container: non-root, read-only rootfs, `cap_drop: ALL`, `no-new-privileges`, geen directory listing,
  PHP zonder shell-functies en zonder `allow_url_fopen`, `open_basedir`.
- Logging als JSON naar `docker logs`, zonder wachtwoorden, tokens, gebruikersnamen of inhoud.
- Dataminimalisatie: geen namen van leden; SQLite `secure_delete` overschrijft verwijderde records.
