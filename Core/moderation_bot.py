"""Moderation commands for the Stitchline Interactive bot.

The community bot is strictly for the community; this module is
strictly for moderation and managing it. Every command answers
with a clean embed, requires the matching Discord permission,
and records its action to the site's moderation log so the
panel's Case Logs and Moderation Actions stay in sync.
"""

from __future__ import annotations

import random
import time
from datetime import timedelta
from typing import Any

import discord
from discord import app_commands


def embed(title: str, description: str, *, color: int = 0xA9CB74) -> discord.Embed:
    return discord.Embed(title=title, description=description, color=color)


def log_record(bot: Any, kind: str, record: dict[str, Any]) -> None:
    """Best-effort write to the site moderation log."""
    try:
        from bots.site_api import add_record

        add_record(kind, record)
    except Exception:  # noqa: BLE001 - logging must never break a command
        pass


def actor(interaction: discord.Interaction) -> dict[str, Any]:
    return {
        "moderator_name": str(interaction.user.name),
        "moderator_id": str(interaction.user.id),
        "actor": "bot",
    }


def register_unban(bot: Any) -> None:
    @bot.tree.command(name="unban", description="Remove a ban from a user")
    @app_commands.describe(user="The banned user", reason="Why they are unbanned")
    @app_commands.checks.has_permissions(ban_members=True)
    async def unban(
        interaction: discord.Interaction,
        user: discord.User,
        reason: str | None = None,
    ) -> None:
        if interaction.guild is None:
            await interaction.response.send_message(
                embed=embed("Unban unavailable", "This command is only available in a server."),
                ephemeral=True,
            )
            return
        await interaction.guild.unban(user, reason=reason or f"Action by {interaction.user}")
        await interaction.response.send_message(
            embed=embed("Member unbanned", f"{user} was unbanned. Reason: {reason or 'Not provided.'}"),
            ephemeral=True,
        )
        log_record(bot, "actions", {
            **actor(interaction),
            "kind": "unban",
            "user_name": str(user.name),
            "user_id": str(user.id),
            "reason": reason or "Not provided.",
        })


