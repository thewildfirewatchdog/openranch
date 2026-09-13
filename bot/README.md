# OpenRanch Telegram assistant

Ask about the ranch in plain English, by text or by voice. The bot is a thin
shell: Claude does the talking, the [MCP server](../mcp/) provides the tools,
and the dashboard's `/api/v1/` decides what is allowed.

```
Telegram ──▶ bot ──▶ Claude ──▶ MCP tools ──▶ /api/v1/ ──▶ dashboard DB
                │                                             │
                └── faster-whisper (in) / Piper (out)          └── commands table ──▶ boards
```

Nothing here talks to hardware directly. Actions end up as rows in the same
`commands` table the dashboard's own buttons write, so firmware is unaffected.

## What it can do

Read anything on the linked account — devices and their latest readings, a
metric's history, zones, programs, automations, the notification and skip log —
and act on it: start or stop a zone, run a program once, hold the schedule, add
or delete a notification automation.

## Three layers of safety

1. **The API token decides what exists.** Every tool call carries one customer's
   token and the dashboard scopes every query to that customer. There is no
   endpoint that reaches another account.
2. **Actions are gated in code.** The first call to anything that moves hardware
   returns `confirmation_required` and touches nothing; the bot then asks, with
   Yes/No buttons. Only a button press or a plain "yes" runs it, and the
   confirmation is single-use. The model cannot talk its way past this.
3. **The system prompt** sets tone and the confirm-first habit. It is the
   weakest layer and is never relied on alone.

Mirrored devices (copied from another install) are readable by the admin account
only and are never controllable — `irr_send_cmd()` and `cmd.php` refuse them,
and the API offers no path around that.

## Linking a chat

1. Sign in to the dashboard, open **More → Telegram assistant**.
2. Tap **Get a link code** — six characters, good for 15 minutes, one use.
3. Message the bot: `/link ABC234`.

`/voice on|off` switches voice replies. Unlinked chats get `/start` and `/link`
and nothing else — no device is visible until a chat is bound to an account.

## Voice

A voice note is transcribed with faster-whisper (`small`, CPU) and handled as if
it had been typed; the bot echoes what it heard so a misheard word is obvious.
Replies carry a Piper voice note when the customer wants one — on by default for
a spoken question, off for a typed one.

On two cores, expect ~15-20s to transcribe a short clip. A larger Whisper model
is more accurate and considerably slower; `WHISPER_MODEL` is the dial.

## Morning briefing

`briefing.py` runs at 07:00 ranch-local (`CRON_TZ` in the cron file, because the
host runs UTC) and sends everyone with the toggle on a few sentences about the
last 24 hours — levels, what watered, what did not and why, anything quiet for
more than 12 hours, and the day's weather decision. `--dry-run` prints instead
of sending.

## Install

```bash
sudo useradd --system --home-dir /opt/openranch-bot --shell /usr/sbin/nologin openranch-bot
sudo mkdir -p /opt/openranch-bot && cd /opt/openranch-bot
sudo python3 -m venv venv
sudo ./venv/bin/pip install fastmcp mcp anthropic python-telegram-bot requests faster-whisper piper-tts
sudo apt-get install ffmpeg

# a Piper voice
sudo mkdir -p voices && cd voices
sudo curl -LO https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/lessac/medium/en_US-lessac-medium.onnx
sudo curl -LO https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/lessac/medium/en_US-lessac-medium.onnx.json

sudo cp bot/.env.example /opt/openranch-bot/.env   # then fill it in
sudo chmod 600 /opt/openranch-bot/.env
sudo chown -R openranch-bot:openranch-bot /opt/openranch-bot

sudo cp bot/openranch-*.service /etc/systemd/system/
sudo systemctl enable --now openranch-mcp openranch-bot
```

Then run `migrate_bot.sql` against the dashboard database and set
`BOT_SHARED_SECRET` in `config.php` to the same value as in `.env`.

## Operating

```bash
systemctl status openranch-bot openranch-mcp
tail -f /var/log/openranch-bot.log
/opt/openranch-bot/venv/bin/python /opt/openranch-bot/bot/briefing.py --dry-run
```

Both units restart on failure. The bot depends on the MCP unit, so starting the
bot starts both.

## Known edges

- Conversation memory is the last 20 turns, held in the process. A restart
  forgets it; nothing important lives there.
- Whisper and Piper models load on first use, so the first voice note of a run
  is slower than the rest.
- One bot serves every customer. Rate limiting is Telegram's and Anthropic's;
  there is none of its own.
