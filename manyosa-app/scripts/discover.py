#!/usr/bin/env python3
"""
Manyosa song discovery — browser-based scraper.

Loads the existing track IDs from manyosa.txt and the 8 user playlists
(scraped via headless Chromium), then walks a curated list of Every Noise
"Sound of …" playlists, collecting tracks not already known, until it has
gathered the target number of new tracks.  The new batch is appended to
manyosa.txt and the SQLite DB is refreshed via `php artisan songs:import`.

No Spotify API is used. A headless Chromium session loads one public
open.spotify.com playlist page to capture Spotify's internal Pathfinder
query, then paginates through full playlist track lists (not just the
first ~50 embed tracks).

Run via scripts/run.sh (used by cron) or manually:
    scripts/.venv/bin/python scripts/discover.py
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from datetime import datetime
from pathlib import Path

from playwright.sync_api import Page, sync_playwright

ROOT          = Path(__file__).resolve().parent.parent
TXT_PATH      = ROOT.parent / "manyosa.txt"
CONFIG_PATH   = ROOT / "scripts" / "config.json"
LOG_DIR       = ROOT / "storage" / "discovery"
TRACK_RE      = re.compile(r"open\.spotify\.com/track/([A-Za-z0-9]{22})")
BATCH_RE      = re.compile(r"BATCH\s+(\d+)\b", re.IGNORECASE)
PATHFINDER_URL = "https://api-partner.spotify.com/pathfinder/v2/query"
USER_AGENT    = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)
_PAGE_FETCH_JS = """async ({url, headers, body}) => {
    const h = {...headers};
    delete h['content-length'];
    const r = await fetch(url, {method: 'POST', headers: h, body: JSON.stringify(body)});
    return await r.json();
}"""


def log(msg: str) -> None:
    print(f"[{datetime.now().isoformat(timespec='seconds')}] {msg}", flush=True)


def load_config() -> dict:
    return json.loads(CONFIG_PATH.read_text())


def existing_track_ids() -> set[str]:
    if not TXT_PATH.is_file():
        return set()
    return set(TRACK_RE.findall(TXT_PATH.read_text(errors="ignore")))


def next_batch_number() -> int:
    if not TXT_PATH.is_file():
        return 1
    nums = [int(m.group(1)) for m in BATCH_RE.finditer(TXT_PATH.read_text(errors="ignore"))]
    return (max(nums) if nums else 0) + 1


class PathfinderSession:
    """Reuse Spotify web Pathfinder credentials to paginate full playlists."""

    def __init__(self) -> None:
        self.headers: dict | None = None
        self.body_template: dict | None = None

    def bootstrap(self, page: Page, playlist_id: str) -> None:
        captured: dict = {}

        def on_request(req) -> None:
            if "pathfinder/v2/query" not in req.url or req.method != "POST":
                return
            try:
                body = json.loads(req.post_data or "")
            except json.JSONDecodeError:
                return
            if body.get("operationName") == "fetchPlaylistContents":
                captured["headers"] = req.headers
                captured["body"] = body

        page.on("request", on_request)
        try:
            page.goto(
                f"https://open.spotify.com/playlist/{playlist_id}",
                wait_until="networkidle",
                timeout=60_000,
            )
            page.wait_for_timeout(2_000)
        finally:
            page.remove_listener("request", on_request)

        if not captured:
            raise RuntimeError(f"failed to capture Pathfinder query for {playlist_id}")

        self.headers = captured["headers"]
        self.body_template = captured["body"]

    def fetch_playlist(self, page: Page, playlist_id: str) -> list[dict]:
        if not self.headers or not self.body_template:
            raise RuntimeError("Pathfinder session not bootstrapped")

        out: list[dict] = []
        seen: set[str] = set()
        offset = 0
        limit = 50
        total: int | None = None

        while total is None or offset < total:
            body = dict(self.body_template)
            body["variables"] = dict(self.body_template["variables"])
            body["variables"].update({
                "uri": f"spotify:playlist:{playlist_id}",
                "offset": offset,
                "limit": limit,
            })
            try:
                result = page.evaluate(
                    _PAGE_FETCH_JS,
                    {"url": PATHFINDER_URL, "headers": self.headers, "body": body},
                )
            except Exception as exc:
                log(f"    pathfinder error at offset {offset} for {playlist_id}: {exc}")
                break

            content = (result.get("data") or {}).get("playlistV2", {}).get("content") or {}
            if total is None:
                total = int(content.get("totalCount") or 0)
            items = content.get("items") or []
            if not items:
                break

            for item in items:
                track = _track_from_pathfinder_item(item)
                if not track or track["id"] in seen:
                    continue
                seen.add(track["id"])
                out.append(track)

            offset += limit

        return out


def _track_duration_ms(data: dict) -> int | None:
    track_duration = data.get("trackDuration")
    if isinstance(track_duration, dict):
        ms = track_duration.get("totalMilliseconds")
        if isinstance(ms, int):
            return ms

    duration = data.get("duration")
    if isinstance(duration, dict):
        ms = duration.get("totalMilliseconds")
        if isinstance(ms, int):
            return ms
    if isinstance(duration, int):
        return duration
    return None


def _track_from_pathfinder_item(item: dict) -> dict | None:
    data = (item.get("itemV2") or item.get("itemV3") or {}).get("data") or {}
    if data.get("__typename") != "Track":
        return None

    uri = data.get("uri") or ""
    if not uri.startswith("spotify:track:"):
        return None

    artists = data.get("artists", {}).get("items") or []
    artist = ", ".join(
        (a.get("profile") or {}).get("name", "").strip()
        for a in artists
        if (a.get("profile") or {}).get("name")
    ) or "(unknown)"

    return {
        "id": uri.split(":")[2],
        "title": (data.get("name") or "(unknown)").strip(),
        "artist": artist,
        "duration_ms": _track_duration_ms(data),
    }


def fetch_playlist(page: Page, playlist_id: str, session: PathfinderSession) -> list[dict]:
    """Return all tracks in a playlist via Spotify's Pathfinder API."""
    log(f"  → fetching {playlist_id}")
    tracks = session.fetch_playlist(page, playlist_id)
    log(f"    {len(tracks)} tracks")
    return tracks


