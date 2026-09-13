#!/usr/bin/env python3
"""OpenRanch morning briefing.

Runs once a day. For every linked customer who wants one, asks Claude to turn
the last 24 hours into a few plain sentences and sends it to their Telegram
chat as text plus a voice note.

    briefing.py            send to everyone who has it switched on
    briefing.py --dry-run  build and print, send nothing
    briefing.py --chat N   just this chat
"""

from __future__ import annotations

import argparse
import json
import logging
import sys
from pathlib import Path

import anthropic
import requests

sys.path.insert(0, str(Path(__file__).resolve().parent))
from openranch_bot import (API_BASE, BOT_SECRET, ENV, MODEL, dash,  # noqa: E402
                           synthesize)

logging.basicConfig(format="%(asctime)s %(levelname)s %(message)s", level=logging.INFO)
log = logging.getLogger("briefing")

claude = anthropic.Anthropic(api_key=ENV["ANTHROPIC_API_KEY"])
TELEGRAM = f"https://api.telegram.org/bot{ENV['TELEGRAM_BOT_TOKEN']}"

BRIEF_SYSTEM = """You write a short morning briefing for someone who looks after
a small ranch. You are given the last 24 hours as JSON.

Write 3-6 plain sentences, no lists, no headings, no markdown. It will be read
aloud, so it has to sound like a person talking. Lead with anything that needs
attention, then what happened overnight, then the day ahead.

Cover, only where the data actually says something:
- tank and pressure levels, and whether they moved
- which zones watered and for how long, and roughly how much water
- anything that did not water, and the reason in plain words
- devices that have not reported for more than 12 hours
- today's weather decision, if one was made

Use "notification", "limit" and "automation" rather than alert, threshold or
rule. Never invent a number. If nothing happened, say so in one sentence rather
than padding it out."""


def summary_for(token: str, hours: int = 24) -> dict:
    r = requests.get(f"{API_BASE.rstrip('/')}/summary",
                     headers={"Authorization": f"Bearer {token}"},
                     params={"hours": hours}, timeout=30)
    return r.json()


def write_briefing(data: dict, name: str) -> str:
    resp = claude.messages.create(
        model=MODEL, max_tokens=800, system=BRIEF_SYSTEM,
        messages=[{"role": "user", "content":
                   f"Good morning briefing for {name}. Last 24 hours:\n\n"
                   + json.dumps(data, default=str)[:20000]}],
    )
    return "".join(b.text for b in resp.content if b.type == "text").strip()


def send(chat_id: int, text: str, voice: bool) -> bool:
    ok = requests.post(f"{TELEGRAM}/sendMessage",
                       json={"chat_id": chat_id, "text": text}, timeout=30).ok
    if ok and voice:
        audio = synthesize(text)
        if audio:
            requests.post(f"{TELEGRAM}/sendVoice", data={"chat_id": chat_id},
                          files={"voice": ("briefing.ogg", audio, "audio/ogg")},
                          timeout=60)
    return ok


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--chat", type=int)
    args = ap.parse_args()

    if not BOT_SECRET:
        log.error("BOT_SHARED_SECRET is not set; cannot list customers")
        return 1

    listed = dash("briefing-list")
    people = listed.get("customers", [])
    if args.chat:
        people = [c for c in people if int(c["telegram_chat_id"]) == args.chat]
    log.info("%d customer(s) to brief", len(people))

    rc = 0
    for c in people:
        who = c.get("name") or c["email"]
        try:
            data = summary_for(c["api_token"])
            if data.get("error"):
                log.warning("%s: %s", who, data["error"])
                rc = 1
                continue
            text = write_briefing(data, who)
            if args.dry_run:
                print(f"--- {who} (chat {c['telegram_chat_id']}) ---\n{text}\n")
                continue
            prefs = dash("resolve", chat_id=int(c["telegram_chat_id"]))
            voice = prefs.get("customer", {}).get("voice", True)
            if send(int(c["telegram_chat_id"]), text, voice):
                log.info("briefed %s", who)
            else:
                log.warning("could not deliver to %s", who)
                rc = 1
        except Exception as e:                                # noqa: BLE001
            log.exception("briefing failed for %s: %s", who, e)
            rc = 1
    return rc


if __name__ == "__main__":
    sys.exit(main())
