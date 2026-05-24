<?php

namespace App\Console\Commands;

use App\Models\Song;
use Illuminate\Console\Command;

class ImportSongs extends Command
{
    protected $signature = 'songs:import {path? : Path to manyosa.txt} {--fresh : Wipe existing songs first}';

    protected $description = 'Parse manyosa.txt and import every track with a Spotify link';

    public function handle(): int
    {
        $path = $this->argument('path') ?? base_path('../manyosa.txt');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            Song::query()->delete();
            $this->info('Wiped existing songs.');
        }

        $contents = file_get_contents($path);

        // Spotify track URL pattern. Track IDs are 22 base62 chars.
        $pattern = '~(?:https?://)?open\.spotify\.com/track/([A-Za-z0-9]{22})~';

        if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            $this->error('No spotify track URLs found.');
            return self::FAILURE;
        }

        $sortOrder = (int) Song::max('sort_order');
        $imported = 0;
        $skipped = 0;

        foreach ($matches[0] as $i => $hit) {
            $trackId = $matches[1][$i][0];
            $offset  = $hit[1];

            if (Song::where('spotify_track_id', $trackId)->exists()) {
                $skipped++;
                continue;
            }

            // The URL may wrap onto a continuation line. Take the line that
            // contains the URL and stitch the preceding line if the URL line
            // doesn't carry enough text to identify the track.
            $lineStart = strrpos(substr($contents, 0, $offset), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $lineEnd   = strpos($contents, "\n", $offset);
            $lineEnd   = $lineEnd === false ? strlen($contents) : $lineEnd;

            $line = substr($contents, $lineStart, $lineEnd - $lineStart);
            $stripped = trim(preg_replace($pattern, '', $line));

            // If the URL-line is essentially just the URL, look upward.
            if (mb_strlen($stripped) < 5 && $lineStart > 0) {
                $prevEnd = $lineStart - 1;
                $prevStart = strrpos(substr($contents, 0, $prevEnd), "\n");
                $prevStart = $prevStart === false ? 0 : $prevStart + 1;
                $prev = trim(substr($contents, $prevStart, $prevEnd - $prevStart));
                $stripped = trim($prev . ' ' . $stripped);
            }

            [$title, $artist, $genre] = $this->parseLine($stripped);

            $sortOrder++;
            Song::create([
                'sort_order'       => $sortOrder,
                'title'            => $title ?: '(untitled)',
                'artist'           => $artist,
                'genre'            => $genre,
                'spotify_url'      => "https://open.spotify.com/track/{$trackId}",
                'spotify_track_id' => $trackId,
                'status'           => 'new',
            ]);
            $imported++;
        }

        $this->info("Imported: {$imported}.  Skipped (duplicate): {$skipped}.");
        return self::SUCCESS;
    }

    /**
     * @return array{0:?string,1:?string,2:?string} [title, artist, genre]
     */
    private function parseLine(string $line): array
    {
        $line = trim($line);

        // Drop a leading "N\t" or "N " counter.
        $line = preg_replace('/^\d+[\t ]+/', '', $line);
        // Some lines have "12\"Title\"..." with no separator at all.
        $line = preg_replace('/^\d+(?=")/', '', $line);

        // Em-dash format: "Title" — Artist
        if (str_contains($line, '"') && (str_contains($line, '—') || str_contains($line, '–'))) {
            if (preg_match('/^"([^"]+)"\s*[—–]\s*(.+)$/u', $line, $m)) {
                return [trim($m[1]), trim($m[2]), null];
            }
        }

        // Tab-separated columns.
        if (str_contains($line, "\t")) {
            $parts = array_values(array_filter(array_map('trim', explode("\t", $line)), 'strlen'));
            $title  = isset($parts[0]) ? trim($parts[0], "\" ") : null;
            $artist = $parts[1] ?? null;
            $genre  = $parts[2] ?? null;
            return [$title, $artist, $genre];
        }

        // Quoted title with no separators: "Title"ArtistGenre
        if (preg_match('/^"([^"]+)"\s*(.*)$/u', $line, $m)) {
            $title = trim($m[1]);
            $rest  = trim($m[2]);
            $genre = null;
            foreach ($this->knownGenres() as $g) {
                if (str_ends_with($rest, $g)) {
                    $genre = $g;
                    $rest = trim(substr($rest, 0, -strlen($g)));
                    break;
                }
            }
            return [$title, $rest ?: null, $genre];
        }

        return [$line ?: null, null, null];
    }

    /** @return list<string> */
    private function knownGenres(): array
    {
        return [
            'South African Soulful Deep House',
            'South African Deep House',
            'SA Soulful Deep House',
            'Soulful / Disco House',
            'Garage/Deep House',
            'Funky Deep House',
            'Deep Funk House',
            'Soulful House',
            'Organic House',
            'Progressive House',
            'Tech House',
            'Funky House',
            'Nu-Disco',
            'Afro House',
            'Deep House',
            'House',
        ];
    }
}
