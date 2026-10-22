# LoRaWAN sensors

OpenRanch does not talk to LoRa radios itself. A **network server** does that —
The Things Stack (TTN), or your own ChirpStack — and forwards each uplink to
`lorawan.php` as an HTTP webhook. From then on a LoRaWAN sensor is an ordinary
device: same cards, graphs, thresholds, notifications and MQTT.

```
sensor ))) gateway ──► network server ──HTTP──► lorawan.php ──► dashboard
                       (TTN / ChirpStack)
```

> Same rule as the rest of OpenRanch: **monitoring only, not a safety device.**
> LoRaWAN uplinks are best effort; packets get lost and gateways go offline.

## 1. Switch the receiver on

In `config.php`:

```php
define('LORAWAN_KEY', '<long random string>');   // php -r 'echo bin2hex(random_bytes(24)), "\n";'
define('LORAWAN_INTERVAL', 900);                 // seconds between uplinks, for the LIVE/STALE dot
```

Existing installs also run `mysql <db> < migrate_lorawan.sql` once (adds
`devices.dev_eui`). Fresh installs get it from `schema.sql`.

## 2. Decode the payload on the network server

OpenRanch stores the **decoded** values, never raw bytes. Every sensor model
packs its bytes differently, so set a payload decoder for the device on the
network server. Both TTN's device repository and ChirpStack's device profiles
ship decoders for most Dragino, Milesight, Browan, etc. sensors — pick the
model and it is filled in for you.

What arrives must be a JSON object, for example
`{"level_pct": 62, "BatV": 3.61, "float_ok": true}`:

- numbers and numeric strings are stored; `true`/`false` become `1`/`0`
- one level of nesting is flattened: `{"bat": {"v": 3.6}}` → `bat_v`
- text values are skipped; up to 40 values per uplink
- `rssi` and `snr` from the gateway that heard it best are added automatically

## 3a. The Things Stack (TTN)

Console → your application → **Integrations → Webhooks → Add webhook → Custom**

| Field | Value |
|---|---|
| Webhook format | JSON |
| Base URL | `https://<your dashboard>/lorawan.php` |
| Additional header | `X-OpenRanch-Key` = your `LORAWAN_KEY` |
| Enabled event types | **Uplink message** only |

## 3b. ChirpStack v4

Application → **Integrations → HTTP**

| Field | Value |
|---|---|
| Payload encoding | JSON |
| Event endpoint URL | `https://<your dashboard>/lorawan.php` |
| Headers | `X-OpenRanch-Key` = your `LORAWAN_KEY` |

ChirpStack appends `?event=up`, `?event=join`, … itself; only `up` is stored.

## 4. Approve the device

The first uplink from a new DevEUI creates a **template** device — disabled,
unassigned, named after the device on the network server, with its variables
taken from the payload. Later uplinks keep adding any new variable names while
it is still a template. Then, exactly like a WiFi board from `register.php`:

- enable it in `admin.php` (the DevEUI shows in the MAC / DevEUI column), or
- give the owner its claim code to claim it into their account.

Once enabled the variable list is fixed, and only those variables are stored.

## Responses

`lorawan.php` answers 200 to everything it understood, including uplinks it
chose not to store (`registered`, `template`, `ignored`), so the network server
never decides the webhook is broken and turns it off. Errors:

| Code | Meaning |
|---|---|
| 401 | missing or wrong `X-OpenRanch-Key` |
| 400 | not JSON, not a TTN/ChirpStack message, or no valid DevEUI |
| 404 | `LORAWAN_KEY` is empty — the receiver is off |

## Not supported yet

- **Downlinks** (switching a LoRaWAN relay from the dashboard). Commands still
  only reach WiFi boards through `poll.php`.
- Network servers other than TTN and ChirpStack.
