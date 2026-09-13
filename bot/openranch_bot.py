#!/usr/bin/env python3
"""OpenRanch Telegram assistant.

Long-polls Telegram. Every message from a linked chat is answered by Claude with
the OpenRanch MCP tools bound, scoped to that chat's customer. Voice notes are
transcribed with faster-whisper; replies can carry a Piper-generated voice note.

Safety model, in order of strength:
  1. The API token decides what exists. A tool call can only ever touch the
     linked customer's rows -- the dashboard enforces that, not this process.
  2. Anything that moves hardware is gated in execute_tool() below: the first
     call returns "confirmation_required" and the bot asks with Yes/No buttons.
     The model cannot talk its way past this; only a button press or an explicit
     yes runs the call.
  3. The system prompt sets tone and the confirm-first habit, but it is the
     weakest layer and is never relied on alone.
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import re
import subprocess
import tempfile
import time
from collections import deque
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import anthropic
import requests
from mcp import ClientSession
from mcp.client.streamable_http import streamable_http_client
from telegram import InlineKeyboardButton, InlineKeyboardMarkup, Update
from telegram.constants import ChatAction
from telegram.ext import (Application, CallbackQueryHandler, CommandHandler,
                          ContextTypes, MessageHandler, filters)

# ---------------------------------------------------------------- config ----
ENV_PATH = Path(os.environ.get("OPENRANCH_ENV", "/opt/openranch-bot/.env"))


def load_env(path: Path) -> dict[str, str]:
    out: dict[str, str] = {}
    if path.is_file():
        for line in path.read_text().splitlines():
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                out[k.strip()] = v.strip()
    return out


ENV = load_env(ENV_PATH)
TELEGRAM_TOKEN = ENV["TELEGRAM_BOT_TOKEN"]
API_BASE = ENV.get("OPENRANCH_API_BASE", "https://dash.remotecontrolranch.com/api/v1")
BOT_SECRET = ENV.get("BOT_SHARED_SECRET", "")
MCP_URL = ENV.get("MCP_URL", "http://127.0.0.1:8765/mcp")
MODEL = ENV.get("CLAUDE_MODEL", "claude-sonnet-4-6")
PIPER_VOICE = ENV.get("PIPER_VOICE", "/opt/openranch-bot/voices/en_US-lessac-medium.onnx")
WHISPER_MODEL = ENV.get("WHISPER_MODEL", "small")
WHISPER_COMPUTE = ENV.get("WHISPER_COMPUTE", "int8")

MAX_TURNS = 20          # per-chat memory, in turns (a turn = one user + one reply)
CONFIRM_TTL = 300       # seconds a pending action stays confirmable

logging.basicConfig(format="%(asctime)s %(levelname)s %(name)s %(message)s",
                    level=logging.INFO)
log = logging.getLogger("openranch-bot")
logging.getLogger("httpx").setLevel(logging.WARNING)

claude = anthropic.Anthropic(api_key=ENV["ANTHROPIC_API_KEY"])

ACTION_TOOLS = {
    "start_zone", "stop_zone", "stop_all_zones", "run_program_once",
    "set_rain_delay", "add_notification_rule", "delete_rule",
    "request_snapshot",
}

# Tools whose result carries a picture the customer should actually see, rather
# than a URL for the model to describe.
PHOTO_TOOLS = {"get_latest_snapshot"}
# Anything that can hand back a snapshot URL. Kept separate so a future
# read-only camera tool is covered without editing the attach logic.
CAMERA_TOOLS = {"list_cameras"}


def snapshot_urls(payload) -> list[tuple[str, str, str]]:
    """Pull (camera name, image url, taken) out of whatever shape a camera tool
    returned -- one snapshot, or a list of cameras each with a latest."""
    found = []
    if not isinstance(payload, dict):
        return found
    if payload.get("url"):
        found.append((payload.get("camera") or "Camera",
                      payload["url"], payload.get("taken", "")))
    for c in payload.get("cameras") or []:
        latest = (c or {}).get("latest") or {}
        if latest.get("url"):
            found.append((c.get("name") or "Camera", latest["url"], latest.get("taken", "")))
    return found

SYSTEM = """You are the OpenRanch assistant. You help one person look after their
own ranch hardware over Telegram: sensors, irrigation zones, watering programs
and automations.

