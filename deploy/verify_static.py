"""Check the static assets and PHP syntax in a Spaceship upload bundle."""
from __future__ import annotations

import argparse
import http.server
import posixpath
import re
import shutil
import subprocess
from html.parser import HTMLParser
import socketserver
import sys
import threading
import time
import urllib.request
from pathlib import Path
from urllib.parse import quote, unquote, urljoin, urlsplit

MIME = {
    ".html": "text/html; charset=utf-8",
    ".css": "text/css; charset=utf-8",
    ".js": "application/javascript; charset=utf-8",
    ".json": "application/json; charset=utf-8",
    ".png": "image/png",
    ".jpg": "image/jpeg",
    ".jpeg": "image/jpeg",
    ".jfif": "image/jpeg",
    ".webp": "image/webp",
    ".svg": "image/svg+xml",
    ".ico": "image/x-icon",
    ".txt": "text/plain; charset=utf-8",
}


class StrictHandler(http.server.BaseHTTPRequestHandler):
    """Serves files, and nothing else."""

    root: Path = Path(".")

    def log_message(self, format: str, *args) -> None:
        pass

    def resolve(self, path: str) -> Path | None:
        """Map a URL path to a file, the way a Linux file server would.

        The case check is the point: Path.resolve() on Windows matches
        case-insensitively, so each segment is also compared byte-for-byte
        against what the filesystem actually holds.
        """
        clean = posixpath.normpath(unquote(path.split("?", 1)[0].split("#", 1)[0]))
        raw_parts = clean.split("/")
        if any(p == ".." for p in raw_parts):
            return None
        parts = [p for p in raw_parts if p not in ("", ".")]
        # "/" is index.html on every static host, including this one. That is not
        # the "directory index" setting being switched on: it is what the root
        # resolves to. The point of the no-index rule below is that /games must
        # NOT resolve, because the site must not depend on that behaviour.
        if not parts:
            parts = ["index.html"]

        # Each segment is matched against the entries of the directory that
        # contains it, which is what a case-sensitive filesystem does. Comparing
        # a segment against the entries of the path it just joined is off by one
        # and fails on the first segment - "assets" is not inside "assets".
        walked = self.root
        parent = self.root
        for part in parts:
            if not parent.is_dir():
                return None
            names = {e.name for e in parent.iterdir()}
            if part not in names:
                return None
            walked = parent / part
            parent = walked
        return walked if walked.is_file() and walked.suffix.lower() in MIME else None

    def do_GET(self) -> None:
        target = self.resolve(self.path)
        if target is None:
            # No directory index, on purpose: a host with indexes disabled must
            # still serve the whole site, which is what the flat .html links are
            # for.
            self.send_error(404, "Not Found")
            return
        body = target.read_bytes()
        kind = MIME.get(target.suffix.lower(), "application/octet-stream")
        self.send_response(200)
        self.send_header("Content-Type", kind)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


def serve(root: Path, port: int) -> socketserver.TCPServer:
    handler = type("H", (StrictHandler,), {"root": root})
    socketserver.TCPServer.allow_reuse_address = True
    httpd = socketserver.ThreadingTCPServer(("127.0.0.1", port), handler)
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    return httpd


class PageLinks(HTMLParser):
    def __init__(self, page: str):
        super().__init__()
        self.page = page
        self.paths: set[str] = set()

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        for name, value in attrs:
            if name not in {"src", "href"} or not value:
                continue
            url = urlsplit(value)
            if url.scheme or url.netloc or not url.path:
                continue
            path = urlsplit(urljoin("/" + self.page, value)).path
            if not path.startswith(("/api/", "/staff/api/")):
                self.paths.add(quote(unquote(path), safe="/"))


def referenced_paths(root: Path) -> set[str]:
    found: set[str] = set()
    for page in root.glob("*.html"):
        parser = PageLinks(page.name)
        parser.feed(page.read_text(encoding="utf-8"))
        found.update(parser.paths)
    return found


