# Bot commands

The requested command inventory is in `../configurations/commands.json`. One Discord application named Stitchline Interactive runs the community prefix/slash commands and moderation commands in a single process.

Community commands are for the community; moderation commands require their Discord permission checks. Shared role IDs, hosting details, and public studio settings are stored in `../configurations/site.json`. Panel access rules are in `../configurations/panel.json`.

Install the root `requirements.txt`, copy `Core/.env.example` to `Core/.env`, and fill secrets privately. Start the site and bot together with `py Starter.py --with-bot`, or use `py Starter.py --bot` to run only the bot. Apply `../deploy/spaceship-schema.sql` to MariaDB before enabling the PHP-hosted site; the bot's optional direct database features use the configured MariaDB credentials.

Discord OAuth credentials, SMTP passwords, payment keys, and TURN credentials must be supplied privately through environment configuration; never publish a populated copy. Payments remain manual until Stripe is configured. Browser voice/video/screenshare and music playback require their respective providers.
