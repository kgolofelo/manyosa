#!/usr/bin/env python3
"""Tiny CLI helper used by run.sh to read/write the discovery_runs table.

Subcommands:
  check-quota              -> exit 0 if a cron-auto run should proceed, 1 otherwise.
                              Prints a human-readable reason on stderr.
  start <source>           -> insert a 'running' row, print the new id on stdout.
  finish <id> <status> [delta] [message]
                           -> mark the row as success/failed with finished_at.
  songs-new-count          -> print COUNT(*) of songs WHERE status='new'.
"""

from __future__ import annotations

import sqlite3
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

DB = Path(__file__).resolve().parent.parent / "database" / "database.sqlite"
DAILY_TARGET = 2
MIN_SPACING = timedelta(hours=4)


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


def now() -> str:
    # Match Laravel's UTC timestamp format.
    return _utcnow().strftime("%Y-%m-%d %H:%M:%S")


def connect() -> sqlite3.Connection:
    conn = sqlite3.connect(str(DB))
    conn.execute("PRAGMA foreign_keys = ON;")
    return conn


def cmd_check_quota() -> int:
    today = _utcnow().strftime("%Y-%m-%d")
    with connect() as c:
        cur = c.execute(
            "SELECT COUNT(*) FROM discovery_runs "
            "WHERE status='success' AND date(started_at)=?",
            (today,),
        )
        done_today = cur.fetchone()[0]
        if done_today >= DAILY_TARGET:
            print(f"daily target met ({done_today}/{DAILY_TARGET})", file=sys.stderr)
            return 1

        cur = c.execute(
            "SELECT MAX(finished_at) FROM discovery_runs WHERE status='success'"
        )
        last = cur.fetchone()[0]
        if last:
            last_dt = datetime.strptime(last, "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
            age = _utcnow() - last_dt
            if age < MIN_SPACING:
                mins = int(age.total_seconds() // 60)
                print(
                    f"last success only {mins}m ago (< {MIN_SPACING}); skipping",
                    file=sys.stderr,
                )
                return 1
    print(f"proceeding ({done_today}/{DAILY_TARGET} runs today)", file=sys.stderr)
    return 0


def cmd_start(source: str) -> int:
    ts = now()
    with connect() as c:
        cur = c.execute(
            "INSERT INTO discovery_runs (source, status, started_at, created_at, updated_at) "
            "VALUES (?, 'running', ?, ?, ?)",
            (source, ts, ts, ts),
        )
        print(cur.lastrowid)
    return 0


def cmd_finish(run_id: str, status: str, delta: str = "", message: str = "") -> int:
    ts = now()
    delta_val = int(delta) if delta else None
    with connect() as c:
        c.execute(
            "UPDATE discovery_runs "
            "SET status=?, finished_at=?, updated_at=?, new_count=?, message=? "
            "WHERE id=?",
            (status, ts, ts, delta_val, message or None, int(run_id)),
        )
    return 0


def cmd_songs_new_count() -> int:
    with connect() as c:
        cur = c.execute("SELECT COUNT(*) FROM songs WHERE status='new'")
        print(cur.fetchone()[0])
    return 0


def main(argv: list[str]) -> int:
    if len(argv) < 2:
        print(__doc__, file=sys.stderr)
        return 2
    sub = argv[1]
    if sub == "check-quota":
        return cmd_check_quota()
    if sub == "start" and len(argv) == 3:
        return cmd_start(argv[2])
    if sub == "finish" and len(argv) >= 4:
        return cmd_finish(*argv[2:6])  # id, status, [delta], [message]
    if sub == "songs-new-count":
        return cmd_songs_new_count()
    print(f"unknown args: {argv[1:]}", file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv))