def build_batch_text(batch_num: int, picks: list[dict]) -> str:
    header = (
        f"\n=== BATCH {batch_num} - Auto-discovered "
        f"({datetime.now().strftime('%Y-%m-%d %H:%M')}) ===\n"
    )
    lines = []
    for i, p in enumerate(picks, 1):
        title = p["title"].replace('"', "'")
        lines.append(
            f"{i}\t\"{title}\"\t{p['artist']}\t{p['genre']}\t"
            f"https://open.spotify.com/track/{p['id']}"
        )
    return header + "\n".join(lines) + "\n"


def run_artisan_import() -> bool:
    log("running: php artisan songs:import")
    res = subprocess.run(
        ["php", "artisan", "songs:import"],
        cwd=ROOT,
        capture_output=True,
        text=True,
        timeout=120,
    )
    log(res.stdout.strip())
    if res.returncode != 0:
        log("artisan stderr: " + res.stderr.strip())
        return False
    return True


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true",
                        help="Print what would be appended; don't write or import.")
    parser.add_argument("--headed", action="store_true",
                        help="Show the browser (debug).")
    args = parser.parse_args()

    LOG_DIR.mkdir(parents=True, exist_ok=True)
    config = load_config()
    target = int(config.get("target_new_songs", 50))
    min_duration_ms = int(config.get("min_duration_seconds", 300)) * 1000

    excluded = existing_track_ids()
    log(f"manyosa.txt holds {len(excluded)} track IDs")

    picks_by_id: dict[str, dict] = {}

    max_new_per_source = int(config.get("max_tracks_per_source", 80))

    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=not args.headed)
        ctx = browser.new_context(user_agent=USER_AGENT, locale="en-US")
        page = ctx.new_page()
        session = PathfinderSession()

        bootstrap_id = (
            config["user_playlists"][0]
            if config.get("user_playlists")
            else config["source_playlists"][0]["id"]
        )
        log(f"bootstrapping Spotify session via playlist {bootstrap_id}")
        session.bootstrap(page, bootstrap_id)

        log("phase 1: scrape user playlists for additional exclusions")
        for pid in config["user_playlists"]:
            for t in fetch_playlist(page, pid, session):
                excluded.add(t["id"])
        log(f"exclusion set after user playlists: {len(excluded)}")

        log(f"phase 2: walk source playlists until {target} new tracks found")
        for src in config["source_playlists"]:
            if len(picks_by_id) >= target:
                break
            log(f"source: {src['name']} ({src['genre']})")
            tracks = fetch_playlist(page, src["id"], session)
            source_picks = 0
            for t in tracks:
                if t["id"] in excluded or t["id"] in picks_by_id:
                    continue
                dur = t.get("duration_ms")
                if dur is None or dur < min_duration_ms:
                    continue
                picks_by_id[t["id"]] = {**t, "genre": src["genre"]}
                source_picks += 1
                if source_picks >= max_new_per_source or len(picks_by_id) >= target:
                    break
            log(f"  cumulative new picks: {len(picks_by_id)}")

        browser.close()

    if not picks_by_id:
        log("no new tracks discovered; nothing to do")
        return 0

    picks = list(picks_by_id.values())
    batch_num = next_batch_number()
    batch_text = build_batch_text(batch_num, picks)

    if args.dry_run:
        log(f"[dry-run] would append batch {batch_num} with {len(picks)} tracks")
        print(batch_text)
        return 0

    with TXT_PATH.open("a", encoding="utf-8") as f:
        f.write(batch_text)
    log(f"appended batch {batch_num} ({len(picks)} tracks) to {TXT_PATH}")

    snapshot = LOG_DIR / f"batch-{batch_num}.txt"
    snapshot.write_text(batch_text, encoding="utf-8")

    run_artisan_import()
    log("done.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
