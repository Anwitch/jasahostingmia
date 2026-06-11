<?php
$conn = new mysqli("localhost", "root", "");
if ($conn->connect_error) {
    die("Koneksi ke database gagal: " . $conn->connect_error);
}
$conn->query("CREATE DATABASE IF NOT EXISTS webgis_spbu");
$conn->select_db("webgis_spbu");

// Create table if not exists (automatic migration helper)
$conn->query("CREATE TABLE IF NOT EXISTS spbu (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(255) NOT NULL,
    nomor VARCHAR(100) NOT NULL,
    status ENUM('24jam', 'tidak') NOT NULL DEFAULT 'tidak',
    latitude DOUBLE NOT NULL,
    longitude DOUBLE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ================== HANDLE POST REQUESTS (API) ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // INSERT SPBU
    if ($action === 'insert') {
        $nama = $conn->real_escape_string($_POST['nama']);
        $nomor = $conn->real_escape_string($_POST['nomor']);
        $status = $conn->real_escape_string($_POST['status']);
        $lat = (float)$_POST['lat'];
        $lng = (float)$_POST['lng'];

        if ($conn->query("INSERT INTO spbu (nama, nomor, status, latitude, longitude) VALUES ('$nama', '$nomor', '$status', '$lat', '$lng')")) {
            echo "success";
        } else {
            echo "error";
        }
        exit;
    }

    // UPDATE SPBU
    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $nama = $conn->real_escape_string($_POST['nama']);
        $nomor = $conn->real_escape_string($_POST['nomor']);
        $status = $conn->real_escape_string($_POST['status']);

        if ($conn->query("UPDATE spbu SET nama='$nama', nomor='$nomor', status='$status' WHERE id=$id")) {
            echo "success";
        } else {
            echo "error";
        }
        exit;
    }

    // DELETE SPBU
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        if ($conn->query("DELETE FROM spbu WHERE id=$id")) {
            echo "success";
        } else {
            echo "error";
        }
        exit;
    }

    // MOVE MARKER (Drag-and-Drop)
    if ($action === 'move') {
        $id = (int)$_POST['id'];
        $lat = (float)$_POST['lat'];
        $lng = (float)$_POST['lng'];

        if ($conn->query("UPDATE spbu SET latitude='$lat', longitude='$lng' WHERE id=$id")) {
            echo "success";
        } else {
            echo "error";
        }
        exit;
    }
}

// ================== GET DATA ==================
$data = [];
$result = $conn->query("SELECT * FROM spbu ORDER BY nama ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebGIS SPBU Pontianak — Layer Control</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Outfit', sans-serif;
        }
        #map {
            height: 100vh;
            width: 100%;
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.03);
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: rgba(156, 163, 175, 0.4);
            border-radius: 2px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: rgba(156, 163, 175, 0.6);
        }
        #map.crosshair-cursor, #map.crosshair-cursor .leaflet-interactive {
            cursor: crosshair !important;
        }
    </style>
