"""Signed HTTPS client for the single Stitchline bot's site API."""

from __future__ import annotations

import hashlib
import hmac
import json
import os
import time
import urllib.error
import urllib.request
from typing import Any

BOT_ID = "community"
HEARTBEAT_PATH = "/api/bot/heartbeat"
FULFILLMENT_PATH = "/api/bot/fulfillment"
RECORDS_PATH = "/api/bot/records"
SETTINGS_PATH = "/api/bot/settings"
ROLE_CHECKS_PATH = "/api/bot/role-checks"


def request(method: str, path: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
    method = method.upper()
    if method not in {"GET", "POST"} or not path.startswith("/api/bot/") or "#" in path:
        raise ValueError("Unsupported bot API request.")
    base_url = os.environ.get("STITCHLINE_SITE_URL", "").strip().rstrip("/")
    secret = os.environ.get("STITCHLINE_BOT_API_SECRET", "").strip()
    if not base_url.startswith("https://") or not secret:
        raise RuntimeError("Set the HTTPS site URL and bot API secret before enabling fulfillment.")
    body = json.dumps(payload or {}, ensure_ascii=False, separators=(",", ":")).encode("utf-8") if method != "GET" else b""
    timestamp = str(int(time.time()))
    canonical = f"{timestamp}\n{BOT_ID}\n{method}\n{path}\n{hashlib.sha256(body).hexdigest()}"
    signature = hmac.new(secret.encode("utf-8"), canonical.encode("utf-8"), hashlib.sha256).hexdigest()
    req = urllib.request.Request(
        base_url + path,
        data=body if method != "GET" else None,
        method=method,
        headers={
            **({"Content-Type": "application/json"} if method != "GET" else {}),
            "Accept": "application/json",
            "User-Agent": "StitchlineBot/1.0",
            "X-SI-Bot": BOT_ID,
            "X-SI-Timestamp": timestamp,
            "X-SI-Signature": signature,
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=8) as response:
            result = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as error:
        raise RuntimeError(f"The site rejected the bot request (HTTP {error.code}).") from None
    except (urllib.error.URLError, TimeoutError, OSError, ValueError) as error:
        raise RuntimeError("The site bot API could not be reached.") from error
    if not isinstance(result, dict):
        raise TypeError("The site returned an invalid bot API response.")
    return result


def report_heartbeat(payload: dict[str, Any]) -> dict[str, Any]:
    return request("POST", HEARTBEAT_PATH, payload)


def bot_settings() -> dict[str, Any]:
    """Return the shared bot settings the panel configures."""
    try:
        result = request("GET", SETTINGS_PATH)
    except RuntimeError:
        return {}
    settings = result.get("settings")
    return settings if isinstance(settings, dict) else {}


def add_record(kind: str, record: dict[str, Any]) -> dict[str, Any] | None:
    """Append one record to a site moderation collection."""
    try:
        result = request(
            "POST", RECORDS_PATH, {"operation": "add", "kind": kind, "record": record}
        )
    except RuntimeError:
        return None
    stored = result.get("record")
    return stored if isinstance(stored, dict) else None


def fulfillment_orders() -> list[dict[str, Any]]:
    result = request("GET", FULFILLMENT_PATH)
    orders = result.get("orders", [])
    return orders if isinstance(orders, list) else []


def claim_fulfillment(reference: str) -> bool:
    try:
        result = request("POST", FULFILLMENT_PATH + "/claim", {"ref": reference})
    except RuntimeError:
        return False
    return result.get("ok") is True


def finish_fulfillment(reference: str, success: bool, error: str = "") -> dict[str, Any]:
    return request("POST", FULFILLMENT_PATH + "/finish", {
        "ref": reference,
        "success": success,
        "error": error[:500],
    })


def pending_role_checks() -> list[dict[str, Any]]:
    """Return verified members whose Discord roles still need checking."""
    result = request("GET", ROLE_CHECKS_PATH)
    checks = result.get("checks", [])
    return checks if isinstance(checks, list) else []


def report_role_check(discord_id: str, roles: list[str]) -> dict[str, Any]:
    """Report the roles a member holds so the site can match staff roles."""
    return request("POST", ROLE_CHECKS_PATH, {"discord_id": discord_id, "roles": roles})
