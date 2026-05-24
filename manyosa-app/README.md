# Manyosa App

A minimalist single-page Laravel app for reviewing the songs in `../manyosa.txt`.

## Quick start

```bash
cd manyosa-app
composer install
php artisan migrate
php artisan songs:import           # parses ../manyosa.txt and loads SQLite
php artisan serve --port=8765
```

Open <http://127.0.0.1:8765/>.

## How it works

- **Database**: SQLite (`database/database.sqlite`).
- **Import**: `php artisan songs:import [path] [--fresh]` extracts every
  `open.spotify.com/track/<id>` from `manyosa.txt` and best-effort parses the
  title/artist/genre from each line. Re-running is safe — duplicates (matched on
  the Spotify track ID) are skipped. Use `--fresh` to wipe and reimport.
- **UI** (`/`): Renders all songs whose status is `new` or `reviewed`. Closed
  songs are hidden.
- **Click flow**: Clicking a song title opens the Spotify link in a new tab
  (`target="_blank"`) and, if the row was `new`, fires a `POST /songs/{id}/review`
  in the background:
    1. The clicked song is set to `reviewed`.
    2. The **oldest** other song already in `reviewed` status is set to `closed`
       and removed from the list (with a fade-out).
  Reviewed rows remain visible (highlighted) so you can click again if the
  Spotify tab didn't open.
- **Auto-update**: The DOM is patched in place via `fetch`; no full-page reload.

## Routes

| Method | URI                     | Action                       |
| ------ | ----------------------- | ---------------------------- |
| GET    | `/`                     | `SongController@index`       |
| GET    | `/songs`                | `SongController@list` (JSON) |
| POST   | `/songs/{song}/review`  | `SongController@review`      |