How to talk:
- Plain English, short, friendly, like a neighbour who knows the place. No
  jargon, no bullet-point walls, no markdown headings. A couple of sentences is
  usually right; this is often being read aloud.
- Use "notification" (not alert), "limit" (not threshold or alarm), and
  "automation" (not rule) when speaking to the customer.
- Give numbers with their units and say when a reading was taken if it matters.
- If something has not reported in a long time, say so plainly rather than
  presenting a stale number as current.

Before you act:
- When the customer asks you to do something -- open or close a zone, hold the
  schedule, add or remove an automation -- call the tool for it straight away.
  Do not ask permission first: the system intercepts every one of these and
  answers "confirmation_required" without touching anything.
- When you get that answer, say in one plain sentence what is about to happen
  and ask them to confirm. Buttons appear under your message. Then stop -- do
  not call the tool again, and do not claim it is done. Only their yes runs it.
- Read-only questions need no permission; just answer them.

Pictures:
- When someone asks to see something, call get_latest_snapshot. The picture is
  sent to them automatically -- do not paste the URL. Say what it shows and how
  old it is, and if it is not recent, say so plainly.
- "Take a picture" means request_snapshot. It is confirmed like any other
  action, the camera takes it on its next poll, and the new picture is sent on
  as soon as it lands.

Limits:
- You can only see this customer's own devices. If asked about anything else,
  say it is not on their account.
- Some devices are mirrored from another system. You can report their readings
  but you cannot control them; say so if asked.