def register_giveaway(bot: Any) -> None:
    giveaway = app_commands.Group(name="giveaway", description="Run community giveaways")

    @giveaway.command(name="create", description="Start a giveaway in this channel")
    @app_commands.describe(
        prize="What is being given away",
        minutes="How long it runs for",
        winners="How many winners",
    )
    @app_commands.checks.has_permissions(manage_messages=True)
    async def create(
        interaction: discord.Interaction,
        prize: app_commands.Range[str, 2, 120],
        minutes: app_commands.Range[int, 1, 10080],
        winners: app_commands.Range[int, 1, 20] = 1,
    ) -> None:
        if not isinstance(interaction.channel, discord.TextChannel):
            await interaction.response.send_message(
                embed=embed("Giveaway unavailable", "Start giveaways from a text channel."),
                ephemeral=True,
            )
            return
        ends_at = time.time() + minutes * 60
        card = embed(
            "Giveaway",
            f"**{prize}**\n{winners} winner(s) · ends <t:{int(ends_at)}:R>",
        )
        card.set_footer(text=f"Started by {interaction.user.name}")
        message = await interaction.channel.send(embed=card)
        await message.add_reaction("🎁")
        await interaction.response.send_message(
            embed=embed("Giveaway started", f"{prize} · {winners} winner(s) · {minutes} minutes"),
            ephemeral=True,
        )
        log_record(bot, "giveaways", {
            **actor(interaction),
            "prize": prize,
            "description": f"{winners} winner(s), {minutes} minutes",
            "winners": winners,
            "entries": 0,
            "message_id": str(message.id),
            "channel_id": str(interaction.channel.id),
            "ends_at": ends_at,
            "status": "open",
        })

    @giveaway.command(name="edit", description="Edit a running giveaway")
    @app_commands.describe(
        message_id="The giveaway message ID",
        prize="New prize",
        minutes="New duration in minutes",
        winners="New winner count",
    )
    @app_commands.checks.has_permissions(manage_messages=True)
    async def edit(
        interaction: discord.Interaction,
        message_id: str,
        prize: str | None = None,
        minutes: int | None = None,
        winners: int | None = None,
    ) -> None:
        if not isinstance(interaction.channel, discord.TextChannel):
            await interaction.response.send_message(
                embed=embed("Giveaway unavailable", "Edit giveaways from a text channel."),
                ephemeral=True,
            )
            return
        try:
            message = await interaction.channel.fetch_message(int(message_id))
        except (discord.NotFound, ValueError):
            await interaction.response.send_message(
                embed=embed("Giveaway not found", "That message ID is not in this channel."),
                ephemeral=True,
            )
            return
        if not message.embeds:
            await interaction.response.send_message(
                embed=embed("Not a giveaway", "That message has no giveaway embed."),
                ephemeral=True,
            )
            return
        card = message.embeds[0]
        if prize:
            card.description = f"**{prize}**\n" + (card.description or "").split("\n", 1)[-1]
        if winners:
            card.description = card.description.replace(
                f"{card.description.split(' winner')[0].split('·')[-1].strip()} winner",
                f"{winners} winner",
            ) if "winner" in (card.description or "") else card.description
        if minutes:
            ends_at = time.time() + minutes * 60
            card.description = card.description.split("\n")[0] + (
                f"\n{winners or 1} winner(s) · ends <t:{int(ends_at)}:R>"
            )
        await message.edit(embed=card)
        await interaction.response.send_message(
            embed=embed("Giveaway updated", f"Message {message_id} was edited."),
            ephemeral=True,
        )
        log_record(bot, "giveaways", {
            **actor(interaction),
            "message_id": str(message_id),
            "prize": prize or "",
            "description": "Giveaway edited",
            "status": "edited",
        })

    @giveaway.command(name="reroll", description="Pick a new winner for a giveaway")
    @app_commands.describe(message_id="The giveaway message ID", winners="How many winners to pick")
    @app_commands.checks.has_permissions(manage_messages=True)
    async def reroll(
        interaction: discord.Interaction,
        message_id: str,
        winners: app_commands.Range[int, 1, 20] = 1,
    ) -> None:
        if not isinstance(interaction.channel, discord.TextChannel):
            await interaction.response.send_message(
                embed=embed("Giveaway unavailable", "Reroll giveaways from a text channel."),
                ephemeral=True,
            )
            return
        try:
            message = await interaction.channel.fetch_message(int(message_id))
        except (discord.NotFound, ValueError):
            await interaction.response.send_message(
                embed=embed("Giveaway not found", "That message ID is not in this channel."),
                ephemeral=True,
            )
            return
        entries = []
        for reaction in message.reactions:
            if str(reaction.emoji) == "🎁":
                async for user in reaction.users():
                    if not user.bot:
                        entries.append(user)
        if not entries:
            await interaction.response.send_message(
                embed=embed("No entries", "Nobody entered that giveaway."),
                ephemeral=True,
            )
            return
        picked = random.sample(entries, min(winners, len(entries)))
        names = ", ".join(user.mention for user in picked)
        await interaction.channel.send(
            embed=embed("Giveaway winner(s)", f"Congratulations to {names}!")
        )
        await interaction.response.send_message(
            embed=embed("Giveaway rerolled", f"{len(picked)} winner(s) picked from {len(entries)} entries."),
            ephemeral=True,
        )
        log_record(bot, "giveaways", {
            **actor(interaction),
            "message_id": str(message_id),
            "prize": (message.embeds[0].title if message.embeds else "Giveaway"),
            "description": f"Rerolled: {len(picked)} winner(s) from {len(entries)} entries",
            "winners": len(picked),
            "entries": len(entries),
            "status": "drawn",
        })

    bot.tree.add_command(giveaway)