</head>
<body class="overflow-hidden bg-slate-50">

    <div class="flex h-screen w-screen relative">
        
        <!-- SIDEBAR PANEL -->
        <div id="sidebar" class="w-80 md:w-96 flex flex-col h-full bg-white text-slate-800 shadow-xl z-[1000] border-r border-slate-200">
            <!-- Sidebar Header -->
            <div class="p-5 border-b border-slate-200 bg-slate-50">
                <div>
                    <h1 class="text-base font-bold text-slate-900 tracking-wide">WebGIS Peta SPBU</h1>
                    <p class="text-[10px] text-slate-500">Informatika UNTAN · GIS Project</p>
                </div>
            </div>

            <!-- List Mode & Search View -->
            <div id="view-list" class="flex-1 flex flex-col min-h-0 bg-white">
                <div class="p-4 space-y-3">
                    
                    <!-- Search + Filter Row -->
                    <div class="flex gap-2">
                        <!-- Search Input -->
                        <div class="relative flex-1">
                            <input type="text" id="search-input" oninput="filterList()" placeholder="Cari nama/nomor..." 
                                class="w-full pl-8 pr-3 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-700 placeholder-slate-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition">
                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs">&#128269;</span>
                        </div>
                        <!-- Status Filter Dropdown -->
                        <select id="status-filter" onchange="filterList()" 
                            class="px-2 py-2 text-xs bg-slate-50 border border-slate-300 rounded-xl text-slate-700 focus:outline-none focus:border-blue-500 transition cursor-pointer font-medium">
                            <option value="semua">Semua Status</option>
                            <option value="24jam">24 Jam</option>
                            <option value="tidak">Biasa</option>
                        </select>
                    </div>

                    <!-- Add mode Trigger -->
                    <button id="add-mode-btn" onclick="toggleAddMode()" 
                        class="w-full flex items-center justify-center gap-2 text-xs font-semibold py-2.5 rounded-xl border border-dashed border-blue-500 text-blue-600 bg-blue-50/50 hover:bg-blue-50 transition">
                        <span id="add-btn-icon">&#10010;</span> <span id="add-btn-text">Tambah SPBU (Klik Peta)</span>
                    </button>
                </div>

                <!-- Scrollable SPBU list -->
                <div class="flex-1 overflow-y-auto px-4 pb-4 custom-scrollbar space-y-2.5" id="spbu-list-container">
                    <!-- Cards populated via JS -->
                </div>
            </div>

            <!-- Add / Edit Form Panel (Hidden by default) -->
            <div id="view-form" class="hidden flex-1 flex flex-col p-5 space-y-4 bg-white border-t border-slate-200">
                <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                    <h3 id="form-title" class="font-bold text-slate-950 text-sm">Tambah SPBU Baru</h3>
                    <button onclick="hideForm()" class="text-slate-400 hover:text-red-500 text-sm">&#10005;</button>
                </div>
                
                <input type="hidden" id="form-id">
                <input type="hidden" id="form-lat">
                <input type="hidden" id="form-lng">

                <div class="space-y-3.5">
                    <div>
                        <label class="text-[10px] font-semibold text-slate-500 block mb-1">Nama SPBU</label>
                        <input type="text" id="form-nama" placeholder="Contoh: SPBU Ahmad Yani" 
                            class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-300 rounded-lg text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold text-slate-500 block mb-1">Nomor SPBU</label>
                        <input type="text" id="form-nomor" placeholder="Contoh: 61.781.01" 
                            class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-300 rounded-lg text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold text-slate-500 block mb-1">Operasional</label>
                        <select id="form-status" 
                            class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-300 rounded-lg text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                            <option value="24jam">Buka 24 Jam</option>
                            <option value="tidak">Tidak 24 Jam</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-[10px] text-slate-500 bg-slate-50 p-2.5 rounded-lg border border-slate-200">
                        <div>Lat: <span id="label-lat" class="text-slate-700 font-mono">-</span></div>
                        <div>Lng: <span id="label-lng" class="text-slate-700 font-mono">-</span></div>
                    </div>
                </div>

                <div class="flex gap-2 pt-2">
                    <button id="form-submit-btn" onclick="submitForm()" 
                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold py-2.5 rounded-lg transition">&#10003; Simpan</button>
                    <button onclick="hideForm()" 
                        class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold py-2.5 rounded-lg transition">Batal</button>
                </div>
            </div>
        </div>

        <!-- MAP VIEW -->
        <div class="flex-grow h-full relative">
            <div id="map"></div>
            <!-- Alert Toast for Map Clicking Add Mode -->
            <div id="add-mode-toast" class="hidden absolute bottom-6 left-1/2 -translate-x-1/2 bg-blue-600 border border-blue-400 text-white font-semibold text-xs px-4 py-2.5 rounded-xl shadow-2xl z-[9000] flex items-center gap-2 animate-bounce">
                <span>&#128506;</span>
                <span>Klik lokasi di peta untuk menempatkan marker SPBU baru</span>
            </div>
        </div>

    </div>

    <!-- Leaflet JS and APP SCRIPT -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Database raw data
        var spbuData = <?php echo json_encode($data); ?>;
        var markers = {};
        var addMode = false;
        var tempMarker = null;

        // Custom Leaflet Icons
        var icon24h = L.icon({
            iconUrl: 'https://maps.google.com/mapfiles/ms/icons/green-dot.png',
            iconSize: [32, 32],
            iconAnchor: [16, 32],
            popupAnchor: [0, -32]
        });

        var iconNot24h = L.icon({
            iconUrl: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png',
            iconSize: [32, 32],
            iconAnchor: [16, 32],
            popupAnchor: [0, -32]
        });

        // Layer Groups
        var layer24h = L.layerGroup();
        var layerNot24h = L.layerGroup();
        var osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');

        // Setup Map
        var map = L.map('map', {
            center: [-0.02, 109.34],
            zoom: 13,
            layers: [osm, layer24h, layerNot24h]
        });

        // Layers Control
        var baseMaps = { "OpenStreetMap": osm };
        var overlayMaps = {
            "Buka 24 Jam": layer24h,
            "Tidak 24 Jam": layerNot24h
        };
        L.control.layers(baseMaps, overlayMaps, { position: 'topright' }).addTo(map);

        // Render SPBUs on Map & Sidebar
        function renderAll() {
            // Clear maps layer groups
            layer24h.clearLayers();
            layerNot24h.clearLayers();
            markers = {};

            // Render to map
            spbuData.forEach(function(spbu) {
                var is24 = (spbu.status === '24jam');
                var icon = is24 ? icon24h : iconNot24h;

                var marker = L.marker([spbu.latitude, spbu.longitude], {
                    icon: icon,
                    draggable: true
                });

                // Popup binding
                marker.bindPopup(`
                    <div class="text-xs p-1 select-none">
                        <div class="font-bold text-slate-800 text-sm mb-1">${spbu.nama}</div>
                        <div class="text-slate-500 mb-0.5">No. SPBU: ${spbu.nomor}</div>
                        <div class="mb-2">Status: <span class="font-bold ${is24 ? 'text-green-600' : 'text-red-500'}">${is24 ? 'Buka 24 Jam' : 'Tidak 24 Jam'}</span></div>
                        <div class="flex gap-1">
                            <button onclick="editSPBU(${spbu.id})" class="px-2 py-1 bg-blue-600 text-white font-bold rounded hover:bg-blue-700 transition">Edit</button>
                            <button onclick="deleteSPBU(${spbu.id})" class="px-2 py-1 bg-red-600 text-white font-bold rounded hover:bg-red-700 transition">Hapus</button>
                        </div>
                    </div>
                `);

                // Drag and drop handler
                marker.on('dragend', function() {
                    var pos = marker.getLatLng();
                    if (confirm(`Pindahkan lokasi ${spbu.nama} ke koordinat baru?`)) {
                        updateMarkerPosition(spbu.id, pos.lat, pos.lng);
                    } else {
                        renderAll(); // revert
                    }
                });

                markers[spbu.id] = marker;

                if (is24) {
                    marker.addTo(layer24h);
                } else {
                    marker.addTo(layerNot24h);
                }
            });

            // Render lists in sidebar
            renderList(spbuData);
        }

        // Render List inside Sidebar
        function renderList(list) {
            var container = document.getElementById("spbu-list-container");
            container.innerHTML = "";

            if (list.length === 0) {
                container.innerHTML = `<div class="text-center text-xs text-slate-500 py-6 italic">Tidak ada data ditemukan</div>`;
                return;
            }

            list.forEach(function(spbu) {
                var is24 = (spbu.status === '24jam');
                var div = document.createElement("div");
                div.className = "p-4 bg-white border border-slate-200 hover:border-blue-400 hover:shadow-md rounded-2xl cursor-pointer transition-all duration-300 transform hover:-translate-y-1 flex items-start gap-3.5 relative overflow-hidden group shadow-sm";
                div.onclick = function() {
                    map.setView([spbu.latitude, spbu.longitude], 15);
                    markers[spbu.id].openPopup();
                };

                var statusBg = is24 ? 'bg-emerald-50 text-emerald-600 border-emerald-100' : 'bg-rose-50 text-rose-600 border-rose-100';

                div.innerHTML = `
                    <!-- Left Circle Status Icon -->
                    <div class="w-9 h-9 rounded-full flex items-center justify-center border shrink-0 ${statusBg} transition-colors group-hover:bg-blue-50 group-hover:text-blue-600 group-hover:border-blue-100">
                        <span class="text-sm">⛽</span>
                    </div>
                    
                    <!-- Middle Content -->
                    <div class="flex-1 min-w-0">
                        <h4 class="font-bold text-slate-900 text-xs truncate pr-1 group-hover:text-blue-600 transition-colors">${spbu.nama}</h4>
                        <p class="text-[10px] text-slate-400 font-mono mt-0.5">No: ${spbu.nomor}</p>
                        
                        <!-- Badges and Actions Row -->
                        <div class="flex items-center justify-between border-t border-slate-100 pt-2.5 mt-2.5">
                            <!-- Badge -->
                            <span class="text-[8px] px-2 py-0.5 rounded-full ${is24 ? 'bg-emerald-100 text-emerald-700 border-green-200' : 'bg-rose-100 text-rose-700 border-rose-200'} font-semibold border">
                                ${is24 ? '24 Jam' : 'Biasa'}
                            </span>
                            
                            <!-- Actions pill buttons -->
                            <div class="flex items-center gap-1.5 opacity-80 group-hover:opacity-100 transition-opacity">
                                <button onclick="event.stopPropagation(); editSPBU(${spbu.id})" 
                                    class="text-[9px] font-bold px-2 py-1 bg-slate-100 hover:bg-blue-50 hover:text-blue-600 text-slate-600 rounded-md transition-all border border-transparent hover:border-blue-200">
                                    Edit
                                </button>
                                <button onclick="event.stopPropagation(); deleteSPBU(${spbu.id})" 
                                    class="text-[9px] font-bold px-2 py-1 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-600 rounded-md transition-all border border-transparent hover:border-rose-200">
                                    Hapus
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                container.appendChild(div);
            });
        }

        // Filter Sidebar List
        function filterList() {
            var query = document.getElementById("search-input").value.toLowerCase();
            var statusVal = document.getElementById("status-filter").value;

            var filtered = spbuData.filter(function(spbu) {
                var matchesQuery = spbu.nama.toLowerCase().includes(query) || spbu.nomor.toLowerCase().includes(query);
                var matchesStatus = (statusVal === 'semua' || spbu.status === statusVal);
                return matchesQuery && matchesStatus;
            });
            renderList(filtered);
        }

        // Toggle Add Mode
        function toggleAddMode() {
            addMode = !addMode;
            var mapEl = document.getElementById("map");
            var toast = document.getElementById("add-mode-toast");
            var btnText = document.getElementById("add-btn-text");
            var btnIcon = document.getElementById("add-btn-icon");

            if (addMode) {
                mapEl.classList.add("crosshair-cursor");
                toast.classList.remove("hidden");
                btnText.innerText = "Batal Menambah";
                btnIcon.innerHTML = "&#10005;";
                hideForm();
            } else {
                mapEl.classList.remove("crosshair-cursor");
                toast.classList.add("hidden");
                btnText.innerText = "Tambah SPBU (Klik Peta)";
                btnIcon.innerHTML = "&#10010;";
                if (tempMarker) {
                    map.removeLayer(tempMarker);
                    tempMarker = null;
                }
            }
        }

        // Map Click Event
        map.on('click', function(e) {
            if (!addMode) return;

            var lat = e.latlng.lat;
            var lng = e.latlng.lng;

            if (tempMarker) {
                map.removeLayer(tempMarker);
            }

            tempMarker = L.marker([lat, lng]).addTo(map);

            // Populate Form Fields
            document.getElementById("form-id").value = "";
            document.getElementById("form-nama").value = "";
            document.getElementById("form-nomor").value = "";
            document.getElementById("form-status").value = "24jam";
            document.getElementById("form-lat").value = lat;
            document.getElementById("form-lng").value = lng;

            document.getElementById("label-lat").innerText = lat.toFixed(6);
            document.getElementById("label-lng").innerText = lng.toFixed(6);

            document.getElementById("form-title").innerText = "Tambah SPBU Baru";
            document.getElementById("form-submit-btn").innerText = "Simpan Data";

            showForm();
            toggleAddMode(); // Turn off add mode once spot clicked
        });

        // Show/Hide Sidebar Form
        function showForm() {
            document.getElementById("view-list").classList.add("hidden");
            document.getElementById("view-form").classList.remove("hidden");
        }

        function hideForm() {
            document.getElementById("view-list").classList.remove("hidden");
            document.getElementById("view-form").classList.add("hidden");
            if (tempMarker) {
                map.removeLayer(tempMarker);
                tempMarker = null;
            }
        }

        // Trigger Edit Form
        function editSPBU(id) {
            var spbu = spbuData.find(d => d.id == id);
            if (!spbu) return;

            document.getElementById("form-id").value = spbu.id;
            document.getElementById("form-nama").value = spbu.nama;
            document.getElementById("form-nomor").value = spbu.nomor;
            document.getElementById("form-status").value = spbu.status;
            document.getElementById("form-lat").value = spbu.latitude;
            document.getElementById("form-lng").value = spbu.longitude;

            document.getElementById("label-lat").innerText = parseFloat(spbu.latitude).toFixed(6);
            document.getElementById("label-lng").innerText = parseFloat(spbu.longitude).toFixed(6);

            document.getElementById("form-title").innerText = "Edit Detail SPBU";
            document.getElementById("form-submit-btn").innerText = "Update Data";

            map.closePopup();
            showForm();
        }

        // AJAX: Submit Form (Insert or Update)
        function submitForm() {
            var id = document.getElementById("form-id").value;
            var nama = document.getElementById("form-nama").value;
            var nomor = document.getElementById("form-nomor").value;
            var status = document.getElementById("form-status").value;
            var lat = document.getElementById("form-lat").value;
            var lng = document.getElementById("form-lng").value;

            if (!nama || !nomor) {
                alert("Harap lengkapi semua bidang!");
                return;
            }

            var action = id ? 'update' : 'insert';
            var bodyParams = `action=${action}&nama=${encodeURIComponent(nama)}&nomor=${encodeURIComponent(nomor)}&status=${status}&lat=${lat}&lng=${lng}`;
            if (id) bodyParams += `&id=${id}`;

            fetch("", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: bodyParams
            })
            .then(res => res.text())
            .then(data => {
                if (data.includes("success")) {
                    alert(id ? "Data SPBU berhasil diperbarui!" : "SPBU baru berhasil disimpan!");
                    location.reload();
                } else {
                    alert("Gagal memproses data.");
                }
            });
        }

        // AJAX: Delete SPBU
        function deleteSPBU(id) {
            var spbu = spbuData.find(d => d.id == id);
            if (!spbu) return;

            if (confirm(`Apakah Anda yakin ingin menghapus ${spbu.nama}?`)) {
                fetch("", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: `action=delete&id=${id}`
                })
                .then(res => res.text())
                .then(data => {
                    if (data.includes("success")) {
                        alert("Data SPBU berhasil dihapus!");
                        location.reload();
                    } else {
                        alert("Gagal menghapus data.");
                    }
                });
            }
        }

        // AJAX: Drag-and-drop Move SPBU
        function updateMarkerPosition(id, lat, lng) {
            fetch("", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: `action=move&id=${id}&lat=${lat}&lng=${lng}`
            })
            .then(res => res.text())
            .then(data => {
                if (data.includes("success")) {
                    alert("Lokasi marker berhasil dipindahkan!");
                    location.reload();
                } else {
                    alert("Gagal menyimpan perubahan lokasi.");
                    renderAll();
                }
            });
        }

        // Initial render
        renderAll();
    </script>
</body>
</html>
