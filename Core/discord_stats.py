"""Live Discord community counts.

Reads guild IDs from the content store, queries the Discord REST API, and
caches the result in the state file so a failed request or a cold cache never
breaks the site. Every network call is time-boxed and fully guarded.
"""

from __future__ import annotations

import json
import importlib
import sys
import threading
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any, Callable, Protocol, cast

_ROOT = Path(__file__).resolve().parent.parent
if str(_ROOT) not in sys.path:
    sys.path.insert(0, str(_ROOT))

class _SiteConfig(Protocol):
    BOT_TOKENS: dict[str, str]
    DISCORD_CACHE_SECONDS: int

    def load_data(self) -> dict[str, Any]: ...
    def load_state(self) -> dict[str, Any]: ...
    def discord_token(self, bot: str = "community") -> str: ...
    def discord_token_source(self, bot: str) -> str: ...
    def update_state(self, mutator: Callable[[dict[str, Any]], Any]) -> Any: ...


config = cast(_SiteConfig, importlib.import_module("Core.config"))

API = "https://discord.com/api/v10"
USER_AGENT = "StitchlineInteractiveSite/1.0"
TIMEOUT = 6


def _normalise_ids(raw: Any) -> list[str]:
    ids: list[str] = []
    entries: list[Any] = list(raw or [])
    for entry in entries:
        item: Any = entry
        if isinstance(item, dict):
            item = cast(dict[str, Any], item).get("id")
        try:
            digits: Any = str(item).strip()
            ids.append(str(int(digits)))
        except (TypeError, ValueError):
            continue
    # A duplicated or whitespace-variant entry in the hand-edited list would
    # otherwise be fetched and summed twice.
    return list(dict.fromkeys(ids))


#: The store also reads the guild list to confirm a checkout's Discord ID, so
#: this is part of the module's surface rather than an internal detail.
normalise_ids = _normalise_ids


def _fetch_guild(guild_id: str, token: str) -> dict[str, Any]:
    request = urllib.request.Request(
        f"{API}/guilds/{guild_id}?with_counts=true",
        headers={
            "Authorization": f"Bot {token}",
            "User-Agent": "StitchlineInteractiveSite/1.0",
        },
    )
    with urllib.request.urlopen(request, timeout=5) as response:
        raw_payload: Any = json.loads(response.read().decode("utf-8"))
    if not isinstance(raw_payload, dict):
        raise ValueError("Discord returned an invalid guild response.")
    payload = cast(dict[str, Any], raw_payload)
    return {
        "id": guild_id,
        "name": payload.get("name") or guild_id,
        "total": int(payload.get("approximate_member_count") or 0),
        "online": int(payload.get("approximate_presence_count") or 0),
    }