def register_rank(bot: Any) -> None:
    async def role_by_name(guild: discord.Guild, name: str) -> discord.Role | None:
        return next((role for role in guild.roles if role.name == name), None)

    @bot.tree.command(name="rank", description="Give a member a role by name")
    @app_commands.describe(member="The member", role="The role name")
    @app_commands.checks.has_permissions(manage_roles=True)
    async def rank(
        interaction: discord.Interaction,
        member: discord.Member,
        role: app_commands.Range[str, 2, 100],
    ) -> None:
        if interaction.guild is None:
            await interaction.response.send_message(
                embed=embed("Rank unavailable", "This command is only available in a server."),
                ephemeral=True,
            )
            return
        target = await role_by_name(interaction.guild, role)
        if target is None:
            await interaction.response.send_message(
                embed=embed("Role not found", f"No role named `{role}` exists."),
                ephemeral=True,
            )
            return
        await member.add_roles(target, reason=f"Rank by {interaction.user}")
        await interaction.response.send_message(
            embed=embed("Rank granted", f"{member} received **{target.name}**."),
            ephemeral=True,
        )
        log_record(bot, "actions", {
            **actor(interaction),
            "kind": "rank",
            "user_name": str(member.name),
            "user_id": str(member.id),
            "reason": f"Role {target.name}",
        })

    @bot.tree.command(name="unrank", description="Remove a role from a member by name")
    @app_commands.describe(member="The member", role="The role name")
    @app_commands.checks.has_permissions(manage_roles=True)
    async def unrank(
        interaction: discord.Interaction,
        member: discord.Member,
        role: app_commands.Range[str, 2, 100],
    ) -> None:
        if interaction.guild is None:
            await interaction.response.send_message(
                embed=embed("Unrank unavailable", "This command is only available in a server."),
                ephemeral=True,
            )
            return
        target = await role_by_name(interaction.guild, role)
        if target is None:
            await interaction.response.send_message(
                embed=embed("Role not found", f"No role named `{role}` exists."),
                ephemeral=True,
            )
            return
        await member.remove_roles(target, reason=f"Unrank by {interaction.user}")
        await interaction.response.send_message(
            embed=embed("Rank removed", f"{member} lost **{target.name}**."),
            ephemeral=True,
        )
        log_record(bot, "actions", {
            **actor(interaction),
            "kind": "unrank",
            "user_name": str(member.name),
            "user_id": str(member.id),
            "reason": f"Role {target.name}",
        })

    async def apply_bulk(interaction: discord.Interaction, members: list[str], role_name: str, grant: bool) -> None:
        if interaction.guild is None:
            await interaction.response.send_message(
                embed=embed("Bulk rank unavailable", "This command is only available in a server."),
                ephemeral=True,
            )
            return
        target = await role_by_name(interaction.guild, role_name)
        if target is None:
            await interaction.response.send_message(
                embed=embed("Role not found", f"No role named `{role_name}` exists."),
                ephemeral=True,
            )
            return
        changed = 0
        for raw in members:
            raw = raw.strip().strip("<@!>")
            if not raw.isdigit():
                continue
            try:
                member = await interaction.guild.fetch_member(int(raw))
            except (discord.NotFound, discord.Forbidden, ValueError):
                continue
            try:
                if grant and target not in member.roles:
                    await member.add_roles(target, reason=f"Bulk rank by {interaction.user}")
                elif not grant and target in member.roles:
                    await member.remove_roles(target, reason=f"Bulk unrank by {interaction.user}")
                changed += 1
            except (discord.Forbidden, discord.HTTPException):
                continue
        verb = "granted" if grant else "removed"
        await interaction.response.send_message(
            embed=embed("Bulk rank complete", f"**{target.name}** {verb} for {changed} member(s)."),
            ephemeral=True,
        )
        log_record(bot, "actions", {
            **actor(interaction),
            "kind": "bulkrank" if grant else "bulkunrank",
            "user_name": f"{len(members)} member(s)",
            "user_id": "",
            "reason": f"Role {target.name}",
        })

    @bot.tree.command(name="bulkrank", description="Give a role to several members")
    @app_commands.describe(role="The role name", members="Space-separated user IDs or mentions")
    @app_commands.checks.has_permissions(manage_roles=True)
    async def bulkrank(
        interaction: discord.Interaction,
        role: app_commands.Range[str, 2, 100],
        members: str,
    ) -> None:
        await apply_bulk(interaction, members.split(), role, True)

    @bot.tree.command(name="bulkunrank", description="Remove a role from several members")
    @app_commands.describe(role="The role name", members="Space-separated user IDs or mentions")
    @app_commands.checks.has_permissions(manage_roles=True)
    async def bulkunrank(
        interaction: discord.Interaction,
        role: app_commands.Range[str, 2, 100],
        members: str,
    ) -> None:
        await apply_bulk(interaction, members.split(), role, False)


def register_blacklist(bot: Any) -> None:
    @bot.tree.command(name="blacklist", description="Record a blacklist entry")
    @app_commands.describe(user="The user", reason="Why they are blacklisted")
    @app_commands.checks.has_permissions(ban_members=True)
    async def blacklist(
        interaction: discord.Interaction,
        user: discord.User,
        reason: app_commands.Range[str, 4, 500],
    ) -> None:
        await interaction.response.send_message(
            embed=embed(
                "Blacklisted",
                f"{user} ({user.id}) was blacklisted.\nReason: {reason}",
                color=0xE06C75,
            ),
            ephemeral=True,
        )
        log_record(bot, "cases", {
            **actor(interaction),
            "kind": "blacklist",
            "user_name": str(user.name),
            "user_id": str(user.id),
            "reason": reason,
            "status": "open",
        })


