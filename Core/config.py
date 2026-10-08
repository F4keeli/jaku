"""Private configuration and runtime state for the Stitchline bot.

Two JSON files live outside the web root:

* ``Core/private/data.json`` — operator-edited settings (guild lists,
  feature flags). Safe to hand-edit while the bot runs.
* ``Core/private/state.json`` — runtime state written by the bot and
  the panel (heartbeats, Discord caches, identity checks).

Tokens never touch disk here: they are read from the environment so a
secret is never committed or bundled into a hosting package.
"""

from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path
from typing import Any, Callable

ROOT = Path(__file__).resolve().parent.parent
PRIVATE = ROOT / "Core" / "private"
DATA_FILE = PRIVATE / "data.json"
STATE_FILE = PRIVATE / "state.json"

#: Which environment variable holds each bot's token. Kept as a map so
#: a second bot can be added without touching every call site.
BOT_TOKENS: dict[str, str] = {
    "community": "DISCORD_BOT_TOKEN",
}

#: How long a validated guild count is trusted before Discord is asked
#: again. Short enough that the panel's community counter stays honest,
#: long enough that a cold page load does not hammer the API.
DISCORD_CACHE_SECONDS = 300


def _read(path: Path, default: Any) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return default


def load_data() -> dict[str, Any]:
    """Return the operator-edited settings, or an empty document."""
    data = _read(DATA_FILE, {})
    return data if isinstance(data, dict) else {}


def load_state() -> dict[str, Any]:
    """Return the runtime state, or an empty document."""
    state = _read(STATE_FILE, {})
    return state if isinstance(state, dict) else {}


def save_data(data: dict[str, Any]) -> None:
    """Persist operator settings atomically."""
    write_json(DATA_FILE, data)


def discord_token(bot: str = "community") -> str:
    """Return a bot token from the environment, or an empty string."""
    return os.environ.get(BOT_TOKENS.get(bot, ""), "").strip()


def discord_token_source(bot: str = "community") -> str:
    """Return the environment variable name a token came from."""
    return BOT_TOKENS.get(bot, "")


def update_state(mutator: Callable[[dict[str, Any]], Any]) -> Any:
    """Run ``mutator(state)`` and persist the result atomically.

    The write goes to a sibling temp file and is renamed into place, so
    a crash mid-write can never leave a truncated state file behind.
    """
    state = load_state()
    result = mutator(state)
    write_json(STATE_FILE, state)
    return result


def write_json(path: Path, payload: Any) -> None:
    PRIVATE.mkdir(parents=True, exist_ok=True)
    handle = tempfile.NamedTemporaryFile(
        mode="w", encoding="utf-8", dir=PRIVATE, delete=False, suffix=".tmp"
    )
    try:
        json.dump(payload, handle, ensure_ascii=False, indent=2, sort_keys=True)
        handle.flush()
        os.fsync(handle.fileno())
        handle.close()
        os.replace(handle.name, path)
    except BaseException:
        handle.close()
        try:
            os.unlink(handle.name)
        except OSError:
            pass
        raise