def check(root: Path, port: int, php: str | None = None) -> int:
    httpd = serve(root, port)
    base = f"http://127.0.0.1:{httpd.server_address[1]}"
    failures: list[str] = []
    checked = 0

    print(f"serving {root} on {base} as a strict Linux-like host\n")

    # 1. every path the markup references must resolve, exactly.
    print("paths the pages reference")
    for path in sorted(referenced_paths(root)):
        try:
            with urllib.request.urlopen(base + path, timeout=15) as resp:
                kind = resp.headers.get("Content-Type", "")
                ok = resp.status == 200
                checked += 1
        except Exception as exc:  # noqa: BLE001
            ok = False
            kind = getattr(exc, "code", "?")
        if not ok:
            failures.append(f"{path} -> {kind}")
        print(f"  {'ok  ' if ok else 'FAIL'} {path:28} {kind}")

    # 2. the MIME type a browser needs for a script, checked explicitly.
    print("\ncontent types")
    for path, expected in (("/website%20scripts/js/main.js", "javascript"),
                           ("/website%20scripts/js/site.js", "javascript"),
                           ("/website%20scripts/css/base.css", "text/css"),
                           ("/games.html", "text/html")):
        try:
            with urllib.request.urlopen(base + path, timeout=15) as resp:
                kind = resp.headers.get("Content-Type", "")
        except Exception as exc:  # noqa: BLE001
            kind = str(getattr(exc, "code", "?"))
        ok = expected in kind
        checked += 1
        if not ok:
            failures.append(f"{path} served as {kind}, expected {expected}")
        print(f"  {'ok  ' if ok else 'FAIL'} {path:28} {kind}")

    # 3. no directory index, so the flat links have to carry the navigation.
    print("\nno reliance on a directory index")
    for path in ("/games", "/games/", "/team", "/team/"):
        try:
            with urllib.request.urlopen(base + path, timeout=15) as resp:
                code = resp.status
        except Exception as exc:  # noqa: BLE001
            code = getattr(exc, "code", 0)
        ok = code == 404
        checked += 1
        if not ok:
            failures.append(f"{path} answered {code}; the site depends on a directory index")
        print(f"  {'ok  ' if ok else 'FAIL'} {path:28} {code} (404 expected)")

    print("\nPHP source")
    executable = shutil.which(php or "php")
    if php and executable is None:
        failures.append(f"PHP executable not found: {php}")
    for source in sorted(root.glob("*.php")):
        checked += 1
        if re.search(r"^\*\*\* (?:Begin|End) Patch|^<<<<<<< |^>>>>>>> ", source.read_text(encoding="utf-8"), re.MULTILINE):
            failures.append(f"{source.name}: unfinished patch or merge conflict")
        if executable:
            result = subprocess.run([executable, "-l", str(source)], capture_output=True, text=True)
            if result.returncode:
                failures.append(f"{source.name}: {result.stdout.strip()} {result.stderr.strip()}")
            else:
                print(f"  ok   {source.name}")
    if not executable:
        print("  PHP syntax not verified. Pass --php /path/to/php to run the PHP linter.")

    httpd.shutdown()
    httpd.server_close()

    print()
    if failures:
        print(f"{len(failures)} problem(s) - do not upload this build:")
        for f in failures:
            print(f"  {f}")
        return 1
    print(f"{checked} asset and source checks passed.")
    print("Database, login, email and payment flows still require a configured PHP host.")
    return 0


def main() -> int:
    description = (__doc__ or "").split("\n", 1)[0] or "verify a static build"
    parser = argparse.ArgumentParser(description=description)
    parser.add_argument("directory", nargs="?", default=str(Path(__file__).resolve().parent / "output" / "spaceship-upload"))
    parser.add_argument("--port", type=int, default=5711)
    parser.add_argument("--php", help="PHP executable for backend syntax checks")
    parser.add_argument("--serve", action="store_true",
                        help="Serve static assets only; use Starter.py to run the PHP backend.")
    args = parser.parse_args()
    root = Path(args.directory).resolve()
    if not root.is_dir():
        print(f"no such directory: {root}", file=sys.stderr)
        return 2

    if args.serve:
        httpd = serve(root, args.port)
        print(f"serving {root} on http://127.0.0.1:{args.port} - Ctrl-C to stop")
        try:
            while True:
                time.sleep(3600)
        except KeyboardInterrupt:
            print("\nstopped")
        httpd.shutdown()
        return 0

    return check(root, args.port, args.php)


if __name__ == "__main__":
    raise SystemExit(main())
