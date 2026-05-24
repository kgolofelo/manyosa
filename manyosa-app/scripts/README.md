# Manyosa discovery scripts

Browser-based scheduled crawler that keeps `manyosa.txt` (and the SQLite DB
behind the Laravel app) topped up with fresh songs to review.

## What it does

`discover.py` runs a headless Chromium browser (no Spotify API):

1. Loads every Spotify track ID already in `manyosa.txt`.
2. Visits each of the 8 user playlists in `config.json#user_playlists` and
   adds those track IDs to the exclusion set.
3. Walks `config.json#source_playlists` (Every Noise "Sound of …" playlists)
   in order. For each playlist it parses the page's `__NEXT_DATA__` JSON and
   collects tracks not already in the exclusion set.
4. Stops when `target_new_songs` (default 50) new tracks have been gathered.
5. Appends a new `=== BATCH N - Auto-discovered (timestamp) ===` block to
   `manyosa.txt` and runs `php artisan songs:import` so the Laravel app
   immediately sees the new songs.

`run.sh` is the cron-friendly wrapper. It logs to
`storage/discovery/cron.log`.

## Setup (one-off)

```bash
cd manyosa-app
sudo apt install -y python3.13-venv          # if needed
python3 -m venv scripts/.venv
scripts/.venv/bin/pip install playwright
scripts/.venv/bin/playwright install chromium
chmod +x scripts/run.sh
```

## Manual run

```bash
scripts/.venv/bin/python scripts/discover.py            # do it for real
scripts/.venv/bin/python scripts/discover.py --dry-run  # preview only
scripts/.venv/bin/python scripts/discover.py --headed   # watch the browser
```

## Cron

Twice daily, 06:00 and 18:00:

```cron
0 6,18 * * * /home/kgolofelo/manyosa/manyosa-app/scripts/run.sh
```

Install with:

```bash
( crontab -l 2>/dev/null | grep -v 'manyosa-app/scripts/run.sh'
  echo "0 6,18 * * * /home/kgolofelo/manyosa/manyosa-app/scripts/run.sh" ) | crontab -
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
- `max_tracks_per_source` — cap how many tracks to consider from any single
  source playlist (default 80) so one mega-playlist can't monopolize a batch.
