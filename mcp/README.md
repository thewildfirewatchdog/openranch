# OpenRanch MCP server

Exposes the dashboard's `/api/v1/` as MCP tools. The Telegram bot uses it, and
you can point any MCP client at it — Claude Desktop, for instance — to talk to
your own ranch.

## Design

The server holds **no credentials**. Every tool takes `api_token` and forwards
it; the dashboard decides what that token may see. One process therefore serves
any number of customers without ever having standing access to any of them.

That is also why it binds loopback only and refuses to start otherwise: a
request is authorised entirely by an argument, so anything that can reach the
port can act as any customer whose token it holds.

## Tools

| Tool | Reads / Acts |
|---|---|
| `whoami` | which account a token belongs to |
| `list_devices` | devices with the latest reading per variable |
| `device_history` | one metric over N hours, with min/max/latest |
| `list_zones` | zones, what is running, next run, rain delay |
| `list_programs` | schedules and per-zone durations |
| `list_rules` | automations |
| `recent_notifications` | notification and skip log |
| `ranch_summary` | everything a briefing needs, in one call |
| `start_zone` | **acts** — opens a zone |
| `stop_zone` / `stop_all_zones` | **acts** — closes zones |
| `run_program_once` | **acts** — runs a program now |
| `set_rain_delay` | **acts** — holds or clears the schedule |
| `add_notification_rule` | **acts** — creates a notification automation |
| `delete_rule` | **acts** — deletes an automation |

The tools marked **acts** move real hardware. The bot gates each one behind an
explicit confirmation; any other client is responsible for its own gate.

Only notification automations can be created here. Automations that command
hardware or start a zone are deliberately left to the Automations page, where
the consequences are visible before they are saved.

## Run

```bash
/opt/openranch-bot/venv/bin/python mcp/openranch_mcp.py
```

Reads `MCP_HOST`, `MCP_PORT` and `OPENRANCH_API_BASE` from
`/opt/openranch-bot/.env` (override the path with `OPENRANCH_ENV`). The
systemd unit is in [`../bot/`](../bot/).

## Pointing Claude Desktop at it

Generate an API token on the dashboard's Assistant page, then add an HTTP MCP
server at `http://127.0.0.1:8765/mcp` and pass your token as `api_token`. Over a
network you would need a tunnel — do not publish the port.
