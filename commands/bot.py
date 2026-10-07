"""Run the combined Stitchline Interactive community and moderation bot."""

import asyncio
import os
import time
from pathlib import Path

import aiomysql
import discord
from discord import app_commands
from discord.ext import commands
from dotenv import load_dotenv

ROOT = Path(__file__).resolve().parents[1]
load_dotenv(ROOT / "Core" / ".env")
BOT_ID = "community"
BOT_LABEL = "Stitchline Interactive"
HEARTBEAT_KEY = "community_heartbeat"
USER_AGENT = "StitchlineBot/1.0"


def load_json(path: Path) -> dict:
    import json

    return json.loads(path.read_text(encoding="utf-8"))


SITE = load_json(ROOT / "configurations" / "site.json")
COMMANDS = load_json(ROOT / "configurations" / "commands.json")


def embed(title: str, description: str, *, color: int = 0xA9CB74) -> discord.Embed:
    return discord.Embed(title=title, description=description, color=color)


def command_enabled(name: str) -> bool:
    settings = COMMANDS["community"].get("settings", {})
    return settings.get("enabled", {}).get(name, True)


async def require_enabled(interaction: discord.Interaction, name: str) -> bool:
    if command_enabled(name):
        return True
    await interaction.response.send_message(embed=embed("Command unavailable", "This command is currently disabled by the studio."), ephemeral=True)
    return False


class StitchlineBot(commands.Bot):
    def __init__(self) -> None:
        intents = discord.Intents.default()
        intents.message_content = True
        intents.members = True
        super().__init__(command_prefix="?", intents=intents, help_command=None)
        self.bot_id = "community"
        self.pool: aiomysql.Pool | None = None
        self.heartbeat_task: asyncio.Task | None = None
        self.fulfillment_task: asyncio.Task | None = None
        from Core import moderation_bot

        moderation_bot.attach(self)

    async def setup_hook(self) -> None:
        required = ("DATABASE_HOST", "DATABASE_NAME", "DATABASE_USER", "DATABASE_PASSWORD")
        if all(os.getenv(key) for key in required):
            self.pool = await aiomysql.create_pool(
                host=os.environ["DATABASE_HOST"],
                port=int(os.getenv("DATABASE_PORT", "3306")),
                db=os.environ["DATABASE_NAME"],
                user=os.environ["DATABASE_USER"],
                password=os.environ["DATABASE_PASSWORD"],
                autocommit=True,
                minsize=1,
                maxsize=5,
            )
        await self.tree.sync()

    async def on_ready(self) -> None:
        if self.heartbeat_task is None or self.heartbeat_task.done():
            self.heartbeat_task = asyncio.create_task(self.heartbeat_loop())
        if self.fulfillment_task is None or self.fulfillment_task.done():
            self.fulfillment_task = asyncio.create_task(self.fulfillment_loop())

    async def heartbeat_loop(self) -> None:
        from Core import config

        while not self.is_closed():
            guilds = [
                {"id": str(guild.id), "name": guild.name, "members": guild.member_count or 0}
                for guild in self.guilds
            ]
            def save_heartbeat(state: dict, guilds=guilds) -> None:
                state[HEARTBEAT_KEY] = {
                    "at": time.time(),
                    "commands": sorted({command.name for command in self.tree.get_commands()}),
                    "guilds": guilds,
                }
            config.update_state(save_heartbeat)
            try:
                from bots.site_api import report_heartbeat

                report_heartbeat({"guilds": guilds, "commands": sorted({command.name for command in self.tree.get_commands()})})
            except Exception as error:  # noqa: BLE001 - background loop must not crash
                print(f"[Stitchline Interactive] site heartbeat unavailable: {error}", flush=True)
            await asyncio.sleep(30)

    async def fulfillment_loop(self) -> None:
        from bots.site_api import (
            claim_fulfillment,
            finish_fulfillment,
            fulfillment_orders,
        )
        from Core.economy_bot import fulfill_web_order

        while not self.is_closed():
            if not os.getenv("STITCHLINE_SITE_URL") or not os.getenv("STITCHLINE_BOT_API_SECRET"):
                await asyncio.sleep(30)
                continue
            try:
                for order in fulfillment_orders():
                    reference = str(order.get("ref") or "")
                    if not reference or not claim_fulfillment(reference):
                        continue
                    try:
                        success, error = await fulfill_web_order(self, order)
                        finish_fulfillment(reference, success, error)
                    except Exception as error:  # noqa: BLE001 - background loop must not crash
                        finish_fulfillment(reference, False, str(error))
            except Exception as error:  # noqa: BLE001 - background loop must not crash
                print(f"[Stitchline Interactive] order fulfillment unavailable: {error}", flush=True)
            await asyncio.sleep(20)

    async def close(self) -> None:
        if self.heartbeat_task and not self.heartbeat_task.done():
            self.heartbeat_task.cancel()
        if self.fulfillment_task and not self.fulfillment_task.done():
            self.fulfillment_task.cancel()
        if self.pool is not None:
            self.pool.close()
            await self.pool.wait_closed()
        await super().close()

    async def fetchone(self, query: str, params: tuple = ()) -> dict | None:
        if self.pool is None:
            return None
        async with self.pool.acquire() as connection, connection.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute(query, params)
            return await cursor.fetchone()

    async def execute(self, query: str, params: tuple = ()) -> None:
        if self.pool is None:
            raise RuntimeError("MariaDB is not configured")
        async with self.pool.acquire() as connection, connection.cursor() as cursor:
            await cursor.execute(query, params)