def register_warn(bot: Any) -> None:
    @bot.tree.command(name="warn", description="Warn a member and log the case")
    @app_commands.describe(member="The member", reason="Why they are warned")
    @app_commands.checks.has_permissions(moderate_members=True)
    async def warn(
        interaction: discord.Interaction,
        member: discord.Member,
        reason: app_commands.Range[str, 4, 500],
    ) -> None:
        record = {
            **actor(interaction),
            "kind": "warning",
            "user_name": str(member.name),
            "user_id": str(member.id),
            "reason": reason,
            "status": "open",
        }
        stored = log_record(bot, "cases", record)
        case_ref = (stored or {}).get("ref") or "unrecorded"
        try:
            await member.send(
                embed=embed(
                    "Stitchline Interactive warning",
                    f"You were warned in {interaction.guild.name if interaction.guild else 'the server'}.\n"
                    f"Case: {case_ref}\nReason: {reason}",
                    color=0xE06C75,
                )
            )
            notified = "The member was notified by direct message."
        except discord.Forbidden:
            notified = "The member could not be notified (direct messages closed)."
        await interaction.response.send_message(
            embed=embed("Member warned", f"{member} · case {case_ref}\nReason: {reason}\n{notified}"),
            ephemeral=True,
        )

    @bot.tree.command(name="unwarn", description="Close a warning case")
    @app_commands.describe(case_id="The case reference, for example C-12")
    @app_commands.checks.has_permissions(moderate_members=True)
    async def unwarn(interaction: discord.Interaction, case_id: str) -> None:
        from bots.site_api import request as site_request

        try:
            result = site_request(
                "GET",
                "/api/bot/records?kind=cases&limit=1000",
            )
        except RuntimeError:
            await interaction.response.send_message(
                embed=embed("Cases unavailable", "The moderation log could not be reached."),
                ephemeral=True,
            )
            return
        records = [row for row in result.get("records", []) if isinstance(row, dict)]
        target = next(
            (row for row in records
             if str(row.get("ref") or "") == case_id or str(row.get("id") or "") == case_id),
            None,
        )
        if target is None or str(target.get("kind") or "") not in ("warning", "warn"):
            await interaction.response.send_message(
                embed=embed("Case not found", f"No open warning matches `{case_id}`."),
                ephemeral=True,
            )
            return
        try:
            site_request(
                "POST",
                "/api/bot/records",
                {
                    "operation": "patch",
                    "kind": "cases",
                    "id": str(target.get("id")),
                    "changes": {"status": "closed"},
                },
            )
        except RuntimeError:
            pass
        await interaction.response.send_message(
            embed=embed("Warning closed", f"Case {case_id} was closed."),
            ephemeral=True,
        )


def register_note(bot: Any) -> None:
    @bot.tree.command(name="note", description="Attach a private staff note to a member")
    @app_commands.describe(member="The member", note="The note")
    @app_commands.checks.has_permissions(moderate_members=True)
    async def note(
        interaction: discord.Interaction,
        member: discord.Member,
        note: app_commands.Range[str, 4, 1000],
    ) -> None:
        stored = log_record(bot, "cases", {
            **actor(interaction),
            "kind": "note",
            "user_name": str(member.name),
            "user_id": str(member.id),
            "reason": note,
            "status": "closed",
        })
        case_ref = (stored or {}).get("ref") or "unrecorded"
        await interaction.response.send_message(
            embed=embed("Note recorded", f"Note for {member} · case {case_ref}\n{note}"),
            ephemeral=True,
        )


def register_purge(bot: Any) -> None:
    @bot.tree.command(name="purge", description="Delete recent messages in a channel")
    @app_commands.describe(count="How many messages (1-100)", channel="Channel to purge, defaults to this one")
    @app_commands.checks.has_permissions(manage_messages=True)
    async def purge(
        interaction: discord.Interaction,
        count: app_commands.Range[int, 1, 100],
        channel: discord.TextChannel | None = None,
    ) -> None:
        target = channel or interaction.channel
        if not isinstance(target, discord.TextChannel):
            await interaction.response.send_message(
                embed=embed("Purge unavailable", "Purge a text channel."),
                ephemeral=True,
            )
            return
        await interaction.response.defer(ephemeral=True, thinking=True)
        removed = await target.purge(limit=count, reason=f"Purge by {interaction.user}")
        await interaction.followup.send(
            embed=embed("Messages purged", f"{len(removed)} message(s) removed from {target.mention}."),
            ephemeral=True,
        )
        log_record(bot, "server", {
            **actor(interaction),
            "kind": "purge",
            "detail": f"{len(removed)} messages removed from #{target.name}",
        })


def attach(bot: Any) -> None:
    """Register every moderation command on the shared bot."""
    register_unban(bot)
    register_giveaway(bot)
    register_rank(bot)
    register_blacklist(bot)
    register_warn(bot)
    register_note(bot)
    register_purge(bot)
