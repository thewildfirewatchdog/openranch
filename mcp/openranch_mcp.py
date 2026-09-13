#!/usr/bin/env python3
"""OpenRanch MCP server.

Exposes the dashboard's /api/v1/ endpoints as MCP tools. Every tool takes the
customer's api_token, so one server instance serves any number of accounts and
holds no credentials of its own -- the caller supplies the authority.

Transport is HTTP on loopback only. It must never be published: a request is
authorised entirely by the token in its arguments, so anything that can reach
this port can act as any customer whose token it knows.

Run:
    openranch_mcp.py            # serves on MCP_HOST:MCP_PORT from .env
"""

from __future__ import annotations

import os
import sys
from pathlib import Path
from typing import Any

import requests
from fastmcp import FastMCP

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
API_BASE = os.environ.get("OPENRANCH_API_BASE") or ENV.get(
    "OPENRANCH_API_BASE", "https://dash.remotecontrolranch.com/api/v1"
)
HOST = os.environ.get("MCP_HOST") or ENV.get("MCP_HOST", "127.0.0.1")
PORT = int(os.environ.get("MCP_PORT") or ENV.get("MCP_PORT", "8765"))
TIMEOUT = 20

mcp = FastMCP("openranch")


def call(method: str, endpoint: str, token: str, **payload: Any) -> dict:
    """One place that talks to the dashboard.

    Errors come back as {"error": ...} rather than raised: the model reads these
    and explains them to the customer, which is far more useful than a traceback
    that ends the turn.
    """
    if not token or len(token) < 20:
        return {"error": "no API token supplied"}
    url = f"{API_BASE.rstrip('/')}/{endpoint.lstrip('/')}"
    headers = {"Authorization": f"Bearer {token}", "Accept": "application/json"}
    try:
        if method == "GET":
            r = requests.get(url, headers=headers, params=payload, timeout=TIMEOUT)
        else:
            r = requests.post(url, headers=headers, json=payload, timeout=TIMEOUT)
    except requests.RequestException as e:
        return {"error": f"could not reach the dashboard: {e}"}
    try:
        body = r.json()
    except ValueError:
        return {"error": f"dashboard returned {r.status_code} (not JSON)"}
    if r.status_code >= 400 and "error" not in body:
        body["error"] = f"HTTP {r.status_code}"
    return body


# ------------------------------------------------------------- read tools ----
@mcp.tool
def whoami(api_token: str) -> dict:
    """Which OpenRanch account this token belongs to, and its preferences."""
    return call("GET", "me", api_token)


@mcp.tool
def list_devices(api_token: str) -> dict:
    """List the customer's devices with their latest reading for each variable.

    Each device reports `offline` (nothing heard recently), `controllable`
    (can be switched from here) and `mirrored` (a copy of another system's
    hardware: readable, never controllable).
    """
    return call("GET", "devices", api_token)


@mcp.tool
def device_history(api_token: str, device: str, metric: str, hours: int = 24) -> dict:
    """Readings for one metric on one device over the last N hours.

    `device` is the device slug or id; `metric` is a variable name such as
    flow_gpm, tank_level_pct or moisture. Returns min/max/latest plus the points.
    """
    return call("GET", "history", api_token, device=device, metric=metric, hours=hours)


@mcp.tool
def list_zones(api_token: str) -> dict:
    """Irrigation zones: which are running, when each next runs, and whether
    programs are currently held by a rain delay."""
    return call("GET", "zones", api_token)


@mcp.tool
def list_programs(api_token: str) -> dict:
    """Watering programs: schedule, per-zone durations and the next run time."""
    return call("GET", "programs", api_token)


@mcp.tool
def list_rules(api_token: str) -> dict:
    """The customer's automations (IF a reading crosses a limit THEN act)."""
    return call("GET", "rules", api_token)


@mcp.tool
def recent_notifications(api_token: str, hours: int = 24) -> dict:
    """Recent notifications and skip log: why a program did not water, which
    automations fired, and any unscheduled-flow notices."""
    return call("GET", "notifications", api_token, hours=hours)


@mcp.tool
def ranch_summary(api_token: str, hours: int = 24) -> dict:
    """Everything needed for a briefing in one call: what watered, what was
    skipped and why, tank and pressure levels, devices that have gone quiet,
    and the cached weather decision."""
    return call("GET", "summary", api_token, hours=hours)