def register_community_commands(bot: StitchlineBot) -> None:
    @bot.command(name="help")
    async def help_command(context: commands.Context) -> None:
        if not command_enabled("help"):
            await context.reply(embed=embed("Command unavailable", "This command is currently disabled by the studio."), mention_author=False)
            return
        prefix = "\n".join(f"`{item['trigger']}`" for item in COMMANDS["community"]["prefixCommands"])
        slash = "\n".join(f"`/{item['name']}`" for item in COMMANDS["community"]["slashCommands"])
        message = COMMANDS["community"].get("settings", {}).get("helpMessage", "Community commands")
        card = embed("Stitchline Interactive", f"{message}\n\n**Prefix commands**\n{prefix}\n\n**Slash commands**\n{slash}")
        await context.reply(embed=card, mention_author=False)

    @bot.command(name="ticketeta")
    async def ticket_eta(context: commands.Context) -> None:
        if not command_enabled("ticketeta"):
            await context.reply(embed=embed("Command unavailable", "This command is currently disabled by the studio."), mention_author=False)
            return
        message = COMMANDS["community"].get("settings", {}).get("ticketEtaMessage", "Response times vary with ticket volume.")
        await context.reply(embed=embed("Ticket response time", message), mention_author=False)

    @bot.command(name="website")
    async def website_command(context: commands.Context) -> None:
        await context.reply(embed=embed("Stitchline Interactive", SITE["studio"]["domain"]), mention_author=False)

    @bot.command(name="ping")
    async def ping_command(context: commands.Context) -> None:
        latency = round(bot.latency * 1000)
        await context.reply(embed=embed("Connection", f"Gateway latency: `{latency} ms`"), mention_author=False)

    @bot.tree.command(name="suggest", description="Send a suggestion to the Stitchline team")
    @app_commands.describe(suggestion="A suggestion for the community or studio")
    async def suggest(interaction: discord.Interaction, suggestion: app_commands.Range[str, 5, 1000]) -> None:
        if not await require_enabled(interaction, "suggest"):
            return
        if bot.pool is None:
            await interaction.response.send_message(embed=embed("Suggestions unavailable", "Storage is not configured yet."), ephemeral=True)
            return
        await bot.execute(
            "INSERT INTO suggestions (discord_user_id, suggestion_text, status) VALUES (%s, %s, 'pending')",
            (interaction.user.id, suggestion),
        )
        await interaction.response.send_message(embed=embed("Suggestion received", "Thank you. Your suggestion has been recorded for review."), ephemeral=True)

    @bot.tree.command(name="balance", description="View your Stitchblocks balance")
    async def balance(interaction: discord.Interaction) -> None:
        if not await require_enabled(interaction, "balance"):
            return
        from Core.wallet import lookup

        account = lookup(interaction.user.id)
        await interaction.response.send_message(embed=embed("Your balance", f"`{account['blocks']:,}` StitchBlocks"), ephemeral=True)

    @bot.tree.command(name="store", description="View the Stitchline community store")
    async def store(interaction: discord.Interaction) -> None:
        if not await require_enabled(interaction, "store"):
            return
        await interaction.response.send_message(embed=embed("Stitchline Store", f"{SITE['studio']['domain']}/store.html"), ephemeral=True)

    @bot.tree.command(name="level", description="View your community level")
    async def level(interaction: discord.Interaction) -> None:
        if not await require_enabled(interaction, "level"):
            return
        row = await bot.fetchone("SELECT level, experience FROM player_profiles WHERE discord_user_id = %s", (interaction.user.id,))
        if row is None and bot.pool is None:
            await interaction.response.send_message(embed=embed("Level unavailable", "Level tracking storage is not configured yet."), ephemeral=True)
            return
        values = row or {"level": 1, "experience": 0}
        await interaction.response.send_message(embed=embed("Community level", f"Level `{values['level']}` · `{values['experience']}` XP"), ephemeral=True)

    @bot.tree.command(name="ticket", description="Open a private support ticket")
    async def ticket(interaction: discord.Interaction) -> None:
        if not await require_enabled(interaction, "ticket"):
            return
        if not isinstance(interaction.channel, discord.TextChannel):
            await interaction.response.send_message(embed=embed("Ticket unavailable", "Open tickets from a server text channel."), ephemeral=True)
            return
        await interaction.response.defer(ephemeral=True, thinking=True)
        thread = await interaction.channel.create_thread(
            name=f"ticket-{interaction.user.name}"[:95],
            type=discord.ChannelType.private_thread,
            invitable=False,
            auto_archive_duration=1440,
        )
        await thread.add_user(interaction.user)
        await thread.send(embed=embed("Support ticket", f"Opened by {interaction.user.mention}. A staff member will respond here."))
        await interaction.followup.send(f"Your private ticket is ready: {thread.mention}", ephemeral=True)

    @bot.tree.command(name="leaderboard", description="Show the community experience leaderboard")
    async def leaderboard(interaction: discord.Interaction) -> None:
        if bot.pool is None:
            await interaction.response.send_message(embed=embed("Leaderboard unavailable", "Storage is not configured yet."), ephemeral=True)
            return
        async with bot.pool.acquire() as connection, connection.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT discord_user_id, level, experience FROM player_profiles ORDER BY experience DESC LIMIT 10")
            rows = await cursor.fetchall()
        lines = [f"`{index}.` <@{row['discord_user_id']}> · level {row['level']} · {row['experience']} XP" for index, row in enumerate(rows, 1)]
        await interaction.response.send_message(embed=embed("Community leaderboard", "\n".join(lines) if lines else "No activity has been recorded yet."), ephemeral=True)

    @bot.tree.command(name="invites", description="View your server invite count")
    async def invites(interaction: discord.Interaction) -> None:
        if interaction.guild is None:
            await interaction.response.send_message(embed=embed("Invites unavailable", "This command is only available in a server."), ephemeral=True)
            return
        invite_list = await interaction.guild.invites()
        total = sum(invite.uses or 0 for invite in invite_list if invite.inviter and invite.inviter.id == interaction.user.id)
        await interaction.response.send_message(embed=embed("Your invites", f"`{total}` uses across your active invite links."), ephemeral=True)

    @bot.tree.command(name="music", description="Music playback status")
    async def music(interaction: discord.Interaction) -> None:
        await interaction.response.send_message(embed=embed("Music unavailable", "Music playback requires an approved audio provider."), ephemeral=True)

    voice = app_commands.Group(name="vc", description="Manage temporary community voice rooms")

    @voice.command(name="creation", description="Create a temporary voice room")
    @app_commands.checks.has_permissions(manage_channels=True)
    async def voice_creation(interaction: discord.Interaction, name: app_commands.Range[str, 2, 90]) -> None:
        if interaction.guild is None:
            await interaction.response.send_message(embed=embed("Voice rooms unavailable", "This command is only available in a server."), ephemeral=True)
            return
        channel = await interaction.guild.create_voice_channel(name=name, reason=f"Requested by {interaction.user}")
        await interaction.response.send_message(embed=embed("Voice room created", channel.mention), ephemeral=True)

    @voice.command(name="configuration", description="Change a voice room name or member limit")
    @app_commands.checks.has_permissions(manage_channels=True)
    async def voice_configuration(
        interaction: discord.Interaction,
        channel: discord.VoiceChannel,
        name: app_commands.Range[str, 2, 90] | None = None,
        user_limit: app_commands.Range[int, 0, 99] | None = None,
    ) -> None:
        if name is None and user_limit is None:
            await interaction.response.send_message(embed=embed("No changes provided", "Provide a new name or member limit."), ephemeral=True)
            return
        await channel.edit(name=name or channel.name, user_limit=user_limit if user_limit is not None else channel.user_limit)
        await interaction.response.send_message(embed=embed("Voice room updated", channel.mention), ephemeral=True)

    @voice.command(name="delete", description="Delete a voice room")
    @app_commands.checks.has_permissions(manage_channels=True)
    async def voice_delete(interaction: discord.Interaction, channel: discord.VoiceChannel) -> None:
        channel_name = channel.name
        await channel.delete(reason=f"Requested by {interaction.user}")
        await interaction.response.send_message(embed=embed("Voice room deleted", f"`{channel_name}` was deleted."), ephemeral=True)

    bot.tree.add_command(voice)


