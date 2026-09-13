# `/api/v1/` — the assistant API

A small JSON API over the dashboard, used by the [MCP server](../mcp/) and
anything else you want to point at your own ranch.

## Authentication

Generate a token on **More → Telegram assistant**. Send it on every request:

```
Authorization: Bearer <api_token>
```

`X-API-Token: <token>` also works. Every query is scoped to that token's
customer; no endpoint can reach another account's rows. Regenerating the token
invalidates the old one immediately — that is how you revoke a client.

Three endpoints act **without** a customer token, because they are how a chat
acquires one. They are gated on `X-Bot-Secret`, matched against
`BOT_SHARED_SECRET` in `config.php`: `resolve`, `bind-next`, `briefing-list`
(and `set-voice`). `link` needs neither — the short-lived code is its authority.

## Mirrored devices

Devices copied from another install (`devices.is_mirrored = 1`) are readable
**only** by the admin account — the one whose email matches `ALERT_EMAIL` — and
are never controllable by anyone. `controllable` is false on every one of them.

## Endpoints

| Method | Path | Body / query | Returns |
|---|---|---|---|
| GET | `/me` | — | who the token belongs to, and preferences |
| GET | `/devices` | — | devices, latest reading per variable, `offline`, `controllable`, `mirrored` |
| GET | `/history` | `device`, `metric`, `hours` (≤720) | points plus min/max/latest |
| GET | `/zones` | — | zones, what is running, next run, rain delay |
| POST | `/zones/start` | `zone_id`, `minutes` | opens a zone |
| POST | `/zones/stop` | `zone_id` | closes a zone |
| POST | `/zones/stopall` | — | closes everything |
| GET | `/programs` | — | programs, per-zone durations, next run |
| POST | `/programs/run` | `program_id` | runs a program once, now |
| POST | `/rain-delay` | `hours` (0 clears) | holds every program |
| GET | `/rules` | — | automations |
| POST | `/rules` | `name`, `device`, `metric`, `op`, `value`, `for_minutes`, `message` | creates a **notification** automation |
| POST | `/rules/delete` | `rule_id` | deletes one |
| GET | `/notifications` | `hours` | notification / skip log |
| GET | `/summary` | `hours` | everything a briefing needs |
| POST | `/link` | `code`, `chat_id` | binds a Telegram chat |

Only `notify` automations can be created through this API. Automations that
command hardware or start a zone are built on the Automations page, where you
can see what they will do before saving.

## Examples

```bash
TOKEN=...   # from the Assistant page
BASE=https://dashboard.example.com/api/v1

curl -s -H "Authorization: Bearer $TOKEN" "$BASE/devices"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/history?device=tank_sensor&metric=tank_level_pct&hours=48"

curl -s -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"zone_id":3,"minutes":12}' "$BASE/zones/start"

curl -s -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"hours":24}' "$BASE/rain-delay"
```

## Errors

`{"error": "..."}` with a matching status: `401` bad or missing token, `404`
unknown endpoint or a row that is not yours, `409` the action cannot apply
(zone already running, device not commandable), `400` malformed input.

A row belonging to another customer returns `404`, not `403` — whether it exists
is not this token's business.

## Routing

The API is one front controller at `api/v1/index.php`. nginx needs to send the
whole prefix to it, or `/api/v1/devices` falls through to the dashboard and
returns HTML:

```nginx
location /api/v1/ {
    try_files $uri /api/v1/index.php?$query_string;
}
```