- Never invent a reading, a schedule or a device. If a tool did not return it,
  say you do not have it."""


# ------------------------------------------------------------ dashboard ----
def dash(endpoint: str, **payload) -> dict:
    """Call a bot-privileged dashboard endpoint with the shared secret."""
    try:
        r = requests.post(f"{API_BASE.rstrip('/')}/{endpoint}", json=payload,
                          headers={"X-Bot-Secret": BOT_SECRET}, timeout=15)
        return r.json()
    except Exception as e:                                    # noqa: BLE001
        log.warning("dashboard %s failed: %s", endpoint, e)
        return {"status": "error", "error": str(e)}


def resolve_chat(chat_id: int) -> dict | None:
    r = dash("resolve", chat_id=chat_id)
    return r.get("customer") if r.get("status") == "linked" else None


# ------------------------------------------------------------ MCP bridge ----
class Tools:
    """Holds the MCP session and the Anthropic tool schemas built from it."""

    def __init__(self) -> None:
        self.session: ClientSession | None = None
        self.schemas: list[dict] = []
        self._ready: asyncio.Event | None = None
        self._stop: asyncio.Event | None = None
        self._task: asyncio.Task | None = None
        self._error: BaseException | None = None

    async def connect(self) -> None:
        """Open the session inside one long-lived task.

        The anyio cancel scope underneath must be entered and exited by the same
        task; driving it from whichever handler happens to run would blow up on
        shutdown with "attempted to exit cancel scope in a different task".
        """
        self._ready, self._stop = asyncio.Event(), asyncio.Event()
        self._task = asyncio.create_task(self._serve())
        await self._ready.wait()
        if self._error is not None:
            raise self._error

    async def aclose(self) -> None:
        if self._stop is not None:
            self._stop.set()
        if self._task is not None:
            await asyncio.wait([self._task], timeout=10)

    async def _serve(self) -> None:
        try:
            async with streamable_http_client(MCP_URL) as streams:
                async with ClientSession(streams[0], streams[1]) as session:
                    await session.initialize()
                    listed = await session.list_tools()
                    self._build(listed)
                    self.session = session
                    self._ready.set()
                    await self._stop.wait()
        except BaseException as e:                            # noqa: BLE001
            self._error = e
            log.exception("MCP session ended")
            self._ready.set()
        finally:
            self.session = None

    def _build(self, listed) -> None:
        # api_token is supplied by the bot, never by the model: strip it from the
        # schema so Claude cannot pass one and cannot see another customer's.
        self.schemas = []
        for t in listed.tools:
            schema = json.loads(json.dumps(getattr(t, "input_schema", None) or getattr(t, "inputSchema", None) or {"type": "object"}))
            schema.get("properties", {}).pop("api_token", None)
            if "required" in schema:
                schema["required"] = [r for r in schema["required"] if r != "api_token"]
            self.schemas.append({"name": t.name,
                                 "description": (t.description or "").strip(),
                                 "input_schema": schema})
        log.info("MCP connected: %d tools", len(self.schemas))

    async def call(self, name: str, args: dict, token: str) -> str:
        if self.session is None:
            return json.dumps({"error": "the tool server is not connected"})
        res = await self.session.call_tool(name, {**args, "api_token": token})
        parts = [c.text for c in res.content if getattr(c, "text", None)]
        return "\n".join(parts) if parts else "{}"


TOOLS = Tools()


# --------------------------------------------------------------- state -----
class Chat:
    def __init__(self) -> None:
        self.history: deque = deque(maxlen=MAX_TURNS * 2)
        self.pending: tuple[str, dict, float] | None = None   # tool, args, when
        self.voice_default = False
        self.photos: list[tuple[bytes, str]] = []             # (jpeg, caption)


CHATS: dict[int, Chat] = {}


def chat_state(chat_id: int) -> Chat:
    if chat_id not in CHATS:
        CHATS[chat_id] = Chat()
    return CHATS[chat_id]


def describe(tool: str, args: dict) -> str:
    a = {k: v for k, v in args.items() if k != "api_token"}
    pretty = ", ".join(f"{k}={v}" for k, v in a.items())
    return f"{tool}({pretty})" if pretty else f"{tool}()"


# ------------------------------------------------------------ the agent ----
async def run_claude(chat_id: int, token: str, user_text: str) -> str:
    """One turn: send history + the new message, run the tool loop, return text."""
    st = chat_state(chat_id)
    st.history.append({"role": "user", "content": user_text})
    messages = list(st.history)

    final_text = ""
    for _ in range(8):                                     # bound the tool loop
        # The model has no clock of its own. Without the current time it cannot
        # say how old a reading or a picture is, and it guesses -- which is how
        # a four-minute-old photo gets described as "taken yesterday".
        system = (SYSTEM + "\n\nThe current time is "
                  + datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
                  + " UTC. All timestamps you receive are UTC.")
        resp = await asyncio.to_thread(
            claude.messages.create,
            model=MODEL, max_tokens=4096, system=system,
            tools=TOOLS.schemas, messages=messages,
        )
        if resp.stop_reason == "refusal":
            final_text = ("I can't help with that one. Ask me about your devices, "
                          "zones or watering and I'll do my best.")
            break

        blocks = [b for b in resp.content if b.type == "tool_use"]
        text = "".join(b.text for b in resp.content if b.type == "text").strip()

        if not blocks:
            final_text = text
            messages.append({"role": "assistant", "content": resp.content})
            break

        messages.append({"role": "assistant", "content": resp.content})
        results = []
        for b in blocks:
            out = await execute_tool(chat_id, token, b.name, b.input)
            results.append({"type": "tool_result", "tool_use_id": b.id, "content": out})
        messages.append({"role": "user", "content": results})

    # Keep only the plain user/assistant text in memory: replaying tool blocks
    # across turns bloats context fast and adds nothing the model needs.
    if final_text:
        st.history.append({"role": "assistant", "content": final_text})
    return final_text or "Sorry, I couldn't work that one out."


async def execute_tool(chat_id: int, token: str, name: str, args: dict) -> str:
    """The gate. Actions need a confirmation that the model cannot fabricate."""
    st = chat_state(chat_id)
    if name in ACTION_TOOLS:
        ok = st.pending is not None and st.pending[0] == "__confirmed__"
        if not ok:
            st.pending = (name, dict(args), time.time())
            return json.dumps({
                "status": "confirmation_required",
                "what": describe(name, args),
                "note": "Tell the customer what you are about to do and wait for a yes.",
            })
        st.pending = None                                   # single use
    try:
        out = await TOOLS.call(name, args, token)
    except Exception as e:                                   # noqa: BLE001
        log.exception("tool %s failed", name)
        return json.dumps({"error": f"{type(e).__name__}: {e}"})

    # A picture is worth sending, not describing. Any tool result that carries a
    # snapshot URL gets its bytes fetched and attached -- not just the obvious
    # one, because the model sometimes reaches for list_cameras instead and
    # would otherwise tell the customer a photo is on its way when none is.
    if name in PHOTO_TOOLS or name in CAMERA_TOOLS:
        try:
            for cam_name, url, taken in snapshot_urls(json.loads(out)):
                if len(st.photos) >= 3:
                    break
                img = await asyncio.to_thread(fetch_photo, url, token)
                if img:
                    st.photos.append((img, f"{cam_name} — {taken} UTC"))
        except Exception:                                     # noqa: BLE001
            log.exception("photo attach failed")

    # A requested snapshot is worth waiting for, within reason.
    if name == "request_snapshot":
        try:
            j = json.loads(out)
            if j.get("status") == "requested":
                img, taken = await asyncio.to_thread(
                    wait_for_new_snapshot, token, j["camera"], j.get("previous"), 60)
                if img:
                    st.photos.append((img, f"{j['camera']} — new picture, {taken} UTC"))
                    out = json.dumps({**j, "arrived": True, "taken": taken})
                else:
                    out = json.dumps({**j, "arrived": False,
                                      "note": "No new picture within 60 seconds; the camera may poll less often."})
        except Exception:                                     # noqa: BLE001
            log.exception("snapshot wait failed")
    return out


# -------------------------------------------------------------- photos -----
def fetch_photo(url: str, token: str) -> bytes | None:
    """Pull a snapshot using the customer's own token, so the bot can never
    reach an image the customer could not."""
    try:
        r = requests.get(url, headers={"Authorization": f"Bearer {token}"}, timeout=25)
        if r.ok and r.content[:2] == b"\xff\xd8":
            return r.content
        log.warning("snapshot fetch %s -> %s", url, r.status_code)
    except requests.RequestException as e:
        log.warning("snapshot fetch failed: %s", e)
    return None


def wait_for_new_snapshot(token: str, slug: str, previous: str | None,
                          timeout: int = 60) -> tuple[bytes | None, str | None]:
    """Poll for a frame newer than `previous`, up to `timeout` seconds.

    A camera answers a request on its next poll, which is usually seconds but is
    bounded by its own interval -- so this waits rather than promising a picture
    that has not arrived.
    """
    deadline = time.time() + timeout
    while time.time() < deadline:
        r = dash_get("cameras", token)
        for c in (r.get("cameras") or []):
            if c["slug"] != slug or not c.get("latest"):
                continue
            if c["latest"]["file"] != previous:
                return fetch_photo(c["latest"]["url"], token), c["latest"]["taken"]
        time.sleep(4)
    return None, None


def dash_get(endpoint: str, token: str) -> dict:
    try:
        r = requests.get(f"{API_BASE.rstrip('/')}/{endpoint}",
                         headers={"Authorization": f"Bearer {token}"}, timeout=20)
        return r.json()
    except Exception as e:                                    # noqa: BLE001
        return {"error": str(e)}


# --------------------------------------------------------------- voice -----
_whisper = None


def transcribe(ogg_path: str) -> str:
    global _whisper
    if _whisper is None:
        from faster_whisper import WhisperModel
        log.info("loading whisper %s", WHISPER_MODEL)
        _whisper = WhisperModel(WHISPER_MODEL, device="cpu", compute_type=WHISPER_COMPUTE)
    segments, _info = _whisper.transcribe(ogg_path, beam_size=1, language="en")
    return " ".join(s.text.strip() for s in segments).strip()


_piper = None


def synthesize(text: str) -> bytes | None:
    """Piper WAV -> ogg/opus, which is what Telegram wants for a voice note."""
    global _piper
    try:
        if _piper is None:
            from piper import PiperVoice
            log.info("loading piper voice")
            _piper = PiperVoice.load(PIPER_VOICE)
        import wave
        with tempfile.TemporaryDirectory() as d:
            wav, ogg = f"{d}/o.wav", f"{d}/o.ogg"
            with wave.open(wav, "wb") as w:
                _piper.synthesize_wav(text[:1800], w)
            subprocess.run(
                ["ffmpeg", "-y", "-loglevel", "error", "-i", wav,
                 "-c:a", "libopus", "-b:a", "32k", ogg],
                check=True, timeout=90)
            return Path(ogg).read_bytes()
    except Exception as e:                                   # noqa: BLE001
        log.warning("tts failed: %s", e)
        return None


# ------------------------------------------------------------- handlers ----
HELP_UNLINKED = (
    "Hello! I'm the OpenRanch assistant.\n\n"
    "To use me, link this chat to your OpenRanch account:\n"
    "1. Sign in at the dashboard and open More → Telegram assistant.\n"
    "2. Tap “Get a link code”.\n"
    "3. Send me:  /link ABC234\n\n"
    "Until then I can't see any devices, so there's not much I can tell you."
)


async def cmd_start(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> None:
    chat_id = update.effective_chat.id
    if resolve_chat(chat_id):
        await update.message.reply_text(
            "You're linked. Ask me anything — how the tank looks, what watered "
            "last night, or to hold the schedule if rain is coming.")
        return
    # An operator may have armed an account to adopt the next chat. This is the
    # only path that links without a code, and it disarms on use.
    r = dash("bind-next", chat_id=chat_id)
    if r.get("status") == "linked":
        who = r["customer"].get("name") or r["customer"]["email"]
        log.info("one-shot bind: chat %s -> %s", chat_id, r["customer"]["email"])
        await update.message.reply_text(
            f"Linked this chat to {who}. Ask me anything about the ranch.")
        return
    await update.message.reply_text(HELP_UNLINKED)


async def cmd_link(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> None:
    chat_id = update.effective_chat.id
    # Normalise the same way the dashboard does, so punctuation someone added
    # while reading the code aloud is forgiven -- and so a wrong-length code is
    # caught here, in words the customer can act on, rather than coming back
    # from the API as a sentence about required fields.
    code = re.sub(r"[^A-Za-z0-9]", "", (ctx.args[0] if ctx.args else "")).upper()
    if not code:
        await update.message.reply_text("Send it like this:  /link ABC234")
        return
    if len(code) != 6:
        await update.message.reply_text(
            "That doesn't look like a link code — it's 6 characters. Open the "
            "dashboard, go to More → Telegram assistant, and it shows you the "
            "whole message to send, like:  /link ABC234")
        return
    try:
        r = requests.post(f"{API_BASE.rstrip('/')}/link",
                          json={"code": code, "chat_id": chat_id}, timeout=15).json()
    except Exception as e:                                   # noqa: BLE001
        await update.message.reply_text(f"Couldn't reach the dashboard: {e}")
        return
    if r.get("status") == "linked":
        who = r["customer"].get("name") or r["customer"]["email"]
        await update.message.reply_text(
            f"Linked to {who}. Ask me anything about the ranch.")
    else:
        await update.message.reply_text(
            r.get("error", "That code didn't work. Get a fresh one from the dashboard."))


async def cmd_voice(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> None:
    chat_id = update.effective_chat.id
    if not resolve_chat(chat_id):
        await update.message.reply_text(HELP_UNLINKED)
        return
    arg = (ctx.args[0] if ctx.args else "").lower()
    if arg not in ("on", "off"):
        await update.message.reply_text("Say  /voice on  or  /voice off.")
        return
    dash("set-voice", chat_id=chat_id, on=1 if arg == "on" else 0)
    await update.message.reply_text(
        "I'll send a voice note with my replies." if arg == "on"
        else "Text only from now on.")


AFFIRMATIVE = {"yes", "y", "yep", "yeah", "ok", "okay", "do it", "go ahead",
               "please do", "confirm", "sure", "go on", "yes please"}


async def on_message(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> None:
    chat = update.effective_chat
    msg = update.message
    if msg is None:
        return
    cust = resolve_chat(chat.id)
    if not cust:
        await msg.reply_text(HELP_UNLINKED)
        return

    st = chat_state(chat.id)
    was_voice = msg.voice is not None

    # ---- get the text --------------------------------------------------
    if was_voice:
        await ctx.bot.send_chat_action(chat.id, ChatAction.TYPING)
        f = await ctx.bot.get_file(msg.voice.file_id)
        with tempfile.TemporaryDirectory() as d:
            p = f"{d}/in.ogg"
            await f.download_to_drive(p)
            text = await asyncio.to_thread(transcribe, p)
        if not text:
            await msg.reply_text("I couldn't make that out — try again?")
            return
        await msg.reply_text(f"“{text}”")
    else:
        text = (msg.text or "").strip()
    if not text:
        return

    # ---- a plain yes runs the action we asked about ---------------------
    if st.pending and st.pending[0] != "__confirmed__":
        if text.lower().strip(" .!") in AFFIRMATIVE:
            if time.time() - st.pending[2] > CONFIRM_TTL:
                st.pending = None
                await msg.reply_text("That request got stale — ask me again?")
                return
            name, args, _ = st.pending
            st.pending = ("__confirmed__", {}, time.time())
            out = await execute_tool(chat.id, cust["api_token"], name, args)
            st.history.append({"role": "user", "content": f"[confirmed] {describe(name, args)}"})
            st.history.append({"role": "assistant", "content": f"Done. Result: {out[:400]}"})
            await reply(ctx, chat.id, msg, summarize_action(name, args, out), was_voice, cust)
            return
        st.pending = None                                   # anything else cancels

    await ctx.bot.send_chat_action(chat.id, ChatAction.TYPING)
    answer = await run_claude(chat.id, cust["api_token"], text)

    # If the model asked a confirmation question, offer buttons as well.
    markup = None
    if st.pending and st.pending[0] != "__confirmed__":
        markup = InlineKeyboardMarkup([[
            InlineKeyboardButton("Yes, do it", callback_data="ok"),
            InlineKeyboardButton("No", callback_data="no"),
        ]])
    await reply(ctx, chat.id, msg, answer, was_voice, cust, markup)


def summarize_action(name: str, args: dict, out: str) -> str:
    try:
        j = json.loads(out)
    except ValueError:
        return "Done."
    if j.get("error"):
        return f"That didn't work: {j['error']}"
    if name == "start_zone":
        return f"Started {j.get('zone', 'the zone')} for {j.get('minutes')} minutes."
    if name in ("stop_zone", "stop_all_zones"):
        return f"Stopped. ({j.get('runs_stopped', 0)} run(s) closed.)"
    if name == "set_rain_delay":
        return ("Hold cleared." if j.get("status") == "cleared"
                else f"Holding the programs for {j.get('hours')} hours.")
    if name == "run_program_once":
        return f"{j.get('program')}: queued {j.get('zones_queued')} zone(s)."
    if name == "add_notification_rule":
        return f"Added the automation “{j.get('name')}”."
    if name == "delete_rule":
        return "Automation deleted."
    return "Done."


async def on_button(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> None:
    q = update.callback_query
    await q.answer()
    chat_id = q.message.chat.id
    st = chat_state(chat_id)
    cust = resolve_chat(chat_id)
    if not cust or not st.pending or st.pending[0] == "__confirmed__":
        await q.edit_message_reply_markup(None)
        return
    if q.data == "no":
        st.pending = None
        await q.edit_message_reply_markup(None)
        await ctx.bot.send_message(chat_id, "Left it alone.")
        return
    name, args, when = st.pending
    await q.edit_message_reply_markup(None)
    if time.time() - when > CONFIRM_TTL:
        st.pending = None
        await ctx.bot.send_message(chat_id, "That request got stale — ask me again?")
        return
    st.pending = ("__confirmed__", {}, time.time())
    out = await execute_tool(chat_id, cust["api_token"], name, args)
    st.history.append({"role": "user", "content": f"[confirmed] {describe(name, args)}"})
    st.history.append({"role": "assistant", "content": f"Done. Result: {out[:400]}"})
    await ctx.bot.send_message(chat_id, summarize_action(name, args, out))


async def reply(ctx, chat_id, msg, text, was_voice, cust, markup=None) -> None:
    """Text always. Any pictures the turn produced. A voice note too when the
    customer wants one -- on by default for a spoken question, off for typed."""
    st = chat_state(chat_id)
    photos, st.photos = st.photos, []
    for img, caption in photos:
        try:
            await ctx.bot.send_photo(chat_id, img, caption=caption[:1024])
        except Exception as e:                                # noqa: BLE001
            log.warning("send_photo failed: %s", e)
    if not text:
        return
    await msg.reply_text(text, reply_markup=markup)
    want_voice = cust.get("voice", True) if was_voice else False
    if want_voice and len(text) < 1800:
        audio = await asyncio.to_thread(synthesize, text)
        if audio:
            await ctx.bot.send_voice(chat_id, audio)


async def post_init(app: Application) -> None:
    await TOOLS.connect()


def main() -> None:
    app = (Application.builder().token(TELEGRAM_TOKEN)
           .post_init(post_init).build())
    app.add_handler(CommandHandler("start", cmd_start))
    app.add_handler(CommandHandler("link", cmd_link))
    app.add_handler(CommandHandler("voice", cmd_voice))
    app.add_handler(CallbackQueryHandler(on_button))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, on_message))
    app.add_handler(MessageHandler(filters.VOICE, on_message))
    log.info("polling as the OpenRanch assistant")
    app.run_polling(drop_pending_updates=True)


if __name__ == "__main__":
    main()
