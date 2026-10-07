"""Run the Stitchline API-backed site, its single bot, or build a host package."""

import argparse
import os
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
def main() -> None:
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument("--host", default="127.0.0.1", help="Site server host")
	parser.add_argument("--port", default=5510, type=int, help="Site server port")
	parser.add_argument("--php", default=os.environ.get("PHP_BINARY", "php"), help="PHP 8.1+ executable for local development")
	parser.add_argument("--bot", action="store_true", help="Run the single Stitchline Discord bot only")
	parser.add_argument("--with-bot", action="store_true", help="Run the site and single Discord bot together")
	parser.add_argument(
		"--package",
		nargs="?",
		const="deploy/output/spaceship-upload.zip",
		metavar="ZIP_PATH",
		help="Build the PHP/MariaDB Spaceship upload package",
	)
	args = parser.parse_args()

	if args.bot and args.with_bot:
		parser.error("Use either --bot or --with-bot, not both.")

	if args.package:
		subprocess.run([sys.executable, "deploy/build_spaceship.py"], cwd=ROOT, check=True)
		built = ROOT / "deploy" / "output" / "spaceship-upload.zip"
		destination = Path(args.package)
		if not destination.is_absolute():
			destination = ROOT / destination
		destination = destination.resolve()
		if destination != built.resolve():
			destination.parent.mkdir(parents=True, exist_ok=True)
			shutil.copy2(built, destination)
		print(f"Hosting package: {destination}")
		return

	if args.bot or args.with_bot:
		missing = [name for name in ("moderation_bot", "config", "economy_bot", "wallet") if not (ROOT / "Core" / (name + ".py")).is_file()]
		if missing:
			parser.error("The bot requires missing Core modules: " + ", ".join(missing) + ". Restore them before starting the bot.")

	if args.bot:
		import asyncio

		from commands.bot import run

		asyncio.run(run())
		return

	php = shutil.which(args.php)
	if php is None:
		parser.error("PHP 8.1+ is required. Add php to PATH or pass --php with the path to php.exe.")
	if not 1 <= args.port <= 65535:
		parser.error("The server port must be between 1 and 65535.")
	command = [php, "-S", f"{args.host}:{args.port}", "-t", str(ROOT), str(ROOT / "deploy" / "serve.php")]
	children = []
	try:
		children.append(subprocess.Popen(command, cwd=ROOT))
		if args.with_bot:
			children.append(subprocess.Popen([sys.executable, str(ROOT / "Starter.py"), "--bot"], cwd=ROOT))
		print(f"Local development site: http://{args.host}:{args.port}", flush=True)
		while True:
			for child in children:
				try:
					status = child.wait(timeout=0.2)
				except subprocess.TimeoutExpired:
					continue
				raise SystemExit(status)
	except KeyboardInterrupt:
		pass
	finally:
		for child in children:
			if child.poll() is None:
				child.terminate()
		for child in children:
			try:
				child.wait(timeout=5)
			except subprocess.TimeoutExpired:
				child.kill()
				child.wait()


if __name__ == "__main__":
	main()
