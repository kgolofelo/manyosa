<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Manyosa — Song Review</title>
    <style>
        :root {
            --bg: #0f1115;
            --panel: #161922;
            --panel-2: #1c2030;
            --text: #e7e9ee;
            --muted: #8a92a6;
            --accent: #1db954;
            --accent-soft: rgba(29, 185, 84, 0.12);
            --reviewed: #f0b441;
            --reviewed-soft: rgba(240, 180, 65, 0.10);
            --border: #232838;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, system-ui, sans-serif; }
        body { min-height: 100vh; }
        .wrap { max-width: 820px; margin: 0 auto; padding: 32px 20px 80px; }

        header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 24px; }
        h1 { margin: 0; font-size: 24px; font-weight: 600; letter-spacing: -0.01em; }
        .sub { color: var(--muted); font-size: 13px; margin-top: 4px; }

        .stats { display: flex; gap: 8px; font-size: 12px; }
        .pill { padding: 4px 10px; border-radius: 999px; background: var(--panel); color: var(--muted); border: 1px solid var(--border); }
        .pill strong { color: var(--text); font-weight: 600; margin-right: 4px; }
        .pill.new strong { color: var(--accent); }
        .pill.reviewed strong { color: var(--reviewed); }

        .list { list-style: none; padding: 0; margin: 0; }
        .row {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; border-radius: 8px;
            transition: background 0.15s ease, opacity 0.3s ease, transform 0.3s ease;
        }
        .row + .row { margin-top: 2px; }
        .row:hover { background: var(--panel); }
        .row.reviewed { background: var(--reviewed-soft); }
        .row.removing { opacity: 0; transform: translateX(-10px); }

        .num { width: 36px; text-align: right; color: var(--muted); font-variant-numeric: tabular-nums; font-size: 13px; flex-shrink: 0; }
        .meta { flex: 1; min-width: 0; }
        .title { font-size: 15px; font-weight: 500; }
        .title a { color: var(--text); text-decoration: none; }
        .title a:hover { color: var(--accent); }
        .row.reviewed .title a { color: var(--reviewed); }
        .sub-meta { color: var(--muted); font-size: 12px; margin-top: 2px; display: flex; gap: 8px; flex-wrap: wrap; }
        .genre { padding: 1px 7px; background: var(--panel-2); border-radius: 4px; font-size: 11px; }

        .status {
            font-size: 11px; padding: 3px 8px; border-radius: 999px;
            background: var(--panel-2); color: var(--muted); flex-shrink: 0;
        }
        .row.reviewed .status { background: var(--reviewed-soft); color: var(--reviewed); }

        .empty { text-align: center; color: var(--muted); padding: 60px 20px; }

        footer { margin-top: 32px; text-align: center; color: var(--muted); font-size: 11px; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div>
            <h1>Manyosa</h1>
            <div class="sub">Click a title to play on Spotify and mark as reviewed.</div>
        </div>
        <div class="stats" id="stats">
            <span class="pill new"><strong id="count-new">{{ $counts['new'] }}</strong>new</span>
            <span class="pill reviewed"><strong id="count-reviewed">{{ $counts['reviewed'] }}</strong>reviewed</span>
            <span class="pill"><strong id="count-closed">{{ $counts['closed'] }}</strong>closed</span>
        </div>
    </header>

    <ul class="list" id="list">
        @forelse ($songs as $song)
            <li class="row {{ $song->status }}" data-id="{{ $song->id }}" data-status="{{ $song->status }}">
                <div class="num">{{ $song->sort_order }}</div>
                <div class="meta">
                    <div class="title">
                        <a href="{{ $song->spotify_url }}" target="_blank" rel="noopener noreferrer">{{ $song->title }}</a>
                    </div>
                    <div class="sub-meta">
                        @if ($song->artist)<span>{{ $song->artist }}</span>@endif
                        @if ($song->genre)<span class="genre">{{ $song->genre }}</span>@endif
                    </div>
                </div>
                <div class="status">{{ $song->status }}</div>
            </li>
        @empty
            <li class="empty">No songs to review. Run <code>php artisan songs:import</code>.</li>
        @endforelse
    </ul>

    <footer>{{ $counts['total'] }} songs imported from manyosa.txt</footer>
</div>

<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    function updateCounts(counts) {
        document.getElementById('count-new').textContent = counts.new;
        document.getElementById('count-reviewed').textContent = counts.reviewed;
        document.getElementById('count-closed').textContent = counts.closed;
    }

    function removeRow(id) {
        const el = document.querySelector(`.row[data-id="${id}"]`);
        if (!el) return;
        el.classList.add('removing');
        setTimeout(() => el.remove(), 300);
    }

    function markReviewed(id) {
        const el = document.querySelector(`.row[data-id="${id}"]`);
        if (!el) return;
        el.classList.remove('new');
        el.classList.add('reviewed');
        el.dataset.status = 'reviewed';
        const status = el.querySelector('.status');
        if (status) status.textContent = 'reviewed';
    }

    document.getElementById('list').addEventListener('click', function (e) {
        const link = e.target.closest('.title a');
        if (!link) return;

        const row = e.target.closest('.row');
        if (!row) return;

        const id = row.dataset.id;
        const wasNew = row.dataset.status === 'new';

        // Let the browser open the link in the new tab naturally (target=_blank).
        // We don't preventDefault — the click already opens the tab.
        // Fire the review request asynchronously.
        if (!wasNew) return; // already reviewed; nothing to do server-side

        fetch(`/songs/${id}/review`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
        })
        .then(r => r.ok ? r.json() : Promise.reject(r))
        .then(data => {
            markReviewed(id);
            if (data.closed_id) removeRow(data.closed_id);
            if (data.counts) updateCounts(data.counts);
        })
        .catch(err => console.error('Review failed', err));
    });
})();
</script>
</body>
</html>