@mcp.tool
def list_cameras(api_token: str) -> dict:
    """The customer's cameras and the newest picture each one has.

    `latest.url` is a link the caller can fetch with the same api_token as a
    Bearer header; `latest.taken` says when it was taken, in UTC.
    """
    return call("GET", "cameras", api_token)


@mcp.tool
def get_latest_snapshot(api_token: str, camera: str = "") -> dict:
    """The most recent picture from one camera, or from the only camera if the
    account has just one. Use this for "show me the stock tank".

    Returns the picture's URL and when it was taken. Say how old it is if it is
    not recent -- a stale photo presented as current is worse than none.
    """
    r = call("GET", "cameras", api_token)
    cams = r.get("cameras") or []
    if r.get("error"):
        return r
    if not cams:
        return {"error": "this account has no cameras"}
    if camera:
        want = camera.strip().lower()
        cams = [c for c in cams
                if want in (c["slug"].lower(), c["name"].lower())
                or want in c["name"].lower()]
        if not cams:
            return {"error": f"no camera matching {camera!r} on this account"}
    c = cams[0]
    if not c.get("latest"):
        return {"camera": c["name"], "error": "that camera has not sent a picture yet"}
    return {"camera": c["name"], "slug": c["slug"], **c["latest"]}


@mcp.tool
def request_snapshot(api_token: str, camera: str) -> dict:
    """Ask a camera to take a fresh picture now. ACTION: confirm first.

    The camera takes it on its next poll, so the picture is not instant. After
    calling this, tell the customer it has been asked for -- the assistant
    watches for the new frame and sends it when it lands.
    """
    return call("POST", "cameras/request", api_token, camera=camera)


# ------------------------------------------------------------ write tools ----
# These change the physical world. The assistant's system prompt requires it to
# confirm with the customer before calling any of them.
@mcp.tool
def start_zone(api_token: str, zone_id: int, minutes: float = 0) -> dict:
    """Open an irrigation zone now for a number of minutes. ACTION: confirm with
    the customer first. Omit minutes to use the zone's own default."""
    args = {"zone_id": zone_id}
    if minutes and minutes > 0:
        args["minutes"] = minutes
    return call("POST", "zones/start", api_token, **args)


@mcp.tool
def stop_zone(api_token: str, zone_id: int) -> dict:
    """Close one irrigation zone now. ACTION: confirm with the customer first."""
    return call("POST", "zones/stop", api_token, zone_id=zone_id)


@mcp.tool
def stop_all_zones(api_token: str) -> dict:
    """Close every zone that is running or queued. ACTION: confirm first."""
    return call("POST", "zones/stopall", api_token)


@mcp.tool
def run_program_once(api_token: str, program_id: int) -> dict:
    """Run a whole watering program once, starting now. ACTION: confirm first."""
    return call("POST", "programs/run", api_token, program_id=program_id)


@mcp.tool
def set_rain_delay(api_token: str, hours: int = 24) -> dict:
    """Hold every program for N hours. Pass hours=0 to clear an existing hold.
    ACTION: confirm with the customer first."""
    return call("POST", "rain-delay", api_token, hours=hours)


@mcp.tool
def add_notification_rule(
    api_token: str,
    name: str,
    device: str,
    metric: str,
    op: str,
    value: float,
    for_minutes: int = 0,
    message: str = "",
) -> dict:
    """Create an automation that sends a notification when a reading crosses a
    limit. `op` is one of > >= < <= == !=. ACTION: confirm the wording first.

    Only notification automations can be created here; automations that command
    hardware or start a zone are built on the Automations page, deliberately.
    """
    return call(
        "POST", "rules", api_token, name=name, device=device, metric=metric,
        op=op, value=value, for_minutes=for_minutes, message=message,
    )


@mcp.tool
def delete_rule(api_token: str, rule_id: int) -> dict:
    """Delete one automation. ACTION: confirm with the customer first."""
    return call("POST", "rules/delete", api_token, rule_id=rule_id)


# Tools whose effect is physical or destructive. The bot reads this list to
# decide what needs a yes before it runs.
ACTION_TOOLS = {
    "start_zone", "stop_zone", "stop_all_zones", "run_program_once",
    "set_rain_delay", "add_notification_rule", "delete_rule",
    "request_snapshot",
}


if __name__ == "__main__":
    if HOST not in ("127.0.0.1", "::1", "localhost"):
        print(f"refusing to bind {HOST}: this server authorises by argument and "
              f"must stay on loopback", file=sys.stderr)
        sys.exit(1)
    mcp.run(transport="http", host=HOST, port=PORT)
