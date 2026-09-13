# OpenRanch Dashboard

A small, self-hosted dashboard for ESP32/ESP8266 devices around a property —
pumps, tanks, flow meters, valves, gauges, sensor boxes. Boards POST their
readings over HTTPS and poll for commands; the dashboard shows every device as
a live card, charts any variable on tap, and can send simple ON/OFF commands
back.

It is deliberately boring: plain PHP, MySQL and nginx. No framework, no build
step, no package manager, no JavaScript bundle. Drop the files on a server,
import one schema, and it runs.

> ### ⚠️ Not a safety device
>
> **OpenRanch is for monitoring and measurement only. It provides no alarm,
> safety, emergency, or life-protection function of any kind.**
>
> Do not use it to protect people, animals, buildings, or property from fire,
> flood, freezing, gas, intrusion, equipment failure, or any other hazard. Do
> not rely on it to detect an emergency, to summon help, or to shut anything
> down in time to prevent harm.
>
> The words "alarm", "warn" and "limit" in this software describe **coloured
> states on a web page**, nothing more. They are not a life-safety alarm system,
> are not certified or listed by any standards body (UL, FM, EN, or otherwise),
> and are not monitored by anyone.
>
> The software is best-effort and will miss things. Notifications depend on
> mains power, your WiFi, your internet connection, your server, your MTA or
> push provider, and your phone being awake and in coverage — any one of which
> can fail silently and without warning. Readings may be stale, wrong, or
> absent. Commands may never reach a board. Data older than `RETENTION_DAYS`
> is deleted automatically.
>
> Where a failure could injure someone or cause loss, use equipment designed
> and certified for that purpose — a listed fire alarm system, a hardware
> float switch, a mechanical pressure relief valve, a thermal cutoff — and
> make it work without this software. Treat anything OpenRanch tells you as
> information, never as protection.
>
> The MIT licence text applies in full: this software comes with **no warranty
> of any kind**, and the authors are not liable for any damages arising from
> its use.

---

## Screenshots

<!-- TODO: replace these placeholders with real screenshots. -->

| Dashboard | Flow meter card | Admin |
|---|---|---|
| _`docs/img/dashboard.png` — the device grid, live / stale / template cards_ | _`docs/img/flow-meter.png` — GPM readout, totals, 7-day usage chart_ | _`docs/img/admin.png` — adding a device and assigning it to a customer_ |

---

## What you get

- **A card per device.** `TEMPLATE` (not programmed yet), `WAITING` (on, no
  data yet), `LIVE`, or `STALE` (silent for more than 3x its expected
  interval). The page refreshes every 10 seconds without reloading.
- **Charts on tap.** Tap any variable row for a 24-hour chart, fetched on
  demand rather than up front.
- **Thresholds with hysteresis.** Set low/high warn and alarm limits per
  variable. The decision is made once at ingest and stored, so the dashboard
  paints a state instead of re-deriving it. Gauge cards draw the limits as tick
  marks.
- **A dedicated flow meter card.** Any device reporting both `flow_gpm` and
  `total_gal` gets a large GPM readout, total/last-hour tiles, a status light,
  and a 7-day daily usage chart.
- **Commands.** ON/OFF (and board-specific auxiliary codes) written to a slot
  the board polls. Guarded by the admin PIN, or by a customer session.
- **Multi-customer.** Accounts created by the administrator — there is no
  self-signup. A signed-in customer sees only their own devices.
- **Self-registration.** A board can claim its own device row on first boot
  with a shared provisioning key, idempotent by MAC.
- **Installable PWA** with offline page, and optional Web Push notifications.

## Requirements

- **Ubuntu** 22.04 or 24.04 (any modern Linux works; the installer assumes
  Debian/Ubuntu paths)
