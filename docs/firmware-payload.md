# Firmware payload reference

Everything a board needs to talk to OpenRanch. Three endpoints, all JSON over
HTTPS, all authenticated with a header rather than a body field.

| Endpoint | Method | Auth header | Purpose |
|---|---|---|---|
| `/ingest.php` | POST | `Device-Token` | send readings |
| `/poll.php` | GET | `Device-Token` | fetch the pending command |
| `/register.php` | POST | `Provision-Key` | claim a device row on first boot |

> **Wire contract.** The JSON keys (`variable`, `value`, `cmd`), the header
> names, and your device's variable names are fixed. Renaming any of them
> breaks boards already in the field.

---

## 1. Sending readings — `POST /ingest.php`

Header: `Device-Token: <the device's 32-character token>`

Two body shapes are accepted and behave identically. Use whichever your sketch
produces more naturally.

**List form**

```json
[
  {"variable": "pressure_psi", "value": 123.4},
  {"variable": "rssi",         "value": -61}
]
```

**Flat object form**

```json
{"pressure_psi": 123.4, "rssi": -61}
```

### Response

```json
{"status": "ok", "saved": 2}
```

`saved` is the number of readings actually stored. **A 200 with `"saved":0` means
your data was thrown away** — see the rules below.

### Rules that will bite you

- **Values must be numeric.** Send `1` and `0`, not `true` and `false`. A JSON
  boolean is silently dropped: it is not numeric, so it never reaches the
  database and `saved` does not count it. This is the single most common reason
  a variable never appears on the dashboard.
- **Variables must be declared.** `ingest.php` drops any variable not named in
  that device's `variables` list. Add it in `admin.php` first. This is what
  keeps a half-built template clean.
- **The device must be enabled.** A device with `enabled = 0` is a template and
  gets `403`. Flip it on in `admin.php`.
- **The token decides the device.** If your body also contains a `slug`, it is
  ignored, never obeyed — a board cannot write into another device's history.
- Repeating a variable in one batch stores every row; the newest wins for
  threshold evaluation.

### Status codes

| Code | Meaning |
|---|---|
| 200 | Stored. Check `saved`. |
| 400 | Body was not JSON. |
| 401 | Missing or unknown `Device-Token`. |
| 403 | Device exists but is still a template (`enabled = 0`). |

### curl

```bash
curl -X POST https://dashboard.example.com/ingest.php \
  -H 'Device-Token: REPLACE_WITH_DEVICE_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '[{"variable":"pressure_psi","value":123.4},{"variable":"rssi","value":-61}]'
```

Flat form, and a boolean sent correctly as `1`:

```bash
curl -X POST https://dashboard.example.com/ingest.php \
  -H 'Device-Token: REPLACE_WITH_DEVICE_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"relay_state":1,"flow_gpm":12.5,"heartbeat":9911}'
```

---

## 2. Fetching a command — `GET /poll.php`

Header: `Device-Token: <token>`

```json
{"cmd": 1, "value": 1}
```

`cmd` and `value` always carry the same number; `value` exists so sketches
written against a `?variable=cmd` style API parse it unchanged.

| Value | Meaning |
|---|---|
| `-1` | no command has ever been sent |
| `0` | OFF / stop |
| `1` | ON / start |
| `2` | one-shot action (a flow meter reads this as "zero the running total") |
| `3`–`5` | board-specific auxiliary codes |

The command **latches**: polling does not clear it, and you will read the same
value until the dashboard writes a new one. Act on transitions, not on every
poll. For one-shot code `2`, the dashboard writes `0` about two seconds later so
a slow poller cannot act on it twice.

A device with `commandable = 0` still gets a valid response; it simply never has
a command to read. `403` means the device is not enabled.

```bash
curl https://dashboard.example.com/poll.php \
  -H 'Device-Token: REPLACE_WITH_DEVICE_TOKEN'
```

---

## 3. Self-registration — `POST /register.php`

Lets a board claim its own device row on first boot instead of you adding it by
hand. Header: `Provision-Key: <PROVISION_KEY from config.php>`

```json
{
  "mac":         "AA:BB:CC:DD:EE:FF",
  "name":        "Ridge Sprinkler",
  "variables":   "sprinkler_state,battery_v,rssi",
  "interval":    60,
  "commandable": 1,
  "notes":       "roof unit"
}
```

`mac` and `variables` are required; `mac` must be uppercase-or-lowercase hex in
`AA:BB:CC:DD:EE:FF` form.

### Response

```json
{"status": "created", "slug": "ridge_sprinkler", "token": "...", "note": "..."}
```

`status` is `created` the first time and `exists` afterwards. **It is idempotent
by MAC** — calling it on every boot returns the same slug and token rather than
creating a second row, so firmware can call it unconditionally.

Store the returned `token` in NVS/EEPROM and use it for `ingest.php` and
`poll.php` from then on. The `Provision-Key` is only ever needed for this call.

New devices arrive `enabled = 0`, so their readings are rejected until someone
turns them on in `admin.php`. That is deliberate: a bench board cannot quietly
start filling the dashboard.

```bash
curl -X POST https://dashboard.example.com/register.php \
  -H 'Provision-Key: REPLACE_WITH_PROVISION_KEY' \
  -H 'Content-Type: application/json' \
  -d '{"mac":"AA:BB:CC:DD:EE:FF","name":"Ridge Sprinkler",
       "variables":"sprinkler_state,battery_v,rssi","interval":60,"commandable":1}'
```

> **A swapped ESP module is a new device.** Registration is keyed on MAC, so
> replacing the module creates a second row. The old row keeps its history;
> reassign or delete it in `admin.php`.

---

## Reporting interval

Set `expected_interval` (seconds) to how often the board actually reports. The
dashboard marks a device **STALE** at `3 x expected_interval` with no data, so
a value that is too low produces false staleness and one that is too high hides
a dead board.

## A minimal ESP32 loop

```cpp
// Pseudocode. Error handling omitted for brevity — do not omit it in yours.
void reportAndPoll() {
  HTTPClient http;
  http.begin("https://dashboard.example.com/ingest.php");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Device-Token", DEVICE_TOKEN);

  // Booleans as 1/0, never true/false.
  String body = String("{\"relay_state\":") + (relayOn ? 1 : 0) +
                ",\"rssi\":" + WiFi.RSSI() +
                ",\"heartbeat\":" + millis() / 1000 + "}";
  http.POST(body);
  http.end();

  http.begin("https://dashboard.example.com/poll.php");
  http.addHeader("Device-Token", DEVICE_TOKEN);
  if (http.GET() == 200) {
    int cmd = parseCmd(http.getString());   // -1 = nothing pending
    if (cmd != lastCmd) { applyCommand(cmd); lastCmd = cmd; }
  }
  http.end();
}
```

## Troubleshooting

| Symptom | Cause |
|---|---|
| `200` but `"saved":0` | Variable not in the device's list, or values sent as JSON booleans |
| `401 unknown token` | Token doesn't match any device; re-register or copy it again from `admin.php` |
| `403 template` | Device is `enabled = 0`; enable it in `admin.php` |
| Card shows STALE | No data for `3 x expected_interval`; check the board or raise the interval |
| Variable missing from the card | Not declared in `variables` |
| Buttons don't appear | Device has `commandable = 0` |
