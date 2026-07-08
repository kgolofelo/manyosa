#!/usr/bin/env python3
"""
Close songs shorter than the configured minimum duration.

Reads `min_duration_seconds` from config.json (default 300 = 5 minutes),
checks every song with status `new` in the SQLite DB, and marks any track
shorter than the threshold as `closed`.

Run after `php artisan songs:import` so freshly imported short tracks are
removed from the review queue automatically.
"""

from __future__ import annotations

import json
import sqlite3
import sys
from datetime import datetime, timezone
from pathlib import Path

from playwright.sync_api import TimeoutError as PWTimeout, sync_playwright

ROOT = Path(__file__).resolve().parent.parent
DB = ROOT / "database" / "database.sqlite"
CONFIG_PATH = ROOT / "scripts" / "config.json"
USER_AGENT = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)


def log(msg: str) -> None:
    print(f"[{datetime.now().isoformat(timespec='seconds')}] {msg}", flush=True)


def load_config() -> dict:
    return json.loads(CONFIG_PATH.read_text())


def utcnow() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def fetch_track_duration_ms(page, track_id: str) -> int | None:
    url = f"https://open.spotify.com/embed/track/{track_id}"
    try:
        page.goto(url, wait_until="domcontentloaded", timeout=30_000)
        page.wait_for_selector("script#__NEXT_DATA__", state="attached", timeout=15_000)
    except PWTimeout:
        return None

    raw = page.eval_on_selector("script#__NEXT_DATA__", "el => el.textContent")
    try:
        data = json.loads(raw)
        duration = data["props"]["pageProps"]["state"]["data"]["entity"]["duration"]
        return duration if isinstance(duration, int) else None
    except (KeyError, TypeError, json.JSONDecodeError):
        return None


def main() -> int:
    config = load_config()
    min_duration_ms = int(config.get("min_duration_seconds", 300)) * 1000

    if not DB.is_file():
        log(f"database not found: {DB}")
        return 1

    with sqlite3.connect(DB) as conn:
        conn.row_factory = sqlite3.Row
        rows = conn.execute(
            "SELECT id, title, spotify_track_id FROM songs "
            "WHERE status = 'new' ORDER BY sort_order"
        ).fetchall()

    if not rows:
        log("no new songs to check")
        return 0

    log(f"checking {len(rows)} new songs (min {min_duration_ms // 1000}s)")
    closed = 0
    kept = 0
    unknown = 0

    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        page = browser.new_context(user_agent=USER_AGENT, locale="en-US").new_page()

        for row in rows:
            duration_ms = fetch_track_duration_ms(page, row["spotify_track_id"])
            if duration_ms is None:
                unknown += 1
                log(f"  ? {row['title']} — duration unknown; leaving as new")
                continue

            mins = duration_ms // 60000
            secs = (duration_ms % 60000) // 1000
            if duration_ms < min_duration_ms:
                with sqlite3.connect(DB) as conn:
                    conn.execute(
                        "UPDATE songs SET status='closed', closed_at=?, updated_at=? "
                        "WHERE id=?",
                        (utcnow(), utcnow(), row["id"]),
                    )
                closed += 1
                log(f"  x {row['title']} — {mins}:{secs:02d} (closed)")
            else:
                kept += 1
                log(f"  + {row['title']} — {mins}:{secs:02d}")

        browser.close()

    log(f"done: kept {kept}, closed {closed}, unknown {unknown}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
