"""Build the PHP/MariaDB Spaceship upload bundle."""
from __future__ import annotations

import shutil
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

OUT = ROOT / "deploy" / "output" / "spaceship-upload"
SITE_SEED = ROOT / "deploy" / "seed" / "site.json"
MODERATION_SEED = ROOT / "deploy" / "seed" / "moderation.json"
PAGES = (
    "index.html",
    "games.html",
    "team.html",
    "contact.html",
    "store.html",
    "application.html",
    "account.html",
    "staff.html",
    "section.html",
)


def copy_tree(source: Path, target: Path) -> None:
    for item in sorted(source.rglob("*")):
        if item.is_file():
            destination = target / item.relative_to(source)
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(item, destination)


def readme() -> str:
    return """STITCHLINE SPACESHIP PACKAGE

1. In cPanel File Manager, open /home/CPANEL_USER/stitchlineinteractivegames.com
   (the folder your domain serves). Move any old files out of it first.
2. Upload everything inside this folder (not the folder itself) into it. Turn on
   Settings > Show Hidden Files so .htaccess is visible and uploaded.
3. Open https://stitchlineinteractivegames.com/install.php and fill in the form.
   Only the database name, user and password are required. Discord, Roblox,
   email sender, Stripe and the owner account can be filled in now or later.
4. The installer creates the tables, loads the site content, writes
   stitchline-config.php, and deletes itself.

Roblox OAuth: register the redirect URI
https://stitchlineinteractivegames.com/api/account/roblox/callback with the
openid and profile scopes. Discord redirect URI:
https://stitchlineinteractivegames.com/api/account/callback

Later changes: edit stitchline-config.php in the site folder. It is blocked from
web access by .htaccess. Account sign-in is Discord plus an emailed code.

For checkout, supply the Stripe secret key and webhook signing secret and enable
Stripe Tax in both Stripe and the installer. Point the webhook at
https://stitchlineinteractivegames.com/api/stripe/webhook and subscribe to
checkout.session.completed, checkout.session.async_payment_succeeded and
checkout.session.expired. Test checkout and webhook delivery before launch.

Local development requires PHP 8.1+ and the database driver, cURL, mbstring and
OpenSSL extensions. Run python Starter.py --php /path/to/php and set
STITCHLINE_PRIVATE_CONFIG to a separate development database configuration.
The local server is for development only; production requires Apache rewrites.
"""

def build() -> int:
    missing_pages = [name for name in PAGES if not (ROOT / name).is_file()]
    if missing_pages:
        raise FileNotFoundError("Required public pages are missing: " + ", ".join(missing_pages))
    if OUT.resolve() != (ROOT / "deploy" / "output" / "spaceship-upload").resolve():
        raise ValueError("The upload directory must be inside deploy/output.")
    for stale in (OUT,):
        if stale.is_dir():
            shutil.rmtree(stale)
        elif stale.exists():
            stale.unlink()

    public = OUT
    public.mkdir(parents=True)

    pages = []
    for name in PAGES:
        shutil.copy2(ROOT / name, public / name)
        pages.append(name)
    copy_tree(ROOT / "assets", public / "assets")
    copy_tree(ROOT / "website scripts", public / "website scripts")
    for name in ("api.php", "bot_api.php", "staff_api.php", "payments_api.php", "account_api.php", "webauthn.php", "db_compat.php", ".htaccess"):
        shutil.copy2(ROOT / name, public / name)
    shutil.copy2(ROOT / "deploy" / "install.php", public / "install.php")
    setup = public / "setup"
    setup.mkdir()
    shutil.copy2(ROOT / "deploy" / "spaceship-schema.sql", setup / "schema.sql")
    shutil.copy2(ROOT / "deploy" / "spaceship-schema.pg.sql", setup / "schema.pg.sql")
    shutil.copy2(SITE_SEED, setup / "site.json")
    shutil.copy2(MODERATION_SEED, setup / "moderation.json")
    (public / "UPLOAD-README.txt").write_text(readme(), encoding="utf-8")
    forbidden = ("Core/", "deploy/", "start.py", ".env", "staff.json", "bots/")
    names = [item.relative_to(OUT).as_posix() for item in sorted(OUT.rglob("*")) if item.is_file()]
    leaked = [name for name in names if any(part in name for part in forbidden)]
    if leaked:
        print("unsafe entries found in package:")
        for name in leaked:
            print(f"  {name}")
        return 1

    for path in public.glob("*.php"):
        if "*** End Patch" in path.read_text(encoding="utf-8"):
            raise ValueError(f"Unfinished patch in {path.name}.")
    archive = shutil.make_archive(str(OUT), "zip", root_dir=OUT)

    print(f"built {OUT.name}/")
    print(f"  Archive: {archive}")
    print(f"  {len(pages)} page(s): {', '.join(pages)}")
    print(f"  {len(names)} packaged files; no project source or secrets")
    print("  Read UPLOAD-README.txt before uploading.")
    print("  Warning: configure/test payment and bot credentials on Spaceship before live launch.")
    return 0


if __name__ == "__main__":
    raise SystemExit(build())
