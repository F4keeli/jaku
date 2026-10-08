"""StitchBlocks wallet.

Balances live in the ``economy_balances`` table of the bot's MariaDB
database. The pool is attached by the bot at start-up; every lookup is
graceful when the database is not configured, so community commands
degrade to a zero balance instead of throwing.
"""

from __future__ import annotations

from typing import Any

import aiomysql


class Wallet:
    """Async balance store backed by the bot's connection pool."""

    pool: aiomysql.Pool | None = None

    @classmethod
    def attach(cls, pool: aiomysql.Pool | None) -> None:
        cls.pool = pool

    @classmethod
    async def balance(cls, discord_user_id: int | str) -> dict[str, Any]:
        """Return ``{"blocks": n, "source": ...}`` for one user."""
        if cls.pool is None:
            return {"blocks": 0, "source": "offline"}
        try:
            async with cls.pool.acquire() as connection, connection.cursor(
                aiomysql.DictCursor
            ) as cursor:
                await cursor.execute(
                    "SELECT balance FROM economy_balances WHERE discord_user_id = %s",
                    (int(discord_user_id),),
                )
                row = await cursor.fetchone()
        except (aiomysql.Error, ValueError, TypeError):
            return {"blocks": 0, "source": "error"}
        if not row:
            return {"blocks": 0, "source": "database"}
        return {"blocks": int(row["balance"]), "source": "database"}

    @classmethod
    async def add(cls, discord_user_id: int | str, amount: int) -> int:
        """Credit ``amount`` StitchBlocks and return the new balance."""
        if cls.pool is None:
            raise RuntimeError("MariaDB is not configured")
        if amount <= 0:
            raise ValueError("Amounts must be positive")
        async with cls.pool.acquire() as connection:
            async with connection.cursor() as cursor:
                await cursor.execute(
                    "INSERT INTO economy_balances (discord_user_id, balance) "
                    "VALUES (%s, %s) ON DUPLICATE KEY UPDATE "
                    "balance = balance + VALUES(balance)",
                    (int(discord_user_id), amount),
                )
                await cursor.execute(
                    "SELECT balance FROM economy_balances WHERE discord_user_id = %s",
                    (int(discord_user_id),),
                )
                row = await cursor.fetchone()
        return int(row[0]) if row else 0


#: The slash command imports ``lookup``; it is async because every
#: MariaDB call in this bot goes through the async pool.
async def lookup(discord_user_id: int | str) -> dict[str, Any]:
    return await Wallet.balance(discord_user_id)
