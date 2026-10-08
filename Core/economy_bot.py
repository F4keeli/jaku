"""Web order fulfillment for the Stitchline bot.

Paid orders on the website are picked up by the bot's fulfillment
loop. Each cart line says what it delivers: StitchBlocks (credited to
the buyer's wallet) or a Discord role (granted in every configured
guild). Failures are reported back to the site so the order stays in
its paid state and can be retried.
"""

from __future__ import annotations

from typing import Any


async def fulfill_web_order(bot: Any, order: dict[str, Any]) -> tuple[bool, str]:
    """Deliver one paid web order. Returns ``(success, detail)``."""
    discord_id = str(order.get("discord_id") or "")
    if not discord_id.isdigit():
        return False, "The order has no Discord user ID."

    granted: list[str] = []
    for line in order.get("lines") or []:
        if not isinstance(line, dict):
            continue
        delivery = line.get("deliver") or {}
        kind = str(delivery.get("kind") or "")
        try:
            if kind == "currency":
                amount = int(delivery.get("amount") or 0)
                if amount <= 0:
                    continue
                from Core.wallet import Wallet

                balance = await Wallet.add(discord_id, amount)
                granted.append(f"{amount:,} StitchBlocks (balance {balance:,})")
            elif kind == "role":
                role_name = str(delivery.get("role") or "").strip()
                if role_name:
                    assigned = await grant_role(bot, discord_id, role_name)
                    granted.append(f"role {role_name} ({assigned} server(s))")
        except RuntimeError as error:
            return False, str(error)

    detail = "; ".join(granted) if granted else "Order contained no deliverable items."
    return True, detail


async def grant_role(bot: Any, discord_id: str, role_name: str) -> int:
    """Assign a role by name in every configured guild. Returns count."""
    from bots.site_api import bot_settings

    settings = bot_settings()
    guild_ids = [str(guild) for guild in (settings.get("guilds") or [])]
    assigned = 0
    for guild_id in guild_ids:
        try:
            guild = bot.get_guild(int(guild_id))
        except (TypeError, ValueError):
            continue
        if guild is None:
            continue
        role = next(
            (role for role in guild.roles if role.name == role_name), None
        )
        if role is None:
            continue
        try:
            member = await guild.fetch_member(int(discord_id))
        except Exception:  # noqa: BLE001 - not a member of this guild
            continue
        if role not in member.roles:
            await member.add_roles(role, reason="Stitchline store purchase")
        assigned += 1
    return assigned
