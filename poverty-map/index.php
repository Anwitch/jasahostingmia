<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$role         = $_SESSION['role'];
$my_ri_id     = (int)($_SESSION['id_rumah_ibadah'] ?? 0);
$nama_lengkap = htmlspecialchars($_SESSION['nama_lengkap'] ?? 'User');
$is_admin     = ($role === 'admin');
$is_koordinator = ($role === 'koordinator');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebGIS Poverty Map</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        #map { height:100%; width:100%; }

        /* Sidebar dark scrollbar */
        .sidebar-tab-scroll::-webkit-scrollbar { width:4px; }
        .sidebar-tab-scroll::-webkit-scrollbar-track { background:#f1f5f9; }
        .sidebar-tab-scroll::-webkit-scrollbar-thumb { background:#94a3b8; border-radius:2px; }

        .progress-bar { transition:width 0.6s ease; }
        .highlight { background:#fef08a; border-radius:2px; padding:0 1px; }

        /* ── TAB SYSTEM ── */
        .tab-content { display:none; }
        .tab-content.active { display:block; }
        .sidebar-tab { transition:all 0.2s; border-bottom:2px solid transparent; }
        .sidebar-tab.tab-active { background:white; color:#0f172a; border-bottom-color:white; }
        .sidebar-tab:not(.tab-active) { color:#94a3b8; }
        .sidebar-tab:not(.tab-active):hover { color:#cbd5e1; background:rgba(255,255,255,0.06); }

        /* ── COLLAPSIBLE ── */
        .collapse-body { overflow:hidden; transition:max-height 0.3s ease, opacity 0.25s ease; }
        .collapse-body.open   { max-height:9999px; opacity:1; }
        .collapse-body.closed { max-height:0; opacity:0; }
        .chevron { transition:transform 0.25s ease; display:inline-block; }
        .chevron.open { transform:rotate(180deg); }

        /* ── SIDEBAR ── */
        #sidebar { transition:width 0.3s ease, opacity 0.3s ease; width:25%; min-width:268px; }
        #sidebar.hidden-sidebar { width:0; min-width:0; opacity:0; overflow:hidden; padding:0; }

        /* ── ICON RAIL (sidebar collapsed state) ── */
        #icon-rail {
            position:fixed; left:0; top:0; bottom:0; width:44px;
            z-index:2999; display:flex; flex-direction:column;
            background:#020617; border-right:1px solid #1e293b;
            box-shadow:2px 0 12px rgba(0,0,0,0.5);
            transition:transform 0.3s ease, opacity 0.3s ease;
            /* hidden by default — sidebar open */
            transform:translateX(-100%); opacity:0; pointer-events:none;
        }
        #icon-rail.visible { transform:translateX(0); opacity:1; pointer-events:auto; }

        /* Toggle chevron — melekat di tepi kanan sidebar saat terbuka */
        #sidebar-toggle {
            position:fixed; top:0; left:max(25%,268px); transform:translateX(-100%);
            z-index:3000; width:20px; height:48px; background:#020617; color:#475569;
            border:none; cursor:pointer; display:flex; align-items:center; justify-content:center;
            font-size:0.65rem; box-shadow:2px 0 8px rgba(0,0,0,0.4);
            border-radius:0 6px 6px 0;
            transition:left 0.3s ease, opacity 0.2s ease, color 0.15s;
        }
        #sidebar-toggle:hover { color:white; }
        /* Saat collapsed — sembunyikan, fungsinya diambil alih rail */
        #sidebar-toggle.collapsed { left:44px; opacity:0; pointer-events:none; }

        /* Rail item buttons */
        .rail-btn {
            flex:0 0 auto; display:flex; flex-direction:column; align-items:center;
            justify-content:center; gap:3px; padding:12px 0; cursor:pointer;
            border:none; background:transparent; color:#475569; width:100%;
            transition:background 0.15s, color 0.15s; font-size:0;
        }
        .rail-btn:hover { background:rgba(255,255,255,0.06); color:#94a3b8; }
        .rail-btn.rail-active { color:#e2e8f0; background:rgba(255,255,255,0.08); }
        .rail-btn span.rail-icon { font-size:1.1rem; line-height:1; }
        .rail-btn span.rail-label {
            font-size:8px; font-weight:700; text-transform:uppercase;
            letter-spacing:.04em; line-height:1;
        }
        /* Expand button — di atas rail seperti Claude */
        .rail-expand {
            flex:0 0 auto; display:flex; align-items:center; justify-content:center;
            padding:14px 0; cursor:pointer; border:none; background:transparent;
            color:#334155; width:100%; transition:color 0.15s;
            border-bottom:1px solid #1e293b; font-size:0.75rem;
        }
        .rail-expand:hover { color:#94a3b8; }

        /* Pastikan leaflet zoom tidak ketutupan rail:
           kita pakai CSS override karena zoomControl position diset via JS */
        .leaflet-top.leaflet-left { padding-top: 56px; padding-left: 0; }

        /* ── SKELETON ── */
        @keyframes shimmer {
            0%   { background-position:200% center; }
            100% { background-position:-200% center; }
        }
        .skeleton {
            background:linear-gradient(90deg,#e2e8f0 25%,#f1f5f9 50%,#e2e8f0 75%);
            background-size:200% 100%; animation:shimmer 1.4s infinite; border-radius:4px;
        }

        /* ── MARKER ICONS ── */
        .ri-pin {
            width:34px; height:34px; background:white; border:2.5px solid #1d4ed8;
            border-radius:50% 50% 50% 0; transform:rotate(-45deg);
            display:flex; align-items:center; justify-content:center;
            box-shadow:0 2px 6px rgba(0,0,0,0.35);
        }
        .ri-pin span { transform:rotate(45deg); font-size:16px; line-height:1; display:block; }
        .pm-dot { border:2.5px solid white; border-radius:50%; box-shadow:0 1px 4px rgba(0,0,0,0.45); }

        /* ── BASEMAP BUTTONS ── */
        .basemap-btn { transition:all 0.15s; }
        .basemap-btn.active { background:#0f172a; color:white; }
        .basemap-btn:not(.active) { background:white; color:#374151; }
        .basemap-btn:not(.active):hover { background:#f8fafc; }

        /* ── LAYER TOGGLE (floating panel) ── */
        .layer-toggle { transition:all 0.15s; }
        .layer-toggle.active   { opacity:1; }
        .layer-toggle.inactive { opacity:0.35; }

        /* ── MAP CONTROLS PANEL ANIMATION ── */
        @keyframes slideDown { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
        #map-controls-panel { animation:slideDown 0.15s ease; }

        /* ── USER MODAL VIEWS ── */
        .user-view { display:none; }
        .user-view.active { display:block; }

        /* ── SEARCH LABEL ── */
        .search-group-label {
            font-size:10px; font-weight:700; text-transform:uppercase;
            letter-spacing:.05em; color:#6b7280; padding:3px 0 2px;
        }

        /* ── ADD MODE ── */
        #map.map-crosshair,
        #map.map-crosshair .leaflet-interactive { cursor:crosshair !important; }
        .btn-add { transition:all 0.18s; }
        .btn-add.mode-active { background:#dbeafe !important; border-color:#3b82f6 !important; color:#1d4ed8 !important; }

        /* ── ADD MODE TOAST ── */
        #add-mode-toast {
            position:fixed; bottom:28px; left:50%; transform:translateX(-50%);
            z-index:9100; transition:opacity 0.2s ease, transform 0.2s ease;
        }
        #add-mode-toast.hidden { opacity:0; transform:translateX(-50%) translateY(6px); pointer-events:none; }
    </style>
</head>
<body class="m-0 p-0 font-sans overflow-hidden" style="background:#e2e8f0">
<div class="flex h-screen w-screen relative">

<!-- ── SIDEBAR TOGGLE ─────────────────────────────────────────────────── -->
<!-- ── ICON RAIL (muncul saat sidebar collapsed) ────────────────────── -->
<div id="icon-rail">
    <button class="rail-expand" onclick="toggleSidebar()" title="Buka Sidebar">&#9654;</button>
    <button class="rail-btn" onclick="openSidebarTab('statistik')" title="Statistik">
        <span class="rail-icon">&#128202;</span>
        <span class="rail-label">Stat</span>
    </button>
    <button class="rail-btn" onclick="openSidebarTab('wilayah')" title="Data Wilayah">
        <span class="rail-icon">&#128205;</span>
        <span class="rail-label">Peta</span>
    </button>
    <button class="rail-btn" onclick="openSidebarTab('histori')" title="Log Histori">
        <span class="rail-icon">&#128203;</span>
        <span class="rail-label">Log</span>
    </button>
</div>

<!-- ── SIDEBAR TOGGLE CHEVRON (melekat di tepi kanan sidebar) ───────── -->
<button id="sidebar-toggle" onclick="toggleSidebar()" title="Sembunyikan Sidebar">&#9666;</button>

<!-- ═══════════════════════════ SIDEBAR ═══════════════════════════════════ -->
<div id="sidebar" class="flex flex-col shadow-2xl z-[1000] relative flex-shrink-0 overflow-hidden" style="background:#0f172a">

    <!-- ── HEADER ── -->
    <div class="flex-shrink-0 px-4 py-3" style="background:#020617">
        <div class="flex items-center gap-2.5 mb-3">
            <div>
                <h1 class="text-sm font-bold text-white uppercase tracking-wider leading-tight">WebGIS Poverty Map</h1>
                <p class="text-[10px] text-slate-500">Informatika UNTAN &middot; GIS Project</p>
            </div>
        </div>
        <!-- User row -->
        <div class="flex items-center justify-between pt-2.5 border-t border-slate-800">
            <div class="min-w-0 flex-1">
                <div class="text-xs font-semibold text-slate-100 truncate"><?= $nama_lengkap ?></div>
                <div class="text-[10px] text-slate-500 mt-0.5">
                    <?php if ($is_admin): ?>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-400 flex-shrink-0"></span>Administrator
                    </span>
                    <?php elseif ($is_koordinator): ?>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-400 flex-shrink-0"></span>Koordinator Rumah Ibadah
                    </span>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-400 flex-shrink-0"></span>Pengambil Kebijakan
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex items-center gap-1.5 ml-2 flex-shrink-0">
                <?php if ($is_admin): ?>
                <button onclick="openModalUser()"
                    class="text-[11px] font-bold px-2.5 py-1.5 rounded-lg text-slate-400 hover:text-white transition"
                    style="background:rgba(255,255,255,0.08)"
                    title="Kelola Akun Koordinator">&#128101; Kelola User</button>
                <?php endif; ?>
                <a href="login.php?action=logout"
                    class="text-[11px] font-bold px-2.5 py-1.5 rounded-lg text-slate-400 hover:text-white transition"
                    style="background:rgba(255,255,255,0.08)"
                    onclick="return confirm('Keluar dari dashboard?')">Logout</a>
            </div>
        </div>
    </div>

    <!-- ── TAB BAR ── -->
    <div class="flex flex-shrink-0 border-b border-slate-700" style="background:#1e293b">
        <button onclick="switchTab('statistik')" id="tab-btn-statistik"
            class="sidebar-tab tab-active flex-1 flex flex-col items-center gap-0.5 py-2.5 text-[10px] font-bold uppercase tracking-wide">
            <span>&#128202;</span><span>Statistik</span>
        </button>
        <button onclick="switchTab('wilayah')" id="tab-btn-wilayah"
            class="sidebar-tab flex-1 flex flex-col items-center gap-0.5 py-2.5 text-[10px] font-bold uppercase tracking-wide border-x border-slate-700">
            <span>&#128205;</span><span>Wilayah</span>
        </button>
        <button onclick="switchTab('histori')" id="tab-btn-histori"
            class="sidebar-tab flex-1 flex flex-col items-center gap-0.5 py-2.5 text-[10px] font-bold uppercase tracking-wide">
            <span>&#128203;</span><span>Histori</span>
        </button>
    </div>

    <!-- ── TAB CONTENTS ── -->
    <div class="flex-1 overflow-y-auto sidebar-tab-scroll bg-slate-50">

        <!-- TAB 1: STATISTIK ─────────────────────────────────────────── -->
        <div id="tab-statistik" class="tab-content active p-3 space-y-3">

            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-3 py-2.5 flex justify-between items-center" style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%)">
                    <span class="text-white font-bold text-xs uppercase tracking-wide">Ringkasan</span>
                    <span id="stat-bulan" class="text-slate-400 text-[10px]"></span>
                </div>
                <div class="grid grid-cols-3 divide-x divide-slate-100 text-center py-3">
                    <div class="px-2">
                        <div id="stat-total-ri"   class="text-2xl font-bold text-slate-700">—</div>
                        <div class="text-[10px] text-slate-400 leading-tight mt-0.5">Rumah<br>Ibadah</div>
                    </div>
                    <div class="px-2">
                        <div id="stat-total-pm"   class="text-2xl font-bold text-slate-700">—</div>
                        <div class="text-[10px] text-slate-400 leading-tight mt-0.5">KK<br>Terdaftar</div>
                    </div>
                    <div class="px-2">
                        <div id="stat-total-jiwa" class="text-2xl font-bold text-slate-700">—</div>
                        <div class="text-[10px] text-slate-400 leading-tight mt-0.5">Total<br>Jiwa</div>
                    </div>
                </div>
                <div class="px-3 pb-3 space-y-2.5 border-t border-slate-100 pt-2.5">
                    <div>
                        <div class="flex justify-between text-[10px] mb-1.5">
                            <span class="font-semibold text-slate-600">Cakupan Bantuan</span>
                            <span id="stat-pct-cover" class="font-bold text-blue-600">—</span>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-2">
                            <div id="bar-cover" class="progress-bar bg-blue-500 h-2 rounded-full" style="width:0%"></div>
                        </div>
                        <div class="flex justify-between text-[10px] mt-1">
                            <span id="stat-ter-cover"   class="text-emerald-600 font-medium">—</span>
                            <span id="stat-belum-cover" class="text-red-500 font-medium">—</span>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-[10px] mb-1.5">
                            <span class="font-semibold text-slate-600">Distribusi Bulan Ini</span>
                            <span id="stat-pct-terima" class="font-bold text-emerald-600">—</span>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-2">
                            <div id="bar-terima" class="progress-bar bg-emerald-500 h-2 rounded-full" style="width:0%"></div>
                        </div>
                        <div class="flex justify-between text-[10px] mt-1">
                            <span id="stat-sudah"        class="text-emerald-600 font-medium">—</span>
                            <span id="stat-belum-terima" class="text-amber-600 font-medium">—</span>
                        </div>
                    </div>
                </div>
                <?php if ($is_admin): ?>
                <div class="px-3 pb-3 border-t border-slate-100 pt-2">
                    <button onclick="resetBulanan()"
                        class="w-full text-[10px] font-bold py-1.5 rounded-lg border border-orange-200 text-orange-600 hover:bg-orange-50 transition">
                        &#128260; Reset Status Distribusi Bulan Ini
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($role === 'pengambil_kebijakan'): ?>
            <!-- Banner khusus pengambil kebijakan -->
            <div class="rounded-xl overflow-hidden border border-slate-700 shadow-sm" style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%)">
                <div class="px-3 pt-3 pb-2">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-lg">&#128202;</span>
                        <span class="text-white font-bold text-xs uppercase tracking-wide">Ringkasan Eksekutif</span>
                    </div>
                    <p id="pk-narasi" class="text-slate-300 text-[11px] leading-relaxed">Memuat data...</p>
                </div>
                <div class="grid grid-cols-2 gap-px bg-slate-700 border-t border-slate-700 mt-2">
                    <div class="bg-slate-800/80 px-3 py-2 text-center">
                        <div id="pk-pct-cover"  class="text-xl font-bold text-blue-400">—</div>
                        <div class="text-[10px] text-slate-400 mt-0.5">KK Ter-cover</div>
                    </div>
                    <div class="bg-slate-800/80 px-3 py-2 text-center">
                        <div id="pk-pct-terima" class="text-xl font-bold text-emerald-400">—</div>
                        <div class="text-[10px] text-slate-400 mt-0.5">Distribusi Bulan Ini</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Warning: ada data belum divalidasi (hanya muncul jika ada) -->
            <div id="stat-validasi-warn" class="hidden bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5">
                <div class="flex items-start gap-2">
                    <span class="text-amber-500 flex-shrink-0 mt-0.5">&#9888;</span>
                    <div class="text-[11px] text-amber-800">
                        <span class="font-bold" id="stat-belum-validasi">0</span> data belum punya koordinat
                        (hasil import CSV, koordinat belum dilengkapi).
                        <?php if ($is_admin): ?>
                        <button onclick="switchTab('wilayah')" class="underline font-semibold hover:text-amber-900 ml-1">Lihat Antrean →</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div><!-- end tab-statistik -->

        <!-- TAB 2: DATA WILAYAH ──────────────────────────────────────── -->
        <div id="tab-wilayah" class="tab-content p-3 space-y-3">

            <!-- Search -->
            <div class="relative">
                <input id="global-search" type="text" placeholder="Cari rumah ibadah atau penduduk..."
                    oninput="globalSearch()"
                    class="w-full pl-8 pr-8 py-2 text-xs border border-slate-300 rounded-lg shadow-sm
                           focus:outline-none focus:border-slate-500 bg-white">
                <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm">&#128269;</span>
                <button id="clear-global" onclick="clearGlobalSearch()"
                    class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-xs font-bold">&#10005;</button>
            </div>
            <div id="search-results" class="hidden bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden -mt-1">
                <div id="search-results-inner" class="p-2 space-y-0.5 max-h-60 overflow-y-auto text-xs"></div>
                <div id="search-empty" class="hidden text-center text-xs text-slate-400 py-4">Tidak ada hasil</div>
            </div>

            <?php if ($is_admin): ?>
            <!-- Tombol import & export -->
            <div class="flex gap-1.5">
                <button onclick="openModalImport()"
                    class="flex-1 flex items-center justify-center gap-1.5 text-[11px] font-bold py-2 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-100 transition bg-white">
                    &#128196; Import CSV
                </button>
                <button onclick="openModalExport()"
                    class="flex-1 flex items-center justify-center gap-1.5 text-[11px] font-bold py-2 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-100 transition bg-white">
                    &#128190; Export Laporan
                </button>
            </div>

            <!-- Antrean Validasi Lokasi -->
            <section id="section-antrean" class="hidden bg-amber-50 rounded-xl border border-amber-200 overflow-hidden">
                <button onclick="toggleCollapse('antrean')"
                    class="w-full flex items-center justify-between px-3 py-2.5 hover:bg-amber-100 transition">
                    <div class="flex items-center gap-2">
                        <span class="text-amber-700 font-bold text-sm">&#9888; Antrean Validasi Lokasi</span>
                        <span id="count-antrean" class="text-xs bg-amber-200 text-amber-800 px-2 py-0.5 rounded-full font-semibold">0</span>
                    </div>
                    <span id="chevron-antrean" class="chevron text-amber-400 text-xs">&#9660;</span>
                </button>
                <div id="collapse-antrean" class="collapse-body closed">
                    <p class="text-[10px] text-amber-700 px-3 pb-2">Data hasil import CSV belum punya koordinat. Klik "Bidik di Peta" lalu klik lokasi yang sesuai.</p>
                    <div id="list-antrean" class="px-3 pb-3 space-y-1.5"></div>
                </div>
            </section>
            <?php endif; ?>

            <!-- Daftar RI -->
            <section class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <button onclick="toggleCollapse('ri')"
                    class="w-full flex items-center justify-between px-3 py-2.5 hover:bg-slate-50 transition">
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-slate-700 text-sm">&#128332; Rumah Ibadah</span>
                        <span id="count-ri" class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-semibold">0</span>
                    </div>
                    <span id="chevron-ri" class="chevron text-slate-400 text-xs">&#9660;</span>
                </button>
                <?php if ($is_admin): ?>
                <div class="px-3 pb-2.5 pt-1 border-t border-slate-100 flex gap-1.5">
                    <button id="btn-add-ri" onclick="enterAddMode('ri')"
                        class="btn-add flex-1 flex items-center justify-center gap-1 text-[11px] font-bold py-1.5 rounded-lg border border-dashed border-blue-300 text-blue-600 hover:bg-blue-50 transition">
                        &#10010; Klik Peta
                    </button>
                    <button onclick="triggerExifInput('ri')"
                        class="flex-1 flex items-center justify-center gap-1 text-[11px] font-bold py-1.5 rounded-lg border border-dashed border-violet-300 text-violet-600 hover:bg-violet-50 transition">
                        &#128247; Dari Foto
                    </button>
                </div>
                <?php endif; ?>
                <div id="collapse-ri" class="collapse-body closed">
                    <div id="skel-ri" class="px-3 pb-3 space-y-2">
                        <div class="skeleton h-12 w-full"></div>
                        <div class="skeleton h-12 w-full"></div>
                        <div class="skeleton h-12 w-4/5"></div>
                    </div>
                    <div id="list-ri" class="px-3 pb-3 space-y-1.5 text-sm hidden"></div>
                </div>
            </section>

            <!-- Daftar PM -->
            <section class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <button onclick="toggleCollapse('pm')"
                    class="w-full flex items-center justify-between px-3 py-2.5 hover:bg-slate-50 transition">
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-slate-700 text-sm">&#128101; Penduduk</span>
                        <span id="count-pm" class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-semibold">0</span>
                    </div>
                    <span id="chevron-pm" class="chevron text-slate-400 text-xs">&#9660;</span>
                </button>
                <?php if ($is_admin): ?>
                <div class="px-3 pb-2.5 pt-1 border-t border-slate-100 flex gap-1.5">
                    <button id="btn-add-pm" onclick="enterAddMode('pm')"
                        class="btn-add flex-1 flex items-center justify-center gap-1 text-[11px] font-bold py-1.5 rounded-lg border border-dashed border-red-300 text-red-600 hover:bg-red-50 transition">
                        &#10010; Klik Peta
                    </button>
                    <button onclick="triggerExifInput('pm')"
                        class="flex-1 flex items-center justify-center gap-1 text-[11px] font-bold py-1.5 rounded-lg border border-dashed border-violet-300 text-violet-600 hover:bg-violet-50 transition">
                        &#128247; Dari Foto
                    </button>
                </div>
                <?php endif; ?>
                <div id="collapse-pm" class="collapse-body closed">
                    <div id="skel-pm" class="px-3 pb-3 space-y-2">
                        <div class="skeleton h-10 w-full"></div>
                        <div class="skeleton h-10 w-full"></div>
                        <div class="skeleton h-10 w-3/4"></div>
                    </div>
                    <div id="list-pm" class="px-3 pb-3 space-y-1.5 text-sm hidden"></div>
                </div>
            </section>

        </div><!-- end tab-wilayah -->

        <!-- TAB 3: LOG HISTORI ───────────────────────────────────────── -->
        <div id="tab-histori" class="tab-content p-3">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-[11px] font-bold text-slate-600 uppercase tracking-wide">Log Penyaluran Terbaru</h3>
                <button onclick="loadHistoriGlobal()"
                    class="text-[10px] text-slate-400 hover:text-slate-600 font-medium transition">&#8635; Refresh</button>
            </div>
            <div id="histori-feed" class="space-y-2">
                <div class="text-center text-slate-400 text-xs py-10 italic">Buka tab ini untuk memuat histori.</div>
            </div>
        </div><!-- end tab-histori -->

    </div><!-- end tab contents -->
</div><!-- end sidebar -->

<!-- ═════════════════════════ PETA ══════════════════════════════════════ -->
<div class="flex-1 relative">
    <div id="map"></div>

    <!-- ── FLOATING MAP CONTROLS (top-right) ── -->
    <div class="absolute top-3 right-3 z-[1000] select-none">
        <!-- Basemap + toggle gear -->
        <div class="flex items-stretch bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden text-xs font-semibold">
            <button id="bm-osm"       onclick="switchBasemap('osm')"       class="basemap-btn active px-3 py-2">&#128506; Peta</button>
            <button id="bm-satellite" onclick="switchBasemap('satellite')" class="basemap-btn px-3 py-2 border-l border-gray-200">&#128752; Satelit</button>
            <button id="bm-hybrid"    onclick="switchBasemap('hybrid')"    class="basemap-btn px-3 py-2 border-l border-gray-200">&#9973; Hybrid</button>
            <button id="map-ctrl-btn" onclick="toggleMapControls(event)"
                class="px-3 py-2 border-l border-gray-200 text-slate-500 hover:bg-slate-50 hover:text-slate-800 transition" title="Filter & Radius">
                &#9881;
            </button>
        </div>
        <!-- Collapsible controls panel -->
        <div id="map-controls-panel" class="hidden mt-1.5 bg-white rounded-xl shadow-lg border border-gray-200 p-3 w-64">
            <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500 mb-2">Filter Tampilan</div>
            <div class="grid grid-cols-2 gap-1.5">
                <button id="toggle-ri"     onclick="toggleLayer('ri')"
                    class="layer-toggle active flex items-center gap-1.5 px-2 py-1.5 rounded-lg border border-slate-200 text-[11px] font-medium text-slate-700 hover:bg-slate-50 transition">
                    <span class="w-2 h-2 rounded-full bg-blue-500 flex-shrink-0"></span>
                    <span class="truncate">Rumah Ibadah</span>
                </button>
                <button id="toggle-green"  onclick="toggleLayer('green')"
                    class="layer-toggle active flex items-center gap-1.5 px-2 py-1.5 rounded-lg border border-slate-200 text-[11px] font-medium text-slate-700 hover:bg-slate-50 transition">
                    <span class="w-2 h-2 rounded-full bg-green-500 flex-shrink-0"></span>
                    <span class="truncate">Sudah Terima</span>
                </button>
                <button id="toggle-yellow" onclick="toggleLayer('yellow')"
                    class="layer-toggle active flex items-center gap-1.5 px-2 py-1.5 rounded-lg border border-slate-200 text-[11px] font-medium text-slate-700 hover:bg-slate-50 transition">
                    <span class="w-2 h-2 rounded-full bg-yellow-400 flex-shrink-0"></span>
                    <span class="truncate">Belum Terima</span>
                </button>
                <button id="toggle-red"    onclick="toggleLayer('red')"
                    class="layer-toggle active flex items-center gap-1.5 px-2 py-1.5 rounded-lg border border-slate-200 text-[11px] font-medium text-slate-700 hover:bg-slate-50 transition">
                    <span class="w-2 h-2 rounded-full bg-red-500 flex-shrink-0"></span>
                    <span class="truncate">Belum Ter-cover</span>
                </button>
            </div>
            <div class="flex gap-1 mt-2">
                <button onclick="setAllLayers(true)"  class="flex-1 text-[10px] py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 font-medium transition">Tampilkan Semua</button>
                <button onclick="setAllLayers(false)" class="flex-1 text-[10px] py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 font-medium transition">Sembunyikan</button>
            </div>
            <?php if ($is_admin): ?>
            <div class="border-t border-slate-100 mt-3 pt-3">
                <div class="flex justify-between text-[10px] mb-1.5">
                    <span class="font-semibold text-slate-600">&#127758; Radius Global RI</span>
                    <span id="global-radius-val" class="font-bold text-blue-600">500m</span>
                </div>
                <input id="global-radius-slider" type="range" min="100" max="2000" step="50" value="500"
                    oninput="previewGlobalRadius(this.value)"
                    onchange="applyGlobalRadius(this.value)"
                    class="w-full h-1.5 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-blue-600">
                <p class="text-[10px] text-slate-400 mt-1">Slider per-RI di popup bisa override.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── LEGENDA ── -->
    <div class="absolute bottom-5 right-5 bg-white p-3 rounded-xl shadow-lg z-[1000] text-xs space-y-1.5 border border-gray-200">
        <div class="font-bold text-slate-500 text-[10px] uppercase tracking-wide mb-1">Legenda</div>
        <div class="font-semibold text-[10px] text-slate-400 mb-1">Rumah Ibadah</div>
        <div class="flex items-center gap-2"><span class="inline-flex items-center justify-center w-4 h-4 flex-shrink-0"><span style="width:11px;height:11px;background:white;border:2px solid #15803d;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 1px 3px rgba(0,0,0,0.3);display:inline-block"></span></span>Masjid</div>
        <div class="flex items-center gap-2"><span class="inline-flex items-center justify-center w-4 h-4 flex-shrink-0"><span style="width:11px;height:11px;background:white;border:2px solid #7c3aed;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 1px 3px rgba(0,0,0,0.3);display:inline-block"></span></span>Gereja Protestan</div>
        <div class="flex items-center gap-2"><span class="inline-flex items-center justify-center w-4 h-4 flex-shrink-0"><span style="width:11px;height:11px;background:white;border:2px solid #7c3aed;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 1px 3px rgba(0,0,0,0.3);display:inline-block"></span></span>Gereja Katolik</div>
        <div class="flex items-center gap-2"><span class="inline-flex items-center justify-center w-4 h-4 flex-shrink-0"><span style="width:11px;height:11px;background:white;border:2px solid #2fbef2;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 1px 3px rgba(0,0,0,0.3);display:inline-block"></span></span>Vihara </div>
        <div class="flex items-center gap-2"><span class="inline-flex items-center justify-center w-4 h-4 flex-shrink-0"><span style="width:11px;height:11px;background:white;border:2px solid #b45309;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 1px 3px rgba(0,0,0,0.3);display:inline-block"></span></span>Pura </div>
        <div class="flex items-center gap-2"><span class="inline-flex items-center justify-center w-4 h-4 flex-shrink-0"><span style="width:11px;height:11px;background:white;border:2px solid #dc2626;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 1px 3px rgba(0,0,0,0.3);display:inline-block"></span></span>Kelenteng</div>
        <div class="font-semibold text-[10px] text-slate-400 mt-1.5">Penduduk</div>
        <div class="flex items-center gap-2"><span class="w-3 h-3 bg-green-500  rounded-full inline-block border-2 border-white shadow-sm"></span>Sudah Terima</div>
        <div class="flex items-center gap-2"><span class="w-3 h-3 bg-yellow-400 rounded-full inline-block border-2 border-white shadow-sm"></span>Belum Terima</div>
        <div class="flex items-center gap-2"><span class="w-3 h-3 bg-red-500    rounded-full inline-block border-2 border-white shadow-sm"></span>Belum Ter-cover</div>
    </div>
</div><!-- end map area -->
</div><!-- end flex wrapper -->

<!-- ═══════════════════════════ MODALS ════════════════════════════════════ -->

<!-- Modal: Konfirmasi Sudah Terima -->
<div id="modal-sudah" class="hidden fixed inset-0 bg-black/60 z-[9999] flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl p-5 w-80 max-w-full">
        <div class="flex items-center gap-2 mb-3">
            <span class="text-green-600 text-xl">&#10003;</span>
            <h3 class="font-bold text-green-700 text-sm">Konfirmasi Penerimaan Bantuan</h3>
        </div>
        <div class="mb-3">
            <label class="text-xs font-semibold text-slate-600 block mb-1">Foto Bukti Penyaluran <span class="text-red-500">*</span></label>
            <input type="file" id="modal-foto-bukti" accept="image/*"
                class="w-full text-xs border border-slate-300 rounded-lg p-1.5 bg-slate-50 cursor-pointer">
            <p class="text-[10px] text-slate-400 mt-0.5">jpg / png / webp, maks 5 MB</p>
        </div>
        <div class="mb-4">
            <label class="text-xs font-semibold text-slate-600 block mb-1">Keterangan <span class="text-slate-400 font-normal">(opsional)</span></label>
            <textarea id="modal-keterangan" rows="2" placeholder="Contoh: Paket sembako beras 10kg, minyak 2L"
                class="w-full text-xs border border-slate-300 rounded-lg p-1.5 resize-none focus:outline-none focus:border-green-400"></textarea>
        </div>
        <div id="modal-sudah-loading" class="hidden text-center text-xs text-slate-400 py-1 mb-2">&#8987; Mengupload...</div>
        <div class="flex gap-2">
            <button onclick="submitTandaiSudah()"
                class="flex-1 bg-green-600 hover:bg-green-700 text-white text-xs font-bold py-2 rounded-lg transition">&#10003; Konfirmasi</button>
            <button onclick="closeModalSudah()"
                class="flex-1 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold py-2 rounded-lg transition">Batal</button>
        </div>
    </div>
</div>

<!-- Modal: Histori per-KK -->
<div id="modal-histori" class="hidden fixed inset-0 bg-black/60 z-[9999] flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-96 max-w-full max-h-[85vh] flex flex-col overflow-hidden">
        <div class="flex items-center justify-between px-4 py-3 flex-shrink-0" style="background:#0f172a">
            <h3 class="font-bold text-white text-sm" id="modal-histori-title">Histori Bantuan</h3>
            <button onclick="closeModalHistori()" class="text-slate-500 hover:text-white text-xl font-bold leading-none transition">&#10005;</button>
        </div>
        <div id="modal-histori-body" class="overflow-y-auto flex-1 p-3 space-y-2 text-xs bg-slate-50"></div>
    </div>
</div>

<!-- Modal: Kelola User (admin only) -->
<?php if ($is_admin): ?>
<div id="modal-user" class="hidden fixed inset-0 bg-black/60 z-[9999] flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-[500px] max-w-full max-h-[85vh] flex flex-col overflow-hidden">
        <!-- Modal header -->
        <div class="flex items-center justify-between px-5 py-3.5 flex-shrink-0" style="background:#020617">
            <div>
                <h3 class="font-bold text-white text-sm" id="modal-user-title">Kelola Pengguna</h3>
                <p class="text-[10px] text-slate-500 mt-0.5" id="modal-user-subtitle">Daftar akun pengguna</p>
            </div>
            <button onclick="closeModalUser()" class="text-slate-500 hover:text-white text-xl font-bold leading-none transition">&#10005;</button>
        </div>
        <!-- Modal body -->
        <div class="flex-1 overflow-y-auto">

            <!-- LIST VIEW -->
            <div id="user-view-list" class="user-view active p-4">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs text-slate-500" id="user-list-count">Memuat...</span>
                    <button onclick="showUserForm(null)"
                        class="flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                        + Tambah Pengguna
                    </button>
                </div>
                <div id="user-table-body" class="space-y-2">
                    <div class="text-center text-slate-400 text-xs py-8 italic">Memuat data...</div>
                </div>
            </div>

            <!-- FORM VIEW -->
            <div id="user-view-form" class="user-view p-4">
                <button onclick="showUserList()"
                    class="flex items-center gap-1 text-xs text-slate-500 hover:text-slate-800 mb-4 transition font-medium">
                    &#8592; Kembali ke Daftar
                </button>
                <h4 class="text-sm font-bold text-slate-800 mb-4" id="user-form-title">Tambah Koordinator Baru</h4>
                <div class="space-y-3">
                    <input type="hidden" id="uf_id">
                    <div>
                        <label class="text-[10px] font-semibold text-slate-600 block mb-1">Role</label>
                        <select id="uf_role" onchange="onRoleChange()"
                            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                            <option value="koordinator">Koordinator Rumah Ibadah</option>
                            <option value="pengambil_kebijakan">Pengambil Kebijakan</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold text-slate-600 block mb-1">Nama Lengkap</label>
                        <input type="text" id="uf_nama" placeholder="Nama lengkap"
                            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold text-slate-600 block mb-1">Nomor WhatsApp</label>
                        <input type="text" id="uf_no_wa" placeholder="Contoh: 08123456789"
                            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold text-slate-600 block mb-1">Username</label>
                        <input type="text" id="uf_username" placeholder="Username untuk login"
                            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold text-slate-600 block mb-1">
                            Password <span id="uf_pw_hint" class="text-slate-400 font-normal">(wajib diisi)</span>
                        </label>
                        <input type="password" id="uf_password" placeholder="Minimal 6 karakter"
                            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                    </div>
                    <!-- Hanya tampil untuk role koordinator -->
                    <div id="uf_ri_wrapper">
                        <label class="text-[10px] font-semibold text-slate-600 block mb-1">
                            Rumah Ibadah yang Dikelola <span class="text-red-500">*</span>
                        </label>
                        <select id="uf_ri"
                            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                            <option value="">— Pilih Rumah Ibadah —</option>
                        </select>
                    </div>
                </div>
                <div id="uf_error" class="hidden mt-3 bg-red-50 border border-red-200 text-red-700 text-xs px-3 py-2 rounded-lg"></div>
                <div class="flex gap-2 mt-5">
                    <button onclick="submitUserForm()"
                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold py-2.5 rounded-lg transition">&#10003; Simpan</button>
                    <button onclick="showUserList()"
                        class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-bold py-2.5 rounded-lg transition">Batal</button>
                </div>
            </div>

        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($is_admin): ?>
<!-- ── MODAL: IMPORT CSV ─────────────────────────────────────────────── -->
<div id="modal-import" class="hidden fixed inset-0 bg-black/60 z-[9999] flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-[480px] max-w-full flex flex-col overflow-hidden" style="max-height:88vh">
        <div class="flex items-center justify-between px-5 py-3.5 flex-shrink-0" style="background:#0f172a">
            <div>
                <h3 class="font-bold text-white text-sm">&#128196; Import Massal CSV</h3>
            </div>
            <button onclick="closeModalImport()" class="text-slate-500 hover:text-white text-xl font-bold leading-none transition">&#10005;</button>
        </div>
        <div class="p-4 flex-1 overflow-y-auto">
            <!-- Upload area -->
            <div id="import-upload-area" class="mb-3">
                <!-- Pilihan tipe -->
                <div class="flex gap-2 mb-3">
                    <button id="import-type-pm" onclick="setImportType('penduduk')"
                        class="flex-1 text-xs font-bold py-2 rounded-lg border-2 border-blue-500 bg-blue-50 text-blue-700 transition">
                        &#128101; Penduduk
                    </button>
                    <button id="import-type-ri" onclick="setImportType('ri')"
                        class="flex-1 text-xs font-bold py-2 rounded-lg border-2 border-slate-200 text-slate-500 hover:border-slate-400 transition">
                        &#128332; Rumah Ibadah
                    </button>
                </div>
                <label for="csv-file-input"
                    class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-slate-300 rounded-xl p-6 cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition">
                    <span class="text-3xl">&#128196;</span>
                    <span class="text-sm font-semibold text-slate-600">Klik untuk pilih file CSV</span>
                    <span class="text-xs text-slate-400">atau seret &amp; lepas di sini · maks 2MB · 500 baris</span>
                    <input type="file" id="csv-file-input" accept=".csv,.txt" class="hidden">
                </label>
                <p id="csv-file-name" class="text-xs text-slate-500 mt-1.5 text-center italic hidden"></p>
                <!-- Format kolom dinamis -->
                <div id="import-format-pm" class="mt-3 bg-slate-50 border border-slate-200 rounded-lg p-2.5 text-[10px] text-slate-500 space-y-0.5">
                    <div class="font-semibold text-slate-600 mb-1">Format kolom (Penduduk):</div>
                    <div class="font-mono bg-white border border-slate-100 rounded px-2 py-1 text-slate-700">Nama KK · Jml Anggota · Alamat · RT · RW · Kelurahan · Kecamatan</div>
                    <div class="mt-1">• Baris pertama (header) diabaikan &nbsp;• Delimiter koma atau titik koma</div>
                    <div>• Koordinat tidak di-geocode otomatis → lengkapi via "Bidik di Peta" pada Antrean Validasi Lokasi</div>
                </div>
                <div id="import-format-ri" class="hidden mt-3 bg-slate-50 border border-slate-200 rounded-lg p-2.5 text-[10px] text-slate-500 space-y-0.5">
                    <div class="font-semibold text-slate-600 mb-1">Format kolom (Rumah Ibadah):</div>
                    <div class="font-mono bg-white border border-slate-100 rounded px-2 py-1 text-slate-700">Nama · Jenis · Alamat · Radius(opsional)</div>
                    <div class="mt-1">• Jenis: Masjid / Gereja Protestan / Gereja Katolik / Vihara / Pura / Kelenteng</div>
                    <div>• Jenis kosong → default Masjid &nbsp;• Radius kosong → default 500m</div>
                    <div class="text-amber-600 font-semibold">• Semua RI diinsert tanpa koordinat → lengkapi via "Bidik di Peta" pada Antrean Validasi Lokasi</div>
                </div>
                <button id="btn-start-import" onclick="startImport()" disabled
                    class="w-full mt-3 bg-blue-600 hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed text-white font-bold text-sm py-2.5 rounded-lg transition">
                    &#9654; Mulai Import
                </button>
            </div>
            <!-- Progress area (hidden by default) -->
            <div id="import-progress-area" class="hidden">
                <div class="flex items-center justify-between text-xs mb-2">
                    <span id="import-progress-label" class="font-semibold text-slate-600">Memproses...</span>
                    <span id="import-progress-pct" class="font-bold text-blue-600">0%</span>
                </div>
                <div class="w-full bg-slate-100 rounded-full h-2.5 mb-3">
                    <div id="import-progress-bar" class="bg-blue-500 h-2.5 rounded-full transition-all" style="width:0%"></div>
                </div>
                <div id="import-log" class="bg-slate-900 rounded-xl p-3 text-[10px] font-mono text-slate-300 space-y-0.5 overflow-y-auto" style="max-height:280px"></div>
            </div>
            <!-- Done summary -->
            <div id="import-done-area" class="hidden text-center py-4">
                <div class="text-4xl mb-2">&#10003;</div>
                <div id="import-done-text" class="text-sm font-bold text-slate-700 mb-1"></div>
                <div id="import-done-sub"  class="text-xs text-slate-500 mb-4"></div>
                <button onclick="closeModalImport();loadData();if(typeof loadGecodingQueue==='function')loadGecodingQueue()"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm px-6 py-2 rounded-lg transition">
                    Selesai &amp; Refresh Peta
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── MODAL: EXPORT LAPORAN ─────────────────────────────────────────── -->
<div id="modal-export" class="hidden fixed inset-0 bg-black/60 z-[9999] flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-80 max-w-full overflow-hidden">
        <div class="flex items-center justify-between px-5 py-3.5" style="background:#0f172a">
            <h3 class="font-bold text-white text-sm">&#128190; Export Laporan CSV</h3>
            <button onclick="closeModalExport()" class="text-slate-500 hover:text-white text-xl font-bold leading-none transition">&#10005;</button>
        </div>
        <div class="p-4 space-y-3">
            <div>
                <label class="text-[10px] font-semibold text-slate-600 block mb-1">Bulan</label>
                <select id="export-bulan" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <?php
                    $bulan_id = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                    foreach ($bulan_id as $i => $b) {
                        $sel = ($i+1 == date('n')) ? 'selected' : '';
                        echo "<option value=\"".($i+1)."\" $sel>$b</option>";
                    }
                    ?>
                </select>
            </div>
            <div>
                <label class="text-[10px] font-semibold text-slate-600 block mb-1">Tahun</label>
                <input type="number" id="export-tahun" value="<?= date('Y') ?>" min="2020" max="2099"
                    class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-2.5 text-[10px] text-slate-500">
                File Excel (.xlsx) berisi 3 sheet: Ringkasan & Rekap RI, Detail per Penduduk, dan Belum Ter-cover.
                Langsung bisa dibuka di Microsoft Excel atau Google Sheets.
            </div>
            <button onclick="doExport()"
                class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm py-2.5 rounded-lg transition">
                &#128190; Download Excel (.xlsx)
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════ SCRIPTS ══════════════════════════════════ -->

<!-- Add Mode Toast -->
<div id="add-mode-toast" class="hidden">
    <div class="flex items-center gap-2.5 text-white text-xs font-medium px-4 py-2.5 rounded-xl shadow-xl"
         style="background:rgba(15,23,42,0.92);backdrop-filter:blur(8px);pointer-events:auto">
        <span id="add-mode-toast-icon" class="text-base flex-shrink-0">&#10010;</span>
        <span id="add-mode-toast-text">Klik lokasi pada peta untuk menambah data baru</span>
        <button onclick="typeof _bidikPmId!=='undefined'&&_bidikPmId?cancelBidikMode():exitAddMode()"
            class="ml-2 text-white/50 hover:text-white font-bold text-sm leading-none bg-transparent border-none cursor-pointer">&#10005;</button>
    </div>
</div>

<!-- Hidden file input for EXIF GPS (reused for both RI and PM) -->
<input type="file" id="exif-file-input" accept="image/*" class="hidden">

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<!-- exifr: lightweight EXIF reader, ~15KB, untuk ekstrak GPS dari foto -->
<script src="https://cdn.jsdelivr.net/npm/exifr/dist/lite.umd.js"></script>
<script>
// ── KONSTANTA SESI ───────────────────────────────────────────────────────
const ROLE     = '<?= $role ?>';
const MY_RI_ID = <?= $my_ri_id ?>;
const IS_ADMIN = ROLE === 'admin';

// ── MAP ──────────────────────────────────────────────────────────────────
const map = L.map('map', {zoomControl: false}).setView([-0.0227, 109.3340], 14);
L.control.zoom({position: 'topleft'}).addTo(map);

// ── BASEMAP LAYERS ────────────────────────────────────────────────────────
const basemaps = {
    osm:       L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution:'&copy; OpenStreetMap contributors', maxZoom:19 }),
    satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution:'&copy; Esri', maxZoom:19 }),
    hybrid:    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution:'&copy; Esri', maxZoom:19 }),
};
const osmLabels = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { opacity:0.45, maxZoom:19 });
let currentBasemap = 'osm';
basemaps.osm.addTo(map);

window.switchBasemap = (key) => {
    if (key === currentBasemap) return;
    map.removeLayer(basemaps[currentBasemap]);
    if (currentBasemap === 'hybrid') map.removeLayer(osmLabels);
    basemaps[key].addTo(map);
    if (key === 'hybrid') osmLabels.addTo(map);
    currentBasemap = key;
    ['osm','satellite','hybrid'].forEach(k => document.getElementById('bm-'+k).classList.toggle('active', k===key));
};

// ── ICON FACTORIES ────────────────────────────────────────────────────────
const jenisBorderColor = {
    'Masjid':           '#15803d',
    'Gereja Protestan': '#7c3aed',
    'Gereja Katolik':   '#7c3aed',
    'Vihara':           '#2fbef2',
    'Pura':             '#b45309',
    'Kelenteng':        '#dc2626',
};
function riIcon(jenis) {
    const border = jenisBorderColor[jenis] || '#64748b';
    return L.divIcon({ html:`<div class="ri-pin" style="border-color:${border}"></div>`, iconSize:[34,34], iconAnchor:[17,34], popupAnchor:[0,-36], className:'' });
}
function pmIcon(color) {
    const colors = { green:'#22c55e', yellow:'#facc15', red:'#ef4444' };
    const hex = colors[color] || '#94a3b8';
    return L.divIcon({ html:`<div class="pm-dot" style="width:14px;height:14px;background:${hex}"></div>`, iconSize:[14,14], iconAnchor:[7,7], popupAnchor:[0,-12], className:'' });
}

// ── LAYER GROUPS ──────────────────────────────────────────────────────────
const layers = { ri:L.layerGroup().addTo(map), green:L.layerGroup().addTo(map), yellow:L.layerGroup().addTo(map), red:L.layerGroup().addTo(map) };
const layerActive = { ri:true, green:true, yellow:true, red:true };
let markersRI={}, markersPM={}, circles={};
let cachedRI=[], cachedPM=[];

// ── TAB SYSTEM ────────────────────────────────────────────────────────────
let activeTab = 'statistik';
let historiLoaded = false;

window.switchTab = (tab) => {
    activeTab = tab;
    ['statistik','wilayah','histori'].forEach(t => {
        document.getElementById('tab-'+t).classList.toggle('active', t===tab);
        document.getElementById('tab-btn-'+t).classList.toggle('tab-active', t===tab);
    });
    if (tab === 'histori' && !historiLoaded) loadHistoriGlobal();
};

// ── FLOATING MAP CONTROLS ─────────────────────────────────────────────────
window.toggleMapControls = (e) => {
    e.stopPropagation();
    const panel = document.getElementById('map-controls-panel');
    panel.classList.toggle('hidden');
};
document.addEventListener('click', () => {
    const panel = document.getElementById('map-controls-panel');
    if (panel) panel.classList.add('hidden');
});

// ── SKELETON ──────────────────────────────────────────────────────────────
function showSkeleton() {
    ['ri','pm'].forEach(t => {
        document.getElementById('skel-'+t).classList.remove('hidden');
        document.getElementById('list-'+t).classList.add('hidden');
    });
}
function hideSkeleton() {
    ['ri','pm'].forEach(t => {
        document.getElementById('skel-'+t).classList.add('hidden');
        document.getElementById('list-'+t).classList.remove('hidden');
    });
}

// ── LOAD DATA ─────────────────────────────────────────────────────────────
function loadData() {
    showSkeleton();
    Object.values(layers).forEach(l => l.clearLayers());
    document.getElementById('list-ri').innerHTML='';
    document.getElementById('list-pm').innerHTML='';
    markersRI={}; markersPM={}; circles={};

    fetch('api.php?action=get_data').then(r=>r.json()).then(data => {
        cachedRI=data.rumah_ibadah; cachedPM=data.penduduk_miskin;
        updateStatistik(data.statistik);
        document.getElementById('count-ri').innerText = data.rumah_ibadah.length;
        document.getElementById('count-pm').innerText = data.penduduk_miskin.length;

        // Ringkasan per RI
        const rk={};
        data.rumah_ibadah.forEach(ri => { rk[ri.id]={kk:0,jiwa:0,sudah:0,belum:0}; });
        data.penduduk_miskin.forEach(p => {
            if (p.id_rumah_ibadah && rk[p.id_rumah_ibadah]) {
                const r=rk[p.id_rumah_ibadah];
                r.kk++; r.jiwa+=parseInt(p.jumlah_anggota)||0;
                p.status_bantuan==='sudah' ? r.sudah++ : r.belum++;
            }
        });

        // Render RI
        data.rumah_ibadah.forEach(ri => {
            const icon = riIcon(ri.jenis || 'Masjid');
            const m = L.marker([ri.lat, ri.lng], {icon}).addTo(layers.ri);
            const c = L.circle([ri.lat,ri.lng],{radius:ri.radius,color:'#3b82f6',fillOpacity:0.07,weight:1.5}).addTo(layers.ri);
            circles[ri.id]=c; markersRI[ri.id]=m;
            const r=rk[ri.id]||{kk:0,jiwa:0,sudah:0,belum:0};
            m.bindTooltip(`<b>${ri.nama}</b><br><span style="font-size:11px">${ri.jenis||''} &middot; radius ${ri.radius}m</span>`,
                {direction:'top', offset:[0,-34], className:'leaflet-tooltip'});
            m.bindPopup(()=>buildPopupRI(ri,r), {minWidth:240, maxWidth:280, keepInView:true, autoPanPaddingTopLeft:L.point(10,80)});

            const borderColor = jenisBorderColor[ri.jenis] || '#64748b';
            const miniPin = `<span style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;flex-shrink:0"><span style="width:9px;height:9px;background:white;border:2px solid ${borderColor};border-radius:50% 50% 50% 0;transform:rotate(-45deg);display:inline-block;box-shadow:0 1px 2px rgba(0,0,0,0.25)"></span></span>`;
            document.getElementById('list-ri').insertAdjacentHTML('beforeend', `
                <div data-type="ri" data-id="${ri.id}"
                    data-nama="${escAttr(ri.nama)}" data-alamat="${escAttr(ri.alamat)}"
                    onclick="focusMap(${ri.lat},${ri.lng},'ri',${ri.id})"
                    class="ri-card bg-slate-50 p-2 rounded-lg border border-slate-100 cursor-pointer hover:bg-blue-50 hover:border-blue-200 transition">
                    <div class="font-bold text-blue-800 text-xs flex items-center gap-1">
                        ${miniPin}<span class="search-nama">${ri.nama}</span>
                    </div>
                    <div class="text-[10px] text-slate-500 truncate search-alamat">${ri.alamat}</div>
                    <div class="text-[10px] text-slate-400 mt-0.5">
                        ${r.kk} KK &middot; ${r.jiwa} jiwa
                        ${r.kk>0 ? `<span class="text-green-600 ml-1">${r.sudah} sudah</span> / <span class="text-amber-600">${r.belum} belum</span>` : '<span class="italic">belum ada PM</span>'}
                    </div>
                </div>`);
        });

        // Render PM — skip jika lat/lng null (belum divalidasi)
        data.penduduk_miskin.forEach(p => {
            // Badge prioritas urgensi
            const skor = parseFloat(p.skor_prioritas) || 0;
            const [prioBg, prioText, prioLabel] =
                skor >= 70 ? ['bg-red-100','text-red-700','Tinggi']
              : skor >= 40 ? ['bg-amber-100','text-amber-700','Sedang']
                           : ['bg-green-100','text-green-700','Rendah'];

            // Sidebar card (semua PM, termasuk yang belum punya koordinat)
            const covered=p.id_rumah_ibadah!=null, sudah=p.status_bantuan==='sudah';
            const badgeClass = !covered ? 'bg-red-100 text-red-600'
                             : sudah    ? 'bg-green-100 text-green-700'
                                        : 'bg-amber-100 text-amber-700';
            const badgeText  = !covered ? 'Belum Ter-cover'
                             : sudah    ? 'Sudah Terima' : 'Belum Terima';
            const alamatSingkat = p.nama_cover
                ? `via <span class="font-medium text-slate-600">${p.nama_cover}</span>`
                : `<span class="italic text-slate-400">Belum tercakup RI</span>`;
            const noLatLng = !p.lat || !p.lng;
            const locWarn  = noLatLng
                ? `<span class="text-[9px] text-amber-600 font-semibold">&#9888; Lokasi belum divalidasi</span>`
                : '';
            const clickHandler = noLatLng
                ? `openBidikMode(${p.id},'${escQ(p.nama_kepala)}')`
                : `focusMap(${p.lat},${p.lng},'pm',${p.id})`;
            document.getElementById('list-pm').insertAdjacentHTML('beforeend', `
                <div data-type="pm" data-id="${p.id}" data-nama="${escAttr(p.nama_kepala)}"
                    onclick="${clickHandler}"
                    class="pm-card bg-white p-2 rounded-lg border border-slate-200 cursor-pointer hover:bg-slate-50 hover:border-slate-300 transition ${noLatLng?'border-l-2 border-l-amber-400':''}">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1 flex-wrap">
                                <span class="font-semibold text-slate-800 text-xs leading-tight search-nama">${p.nama_kepala}</span>
                            </div>
                            <div class="text-[10px] mt-0.5">
                                    <span class="text-slate-500">Prioritas:</span>
                                    <span class="font-semibold ${prioText}">
                                        ${prioLabel}
                                    </span>
                                </div>
                            <div class="text-[10px] text-slate-400 mt-0.5 truncate">${alamatSingkat}</div>
                            ${locWarn}
                        </div>
                        <span class="flex-shrink-0 text-[9px] font-bold px-1.5 py-0.5 rounded-full ${badgeClass} whitespace-nowrap mt-0.5">${badgeText}</span>
                    </div>
                </div>`);

            // Marker di peta — hanya jika koordinat tersedia
            if (noLatLng) return;
            const lk=!covered?'red':(sudah?'green':'yellow');
            const m=L.marker([p.lat,p.lng],{icon:pmIcon(lk)}).addTo(layers[lk]);
            markersPM[p.id]=m;
            const tipStatus = !covered ? 'Belum Ter-cover' : sudah ? 'Sudah Terima' : 'Belum Terima';
            m.bindTooltip(`<b>${p.nama_kepala}</b><br><span style="font-size:11px">${tipStatus}</span>`,
                {direction:'top', offset:[0,-10], className:'leaflet-tooltip'});
            m.bindPopup(()=>buildPopupPM(p), {minWidth:210, maxWidth:260, keepInView:true, autoPanPaddingTopLeft:L.point(10,80)});
        });

        hideSkeleton();
        if (document.getElementById('global-search').value.trim()) globalSearch();
        // Refresh histori tab jika sedang aktif
        if (activeTab === 'histori') { historiLoaded=false; loadHistoriGlobal(); }
    });
}

// ── STATISTIK ─────────────────────────────────────────────────────────────
function updateStatistik(s) {
    if (!s) return;
    document.getElementById('stat-bulan').innerText        = s.bulan;
    document.getElementById('stat-total-ri').innerText     = s.total_ri;
    document.getElementById('stat-total-pm').innerText     = s.total_pm;
    document.getElementById('stat-total-jiwa').innerText   = s.total_jiwa;
    document.getElementById('stat-pct-cover').innerText    = s.pct_cover+'%';
    document.getElementById('stat-pct-terima').innerText   = s.pct_terima+'%';
    document.getElementById('stat-ter-cover').innerText    = s.ter_cover+' ter-cover';
    document.getElementById('stat-belum-cover').innerText  = s.belum_cover+' belum';
    document.getElementById('stat-sudah').innerText        = s.sudah_terima+' sudah';
    document.getElementById('stat-belum-terima').innerText = s.belum_terima+' belum';
    setTimeout(()=>{
        document.getElementById('bar-cover').style.width  = s.pct_cover+'%';
        document.getElementById('bar-terima').style.width = s.pct_terima+'%';
    }, 100);

    // Warning data belum validasi
    const warnEl   = document.getElementById('stat-validasi-warn');
    const warnNum  = document.getElementById('stat-belum-validasi');
    if (warnEl && s.belum_validasi > 0) {
        warnEl.classList.remove('hidden');
        warnNum.textContent = s.belum_validasi;
    } else if (warnEl) {
        warnEl.classList.add('hidden');
    }

    // Banner pengambil kebijakan
    const pkNarasi = document.getElementById('pk-narasi');
    if (pkNarasi) {
        const coverTxt   = s.pct_cover  >= 80 ? 'sangat baik'
                         : s.pct_cover  >= 50 ? 'cukup'
                                               : 'perlu perhatian';
        const distribTxt = s.pct_terima >= 80 ? 'berjalan lancar'
                         : s.pct_terima >= 50 ? 'sebagian terlaksana'
                                               : 'masih rendah';
        pkNarasi.textContent =
            `Per ${s.bulan}, ${s.total_pm} KK (${s.total_jiwa} jiwa) terdaftar di sistem. `+
            `Cakupan rumah ibadah ${coverTxt} (${s.pct_cover}%), `+
            `dengan distribusi bantuan bulan ini ${distribTxt} (${s.pct_terima}% dari KK ter-cover).`;
        document.getElementById('pk-pct-cover').textContent  = s.pct_cover+'%';
        document.getElementById('pk-pct-terima').textContent = s.pct_terima+'%';
    }
}

// ── HISTORI GLOBAL (Tab 3) ────────────────────────────────────────────────
window.loadHistoriGlobal = () => {
    historiLoaded = true;
    const feed = document.getElementById('histori-feed');
    feed.innerHTML = '<div class="text-center text-slate-400 text-xs py-8">&#8987; Memuat...</div>';
    fetch('api.php?action=get_histori_global&limit=30')
        .then(r=>r.json())
        .then(rows => {
            if (!rows.length) {
                feed.innerHTML = '<div class="text-center text-slate-400 text-xs py-10 italic">Belum ada data penyaluran tercatat.</div>';
                return;
            }
            const BULAN_ID=['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
            feed.innerHTML = rows.map(h => {
                const d = new Date(h.tanggal_penyaluran);
                const tgl = d.toLocaleDateString('id-ID',{day:'numeric',month:'short',year:'numeric'});
                const jam = d.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'});
                const ket = h.keterangan ? `<div class="text-[10px] text-slate-400 mt-0.5 italic truncate">${h.keterangan}</div>` : '';
                return `
                <div class="flex gap-2.5 bg-white border border-slate-200 rounded-xl p-2.5 shadow-sm">
                    <img src="uploads/foto_bukti/${h.foto_bukti}"
                        class="w-14 h-14 object-cover rounded-lg border border-slate-200 flex-shrink-0 cursor-pointer"
                        onclick="window.open('uploads/foto_bukti/${h.foto_bukti}','_blank')"
                        onerror="this.style.display='none'" title="Klik untuk perbesar">
                    <div class="min-w-0 flex-1">
                        <div class="font-bold text-slate-800 text-xs truncate">${h.nama_kepala||'—'}</div>
                        <div class="text-[10px] text-slate-500">via <span class="font-semibold">${h.nama_ri||'—'}</span></div>
                        <div class="text-[10px] text-slate-400">${tgl} &middot; ${jam}</div>
                        ${ket}
                    </div>
                    <div class="flex-shrink-0 text-right">
                        <div class="text-[10px] font-bold text-blue-600">${BULAN_ID[h.bulan]}</div>
                        <div class="text-[10px] text-slate-400">${h.tahun}</div>
                    </div>
                </div>`;
            }).join('');
        })
        .catch(() => { feed.innerHTML='<div class="text-center text-red-400 text-xs py-6">Gagal memuat data.</div>'; });
};

// ── SIDEBAR & COLLAPSE ────────────────────────────────────────────────────
let sidebarOpen = true;

window.toggleSidebar = () => {
    const sb   = document.getElementById('sidebar');
    const btn  = document.getElementById('sidebar-toggle');
    const rail = document.getElementById('icon-rail');
    sidebarOpen = !sidebarOpen;
    sb.classList.toggle('hidden-sidebar', !sidebarOpen);
    btn.classList.toggle('collapsed', !sidebarOpen);
    rail.classList.toggle('visible', !sidebarOpen);

    // Shift zoom control saat rail muncul
    const zoomEl = document.querySelector('.leaflet-top.leaflet-left');
    if (zoomEl) zoomEl.style.paddingLeft = sidebarOpen ? '0' : '44px';

    // Smooth resize: panggil invalidateSize tiap frame selama 300ms transisi
    // pan:false mencegah peta re-center, animate:false mencegah tile glitch
    const start = performance.now();
    const DURATION = 320;
    function step(now) {
        map.invalidateSize({pan: false, animate: false});
        if (now - start < DURATION) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
};

// Buka sidebar langsung ke tab tertentu (dipanggil dari icon rail)
window.openSidebarTab = (tab) => {
    if (!sidebarOpen) toggleSidebar();
    // Tunggu animasi slide selesai baru switch tab
    setTimeout(() => switchTab(tab), sidebarOpen ? 0 : 320);
};

// Update rail-active sesuai tab aktif
const _origSwitchTab = window.switchTab;
window.switchTab = (tab) => {
    _origSwitchTab(tab);
    document.querySelectorAll('.rail-btn').forEach(btn => {
        const target = btn.getAttribute('onclick')?.match(/'(\w+)'/)?.[1];
        btn.classList.toggle('rail-active', target === tab);
    });
};
window.toggleCollapse = (key) => {
    const body=document.getElementById('collapse-'+key);
    const chevron=document.getElementById('chevron-'+key);
    const isOpen=body.classList.contains('open');
    body.classList.toggle('open',!isOpen);
    body.classList.toggle('closed',isOpen);
    chevron.classList.toggle('open',!isOpen);
};

// ── GLOBAL SEARCH ─────────────────────────────────────────────────────────
window.globalSearch = () => {
    const q=document.getElementById('global-search').value.toLowerCase().trim();
    const box=document.getElementById('search-results');
    const inner=document.getElementById('search-results-inner');
    const empty=document.getElementById('search-empty');
    document.getElementById('clear-global').classList.toggle('hidden',q==='');
    if(!q){box.classList.add('hidden');return;}
    box.classList.remove('hidden');
    const mRI=cachedRI.filter(r=>r.nama.toLowerCase().includes(q)||r.alamat.toLowerCase().includes(q));
    const mPM=cachedPM.filter(p=>p.nama_kepala.toLowerCase().includes(q));
    inner.innerHTML='';
    if(mRI.length+mPM.length===0){inner.classList.add('hidden');empty.classList.remove('hidden');return;}
    inner.classList.remove('hidden');empty.classList.add('hidden');
    if(mRI.length>0){
        inner.insertAdjacentHTML('beforeend',`<div class="search-group-label border-b border-slate-100 pb-1 mb-1">Rumah Ibadah (${mRI.length})</div>`);
        mRI.forEach(ri=>{
            const bc=jenisBorderColor[ri.jenis]||'#64748b';
            const pin=`<span style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;flex-shrink:0;margin-top:2px"><span style="width:9px;height:9px;background:white;border:2px solid ${bc};border-radius:50% 50% 50% 0;transform:rotate(-45deg);display:inline-block;box-shadow:0 1px 2px rgba(0,0,0,0.25)"></span></span>`;
            inner.insertAdjacentHTML('beforeend',`
                <div onclick="focusMap(${ri.lat},${ri.lng},'ri',${ri.id})"
                    class="flex items-start gap-2 p-1.5 rounded-lg cursor-pointer hover:bg-blue-50 transition">
                    ${pin}<div class="min-w-0">
                        <div class="font-semibold text-blue-800 text-xs leading-tight">${hl(ri.nama,q)}</div>
                        <div class="text-[10px] text-slate-400 truncate">${hl(ri.alamat,q)}</div>
                    </div>
                </div>`);
        });
    }
    if(mPM.length>0){
        if(mRI.length>0) inner.insertAdjacentHTML('beforeend','<div class="border-t border-slate-100 my-1"></div>');
        inner.insertAdjacentHTML('beforeend',`<div class="search-group-label border-b border-slate-100 pb-1 mb-1">Penduduk (${mPM.length})</div>`);
        mPM.forEach(p=>{
            const covered=p.id_rumah_ibadah!=null,sudah=p.status_bantuan==='sudah';
            const dot=!covered?'bg-red-500':sudah?'bg-green-500':'bg-yellow-400';
            const sub=!covered?'Belum Ter-cover':sudah?'Sudah Terima':'Belum Terima';
            inner.insertAdjacentHTML('beforeend',`
                <div onclick="focusMap(${p.lat},${p.lng},'pm',${p.id})"
                    class="flex items-start gap-2 p-1.5 rounded-lg cursor-pointer hover:bg-slate-50 transition">
                    <span class="w-2 h-2 rounded-full ${dot} mt-1 flex-shrink-0"></span>
                    <div><div class="font-semibold text-slate-800 text-xs">${hl(p.nama_kepala,q)}</div>
                    <div class="text-[10px] text-slate-400">${sub}</div></div>
                </div>`);
        });
    }
};
window.clearGlobalSearch=()=>{document.getElementById('global-search').value='';globalSearch();};
function hl(t,q){if(!q)return t;return t.replace(new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),'<span class="highlight">$1</span>');}

// ── GLOBAL RADIUS ─────────────────────────────────────────────────────────
window.previewGlobalRadius=(val)=>{
    document.getElementById('global-radius-val').innerText=val+'m';
    Object.values(circles).forEach(c=>c.setRadius(parseInt(val)));
};
window.applyGlobalRadius=(val)=>{
    if(!cachedRI.length)return;
    Promise.all(cachedRI.map(ri=>{
        const fd=new FormData();fd.append('id',ri.id);fd.append('radius',val);
        return fetch('api.php?action=update_radius',{method:'POST',body:fd});
    })).then(()=>loadData());
};

// ── TOGGLE LAYER ──────────────────────────────────────────────────────────
window.toggleLayer=(key)=>{
    layerActive[key]=!layerActive[key];
    layerActive[key]?map.addLayer(layers[key]):map.removeLayer(layers[key]);
    document.getElementById('toggle-'+key).classList.toggle('active', layerActive[key]);
    document.getElementById('toggle-'+key).classList.toggle('inactive',!layerActive[key]);
};
window.setAllLayers=(show)=>{
    ['ri','green','yellow','red'].forEach(k=>{if(layerActive[k]!==show)toggleLayer(k);});
};

// ── POPUP BUILDERS ────────────────────────────────────────────────────────
const JENIS_OPTIONS=['Masjid','Gereja Protestan','Gereja Katolik','Vihara','Pura','Kelenteng'];

function buildPopupRI(ri, r) {
    const pct=r.kk>0?Math.round(r.sudah/r.kk*100):0;
    const borderCol=jenisBorderColor[ri.jenis]||'#64748b';
    const isMyRI=(ROLE==='koordinator'&&ri.id===MY_RI_ID);
    const myBadge=isMyRI?`<span class="ml-1 text-[9px] bg-green-100 text-green-700 font-bold px-1.5 py-0.5 rounded-full">RI Anda</span>`:'';

    const radiusSlider=IS_ADMIN?`
        <div class="border-t pt-2 mb-2">
            <label class="text-[10px] font-bold uppercase text-slate-500">
                Radius Individual: <span id="val-${ri.id}" class="text-blue-600">${ri.radius}</span>m
            </label>
            <input type="range" min="100" max="2000" step="50" value="${ri.radius}"
                class="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer mt-1 accent-blue-600"
                oninput="previewRadius(${ri.id},this.value)" onchange="saveRadius(${ri.id},this.value)">
        </div>`:`<div class="text-[10px] text-slate-400 border-t pt-2 mb-2">Radius: <b class="text-blue-600">${ri.radius}m</b></div>`;

    const actionBtns=IS_ADMIN?`
        <div class="flex gap-1 border-t pt-2">
            <button onclick="showEditRI(${ri.id},'${escQ(ri.nama)}','${escQ(ri.jenis||'')}','${escQ(ri.alamat)}')"
                class="flex-1 bg-yellow-400 hover:bg-yellow-500 text-white text-xs font-bold py-1 rounded">&#9999; Edit</button>
            <button onclick="deleteRI(${ri.id},'${escQ(ri.nama)}')"
                class="flex-1 bg-red-500 hover:bg-red-600 text-white text-xs font-bold py-1 rounded">&#128465; Hapus</button>
        </div>`:'';

    const div=document.createElement('div'); div.className='p-2 w-60'; div.style.minWidth='240px';
    div.innerHTML=`
        <div class="flex items-center gap-2 mb-1">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;flex-shrink:0"><span style="width:13px;height:13px;background:white;border:2.5px solid ${borderCol};border-radius:50% 50% 50% 0;transform:rotate(-45deg);display:inline-block;box-shadow:0 1px 3px rgba(0,0,0,0.3)"></span></span>
            <div><h3 class="font-bold text-blue-700 text-sm leading-tight">${ri.nama}${myBadge}</h3>
            <span class="text-[10px] text-blue-400">${ri.jenis||''}</span></div>
        </div>
        <p class="text-[11px] text-slate-600 mb-1">${ri.alamat}</p>
        ${ri.koordinator_nama
            ? `<div class="text-xs mb-2 text-slate-600"><b>Koordinator:</b> ${ri.koordinator_nama}${ri.koordinator_wa ? `<br><b>WA:</b> <a href="https://wa.me/${ri.koordinator_wa.replace(/\D/g,'')}" target="_blank" class="text-green-600 hover:underline">${ri.koordinator_wa}</a>` : ''}</div>`
            : `<div class="text-xs mb-2 text-slate-400 italic">Belum ada koordinator terdaftar.</div>`
        }
        <div class="bg-blue-50 rounded-lg p-2 mb-2 text-[11px] border border-blue-100">
            <div class="font-bold text-blue-700 mb-1">Ringkasan Coverage</div>
            <div class="grid grid-cols-2 gap-x-2 gap-y-0.5">
                <span class="text-slate-500">Total KK:</span>     <span class="font-semibold">${r.kk} KK</span>
                <span class="text-slate-500">Total Jiwa:</span>   <span class="font-semibold">${r.jiwa} jiwa</span>
                <span class="text-slate-500">Sudah Terima:</span> <span class="font-semibold text-green-600">${r.sudah} KK</span>
                <span class="text-slate-500">Belum Terima:</span> <span class="font-semibold text-amber-600">${r.belum} KK</span>
            </div>
            ${r.kk>0?`<div class="mt-1.5"><div class="flex justify-between text-[10px] mb-0.5"><span>Distribusi bulan ini</span><span class="font-bold text-green-600">${pct}%</span></div><div class="w-full bg-slate-200 rounded-full h-1.5"><div class="bg-green-500 h-1.5 rounded-full" style="width:${pct}%"></div></div></div>`:''}
        </div>
        ${radiusSlider}
        ${actionBtns}`;
    return div;
}

function buildPopupPM(p) {
    const covered=p.id_rumah_ibadah!=null, sudah=p.status_bantuan==='sudah';
    const statusHtml=!covered
        ?`<span class="text-red-600 font-bold">Diluar Jangkauan</span>`
        :sudah?`<span class="text-green-600 font-bold">Sudah Terima &middot; ${p.nama_cover}</span>`
              :`<span class="text-amber-600 font-bold">Belum Terima &middot; ${p.nama_cover}</span>`;
    const canMarkSudah = covered && !sudah && (IS_ADMIN || (ROLE === 'koordinator' && p.id_rumah_ibadah == MY_RI_ID));
    const canMarkBelum = covered && sudah && IS_ADMIN;
    const toggleBtn = canMarkSudah
        ? `<button onclick="openModalSudah(${p.id})"
            class="w-full mt-2 bg-green-500 hover:bg-green-600 text-white text-xs font-bold py-1.5 rounded-lg">
            &#10003; Tandai Sudah Terima
        </button>`
        : canMarkBelum
        ? `<button onclick="toggleStatus(${p.id},'belum')"
            class="w-full mt-2 bg-amber-400 hover:bg-amber-500 text-white text-xs font-bold py-1.5 rounded-lg">
            &#8629; Batalkan (Tandai Belum Terima)
        </button>`
        : '';
    const actionBtns=IS_ADMIN?`
        <div class="flex gap-1 border-t pt-2 mt-2">
            <button onclick="showEditPM(${p.id},'${escQ(p.nama_kepala)}',${p.jumlah_anggota},'${escQ(p.foto_rumah||"")}','${escQ(p.alamat||"")}' )"
                class="flex-1 bg-yellow-400 hover:bg-yellow-500 text-white text-xs font-bold py-1 rounded">&#9999; Edit</button>
            <button onclick="deletePM(${p.id},'${escQ(p.nama_kepala)}')"
                class="flex-1 bg-red-500 hover:bg-red-600 text-white text-xs font-bold py-1 rounded">&#128465; Hapus</button>
        </div>`:'';
    const fotoRumah=p.foto_rumah?`<div class="my-1.5"><img src="uploads/foto_rumah/${p.foto_rumah}" class="w-full h-24 object-cover rounded-lg border border-slate-200 cursor-pointer" onclick="window.open('uploads/foto_rumah/${p.foto_rumah}','_blank')" title="Klik untuk perbesar"></div>`:'';
    const alamatHtml = p.alamat
        ? `<p class="text-[10px] text-slate-400 leading-tight mb-1">&#128205; ${p.alamat}</p>` : '';
    const div=document.createElement('div'); div.className='p-2 w-52'; div.style.minWidth='210px';
    div.innerHTML=`
        <h3 class="font-bold text-slate-800 mb-0.5">KK: ${p.nama_kepala}</h3>
        ${alamatHtml}
        ${fotoRumah}
        <p class="text-xs text-slate-500">Anggota: ${p.jumlah_anggota} jiwa</p>
        <div class="mt-1 mb-1 text-[11px]">${statusHtml}</div>
        ${toggleBtn}
        <button onclick="openModalHistori(${p.id},'${escQ(p.nama_kepala)}')"
            class="w-full mt-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 text-[11px] font-bold py-1 rounded-lg border border-blue-200 transition">
            &#128203; Lihat Histori Bantuan
        </button>
        ${actionBtns}`;
    return div;
}

function escQ(s)    { return (s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;').replace(/\r?\n/g,' '); }
function escAttr(s) { return (s||'').replace(/"/g,'&quot;').toLowerCase(); }

// ── RADIUS PER-RI ─────────────────────────────────────────────────────────
window.previewRadius=(id,val)=>{ const el=document.getElementById('val-'+id);if(el)el.innerText=val;if(circles[id])circles[id].setRadius(val); };
window.saveRadius=(id,val)=>{ const fd=new FormData();fd.append('id',id);fd.append('radius',val);fetch('api.php?action=update_radius',{method:'POST',body:fd}).then(()=>loadData()); };

// ── TOGGLE STATUS & RESET ─────────────────────────────────────────────────
window.toggleStatus=(id,st)=>{ const fd=new FormData();fd.append('id',id);fd.append('status',st);fetch('api.php?action=toggle_status',{method:'POST',body:fd}).then(()=>{map.closePopup();loadData();}); };
window.resetBulanan=()=>{ if(!confirm('Reset semua status distribusi bulan ini?'))return;fetch('api.php?action=reset_bulanan',{method:'POST'}).then(()=>loadData()); };

// ── EDIT / HAPUS RI ───────────────────────────────────────────────────────
window.showEditRI=(id,nama,jenis,alamat)=>{
    map.closePopup();
    const marker = markersRI[id];
    if (!marker) return;
    const selHtml=`<select id="eri_jenis" class="w-full mb-1 p-1 text-xs border rounded">${JENIS_OPTIONS.map(o=>`<option value="${o}"${o===jenis?' selected':''}>${o}</option>`).join('')}</select>`;
    marker.bindPopup(`
        <div class="w-64 p-1" style="min-width:250px">
            <h3 class="font-bold mb-2 border-b text-sm text-blue-700">&#9999; Edit Rumah Ibadah</h3>
            <input id="eri_nama"  value="${nama}"  placeholder="Nama RI"  class="w-full mb-1 p-1 text-xs border rounded">
            ${selHtml}
            <textarea id="eri_alamat" rows="2" class="w-full mb-1 p-1 text-xs border rounded">${alamat}</textarea>
            <p class="text-[10px] text-slate-400 italic mb-1">Info koordinator dikelola melalui menu Kelola User.</p>
            <div class="flex gap-1 mt-2">
                <button onclick="saveEditRI(${id})" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white p-1 rounded text-xs font-bold">Simpan</button>
                <button onclick="cancelEditRI(${id})"  class="flex-1 bg-slate-400 text-white p-1 rounded text-xs font-bold">Batal</button>
            </div>
        </div>`,{maxWidth:280,minWidth:260,keepInView:true,autoPanPaddingTopLeft:L.point(10,80)});
    marker.off('popupclose'); // bersihkan listener lama sebelum daftar baru
    marker.once('popupclose', () => {
        const ri = cachedRI.find(r => r.id == id);
        if (!ri) return;
        const rk = {};
        cachedPM.forEach(p => {
            if (p.id_rumah_ibadah && !rk[p.id_rumah_ibadah]) rk[p.id_rumah_ibadah]={kk:0,jiwa:0,sudah:0,belum:0};
            if (p.id_rumah_ibadah && rk[p.id_rumah_ibadah]) {
                const r=rk[p.id_rumah_ibadah];
                r.kk++; r.jiwa+=parseInt(p.jumlah_anggota)||0;
                p.status_bantuan==='sudah'?r.sudah++:r.belum++;
            }
        });
        marker.bindPopup(()=>buildPopupRI(ri, rk[id]||{kk:0,jiwa:0,sudah:0,belum:0}), {minWidth:240, maxWidth:280, keepInView:true, autoPanPaddingTopLeft:L.point(10,80)});
    });
    marker.openPopup();
};
window.saveEditRI=(id)=>{
    const fd=new FormData();fd.append('id',id);
    fd.append('nama',document.getElementById('eri_nama').value);
    fd.append('jenis',document.getElementById('eri_jenis').value);
    fd.append('alamat',document.getElementById('eri_alamat').value);
    fetch('api.php?action=edit_ri',{method:'POST',body:fd}).then(()=>{map.closePopup();loadData();});
};
window.cancelEditRI=(id)=>{
    const marker = markersRI[id];
    if (!marker) { map.closePopup(); return; }
    const ri = cachedRI.find(r => r.id == id);
    if (ri) {
        const rk = {};
        cachedPM.forEach(p => {
            if (p.id_rumah_ibadah && !rk[p.id_rumah_ibadah]) rk[p.id_rumah_ibadah]={kk:0,jiwa:0,sudah:0,belum:0};
            if (p.id_rumah_ibadah && rk[p.id_rumah_ibadah]) {
                const r=rk[p.id_rumah_ibadah];
                r.kk++; r.jiwa+=parseInt(p.jumlah_anggota)||0;
                p.status_bantuan==='sudah'?r.sudah++:r.belum++;
            }
        });
        marker.off('popupclose');
        marker.bindPopup(()=>buildPopupRI(ri, rk[id]||{kk:0,jiwa:0,sudah:0,belum:0}), {minWidth:240, maxWidth:280, keepInView:true, autoPanPaddingTopLeft:L.point(10,80)});
    }
    map.closePopup();
};
window.deleteRI=(id,nama)=>{
    if(!confirm('Hapus Rumah Ibadah "'+nama+'"?\nCoverage PM akan dihitung ulang.'))return;
    const fd=new FormData();fd.append('id',id);
    fetch('api.php?action=delete_ri',{method:'POST',body:fd}).then(()=>{map.closePopup();loadData();});
};

// ── EDIT / HAPUS PM ───────────────────────────────────────────────────────
window.showEditPM=(id,nama,jumlah,fotoRumah,alamat)=>{
    map.closePopup();
    const marker = markersPM[id];
    if (!marker) return;
    const fotoHtml=fotoRumah?`<div class="mb-1"><img src="uploads/foto_rumah/${fotoRumah}" class="w-full h-20 object-cover rounded border border-slate-200 cursor-pointer" onclick="window.open('uploads/foto_rumah/${fotoRumah}','_blank')"><p class="text-[9px] text-slate-400 mt-0.5">Foto saat ini</p></div>`:'';
    marker.bindPopup(`
        <div class="w-56 p-1" style="min-width:220px">
            <h3 class="font-bold mb-2 border-b text-sm text-slate-700">&#9999; Edit Penduduk</h3>
            <input id="epm_nama"   type="text"   value="${nama}"   class="w-full mb-1 p-1 text-xs border rounded" placeholder="Nama Kepala Keluarga">
            <input id="epm_jumlah" type="number" value="${jumlah}" class="w-full mb-1 p-1 text-xs border rounded" placeholder="Jumlah Anggota">
            <textarea id="epm_alamat" rows="2" class="w-full mb-1 p-1 text-xs border rounded resize-none" placeholder="Alamat lengkap">${alamat}</textarea>
            ${fotoHtml}
            <label class="text-[10px] text-slate-500 block mb-0.5">${fotoRumah?'Ganti':'Tambah'} Foto Rumah (opsional)</label>
            <input type="file" id="epm_foto" accept="image/*" class="w-full text-[10px] p-0.5 border border-slate-300 rounded bg-white mb-1">
            <div class="flex gap-1 mt-2">
                <button onclick="saveEditPM(${id})" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white p-1 rounded text-xs font-bold">Simpan</button>
                <button onclick="cancelEditPM(${id})" class="flex-1 bg-slate-400 text-white p-1 rounded text-xs font-bold">Batal</button>
            </div>
        </div>`,{maxWidth:270,minWidth:255,keepInView:true,autoPanPaddingTopLeft:L.point(10,80)});
    marker.off('popupclose'); // bersihkan listener lama
    marker.once('popupclose', () => {
        const p = cachedPM.find(x => x.id == id); // == bukan === untuk toleransi tipe
        if (p) marker.bindPopup(()=>buildPopupPM(p), {minWidth:210, maxWidth:260, keepInView:true, autoPanPaddingTopLeft:L.point(10,80)});
    });
    marker.openPopup();
};
window.saveEditPM=(id)=>{
    const fd=new FormData();fd.append('id',id);
    fd.append('nama_kepala',   document.getElementById('epm_nama').value);
    fd.append('jumlah_anggota',document.getElementById('epm_jumlah').value);
    fd.append('alamat',        document.getElementById('epm_alamat').value);
    const fotoFile=document.getElementById('epm_foto')?.files[0];
    if(fotoFile)fd.append('foto_rumah',fotoFile);
    fetch('api.php?action=edit_pm',{method:'POST',body:fd}).then(()=>{map.closePopup();loadData();});
};
window.cancelEditPM=(id)=>{
    const marker = markersPM[id];
    if (!marker) { map.closePopup(); return; }
    const p = cachedPM.find(x => x.id == id);
    if (p) {
        marker.off('popupclose');
        marker.bindPopup(()=>buildPopupPM(p), {minWidth:210, maxWidth:260, keepInView:true, autoPanPaddingTopLeft:L.point(10,80)});
    }
    map.closePopup();
};
window.deletePM=(id,nama)=>{
    if(!confirm('Hapus data "'+nama+'"?'))return;
    const fd=new FormData();fd.append('id',id);
    fetch('api.php?action=delete_pm',{method:'POST',body:fd}).then(()=>{map.closePopup();loadData();});
};

// ── NAVIGASI & INPUT BARU ─────────────────────────────────────────────────
window.focusMap=(lat,lng,type,id)=>{
    document.getElementById('global-search').value='';
    document.getElementById('search-results').classList.add('hidden');
    document.getElementById('clear-global').classList.add('hidden');
    if(!sidebarOpen)toggleSidebar();
    map.flyTo([lat,lng],17);
    const t=type==='ri'?markersRI[id]:markersPM[id];
    if(t)setTimeout(()=>t.openPopup(),500);
};

const JENIS_OPTS_HTML=JENIS_OPTIONS.map(j=>`<option value="${j}">${j}</option>`).join('');

// ── ADD MODE ──────────────────────────────────────────────────────────────
let addModeType = null; // 'ri' | 'pm' | null

window.enterAddMode = (type) => {
    if (addModeType === type) { exitAddMode(); return; } // second click = toggle off
    addModeType = type;
    document.getElementById('map').classList.add('map-crosshair');
    const label = type === 'ri' ? 'Rumah Ibadah' : 'Penduduk';
    document.getElementById('add-mode-toast-text').textContent =
        `Klik lokasi pada peta untuk menambah ${label} baru`;
    document.getElementById('add-mode-toast').classList.remove('hidden');
    // Highlight active button
    const ri  = document.getElementById('btn-add-ri');
    const pm  = document.getElementById('btn-add-pm');
    if (ri) ri.classList.toggle('mode-active', type === 'ri');
    if (pm) pm.classList.toggle('mode-active', type === 'pm');
    // Switch to wilayah tab so user sees the toast context
    if (activeTab !== 'wilayah') switchTab('wilayah');
};

window.exitAddMode = () => {
    addModeType = null;
    document.getElementById('map').classList.remove('map-crosshair');
    document.getElementById('add-mode-toast').classList.add('hidden');
    ['ri','pm'].forEach(t => {
        const btn = document.getElementById('btn-add-'+t);
        if (btn) btn.classList.remove('mode-active');
    });
};

// ── TAMBAH DARI FOTO (EXIF GPS) ───────────────────────────────────────────
let _exifMode = null; // tipe yang sedang menunggu: 'ri' | 'pm'

window.triggerExifInput = (type) => {
    _exifMode = type;
    const input = document.getElementById('exif-file-input');
    input.value = ''; // reset supaya change event tetap trigger walau file sama
    input.click();
};

document.getElementById('exif-file-input').addEventListener('change', async function() {
    if (!this.files[0] || !_exifMode) return;
    const file = this.files[0];
    const type = _exifMode;
    _exifMode = null;

    // Tampilkan loading toast sementara proses EXIF
    document.getElementById('add-mode-toast-icon').innerHTML = '&#8987;';
    document.getElementById('add-mode-toast-text').textContent = 'Membaca data GPS dari foto...';
    document.getElementById('add-mode-toast').classList.remove('hidden');

    let gps = null;
    try {
        // exifr.gps() mengembalikan {latitude, longitude} atau null
        gps = await exifr.gps(file);
    } catch(err) {
        gps = null;
    }

    document.getElementById('add-mode-toast').classList.add('hidden');

    if (!gps || !gps.latitude || !gps.longitude) {
        // Tidak ada GPS di foto — fallback ke mode klik peta
        const label = type === 'ri' ? 'Rumah Ibadah' : 'Penduduk';
        const fallback = confirm(
            `Foto ini tidak mengandung data GPS.\n\n` +
            `Kemungkinan penyebab:\n` +
            `• Fitur lokasi kamera dinonaktifkan\n` +
            `• Foto diambil dari screenshot atau kiriman\n\n` +
            `Klik OK untuk beralih ke mode klik peta, atau Batal untuk membatalkan.`
        );
        if (fallback) enterAddMode(type);
        return;
    }

    const {latitude: lat, longitude: lng} = gps;

    // Terbang ke lokasi di peta
    map.flyTo([lat, lng], 18);

    // Reverse geocode lalu buka popup form (sama persis dengan klik peta)
    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
        .then(r => r.json())
        .catch(() => ({display_name: ''}))
        .then(data => {
            const alamat = data.display_name || '';
            const isRI   = type === 'ri';
            // Gunakan popup yang sama dengan klik peta, tapi sertakan foto aslinya di formPM
            // untuk foto_rumah supaya tidak perlu upload ulang
            if (isRI) {
                setTimeout(() => {
                    popupMap.setLatLng([lat,lng]).setContent(`
                        <div style="min-width:260px;width:260px;padding:6px">
                            <h3 class="font-bold mb-1 border-b text-sm text-blue-700">&#128332; Rumah Ibadah Baru</h3>
                            <p class="text-[10px] text-violet-600 mb-2">
                                &#128247; Koordinat dari foto GPS &mdash;
                                <span class="font-mono text-slate-400">${lat.toFixed(5)}, ${lng.toFixed(5)}</span>
                            </p>
                            <input type="text" id="nama_ri" placeholder="Nama Rumah Ibadah *" class="w-full mb-1 p-1 text-xs border rounded">
                            <select id="jenis_ri" class="w-full mb-1 p-1 text-xs border rounded">${JENIS_OPTS_HTML}</select>
                            <textarea id="alamat_form" rows="2" class="w-full mb-1 p-1 text-xs border rounded">${alamat}</textarea>
                            <p class="text-[10px] text-slate-400 italic mb-1">Koordinator ditambahkan melalui menu Kelola User.</p>
                            <button onclick="simpanData(${lat},${lng},'ri')" class="w-full bg-blue-600 text-white p-1.5 rounded text-xs mt-1 font-bold hover:bg-blue-700">&#10003; Simpan Rumah Ibadah</button>
                        </div>`).openOn(map);
                }, 600); // tunggu flyTo selesai sebagian
            } else {
                // Untuk PM: foto yang dipilih bisa langsung dipakai sebagai foto_rumah
                setTimeout(() => {
                    popupMap.setLatLng([lat,lng]).setContent(`
                        <div style="min-width:245px;width:245px;padding:6px">
                            <h3 class="font-bold mb-1 border-b text-sm text-red-700">&#128101; Penduduk Baru</h3>
                            <p class="text-[10px] text-violet-600 mb-2">
                                &#128247; Koordinat dari foto GPS &mdash;
                                <span class="font-mono text-slate-400">${lat.toFixed(5)}, ${lng.toFixed(5)}</span>
                            </p>
                            <input type="text"   id="kepala"  placeholder="Nama Kepala Keluarga *" class="w-full mb-1 p-1 text-xs border rounded">
                            <input type="number" id="anggota" placeholder="Jumlah Anggota KK *"    class="w-full mb-1 p-1 text-xs border rounded">
                            <textarea id="alamat_pm" rows="2" class="w-full mb-1 p-1 text-xs border rounded">${alamat}</textarea>
                            <label class="text-[10px] text-slate-500 block mt-1 mb-0.5">
                                Foto Rumah
                                <span class="text-violet-600 font-semibold">(terisi otomatis dari foto GPS &#10003;)</span>
                            </label>
                            <input type="file" id="foto_rumah_input" accept="image/*" class="w-full text-[10px] p-0.5 border border-slate-200 rounded bg-slate-50">
                            <button onclick="simpanDataFromExif(${lat},${lng},'pm')" class="w-full bg-red-600 text-white p-1.5 rounded text-xs mt-2 font-bold hover:bg-red-700">&#10003; Simpan Penduduk</button>
                        </div>`).openOn(map);
                    // Auto-transfer foto EXIF ke input file PM
                    setTimeout(() => {
                        const pmInput = document.getElementById('foto_rumah_input');
                        if (pmInput) {
                            const dt = new DataTransfer();
                            dt.items.add(file);
                            pmInput.files = dt.files;
                        }
                    }, 700);
                }, 600);
            }
        });
});

// Simpan data PM dari EXIF: pakai foto_rumah_input (sudah di-autofill atau bisa diganti)
window.simpanDataFromExif = (lat, lng, type) => {
    window.simpanData(lat, lng, type);
};

let popupMap=L.popup({minWidth:260,maxWidth:300,keepInView:true,autoPanPaddingTopLeft:L.point(10,80)});

if (IS_ADMIN) {
map.on('click',function(e){
    const {lat,lng}=e.latlng;

    // Tentukan tipe: dari add mode (jika aktif) atau biarkan user pilih
    const forcedType = addModeType; // simpan sebelum exitAddMode membersihkannya
    if (addModeType) exitAddMode(); // keluar mode, hapus crosshair & toast

    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
    .then(r=>r.json()).then(data=>{
        const alamat=data.display_name||'';
        // Jika ada forcedType: sembunyikan select tipe, tampilkan judul spesifik
        const isRI = forcedType === 'ri' || !forcedType;
        const isPM = forcedType === 'pm';
        const typeSelectHtml = forcedType
            ? `<div class="mb-2 text-xs font-bold text-slate-700">${forcedType==='ri'?'🕌 Rumah Ibadah Baru':'👤 Penduduk Baru'}</div>`
            : `<select id="inputType" class="w-full mb-2 p-1 text-xs border rounded" onchange="toggleForm()">
                   <option value="ri"${!isPM?' selected':''}>Rumah Ibadah</option>
                   <option value="pm"${isPM?' selected':''}>Penduduk</option>
               </select>`;
        popupMap.setLatLng(e.latlng).setContent(`
            <div style="min-width:256px;width:256px;padding:4px">
                <h3 class="font-bold mb-2 border-b text-sm">Input Data Baru</h3>
                ${typeSelectHtml}
                <div id="formRI"${isPM?' class="hidden"':''}>
                    <input type="text" id="nama_ri" placeholder="Nama Rumah Ibadah" class="w-full mb-1 p-1 text-xs border rounded">
                    <select id="jenis_ri" class="w-full mb-1 p-1 text-xs border rounded">${JENIS_OPTS_HTML}</select>
                    <textarea id="alamat_form" rows="2" class="w-full mb-1 p-1 text-xs border rounded">${alamat}</textarea>
                    <p class="text-[10px] text-slate-400 italic mb-1">Koordinator ditambahkan melalui menu Kelola User.</p>
                </div>
                <div id="formPM"${!isPM?' class="hidden"':''}>
                    <input type="text"   id="kepala"  placeholder="Nama Kepala Keluarga" class="w-full mb-1 p-1 text-xs border rounded">
                    <input type="number" id="anggota" placeholder="Jumlah Anggota KK"    class="w-full mb-1 p-1 text-xs border rounded">
                    <textarea id="alamat_pm" rows="2" class="w-full mb-1 p-1 text-xs border rounded">${alamat}</textarea>
                    <label class="text-[10px] text-slate-500 block mt-1 mb-0.5">Foto Rumah (opsional)</label>
                    <input type="file" id="foto_rumah_input" accept="image/*" class="w-full text-[10px] p-0.5 border border-slate-300 rounded bg-white">
                </div>
                <button onclick="simpanData(${lat},${lng},'${forcedType||''}')" class="w-full bg-blue-600 text-white p-1 rounded text-xs mt-2 font-bold hover:bg-blue-700">SIMPAN DATA</button>
            </div>`).openOn(map);
    });
});
} // end IS_ADMIN

window.toggleForm=()=>{
    const sel=document.getElementById('inputType');
    if(!sel)return; // forced type mode — select tidak ada
    const t=sel.value;
    document.getElementById('formRI').classList.toggle('hidden',t!=='ri');
    document.getElementById('formPM').classList.toggle('hidden',t!=='pm');
};

// forcedType: 'ri' | 'pm' | '' (kosong = baca dari select)
window.simpanData=(lat,lng,forcedType='')=>{
    const sel=document.getElementById('inputType');
    const t=forcedType||(sel?sel.value:'ri');
    const fd=new FormData();fd.append('lat',lat);fd.append('lng',lng);
    const url=t==='ri'?'api.php?action=tambah_ri':'api.php?action=tambah_penduduk';
    if(t==='ri'){
        const nama=document.getElementById('nama_ri').value.trim();
        if(!nama){alert('Nama Rumah Ibadah wajib diisi.');return;}
        fd.append('nama',nama);fd.append('jenis',document.getElementById('jenis_ri').value);
        fd.append('alamat',document.getElementById('alamat_form').value);
    }else{
        const kepala=document.getElementById('kepala').value.trim();
        const anggota=parseInt(document.getElementById('anggota').value)||0;
        if(!kepala){alert('Nama Kepala Keluarga wajib diisi.');return;}
        if(anggota<1){alert('Jumlah anggota harus minimal 1.');return;}
        fd.append('nama_kepala',kepala);
        fd.append('jumlah_anggota',anggota);
        // Alamat dari hasil reverse geocoding disimpan di hidden field
        const alamatPm = document.getElementById('alamat_pm')?.value || '';
        fd.append('alamat', alamatPm);
        const fotoRumah=document.getElementById('foto_rumah_input')?.files[0];
        if(fotoRumah)fd.append('foto_rumah',fotoRumah);
    }
    fetch(url,{method:'POST',body:fd}).then(()=>{map.closePopup();loadData();});
};

// ── MODAL: TANDAI SUDAH ───────────────────────────────────────────────────
let _modalPmId=null;
window.openModalSudah=(id)=>{
    _modalPmId=id; map.closePopup();
    document.getElementById('modal-foto-bukti').value='';
    document.getElementById('modal-keterangan').value='';
    document.getElementById('modal-sudah-loading').classList.add('hidden');
    document.getElementById('modal-sudah').classList.remove('hidden');
};
window.closeModalSudah=()=>{document.getElementById('modal-sudah').classList.add('hidden');};
window.submitTandaiSudah=()=>{
    const fotoFile=document.getElementById('modal-foto-bukti').files[0];
    if(!fotoFile){alert('Foto bukti penyaluran wajib diupload.');return;}
    const loading=document.getElementById('modal-sudah-loading');
    loading.classList.remove('hidden');
    const fd=new FormData();
    fd.append('id',_modalPmId);fd.append('foto_bukti',fotoFile);
    fd.append('keterangan',document.getElementById('modal-keterangan').value);
    fetch('api.php?action=tandai_sudah',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(res=>{
            loading.classList.add('hidden');
            if(res.status==='success'){closeModalSudah();historiLoaded=false;loadData();}
            else alert('Gagal: '+(res.message||'Terjadi kesalahan.'));
        })
        .catch(()=>{loading.classList.add('hidden');alert('Gagal terhubung ke server.');});
};

// ── MODAL: HISTORI PER-KK ─────────────────────────────────────────────────
window.openModalHistori=(id,nama)=>{
    map.closePopup();
    document.getElementById('modal-histori-title').textContent=`Histori — ${nama}`;
    const body=document.getElementById('modal-histori-body');
    body.innerHTML='<div class="text-center text-slate-400 py-6">&#8987; Memuat histori...</div>';
    document.getElementById('modal-histori').classList.remove('hidden');
    fetch(`api.php?action=get_histori&id_pm=${id}`).then(r=>r.json()).then(rows=>{
        if(!rows.length){body.innerHTML='<div class="text-center text-slate-400 py-8 italic">Belum ada histori bantuan tercatat.</div>';return;}
        const BULAN_ID=['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        body.innerHTML=rows.map(h=>{
            const tgl=new Date(h.tanggal_penyaluran).toLocaleDateString('id-ID',{day:'numeric',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'});
            const ket=h.keterangan?`<div class="text-[10px] text-slate-500 mt-0.5 italic">${h.keterangan}</div>`:'';
            return `<div class="flex gap-2.5 bg-white border border-slate-200 rounded-xl p-2.5 shadow-sm">
                <img src="uploads/foto_bukti/${h.foto_bukti}" class="w-16 h-16 object-cover rounded-lg border border-slate-200 flex-shrink-0 cursor-pointer" onclick="window.open('uploads/foto_bukti/${h.foto_bukti}','_blank')" onerror="this.style.display='none'" title="Klik untuk perbesar">
                <div class="min-w-0">
                    <div class="font-bold text-blue-700 text-xs leading-tight">${BULAN_ID[h.bulan]} ${h.tahun}</div>
                    <div class="text-[10px] text-slate-400">${tgl}</div>
                    <div class="text-[10px] text-slate-500 mt-0.5">via <span class="font-semibold">${h.nama_ri||'—'}</span></div>
                    ${ket}
                </div>
            </div>`;
        }).join('');
    }).catch(()=>{body.innerHTML='<div class="text-center text-red-400 py-6">Gagal memuat data.</div>';});
};
window.closeModalHistori=()=>{document.getElementById('modal-histori').classList.add('hidden');};

// ── MODAL: KELOLA USER ────────────────────────────────────────────────────
<?php if ($is_admin): ?>
window.openModalUser=()=>{
    document.getElementById('modal-user').classList.remove('hidden');
    showUserList(); loadUsers();
};
window.closeModalUser=()=>{document.getElementById('modal-user').classList.add('hidden');};

window.showUserList=()=>{
    document.getElementById('user-view-list').classList.add('active');
    document.getElementById('user-view-form').classList.remove('active');
    document.getElementById('modal-user-title').textContent='Kelola Pengguna';
    document.getElementById('modal-user-subtitle').textContent='Koordinator & Pengambil Kebijakan';
};
// Toggle visibilitas field RI berdasarkan role yang dipilih
window.onRoleChange=()=>{
    const role=document.getElementById('uf_role').value;
    document.getElementById('uf_ri_wrapper').style.display =
        role==='koordinator' ? '' : 'none';
};

window.showUserForm=(user)=>{
    document.getElementById('user-view-list').classList.remove('active');
    document.getElementById('user-view-form').classList.add('active');
    // Populate RI select
    const sel=document.getElementById('uf_ri');
    sel.innerHTML='<option value="">— Pilih Rumah Ibadah —</option>'+
        cachedRI.map(ri=>`<option value="${ri.id}">${ri.nama}${ri.jenis?' ('+ri.jenis+')':''}</option>`).join('');

    if(user){
        const role=user.role||'koordinator';
        document.getElementById('uf_id').value=user.id;
        document.getElementById('uf_role').value=role;
        document.getElementById('uf_nama').value=user.nama_lengkap||'';
        document.getElementById('uf_no_wa').value=user.no_wa||'';
        document.getElementById('uf_username').value=user.username;
        document.getElementById('uf_password').value='';
        document.getElementById('uf_ri').value=user.id_rumah_ibadah||'';
        document.getElementById('uf_pw_hint').textContent='(kosongkan jika tidak diubah)';
        document.getElementById('user-form-title').textContent='Edit Pengguna';
        document.getElementById('modal-user-title').textContent='Edit Pengguna';
        document.getElementById('modal-user-subtitle').textContent='Perbarui data akun';
        onRoleChange();
    }else{
        document.getElementById('uf_id').value='';
        document.getElementById('uf_role').value='koordinator';
        document.getElementById('uf_nama').value='';
        document.getElementById('uf_no_wa').value='';
        document.getElementById('uf_username').value='';
        document.getElementById('uf_password').value='';
        document.getElementById('uf_ri').value='';
        document.getElementById('uf_pw_hint').textContent='(wajib diisi)';
        document.getElementById('user-form-title').textContent='Tambah Pengguna Baru';
        document.getElementById('modal-user-title').textContent='Tambah Pengguna';
        document.getElementById('modal-user-subtitle').textContent='Buat akun koordinator atau pengambil kebijakan';
        onRoleChange();
    }
    document.getElementById('uf_error').classList.add('hidden');
};

window.loadUsers=()=>{
    const tbody=document.getElementById('user-table-body');
    tbody.innerHTML='<div class="text-center text-slate-400 text-xs py-6">Memuat...</div>';
    fetch('api.php?action=get_users').then(r=>r.json()).then(users=>{
        const kCount=users.filter(u=>u.role==='koordinator').length;
        const pkCount=users.filter(u=>u.role==='pengambil_kebijakan').length;
        document.getElementById('user-list-count').textContent=
            `${users.length} pengguna (${kCount} koordinator, ${pkCount} pengambil kebijakan)`;
        if(!users.length){tbody.innerHTML='<div class="text-center text-slate-400 text-xs py-8 italic">Belum ada pengguna terdaftar.<br><span class="text-[10px]">Klik &quot;+ Tambah Pengguna&quot; di atas.</span></div>';return;}
        tbody.innerHTML=users.map(u=>{
            const isKoord=u.role==='koordinator';
            const roleBadge=isKoord
                ?`<span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[9px] font-bold bg-blue-100 text-blue-700">Koordinator</span>`
                :`<span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[9px] font-bold bg-purple-100 text-purple-700">Pengambil Kebijakan</span>`;
            const avatarColor=isKoord?'bg-blue-100 text-blue-600':'bg-purple-100 text-purple-600';
            const riLabel=isKoord
                ?(u.nama_ri?`<div class="text-[10px] text-blue-600 font-medium truncate">&#128332; ${u.nama_ri}</div>`:`<div class="text-[10px] text-amber-600 italic font-medium">&#9888; Belum ditugaskan ke RI</div>`)
                :'';
            const waLabel=u.no_wa?`<div class="text-[10px] text-slate-400">&#128222; ${u.no_wa}</div>`:'';
            const initial=(u.nama_lengkap||u.username).charAt(0).toUpperCase();
            return `
            <div class="flex items-center gap-3 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5">
                <div class="w-9 h-9 rounded-full ${avatarColor} flex items-center justify-center text-sm font-bold flex-shrink-0">${initial}</div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1.5 mb-0.5">
                        <span class="text-xs font-bold text-slate-800 truncate">${u.nama_lengkap||'—'}</span>
                        ${roleBadge}
                    </div>
                    <div class="text-[10px] text-slate-400">@${u.username}</div>
                    ${waLabel}
                    ${riLabel}
                </div>
                <div class="flex gap-1 flex-shrink-0">
                    <button onclick='showUserForm(${JSON.stringify(u)})'
                        class="w-8 h-8 flex items-center justify-center rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-600 transition" title="Edit">&#9999;</button>
                    <button onclick="deleteUser(${u.id},'${escQ(u.nama_lengkap||u.username)}')"
                        class="w-8 h-8 flex items-center justify-center rounded-lg bg-red-50 hover:bg-red-100 text-red-600 transition" title="Hapus">&#128465;</button>
                </div>
            </div>`;
        }).join('');
    }).catch(()=>{tbody.innerHTML='<div class="text-center text-red-400 text-xs py-6">Gagal memuat data.</div>';});
};

window.submitUserForm=()=>{
    const id=document.getElementById('uf_id').value;
    const role=document.getElementById('uf_role').value;
    const nama=document.getElementById('uf_nama').value.trim();
    const no_wa=document.getElementById('uf_no_wa').value.trim();
    const username=document.getElementById('uf_username').value.trim();
    const password=document.getElementById('uf_password').value;
    const ri=document.getElementById('uf_ri').value;
    const errEl=document.getElementById('uf_error');
    if(!nama||!username){errEl.textContent='Nama dan username wajib diisi.';errEl.classList.remove('hidden');return;}
    if(!id&&!password){errEl.textContent='Password wajib diisi untuk akun baru.';errEl.classList.remove('hidden');return;}
    if(password&&password.length<6){errEl.textContent='Password minimal 6 karakter.';errEl.classList.remove('hidden');return;}
    // RI wajib hanya untuk koordinator
    if(role==='koordinator'&&!ri){errEl.textContent='Rumah ibadah yang dikelola wajib dipilih untuk koordinator.';errEl.classList.remove('hidden');return;}
    errEl.classList.add('hidden');
    const fd=new FormData();
    if(id)fd.append('id',id);
    fd.append('role',role);
    fd.append('nama_lengkap',nama);
    fd.append('no_wa',no_wa);
    fd.append('username',username);
    if(password)fd.append('password',password);
    if(role==='koordinator')fd.append('id_rumah_ibadah',ri);
    fetch(`api.php?action=${id?'edit_user':'tambah_user'}`,{method:'POST',body:fd})
        .then(r=>r.json())
        .then(res=>{
            if(res.status==='success'){showUserList();loadUsers();}
            else{errEl.textContent=res.message||'Terjadi kesalahan.';errEl.classList.remove('hidden');}
        })
        .catch(()=>{errEl.textContent='Gagal terhubung ke server.';errEl.classList.remove('hidden');});
};
window.deleteUser=(id,nama)=>{
    if(!confirm(`Hapus akun pengguna "${nama}"?`))return;
    const fd=new FormData();fd.append('id',id);
    fetch('api.php?action=delete_user',{method:'POST',body:fd}).then(r=>r.json()).then(()=>loadUsers());
};
<?php endif; ?>

// ── GEOCODING QUEUE ───────────────────────────────────────────────────
<?php if ($is_admin): ?>
window.loadGecodingQueue = () => {
    fetch('api.php?action=get_geocoding_queue')
        .then(r => r.json())
        .then(rows => {
            const sec   = document.getElementById('section-antrean');
            const list  = document.getElementById('list-antrean');
            const count = document.getElementById('count-antrean');
            if (!rows.length) { sec.classList.add('hidden'); return; }
            sec.classList.remove('hidden');
            count.textContent = rows.length;
            list.innerHTML = rows.map(r => `
                <div class="group relative flex items-center gap-2 bg-white rounded-lg border border-amber-200 px-2 py-1.5 hover:border-amber-300 transition">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-semibold text-slate-800 truncate pr-5">${r.nama}${r.tipe==='ri' ? ' <span class=\"text-amber-500\">(RI)</span>' : ''}</div>
                        <div class="text-[10px] text-slate-400 truncate">${r.alamat||'Alamat tidak tersedia'}</div>
                    </div>
                    <button onclick="openBidikMode(${r.id},'${escQ(r.nama)}','${r.tipe}')"
                        class="flex-shrink-0 text-[10px] font-bold px-2 py-1 bg-amber-500 hover:bg-amber-600 text-white rounded-lg transition whitespace-nowrap">
                        &#127919; Bidik
                    </button>
                    <!-- Tombol hapus, muncul saat hover -->
                    <button onclick="hapusAntrean(${r.id},'${escQ(r.nama)}','${r.tipe}')"
                        class="absolute top-1 right-1 opacity-0 group-hover:opacity-100 transition-opacity
                               w-4 h-4 flex items-center justify-center rounded-full
                               bg-red-100 hover:bg-red-500 text-red-500 hover:text-white text-[9px] font-bold leading-none"
                        title="Hapus data ini">&#10005;</button>
                </div>`).join('');
        });
};

window.hapusAntrean = (id, nama, tipe) => {
    if (!confirm(`Hapus data "${nama}"?\nData ini akan dihapus permanen dari database.`)) return;
    const fd = new FormData(); fd.append('id', id);
    const endpoint = tipe === 'ri' ? 'delete_ri' : 'delete_pm';
    fetch(`api.php?action=${endpoint}`, {method:'POST', body:fd})
        .then(r => r.json())
        .then(() => { loadData(); loadGecodingQueue(); });
};

// ── BIDIK MODE ────────────────────────────────────────────────────────
let _bidikPmId = null;
let _bidikTipe = 'penduduk';

window.openBidikMode = (id, nama, tipe) => {
    _bidikPmId = id;
    _bidikTipe = tipe || 'penduduk';
    document.getElementById('map').classList.add('map-crosshair');
    document.getElementById('add-mode-toast-icon').innerHTML = '&#127919;';
    document.getElementById('add-mode-toast-text').textContent = `Bidik lokasi: ${nama}`;
    document.getElementById('add-mode-toast').classList.remove('hidden');
    // Pastikan tab wilayah terbuka supaya user bisa lihat antrian
    if (activeTab !== 'wilayah') switchTab('wilayah');
};

window.cancelBidikMode = () => {
    _bidikPmId = null;
    _bidikTipe = 'penduduk';
    document.getElementById('map').classList.remove('map-crosshair');
    document.getElementById('add-mode-toast').classList.add('hidden');
};

// Bidik click handler — harus setelah map sudah dibuat
map.on('click', function(e) {
    if (!_bidikPmId) return;
    const {lat, lng} = e.latlng;
    const id = _bidikPmId;
    const tipe = _bidikTipe;
    cancelBidikMode();
    const fd = new FormData();
    fd.append('id', id); fd.append('lat', lat); fd.append('lng', lng); fd.append('tipe', tipe);
    fetch('api.php?action=update_lokasi', {method:'POST', body:fd})
        .then(r => r.json())
        .then(() => { loadData(); loadGecodingQueue(); });
});

// Escape key cancels bidik mode too
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (_bidikPmId) cancelBidikMode();
    else if (addModeType) exitAddMode();
});

// ── IMPORT CSV ────────────────────────────────────────────────────────
window.openModalImport = () => {
    document.getElementById('modal-import').classList.remove('hidden');
    document.getElementById('import-upload-area').classList.remove('hidden');
    document.getElementById('import-progress-area').classList.add('hidden');
    document.getElementById('import-done-area').classList.add('hidden');
    document.getElementById('csv-file-input').value = '';
    document.getElementById('csv-file-name').classList.add('hidden');
    document.getElementById('btn-start-import').disabled = true;
    setImportType('penduduk'); // reset ke default
};
window.closeModalImport = () => { document.getElementById('modal-import').classList.add('hidden'); };

let _importType = 'penduduk';
window.setImportType = (type) => {
    _importType = type;
    const isPM = type === 'penduduk';
    document.getElementById('import-type-pm').className =
        `flex-1 text-xs font-bold py-2 rounded-lg border-2 transition ${isPM ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-slate-200 text-slate-500 hover:border-slate-400'}`;
    document.getElementById('import-type-ri').className =
        `flex-1 text-xs font-bold py-2 rounded-lg border-2 transition ${!isPM ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-slate-200 text-slate-500 hover:border-slate-400'}`;
    document.getElementById('import-format-pm').classList.toggle('hidden', !isPM);
    document.getElementById('import-format-ri').classList.toggle('hidden', isPM);
};

document.getElementById('csv-file-input').addEventListener('change', function() {
    const f = this.files[0];
    const nameEl = document.getElementById('csv-file-name');
    const btn    = document.getElementById('btn-start-import');
    if (f) {
        nameEl.textContent = `File dipilih: ${f.name} (${(f.size/1024).toFixed(1)} KB)`;
        nameEl.classList.remove('hidden');
        btn.disabled = false;
    } else { nameEl.classList.add('hidden'); btn.disabled = true; }
});

const _dropZone = document.querySelector('label[for="csv-file-input"]');
if (_dropZone) {
    _dropZone.addEventListener('dragover', e => { e.preventDefault(); _dropZone.classList.add('border-blue-500','bg-blue-50'); });
    _dropZone.addEventListener('dragleave', () => _dropZone.classList.remove('border-blue-500','bg-blue-50'));
    _dropZone.addEventListener('drop', e => {
        e.preventDefault(); _dropZone.classList.remove('border-blue-500','bg-blue-50');
        const f = e.dataTransfer.files[0];
        if (f) {
            const inp = document.getElementById('csv-file-input');
            const dt = new DataTransfer(); dt.items.add(f); inp.files = dt.files;
            inp.dispatchEvent(new Event('change'));
        }
    });
}

window.startImport = () => {
    const file = document.getElementById('csv-file-input').files[0];
    if (!file) return;
    document.getElementById('import-upload-area').classList.add('hidden');
    document.getElementById('import-progress-area').classList.remove('hidden');
    const log   = document.getElementById('import-log');
    const bar   = document.getElementById('import-progress-bar');
    const pct   = document.getElementById('import-progress-pct');
    const label = document.getElementById('import-progress-label');
    log.innerHTML = '';
    const fd = new FormData(); fd.append('csv_file', file);
    fetch(`import.php?type=${_importType}`, {method:'POST', body:fd}).then(response => {
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        function processChunk({done, value}) {
            if (done) return;
            buffer += decoder.decode(value, {stream:true});
            const lines = buffer.split('\n');
            buffer = lines.pop();
            lines.forEach(line => {
                if (!line.startsWith('data: ')) return;
                try {
                    const ev = JSON.parse(line.slice(6));
                    if (ev.type === 'start') {
                        label.textContent = ev.msg;
                        log.innerHTML += `<div class="text-blue-400">&#9654; ${ev.msg}</div>`;
                    } else if (ev.type === 'row') {
                        const progress = Math.round(ev.num / ev.total * 100);
                        bar.style.width = progress + '%'; pct.textContent = progress + '%';
                        label.textContent = `Memproses baris ${ev.num} dari ${ev.total}...`;
                        const color = ev.status==='sukses'?'text-green-400':ev.status==='gagal'?'text-red-400':'text-slate-400';
                        const icon  = ev.status==='sukses'?'✓':ev.status==='gagal'?'✗':'~';
                        log.innerHTML += `<div class="${color}">[${ev.num}/${ev.total}] ${icon} ${ev.nama}${ev.msg?' — '+ev.msg:''}</div>`;
                        log.scrollTop = log.scrollHeight;
                    } else if (ev.type === 'done') {
                        bar.style.width='100%'; pct.textContent='100%';
                        document.getElementById('import-progress-area').classList.add('hidden');
                        document.getElementById('import-done-area').classList.remove('hidden');
                        document.getElementById('import-done-text').textContent = ev.msg;
                        document.getElementById('import-done-sub').textContent =
                            `${ev.sukses} berhasil · ${ev.gagal} perlu validasi manual`;
                    } else if (ev.type === 'error') {
                        log.innerHTML += `<div class="text-red-400">ERROR: ${ev.msg}</div>`;
                    }
                } catch(e) {}
            });
            reader.read().then(processChunk);
        }
        reader.read().then(processChunk);
    }).catch(err => {
        log.innerHTML += `<div class="text-red-400">Koneksi gagal: ${err.message}</div>`;
    });
};

// ── EXPORT LAPORAN ────────────────────────────────────────────────────
window.openModalExport  = () => document.getElementById('modal-export').classList.remove('hidden');
window.closeModalExport = () => document.getElementById('modal-export').classList.add('hidden');
window.doExport = () => {
    const bulan = document.getElementById('export-bulan').value;
    const tahun = document.getElementById('export-tahun').value;
    window.open(`api.php?action=export_laporan&bulan=${bulan}&tahun=${tahun}`, '_blank');
    closeModalExport();
};
<?php endif; ?>

loadData();
<?php if ($is_admin): ?>
setTimeout(loadGecodingQueue, 1200);
<?php endif; ?>
</script>
</body>
</html>