def fetch_member(guild_id: str, user_id: str, token: str) -> dict[str, Any] | None:
    """Look one user up in one guild.

    Returns ``None`` when the user is not a member of that guild, so a caller
    can try several studio servers before concluding anything. Used to confirm
    that a Discord user ID and a username really belong together before a paid
    order is created.
    """
    request = urllib.request.Request(
        f"{API}/guilds/{guild_id}/members/{user_id}",
        headers={
            "Authorization": f"Bot {token}",
            "User-Agent": "StitchlineInteractiveSite/1.0",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=6) as response:
            raw_payload: Any = json.loads(response.read().decode("utf-8"))
    except (urllib.error.URLError, urllib.error.HTTPError, OSError, ValueError, TimeoutError):
        return None
    if not isinstance(raw_payload, dict):
        return None
    payload = cast(dict[str, Any], raw_payload)
    raw_user = payload.get("user")
    if not isinstance(raw_user, dict):
        return None
    user = cast(dict[str, Any], raw_user)
    return {
        "id": str(user.get("id") or user_id),
        "username": str(user.get("username") or ""),
        "global_name": str(user.get("global_name") or ""),
    }


def _cached(state: dict[str, Any]) -> dict[str, Any]:
    raw: Any = state.get("discord_cache")
    if not isinstance(raw, dict):
        return {}
    cache = cast(dict[str, Any], raw)
    age = time.time() - float(cache.get("fetched_at") or 0)
    if age > config.DISCORD_CACHE_SECONDS:
        return {}
    return cache


def collect(guild_ids: Any = None, force: bool = False) -> dict[str, Any]:
    """Return ``{source, total, online, guilds, error}`` for the community counter."""
    data = config.load_data()
    ids = _normalise_ids(guild_ids if guild_ids is not None else data.get("discord", {}).get("guilds"))
    state = config.load_state()

    if not ids:
        return {
            "source": "none",
            "total": 0,
            "online": 0,
            "guilds": [],
            "error": None,
        }

    token = config.discord_token("community")
    if not token:
        return {
            "source": "config",
            "total": 0,
            "online": 0,
            "guilds": [],
            "error": (
                f"{config.BOT_TOKENS['community']} is not set, so live counts are "
                "unavailable."
            ),
        }

    cache = {} if force else _cached(state)
    if cache.get("guilds"):
        return {
            "source": "cache",
            "total": cache.get("total", 0),
            "online": cache.get("online", 0),
            "guilds": cache.get("guilds", []),
            "error": None,
        }

    guilds: list[dict[str, Any]] = []
    errors: list[str] = []
    for guild_id in ids:
        try:
            guilds.append(_fetch_guild(guild_id, token))
        except (urllib.error.URLError, urllib.error.HTTPError, OSError, ValueError, TimeoutError) as exc:
            errors.append(f"{guild_id}: {exc}")

    if not guilds:
        return {
            "source": "error",
            "total": 0,
            "online": 0,
            "guilds": [],
            "error": "; ".join(errors)[:400] or "Discord did not respond.",
        }

    total = sum(guild["total"] for guild in guilds)
    online = sum(guild["online"] for guild in guilds)

    def store(state: dict[str, Any]) -> None:
        state["discord_cache"] = {
            "fetched_at": time.time(),
            "total": total,
            "online": online,
            "guilds": guilds,
        }

    # Persisting the cache is an optimisation, not the answer, and this runs on a
    # GET. state.json is written by the combined bot and the panel, and on
    # Windows the replace can lose that race often enough to
    # matter: two of three hundred requests to /api/site came back as a closed
    # connection with no response at all, because the exception from the cache
    # write unwound the handler. The counts are already in hand, so a cache that
    # could not be stored is logged and answered with.
    try:
        config.update_state(store)
    except (OSError, ValueError, TypeError) as exc:
        print(f"[discord_stats] could not cache the counts: {exc!r}",
              file=sys.stderr)

    return {
        "source": "live",
        "total": total,
        "online": online,
        "guilds": guilds,
        "error": "; ".join(errors)[:400] or None,
    }


#: How long a validated bot identity is trusted. Long, because a token does not
#: change validity often, and the Dashboard must not call Discord on every load.
IDENTITY_CACHE_SECONDS = 900

#: Shorter than the guild-fetch timeout, because this one is on the panel's
#: request path the first time and a stalled Discord should cost a moment, not
#: the whole page.
IDENTITY_TIMEOUT = 3


def bot_identity(bot: str = "community", force: bool = False) -> dict[str, Any]:
    """Confirm a bot's token actually authenticates, and say which one it is.

    Answers the question you cannot otherwise answer from the site: "did the
    token I pasted go in, and is it the bot I meant?". A revoked or mistyped
    token is indistinguishable from a missing one until something calls Discord
    and gets a 401, which is exactly the failure that is easy to miss.
    """
    token = config.discord_token(bot)
    label = config.discord_token_source(bot) or config.BOT_TOKENS.get(bot, bot)
    if not token:
        return {
            "bot": bot,
            "configured": False,
            "valid": False,
            "id": None,
            "username": None,
            "source": None,
            "checked": None,
            "error": f"{config.BOT_TOKENS.get(bot, bot)} is not set.",
        }

    state = config.load_state()
    key = f"discord_identity_{bot}"
    cached: Any = state.get(key)
    cached_identity = cast(dict[str, Any], cached) if isinstance(cached, dict) else None
    fresh = (
        cached_identity is not None
        and time.time() - float(cached_identity.get("checked_at") or 0) <= IDENTITY_CACHE_SECONDS
    )
    if cached_identity is not None and fresh and not force:
        return {**cached_identity, "checked": "cache"}

    # This is called on every panel load, so a stale or cold answer is refreshed
    # off the request path. A slow or unreachable Discord must not hold the
    # Dashboard open while it times out; the previous answer is returned instead
    # and this request is the one that notices the change.
    if cached_identity is not None and not force:
        _refresh_identity_in_background(bot, key)
        return {**cached_identity, "checked": "stale"}

    return _check_identity(bot, key, token, label)


def _refresh_identity_in_background(bot: str, key: str) -> None:
    token = config.discord_token(bot)
    label = config.discord_token_source(bot) or config.BOT_TOKENS.get(bot, bot)
    if not token:
        return

    def work() -> None:
        try:
            _check_identity(bot, key, token, label)
        except BaseException:  # background refresh must never crash the panel
            pass

    threading.Thread(target=work, daemon=True).start()


def _check_identity(bot: str, key: str, token: str, label: str) -> dict[str, Any]:
    """One blocking /users/@me call. Short timeout: the panel is waiting."""
    result: dict[str, Any] = {
        "bot": bot,
        "configured": True,
        "valid": False,
        "id": None,
        "username": None,
        # Which env var supplied the token. Kept distinct from `checked`, which
        # says whether this answer came from the cache or from Discord, so the
        # panel can name the variable the operator has to edit.
        "source": label,
        "checked": "live",
        "checked_at": time.time(),
    }
    try:
        request = urllib.request.Request(
            f"{API}/users/@me",
            headers={"Authorization": f"Bot {token}", "User-Agent": USER_AGENT},
        )
        with urllib.request.urlopen(request, timeout=IDENTITY_TIMEOUT) as response:
            me = json.loads(response.read().decode("utf-8"))
        result["valid"] = True
        result["id"] = str(me.get("id") or "")
        result["username"] = str(me.get("username") or "")
    except urllib.error.HTTPError as exc:
        # 401 is the one that matters: the token is wrong, revoked, or from a
        # different bot than the one it was pasted under.
        if exc.code == 401:
            result["error"] = "Discord rejected this token (401). It is revoked or wrong."
        elif exc.code == 403:
            result["error"] = "Discord refused this token (403)."
        else:
            result["error"] = f"Discord returned {exc.code}."
    except (urllib.error.URLError, OSError, TimeoutError, ValueError) as exc:
        result["error"] = f"Could not reach Discord: {exc}"

    # Annotated dict, not None: update_state returns whatever the mutator
    # returns, and returning the target is what makes that useful. The annotation
    # said None while the body returned a dict, so a caller following the type
    # would discard the result.
    def store(target: dict[str, Any]) -> dict[str, Any]:
        target[key] = result
        return target

    config.update_state(store)
    return dict(result)
