#!/usr/bin/env python3
"""
Manyosa song discovery — browser-based scraper.

Loads the existing track IDs from manyosa.txt and the 8 user playlists
(scraped via headless Chromium), then walks a curated list of Every Noise
"Sound of …" playlists, collecting tracks not already known, until it has
gathered the target number of new tracks.  The new batch is appended to
manyosa.txt and the SQLite DB is refreshed via `php artisan songs:import`.

No Spotify API is used. Everything goes through a real browser (Playwright +
Chromium) loading public open.spotify.com playlist URLs and reading the
embedded `__NEXT_DATA__` JSON.

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

from playwright.sync_api import Page, TimeoutError as PWTimeout, sync_playwright

ROOT          = Path(__file__).resolve().parent.parent
TXT_PATH      = ROOT.parent / "manyosa.txt"
CONFIG_PATH   = ROOT / "scripts" / "config.json"
LOG_DIR       = ROOT / "storage" / "discovery"
TRACK_RE      = re.compile(r"open\.spotify\.com/track/([A-Za-z0-9]{22})")
BATCH_RE      = re.compile(r"BATCH\s+(\d+)\b", re.IGNORECASE)
USER_AGENT    = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)


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


def fetch_playlist(page: Page, playlist_id: str) -> list[dict]:
    """Return a list of {id, title, artist} dicts for tracks in the playlist.

    Uses Spotify's public oEmbed-style page at /embed/playlist/<id>, which
    server-renders the first ~100 tracks into a __NEXT_DATA__ JSON blob.
    """
    url = f"https://open.spotify.com/embed/playlist/{playlist_id}"
    log(f"  → loading {playlist_id}")
    try:
        page.goto(url, wait_until="domcontentloaded", timeout=30_000)
    except PWTimeout:
        log(f"    timeout loading {playlist_id}")
        return []

    try:
        page.wait_for_selector("script#__NEXT_DATA__", state="attached", timeout=15_000)
    except PWTimeout:
        log(f"    no __NEXT_DATA__ for {playlist_id}")
        return []

    raw = page.eval_on_selector("script#__NEXT_DATA__", "el => el.textContent")
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        log(f"    bad JSON for {playlist_id}")
        return []

    track_list = _find_tracklist(data)
    out: list[dict] = []
    for item in track_list:
        if not isinstance(item, dict):
            continue
        uri = item.get("uri") or ""
        if not uri.startswith("spotify:track:"):
            continue
        tid = uri.split(":")[2]
        title = (item.get("title") or "(unknown)").strip()
        artist = (item.get("subtitle") or "(unknown)").replace("\u00a0", " ").strip()
        duration_ms = item.get("duration")
        out.append({
            "id": tid,
            "title": title,
            "artist": artist,
            "duration_ms": duration_ms if isinstance(duration_ms, int) else None,
        })
    return out


def _find_tracklist(node):
    """Locate the first list of track-shaped dicts inside the __NEXT_DATA__ tree."""
    if isinstance(node, dict):
        tl = node.get("trackList")
        if isinstance(tl, list) and tl:
            return tl
        for v in node.values():
            found = _find_tracklist(v)
            if found:
                return found
    elif isinstance(node, list):
        for v in node:
            found = _find_tracklist(v)
            if found:
                return found
    return []


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

    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=not args.headed)
        ctx = browser.new_context(user_agent=USER_AGENT, locale="en-US")
        page = ctx.new_page()

        log("phase 1: scrape user playlists for additional exclusions")
        for pid in config["user_playlists"]:
            for t in fetch_playlist(page, pid):
                excluded.add(t["id"])
        log(f"exclusion set after user playlists: {len(excluded)}")

        log(f"phase 2: walk source playlists until {target} new tracks found")
        for src in config["source_playlists"]:
            if len(picks_by_id) >= target:
                break
            log(f"source: {src['name']} ({src['genre']})")
            tracks = fetch_playlist(page, src["id"])
            for t in tracks[: int(config.get("max_tracks_per_source", 80))]:
                if t["id"] in excluded or t["id"] in picks_by_id:
                    continue
                dur = t.get("duration_ms")
                if dur is None or dur < min_duration_ms:
                    continue
                picks_by_id[t["id"]] = {**t, "genre": src["genre"]}
                if len(picks_by_id) >= target:
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