- **nginx**
- **PHP 8.1+** with `php-fpm`, `php-mysql`, `php-curl`, `php-mbstring`
- **MySQL 8** or **MariaDB 10.6+**
- **HTTPS** — required, not optional. The PWA, service worker and Web Push all
  need a secure context. [certbot](https://certbot.eff.org/) is free.
- A mail transfer agent if you want email notifications (`mail()` must work)

```bash
sudo apt update
sudo apt install nginx mysql-server php-fpm php-mysql php-curl php-mbstring certbot python3-certbot-nginx
```

## Install

Ten steps. Steps 4–8 are what `install.sh` does for you.

1. **Point a DNS A record** at your server and make sure ports 80 and 443 are
   open.
2. **Install the packages** listed above.
3. **Get the code.**
   ```bash
   git clone https://github.com/thewildfirewatchdog/openranch.git
   cd openranch
   ```
4. **Run the installer.** It prompts for the database name, credentials, site
   name, URL, admin PIN and timezone, generating sensible values where you
   press Enter.
   ```bash
   sudo ./install.sh
   ```
5. It **creates the database and a least-privilege user** (`SELECT`, `INSERT`,
   `UPDATE`, `DELETE` only — no `CREATE` or `DROP` once installed).
6. It **imports `schema.sql`** — all eight tables, plus four `example_*`
   template devices so the dashboard isn't empty on first load.
7. It **writes `config.php`** from `config.example.php` with your answers
   filled in. `config.php` is gitignored and never committed.
8. It **sets permissions**: the tree `root:www-data`, and `config.php` mode
   `640` so only the web server can read your secrets.
9. **Add the nginx server block** the installer prints, then get a certificate:
   ```bash
   sudo ln -s /etc/nginx/sites-available/openranch /etc/nginx/sites-enabled/
   sudo nginx -t && sudo systemctl reload nginx
   sudo certbot --nginx -d dashboard.example.com
   ```
10. **Open `/admin.php`** and enter your admin PIN. You'll see the four
    `example_*` templates. Register your first real board (see *Adding a
    device* below), enable it, and copy its token into your firmware. Delete
    the examples when you don't need them:
    ```sql
    DELETE FROM devices WHERE slug LIKE 'example_%';
    ```

### Upgrading

Pull and copy the files over your web root. Leave `config.php` alone — it is
yours. Re-import `schema.sql` if a release adds a table; every statement in it
is `IF NOT EXISTS`, so it is safe to re-run.

## Adding a device

A device row can be created two ways. `admin.php` does not create devices — it
enables them, shows and regenerates their tokens, and assigns them to
customers.

### Option A — let the board register itself (recommended)

The board POSTs once to `/register.php` with the shared `Provision-Key` and
gets back its own slug and token. It is idempotent by MAC, so firmware can call
it on every boot:

```bash
curl -X POST https://dashboard.example.com/register.php \
  -H 'Provision-Key: REPLACE_WITH_PROVISION_KEY' \
  -H 'Content-Type: application/json' \
  -d '{"mac":"AA:BB:CC:DD:EE:FF","name":"Ridge Sprinkler",
       "variables":"sprinkler_state,battery_v,rssi","interval":60,"commandable":1}'
```

```json
{"status":"created","slug":"ridge_sprinkler","token":"...","note":"..."}
```

### Option B — insert the row yourself

```sql
INSERT INTO devices (slug, name, token, variables, commandable, enabled, expected_interval, notes)
VALUES ('ridge_sprinkler', 'Ridge Sprinkler', MD5(RAND()),
        'sprinkler_state,battery_v,rssi', 1, 0, 60, 'roof unit');
```

### The fields, and why they matter

| Field | Notes |
|---|---|
| `slug` | Lowercase identifier used in URLs and by thresholds. Changing it later orphans that device's threshold rows. |
| `name` | Free text, shown on the card. |
| `variables` | Comma-separated **allow-list**. `ingest.php` silently drops anything not named here — this is what keeps a half-built template clean. Add the variable before the board sends it. |
| `expected_interval` | Seconds between reports. The card goes `STALE` at 3x this, so make it match reality. |
| `commandable` | `1` if the board polls for ON/OFF commands. |
| `enabled` | `0` = template: shown as a shell, and its readings are **rejected with 403** until you turn it on. |

### Then, in `/admin.php` (admin PIN required)

1. Find the device in the list and **copy its token** into your firmware.
2. **Enable** it once the board is really programmed — until then `ingest.php`
   rejects its data, so a bench board can't quietly fill the dashboard.
3. Optionally **regenerate the token** if it has leaked, or **assign the device
   to a customer** from the dropdown. Unassigned devices belong to the operator
   and are visible to anonymous visitors.

Full registration reference: [docs/firmware-payload.md](docs/firmware-payload.md).

## How a device posts readings

`POST /ingest.php` with a `Device-Token` header. Two body shapes are accepted:

```json
[{"variable": "pressure_psi", "value": 123.4}, {"variable": "rssi", "value": -61}]
```

```json
{"pressure_psi": 123.4, "rssi": -61}
```

```bash
curl -X POST https://dashboard.example.com/ingest.php \
  -H 'Device-Token: REPLACE_WITH_DEVICE_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '[{"variable":"pressure_psi","value":123.4},{"variable":"rssi","value":-61}]'
```

```json
{"status": "ok", "saved": 2}
```

**`saved` is the number actually stored.** A `200` with `"saved":0` means your
data was dropped — almost always because the variable isn't in the device's
list, or because values were sent as JSON booleans. **Send `1` and `0`, never
`true` and `false`** — non-numeric values are discarded.

To fetch a pending command:

```bash
curl https://dashboard.example.com/poll.php -H 'Device-Token: REPLACE_WITH_DEVICE_TOKEN'
# {"cmd":1,"value":1}     -1 = nothing pending
```

Full reference, including self-registration, status codes, a sample ESP32 loop
and a troubleshooting table: **[docs/firmware-payload.md](docs/firmware-payload.md)**.

## Configuration

Everything lives in `config.php`, created from
[`config.example.php`](config.example.php), which documents every constant.
The ones you are most likely to change:

| Constant | Purpose |
|---|---|
| `DB_*` | Database connection |
| `ADMIN_PIN` | Guards every write from a browser that isn't a signed-in customer |
| `RETENTION_DAYS` | How long readings are kept. `ingest.php` prunes older rows automatically |
| `FLOW_TZ` | Timezone for daily-chart day boundaries (readings are always stored UTC) |
| `PROVISION_KEY` | Shared secret for self-registering boards |
| `VAPID_*` | Web Push keypair; leave empty to disable push and use email |
| `ALERT_EMAIL` | Where outage notifications go |

## Security notes

- `config.php` holds every secret. Keep it mode `640`, and make sure your web
  server never serves it — or any `*.bak` copy of it — as plain text. The
  server block from `install.sh` denies both.
- The **admin PIN is the whole of admin authentication.** Make it long and
  random, and serve the site over HTTPS only.
- **Device tokens are bearer credentials.** Anyone holding one can post
  readings as that device.
- There is no rate limiting. If the endpoints are internet-facing and you need
  it, put it in nginx.

## Project layout

```
index.php            dashboard + JSON data endpoint + 24h history
admin.php            device and customer management (PIN)
ingest.php           device -> server: readings
poll.php             device <- server: pending command
register.php         device self-provisioning
cmd.php              dashboard -> command slot
thresholds.php       threshold editor API
thresholds_lib.php   threshold evaluation and hysteresis
daily.php            7-day daily usage aggregation
alerts.php           outage notification sweep
subscribe.php        Web Push subscription management
wpush.php            VAPID signing and push delivery
pump.php             full-screen single-device control page
login.php logout.php customer sessions
sw.js pwa.js         service worker and install prompt
schema.sql           complete database schema
config.example.php   configuration template
install.sh           installer
```

## Licence

MIT — see [LICENSE](LICENSE). Copyright (c) 2026 Remote Control Ranch LLC.

Contributions welcome: see [CONTRIBUTING.md](CONTRIBUTING.md).
