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

        .discover-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
        .btn { font-family: inherit; font-size: 13px; padding: 7px 14px; border-radius: 6px; border: 1px solid var(--border); background: var(--panel); color: var(--text); cursor: pointer; transition: background 0.15s ease, border-color 0.15s ease; }
        .btn:hover:not(:disabled) { background: var(--panel-2); border-color: var(--accent); }
        .btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .btn.primary { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }
        .discover-status { color: var(--muted); font-size: 12px; display: flex; align-items: center; gap: 8px; }
        .discover-status .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--muted); }
        .discover-status.running .dot { background: var(--accent); animation: pulse 1.2s ease-in-out infinite; }
        .discover-status.success .dot { background: var(--accent); }
        .discover-status.failed  .dot { background: #e85a5a; }
        .discover-status.skipped .dot { background: var(--muted); }
        @keyframes pulse { 0%,100% { opacity: 0.4; } 50% { opacity: 1; } }

        footer { margin-top: 32px; text-align: center; color: var(--muted); font-size: 11px; }

        .btn-close {
            font-family: inherit; font-size: 11px; padding: 3px 8px; border-radius: 999px;
            border: 1px solid var(--border); background: transparent; color: var(--muted);
            cursor: pointer; transition: border-color 0.15s ease, color 0.15s ease;
            flex-shrink: 0;
        }
        .btn-close:hover { border-color: #e85a5a; color: #e85a5a; }
        .row.closed .btn-close { display: none; }
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

    <div class="discover-bar">
        <button type="button" class="btn primary" id="discover-btn">Find more songs</button>
        <div class="discover-status" id="discover-status"><span class="dot"></span><span class="text">Loading…</span></div>
    </div>

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
                <button class="btn-close" data-id="{{ $song->id }}" title="Close">close</button>
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
        // Close button
        const closeBtn = e.target.closest('.btn-close');
        if (closeBtn) {
            e.preventDefault();
            const id = closeBtn.dataset.id;
            fetch(`/songs/${id}/close`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                credentials: 'same-origin',
            })
            .then(r => r.ok ? r.json() : Promise.reject(r))
            .then(data => {
                removeRow(id);
                if (data.counts) updateCounts(data.counts);
            })
            .catch(err => console.error('Close failed', err));
            return;
        }

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

    // ---------- Discovery trigger & status polling --------------------
    const btn = document.getElementById('discover-btn');
    const statusEl = document.getElementById('discover-status');
    const statusText = statusEl.querySelector('.text');
    let pollTimer = null;
    let tickTimer = null;
    let lastSnap = null;
    let lastSeenRunId = null;
    let pendingDiscover = false;
    let runIdAtQueue = 0;

    function fmtAgo(iso) {
        if (!iso) return 'never';
        const then = new Date(iso).getTime();
        const secs = Math.max(0, Math.round((Date.now() - then) / 1000));
        if (secs < 60) return `${secs}s ago`;
        if (secs < 3600) return `${Math.round(secs/60)}m ago`;
        if (secs < 86400) return `${Math.round(secs/3600)}h ago`;
        return `${Math.round(secs/86400)}d ago`;
    }

    function startPolling(intervalMs) {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(() => fetchStatus(), intervalMs ?? 3000);
        if (tickTimer) { clearInterval(tickTimer); tickTimer = null; }
    }

    function showDiscovering(label) {
        statusEl.classList.remove('success', 'failed', 'skipped');
        statusEl.classList.add('running');
        btn.disabled = true;
        btn.textContent = 'Discovering…';
        statusText.textContent = label || 'Discovering…';
    }

    function renderStatus(snap) {
        const latest = snap.latest;
        statusEl.classList.remove('running', 'success', 'failed', 'skipped');
        if (!latest) {
            statusText.textContent = `No runs yet — ${snap.today_success}/${snap.daily_target} today`;
            btn.disabled = false;
            btn.textContent = 'Find more songs';
            return;
        }
        statusEl.classList.add(latest.status === 'running' ? 'running' : latest.status);
        if (latest.status === 'running') {
            btn.disabled = true;
            btn.textContent = 'Discovering…';
            statusText.textContent = `Run #${latest.id} (${latest.source}) running…`;
        } else {
            btn.disabled = false;
            btn.textContent = 'Find more songs';
            const delta = latest.new_count != null ? ` · +${latest.new_count} new` : '';
            const when = fmtAgo(latest.finished_at || latest.started_at);
            const note = latest.status === 'skipped' && latest.message ? ` — ${latest.message}` : '';
            statusText.textContent = `Last: ${latest.status} ${when}${delta}${note} · ${snap.today_success}/${snap.daily_target} cron today`;
        }
    }

    function refreshSongList() {
        fetch('/songs', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : Promise.reject(r))
            .then(data => {
                if (data.counts) updateCounts(data.counts);
                // Re-render the list to surface freshly discovered songs.
                if (Array.isArray(data.songs)) {
                    const list = document.getElementById('list');
                    list.innerHTML = data.songs.map(s => `
                        <li class="row ${s.status}" data-id="${s.id}" data-status="${s.status}">
                            <div class="num">${s.sort_order}</div>
                            <div class="meta">
                                <div class="title"><a href="${s.spotify_url}" target="_blank" rel="noopener noreferrer">${escapeHtml(s.title)}</a></div>
                                <div class="sub-meta">${s.artist ? `<span>${escapeHtml(s.artist)}</span>` : ''}${s.genre ? `<span class="genre">${escapeHtml(s.genre)}</span>` : ''}</div>
                            </div>
                            <div class="status">${s.status}</div>
                            <button class="btn-close" data-id="${s.id}" title="Close">close</button>
                        </li>`).join('');
                }
            })
            .catch(err => console.error('Refresh failed', err));
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
    }

    function fetchStatus(initial = false) {
        return fetch('/discovery/status', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(r => r.json())
            .then(snap => {
                const latest = snap.latest;
                const wasRunning = lastSnap?.latest?.status === 'running';
                lastSnap = snap;

                if (latest && latest.status === 'running') {
                    pendingDiscover = false;
                    renderStatus(snap);
                    lastSeenRunId = latest.id;
                    startPolling(3000);
                } else if (pendingDiscover) {
                    if (latest && latest.id > runIdAtQueue) {
                        pendingDiscover = false;
                        renderStatus(snap);
                        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
                        if (latest.status === 'success') refreshSongList();
                        if (!tickTimer) tickTimer = setInterval(() => { if (lastSnap) renderStatus(lastSnap); }, 30000);
                    } else {
                        showDiscovering('Starting discovery run…');
                        startPolling(1000);
                    }
                } else {
                    renderStatus(snap);
                    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
                    if (wasRunning && latest && latest.status === 'success') {
                        refreshSongList();
                    }
                    if (!tickTimer) tickTimer = setInterval(() => { if (lastSnap) renderStatus(lastSnap); }, 30000);
                }
            })
            .catch(err => console.error('Status fetch failed', err));
    }

    btn.addEventListener('click', function () {
        showDiscovering('Starting discovery run…');
        fetch('/discovery/run', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        .then(r => r.json().then(body => ({ ok: r.ok, status: r.status, body })))
        .then(({ ok, status, body }) => {
            if (!ok && status !== 409) {
                console.error('Trigger failed', body);
                lastSnap = body;
                renderStatus(body);
                return;
            }
            lastSnap = body;
            runIdAtQueue = body.latest?.id ?? 0;
            const running = body.running || (body.latest && body.latest.status === 'running');
            if (body.queued || running) {
                pendingDiscover = !!body.queued && !running;
                if (running) {
                    pendingDiscover = false;
                    renderStatus(body);
                } else {
                    showDiscovering('Starting discovery run…');
                }
                startPolling(body.queued ? 1000 : 3000);
                return;
            }
            pendingDiscover = false;
            renderStatus(body);
        })
        .catch(err => {
            console.error('Trigger failed', err);
            btn.disabled = false;
            btn.textContent = 'Find more songs';
            if (lastSnap) renderStatus(lastSnap);
        });
    });

    fetchStatus(true);
})();
</script>
</body>
</html>
