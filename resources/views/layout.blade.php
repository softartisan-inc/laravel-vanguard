<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Vanguard · Backup Manager</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,600;1,9..144,300&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:       #0b0d11;
            --surface:  #13161d;
            --border:   #1e2330;
            --muted:    #2e3347;
            --text:     #c8cdd8;
            --dim:      #606880;
            --accent:   #5ee7a8;
            --accent2:  #4dc4f0;
            --warn:     #f0a44d;
            --danger:   #f05454;
            --running:  #a78bfa;
            --mono:     'DM Mono', monospace;
            --serif:    'Fraunces', Georgia, serif;
        }

        html, body { height: 100%; background: var(--bg); color: var(--text); font-family: var(--mono); font-size: 13px; }

        /* ── Layout ── */
        #app { display: flex; height: 100vh; overflow: hidden; }

        /* ── Sidebar ── */
        #sidebar {
            width: 220px; flex-shrink: 0;
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            padding: 0;
        }
        .sidebar-logo {
            padding: 24px 20px 20px;
            border-bottom: 1px solid var(--border);
        }
        .logo-mark {
            font-family: var(--serif); font-size: 22px; font-weight: 600;
            color: #fff; letter-spacing: -0.5px; line-height: 1;
        }
        .logo-mark span { color: var(--accent); }
        .logo-sub { font-size: 10px; color: var(--dim); letter-spacing: 2px; text-transform: uppercase; margin-top: 4px; }

        .sidebar-nav { flex: 1; padding: 16px 0; }
        .nav-item {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 20px; cursor: pointer;
            color: var(--dim); transition: all 0.15s;
            border-left: 2px solid transparent;
            font-size: 12px; letter-spacing: 0.3px;
        }
        .nav-item:hover { color: var(--text); background: rgba(255,255,255,0.03); }
        .nav-item.active { color: var(--accent); border-left-color: var(--accent); background: rgba(94,231,168,0.06); }
        .nav-icon { width: 16px; height: 16px; opacity: 0.8; }

        .sidebar-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            font-size: 10px; color: var(--dim); letter-spacing: 1px;
        }

        /* ── Main ── */
        #main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

        .topbar {
            height: 52px; flex-shrink: 0;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 28px;
            background: var(--bg);
        }
        .topbar-title {
            font-family: var(--serif); font-size: 16px; color: #fff; font-weight: 300;
        }
        .topbar-actions { display: flex; gap: 8px; }

        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 4px; cursor: pointer;
            font-family: var(--mono); font-size: 11px; letter-spacing: 0.5px;
            border: 1px solid transparent; transition: all 0.15s;
        }
        .btn-primary { background: var(--accent); color: #0b0d11; border-color: var(--accent); font-weight: 500; }
        .btn-primary:hover { background: #7af0bb; }
        .btn-ghost { background: transparent; color: var(--dim); border-color: var(--border); }
        .btn-ghost:hover { color: var(--text); border-color: var(--muted); }
        .btn-danger { background: transparent; color: var(--danger); border-color: var(--danger); }
        .btn-danger:hover { background: rgba(240,84,84,0.1); }
        .btn:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ── Content ── */
        #content { flex: 1; overflow-y: auto; padding: 28px; }

        /* ── Stat Cards ── */
        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr);
            gap: 16px; margin-bottom: 28px;
        }
        .stat-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 8px; padding: 20px;
            position: relative; overflow: hidden;
        }
        .stat-card::before {
            content: ''; position: absolute;
            top: 0; left: 0; right: 0; height: 2px;
        }
        .stat-card.accent::before  { background: var(--accent); }
        .stat-card.blue::before    { background: var(--accent2); }
        .stat-card.warn::before    { background: var(--warn); }
        .stat-card.danger::before  { background: var(--danger); }

        .stat-label { font-size: 10px; color: var(--dim); letter-spacing: 2px; text-transform: uppercase; }
        .stat-value {
            font-family: var(--serif); font-size: 36px; color: #fff;
            font-weight: 300; line-height: 1.1; margin-top: 8px;
        }
        .stat-sub { font-size: 11px; color: var(--dim); margin-top: 6px; }

        /* ── Section ── */
        .section { margin-bottom: 28px; }
        .section-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 14px;
        }
        .section-title { font-size: 11px; color: var(--dim); letter-spacing: 2px; text-transform: uppercase; }

        /* ── Table ── */
        .table-wrap {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 8px; overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; }
        th {
            padding: 10px 16px; text-align: left;
            font-size: 10px; color: var(--dim); letter-spacing: 1.5px; text-transform: uppercase;
            border-bottom: 1px solid var(--border);
            background: rgba(255,255,255,0.02);
        }
        td { padding: 11px 16px; border-bottom: 1px solid rgba(255,255,255,0.04); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255,255,255,0.02); }

        /* ── Badges ── */
        .badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 2px 8px; border-radius: 3px; font-size: 10px;
            letter-spacing: 0.5px; font-weight: 500;
        }
        .badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; display: inline-block; }
        .badge-completed { background: rgba(94,231,168,0.12); color: var(--accent); }
        .badge-completed::before { background: var(--accent); }
        .badge-running { background: rgba(167,139,250,0.12); color: var(--running); }
        .badge-running::before { background: var(--running); animation: pulse 1.2s ease-in-out infinite; }
        .badge-failed { background: rgba(240,84,84,0.12); color: var(--danger); }
        .badge-failed::before { background: var(--danger); }
        .badge-pending { background: rgba(96,104,128,0.2); color: var(--dim); }
        .badge-pending::before { background: var(--dim); }

        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        /* ── Type tags ── */
        .tag {
            display: inline-block; padding: 1px 7px; border-radius: 3px;
            font-size: 10px; letter-spacing: 1px; text-transform: uppercase;
        }
        .tag-landlord  { background: rgba(77,196,240,0.1); color: var(--accent2); border: 1px solid rgba(77,196,240,0.2); }
        .tag-tenant    { background: rgba(240,164,77,0.1); color: var(--warn); border: 1px solid rgba(240,164,77,0.2); }
        .tag-filesystem { background: rgba(167,139,250,0.1); color: var(--running); border: 1px solid rgba(167,139,250,0.2); }

        /* ── Actions ── */
        .action-row { display: flex; gap: 6px; }

        /* ── Toast ── */
        #toast-container {
            position: fixed; bottom: 24px; right: 24px;
            display: flex; flex-direction: column; gap: 8px; z-index: 9999;
        }
        .toast {
            padding: 10px 16px; border-radius: 6px; font-size: 12px;
            background: var(--surface); border: 1px solid var(--border);
            display: flex; align-items: center; gap: 8px;
            animation: slideIn 0.2s ease;
            max-width: 320px;
        }
        .toast.success { border-color: var(--accent); color: var(--accent); }
        .toast.error   { border-color: var(--danger); color: var(--danger); }
        .toast.info    { border-color: var(--accent2); color: var(--accent2); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        /* ── Modal ── */
        .modal-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,0.7);
            display: flex; align-items: center; justify-content: center; z-index: 1000;
        }
        .modal {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 10px; padding: 28px; width: 460px; max-width: 95vw;
        }
        .modal-title { font-family: var(--serif); font-size: 18px; color: #fff; margin-bottom: 20px; font-weight: 300; }
        .modal-footer { display: flex; gap: 8px; justify-content: flex-end; margin-top: 24px; }

        /* ── Form ── */
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 10px; color: var(--dim); letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 6px; }
        .form-select, .form-input {
            width: 100%; padding: 8px 12px; border-radius: 4px;
            background: var(--bg); border: 1px solid var(--border);
            color: var(--text); font-family: var(--mono); font-size: 12px;
            outline: none; transition: border-color 0.15s;
        }
        .form-select:focus, .form-input:focus { border-color: var(--accent); }
        .form-select option { background: var(--surface); }

        /* ── Empty state ── */
        .empty { text-align: center; padding: 48px; color: var(--dim); }
        .empty-icon { font-size: 32px; margin-bottom: 12px; opacity: 0.4; }
        .empty-text { font-size: 13px; }

        /* ── Loading ── */
        .spinner {
            width: 16px; height: 16px; border: 2px solid var(--border);
            border-top-color: var(--accent); border-radius: 50%;
            animation: spin 0.7s linear infinite; display: inline-block;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Responsive ── */
        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        /* ── Tenants view ── */
        .tenant-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
        .tenant-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 8px; padding: 18px; transition: border-color 0.15s;
        }
        .tenant-card:hover { border-color: var(--muted); }
        .tenant-id { font-size: 14px; color: #fff; margin-bottom: 10px; font-family: var(--serif); font-weight: 300; }
        .tenant-meta { font-size: 11px; color: var(--dim); margin-bottom: 14px; }
        .tenant-meta span { color: var(--text); }

        /* ── Scrollbar ── */
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--muted); border-radius: 4px; }

        /* ── Page transitions ── */
        .page { animation: fadeIn 0.2s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        /* ── Pagination ── */
        .pagination { display: flex; gap: 4px; align-items: center; }
        .page-btn {
            padding: 4px 10px; border-radius: 3px; cursor: pointer;
            border: 1px solid var(--border); color: var(--dim); font-family: var(--mono); font-size: 11px;
            background: transparent; transition: all 0.1s;
        }
        .page-btn:hover:not(:disabled) { border-color: var(--muted); color: var(--text); }
        .page-btn.active { border-color: var(--accent); color: var(--accent); background: rgba(94,231,168,0.08); }
        .page-btn:disabled { opacity: 0.3; cursor: not-allowed; }
    </style>
</head>
<body>
<div id="app">
    <!-- Sidebar -->
    <aside id="sidebar">
        <div class="sidebar-logo">
            <div class="logo-mark">Van<span>guard</span></div>
            <div class="logo-sub">by SoftArtisan</div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-item active" data-page="dashboard" onclick="navigate('dashboard')">
                <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M2 10a8 8 0 1116 0A8 8 0 012 10zm8-3a1 1 0 00-1 1v3a1 1 0 001 1h2a1 1 0 100-2H10V8a1 1 0 00-1-1z"/>
                </svg>
                Dashboard
            </div>
            <div class="nav-item" data-page="backups" onclick="navigate('backups')">
                <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                </svg>
                All Backups
            </div>
            <div class="nav-item" data-page="tenants" onclick="navigate('tenants')" id="nav-tenants">
                <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zm5 6a2 2 0 10-4 0 2 2 0 004 0zm-9 0a2 2 0 10-4 0 2 2 0 004 0z"/>
                </svg>
                Tenants
            </div>
        </nav>
        <div class="sidebar-footer">VANGUARD v1.0</div>
    </aside>

    <!-- Main -->
    <div id="main">
        <div class="topbar">
            <div class="topbar-title" id="page-title">Overview</div>
            <div class="topbar-actions">
                <button class="btn btn-ghost" onclick="refreshData()">
                    <span id="refresh-icon">↻</span> Refresh
                </button>
                <button class="btn btn-primary" onclick="openRunModal()">
                    + Run Backup
                </button>
            </div>
        </div>

        <div id="content">
            <!-- Pages rendered here by JS -->
        </div>
    </div>
</div>

<!-- Toast container -->
<div id="toast-container"></div>

<script>
    const BASE = '{{ rtrim(Vanguard::path(), "/") }}';
    // BASE est injecté par Blade — fiable quelle que soit l'URL courante
    const API  = BASE + '/api';
    let currentPage = 'dashboard';
    let statsData   = null;
    let autoRefresh = null;

    // ─── Navigation ───────────────────────────────────────────────
    function navigate(page) {
        currentPage = page;
        document.querySelectorAll('.nav-item').forEach(el => {
            el.classList.toggle('active', el.dataset.page === page);
        });
        const titles = { dashboard: 'Overview', backups: 'All Backups', tenants: 'Tenants' };
        document.getElementById('page-title').textContent = titles[page] || page;
        renderPage(page);
    }

    // ─── API ──────────────────────────────────────────────────────
    async function api(path, opts = {}) {
        const res = await fetch(API + path, {
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'X-Requested-With': 'XMLHttpRequest',
            },
            ...opts,
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({ error: 'Request failed' }));
            throw new Error(err.error || 'Request failed');
        }
        return res.json();
    }

    // ─── Render Pages ─────────────────────────────────────────────
    async function renderPage(page) {
        const content = document.getElementById('content');
        content.innerHTML = '<div class="empty"><div class="spinner"></div></div>';

        try {
            if (page === 'dashboard') await renderDashboard();
            if (page === 'backups')   await renderBackups();
            if (page === 'tenants')   await renderTenants();
        } catch (e) {
            content.innerHTML = `<div class="empty"><div class="empty-icon">⚠</div><div class="empty-text">Error: ${e.message}</div></div>`;
        }
    }

    // ─── Dashboard ────────────────────────────────────────────────
    async function renderDashboard() {
        const stats = await api('/stats');
        statsData = stats;
        const content = document.getElementById('content');

        content.innerHTML = `
    <div class="page">
        <div class="stats-grid">
            <div class="stat-card accent">
                <div class="stat-label">Tenants</div>
                <div class="stat-value">${stats.total_tenants}</div>
                <div class="stat-sub">Active tenant databases</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-label">Total Backups</div>
                <div class="stat-value">${stats.total_backups}</div>
                <div class="stat-sub">${stats.total_size_human} stored</div>
            </div>
            <div class="stat-card warn">
                <div class="stat-label">Running</div>
                <div class="stat-value">${stats.running_backups}</div>
                <div class="stat-sub">Currently in progress</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-label">Failed (24h)</div>
                <div class="stat-value">${stats.failed_recent}</div>
                <div class="stat-sub">Requires attention</div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <div class="section-title">Recent Backups</div>
            </div>
            <div class="table-wrap">
                ${renderBackupTable(stats.recent_backups)}
            </div>
        </div>
    </div>`;
    }

    // ─── Backups List ─────────────────────────────────────────────
    let backupPage = 1;
    async function renderBackups(page = 1) {
        backupPage = page;
        const data = await api(`/backups?page=${page}&per_page=15`);
        const content = document.getElementById('content');

        content.innerHTML = `
    <div class="page">
        <div class="section">
            <div class="section-header">
                <div class="section-title">All Backups · ${data.meta.total} records</div>
                <div class="pagination">
                    <button class="page-btn" onclick="renderBackups(${page-1})" ${page<=1?'disabled':''}>←</button>
                    <button class="page-btn active">${page} / ${data.meta.last_page}</button>
                    <button class="page-btn" onclick="renderBackups(${page+1})" ${page>=data.meta.last_page?'disabled':''}>→</button>
                </div>
            </div>
            <div class="table-wrap">
                ${renderBackupTable(data.data, true)}
            </div>
        </div>
    </div>`;
    }

    function renderBackupTable(records, withActions = false) {
        if (!records || records.length === 0) {
            return `<div class="empty"><div class="empty-icon">🗄</div><div class="empty-text">No backups found.</div></div>`;
        }
        const rows = records.map(r => `
        <tr>
            <td><span style="color:var(--dim);font-size:11px">#${r.id}</span></td>
            <td><span class="tag tag-${r.type}">${r.type}</span></td>
            <td style="color:${r.tenant_id ? 'var(--warn)' : 'var(--accent2)'}">
                ${r.tenant_id || '— landlord'}
            </td>
            <td><span class="badge badge-${r.status}">${r.status}</span></td>
            <td style="color:var(--text)">${r.file_size_human || '—'}</td>
            <td style="color:var(--dim)">${r.duration || '—'}</td>
            <td style="color:var(--dim);font-size:11px">${r.created_at ? new Date(r.created_at).toLocaleString() : '—'}</td>
            ${withActions ? `<td>
                <div class="action-row">
                    <button class="btn btn-ghost" style="padding:4px 10px;font-size:10px" onclick="confirmRestore(${r.id})" title="Restore">↩ Restore</button>
                    <button class="btn btn-danger" style="padding:4px 10px;font-size:10px" onclick="confirmDelete(${r.id})" title="Delete">✕</button>
                </div>
            </td>` : ''}
        </tr>`).join('');

        return `<table>
        <thead><tr>
            <th>ID</th><th>Type</th><th>Tenant</th><th>Status</th><th>Size</th><th>Duration</th><th>Date</th>
            ${withActions ? '<th>Actions</th>' : ''}
        </tr></thead>
        <tbody>${rows}</tbody>
    </table>`;
    }

    // ─── Tenants ──────────────────────────────────────────────────
    async function renderTenants() {
        const data = await api('/tenants');
        const content = document.getElementById('content');
        const tenants = data.tenants;

        if (!tenants || tenants.length === 0) {
            content.innerHTML = `<div class="page"><div class="empty">
            <div class="empty-icon">👥</div>
            <div class="empty-text">No tenants found or tenancy is disabled.</div>
        </div></div>`;
            return;
        }

        const cards = tenants.map(t => {
            const lb = t.latest_backup;
            const statusClass = lb ? `badge-${lb.status}` : '';
            return `<div class="tenant-card">
            <div class="tenant-id">${t.id}</div>
            <div class="tenant-meta">
                Backups: <span>${t.total_backups}</span> ·
                Schedule: <span>${t.schedule || 'global'}</span>
            </div>
            ${lb ? `<div style="margin-bottom:12px">
                <span class="badge ${statusClass}" style="font-size:10px">${lb.status}</span>
                <span style="color:var(--dim);font-size:11px;margin-left:8px">${lb.file_size_human} · ${new Date(lb.created_at).toLocaleDateString()}</span>
            </div>` : '<div style="color:var(--dim);font-size:11px;margin-bottom:12px">No backups yet</div>'}
            <button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="runBackup('tenant','${t.id}')">
                ▶ Run Backup
            </button>
        </div>`;
        }).join('');

        content.innerHTML = `<div class="page">
        <div class="section">
            <div class="section-header">
                <div class="section-title">${tenants.length} Tenants</div>
                <button class="btn btn-ghost" style="font-size:11px" onclick="runBackup('all-tenants')">▶ Backup All</button>
            </div>
            <div class="tenant-grid">${cards}</div>
        </div>
    </div>`;
    }

    // ─── Run Backup Modal ─────────────────────────────────────────
    function openRunModal() {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.id = 'run-modal';
        backdrop.innerHTML = `
        <div class="modal">
            <div class="modal-title">Run Backup</div>
            <div class="form-group">
                <label class="form-label">Backup Type</label>
                <select class="form-select" id="backup-type" onchange="toggleTenantField()">
                    <option value="landlord">Landlord (Central DB + Filesystem)</option>
                    <option value="tenant">Specific Tenant</option>
                    <option value="all-tenants">All Tenants</option>
                    <option value="filesystem">Filesystem Only</option>
                </select>
            </div>
            <div class="form-group" id="tenant-id-group" style="display:none">
                <label class="form-label">Tenant ID</label>
                <input class="form-input" id="tenant-id-input" type="text" placeholder="tenant-uuid-or-key">
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
                <button class="btn btn-primary" id="run-btn" onclick="submitRunModal()">▶ Run</button>
            </div>
        </div>`;
        backdrop.addEventListener('click', e => { if (e.target === backdrop) closeModal(); });
        document.body.appendChild(backdrop);
    }

    function toggleTenantField() {
        const type = document.getElementById('backup-type').value;
        document.getElementById('tenant-id-group').style.display = type === 'tenant' ? 'block' : 'none';
    }

    async function submitRunModal() {
        const type     = document.getElementById('backup-type').value;
        const tenantId = document.getElementById('tenant-id-input')?.value;
        const btn      = document.getElementById('run-btn');

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> Running…';

        try {
            await runBackup(type, tenantId);
            closeModal();
        } catch(e) {
            btn.disabled = false;
            btn.textContent = '▶ Run';
        }
    }

    async function runBackup(type, tenantId = null) {
        try {
            const body = { type };
            if (tenantId) body.tenant_id = tenantId;
            const res = await api('/backups/run', { method: 'POST', body: JSON.stringify(body) });
            toast(res.queued ? 'Backup queued successfully.' : 'Backup started.', 'success');
            setTimeout(() => refreshData(), 1500);
        } catch(e) {
            toast(e.message, 'error');
            throw e;
        }
    }

    function closeModal() {
        const m = document.getElementById('run-modal');
        if (m) m.remove();
    }

    // ─── Confirm Delete ───────────────────────────────────────────
    function confirmDelete(id) {
        if (!confirm(`Delete backup #${id}? This will remove the archive file.`)) return;
        api(`/backups/${id}`, { method: 'DELETE' })
            .then(() => { toast('Backup deleted.', 'success'); refreshData(); })
            .catch(e => toast(e.message, 'error'));
    }

    function confirmRestore(id) {
        if (!confirm(`Restore backup #${id}? This will overwrite current database data.`)) return;
        api(`/backups/${id}/restore`, { method: 'POST', body: JSON.stringify({ verify_checksum: true, restore_db: true }) })
            .then(() => toast('Restore completed successfully.', 'success'))
            .catch(e => toast(e.message, 'error'));
    }

    // ─── Toast ────────────────────────────────────────────────────
    function toast(msg, type = 'info') {
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.innerHTML = `<span>${type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ'}</span> ${msg}`;
        document.getElementById('toast-container').appendChild(el);
        setTimeout(() => el.remove(), 4000);
    }

    // ─── Refresh ──────────────────────────────────────────────────
    function refreshData() {
        const icon = document.getElementById('refresh-icon');
        icon.style.display = 'inline-block';
        icon.style.animation = 'spin 0.7s linear infinite';
        renderPage(currentPage).finally(() => {
            icon.style.animation = '';
        });
    }

    // ─── Init ─────────────────────────────────────────────────────
    navigate('dashboard');

    // Auto-refresh every 30s
    autoRefresh = setInterval(() => {
        if (currentPage === 'dashboard') refreshData();
    }, 30000);
</script>
</body>
</html>