def register_moderation_commands(bot: StitchlineBot) -> None:
    @bot.tree.command(name="kick", description="Remove a member from the server")
    @app_commands.checks.has_permissions(kick_members=True)
    async def kick(interaction: discord.Interaction, member: discord.Member, reason: str | None = None) -> None:
        await member.kick(reason=reason or f"Action by {interaction.user}")
        await interaction.response.send_message(embed=embed("Member kicked", f"{member} was removed. Reason: {reason or 'Not provided.'}"), ephemeral=True)

    @bot.tree.command(name="ban", description="Ban a member from the server")
    @app_commands.checks.has_permissions(ban_members=True)
    async def ban(interaction: discord.Interaction, member: discord.Member, reason: str | None = None) -> None:
        await member.ban(reason=reason or f"Action by {interaction.user}")
        await interaction.response.send_message(embed=embed("Member banned", f"{member} was banned. Reason: {reason or 'Not provided.'}"), ephemeral=True)

    @bot.tree.command(name="timeout", description="Temporarily timeout a member")
    @app_commands.checks.has_permissions(moderate_members=True)
    async def timeout(
        interaction: discord.Interaction,
        member: discord.Member,
        minutes: app_commands.Range[int, 1, 40320],
        reason: str | None = None,
    ) -> None:
        from datetime import timedelta

        await member.timeout(timedelta(minutes=minutes), reason=reason or f"Action by {interaction.user}")
        await interaction.response.send_message(embed=embed("Member timed out", f"{member} · {minutes} minutes. Reason: {reason or 'Not provided.'}"), ephemeral=True)


async def run() -> None:
    token = os.getenv("DISCORD_BOT_TOKEN")
    if not token:
        raise RuntimeError("Set DISCORD_BOT_TOKEN in the private bot environment before starting.")
    bot = StitchlineBot()
    register_community_commands(bot)
    register_moderation_commands(bot)
    await bot.start(token)


def main() -> None:
    asyncio.run(run())


if __name__ == "__main__":
    main()
