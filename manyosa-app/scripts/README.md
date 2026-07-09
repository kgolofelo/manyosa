# Manyosa discovery scripts

Browser-based scheduled crawler that keeps `manyosa.txt` (and the SQLite DB
behind the Laravel app) topped up with fresh songs to review.

## What it does

`discover.py` runs a headless Chromium browser (no Spotify API):

1. Loads every Spotify track ID already in `manyosa.txt`.
2. Visits each of the 8 user playlists in `config.json#user_playlists` and
   adds those track IDs to the exclusion set.
3. Walks `config.json#source_playlists` (Every Noise "Sound of …" playlists)
   in order. For each playlist it paginates Spotify's Pathfinder API and
   collects tracks not already in the exclusion set.
4. Stops when `target_new_songs` (default 50) new tracks have been gathered.
5. Appends a new `=== BATCH N - Auto-discovered (timestamp) ===` block to
   `manyosa.txt` and runs `php artisan songs:import` so the Laravel app
   immediately sees the new songs.
6. `filter_duration.py` closes any `new` songs shorter than
   `min_duration_seconds` (default 300 = 5 minutes).

`run.sh` is the cron-friendly wrapper. It logs to
`storage/discovery/cron.log`.

## Setup (one-off)

PHP (for `php artisan songs:import`):

```bash
sudo apt install -y php-xml php-sqlite3
```

Python (for `discover.py` / Playwright):

```bash
cd manyosa-app
sudo apt install -y python3-venv             # or python3.14-venv on newer Ubuntu
python3 -m venv scripts/.venv                # use --without-pip if ensurepip missing
scripts/.venv/bin/pip install -r scripts/requirements.txt
scripts/.venv/bin/playwright install chromium
chmod +x scripts/run.sh
```

After an OS upgrade, recreate the venv if Python was bumped (e.g. 3.13 → 3.14):
the old `.venv` will break with `ModuleNotFoundError: No module named 'playwright'`.
Remove `scripts/.venv` and run the Python block above.

## Manual run

```bash
scripts/.venv/bin/python scripts/discover.py            # do it for real
scripts/.venv/bin/python scripts/discover.py --dry-run  # preview only
scripts/.venv/bin/python scripts/discover.py --headed   # watch the browser
```

## Cron

Every 30 minutes (with daily quota / spacing gates):

```cron
*/30 * * * * /home/kgolofelo/manyosa/manyosa-app/scripts/run.sh cron-auto
```

Install with:

```bash
( crontab -l 2>/dev/null | grep -v 'manyosa-app/scripts/run.sh'
  echo "*/30 * * * * /home/kgolofelo/manyosa/manyosa-app/scripts/run.sh cron-auto" ) | crontab -
```

Inspect:

```bash
crontab -l
tail -f manyosa-app/storage/discovery/cron.log
```

## Config (`scripts/config.json`)

- `user_playlists` — Spotify playlist IDs whose tracks should NEVER be
  suggested (your own libraries).
- `source_playlists` — ordered list of Every Noise / public playlists to
  mine. Each has `{id, name, genre}`; `genre` is what gets written to the
  new batch.
- `target_new_songs` — how many new tracks per run (default 50).
- `max_tracks_per_source` — max new tracks to take from any single source
  playlist per run (default 80). The scraper walks the full playlist, not
  just the first page.
- `min_duration_seconds` — only discover and keep songs at least this long
  (default 300 = 5 minutes). Shorter `new` songs are auto-closed after import.
