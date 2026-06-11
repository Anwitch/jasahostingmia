<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'koneksi.php';
require_once 'SimpleXLSX.php';

// ── AUTENTIKASI ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'unauthorized', 'message' => 'Sesi tidak valid. Silakan login kembali.']);
    exit;
}
$role     = $_SESSION['role'];
$my_ri_id = (int)($_SESSION['id_rumah_ibadah'] ?? 0);
$is_pk    = ($role === 'pengambil_kebijakan');

// Set JSON header default — export_laporan akan override ini
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

// ── HAVERSINE ─────────────────────────────────────────────────────────────────
function hitungJarak($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000;
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    $a = sin($latDelta / 2) * sin($latDelta / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($lonDelta / 2) * sin($lonDelta / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

// ── UPDATE COVERAGE ───────────────────────────────────────────────────────────
function updateCoverage($conn) {
    $conn->query("UPDATE penduduk_miskin SET id_rumah_ibadah = NULL");
    
    // Fetch all rumah_ibadah once to prevent N+1 query inside the loop
    $rumah_ibadah_q = $conn->query("SELECT * FROM rumah_ibadah");
    $rumah_ibadah_list = [];
    while ($ri = $rumah_ibadah_q->fetch_assoc()) {
        $rumah_ibadah_list[] = $ri;
    }

    $penduduk = $conn->query("SELECT * FROM penduduk_miskin");
    while ($p = $penduduk->fetch_assoc()) {
        $terdekat_id = "NULL";
        $jarak_min   = INF;
        
        foreach ($rumah_ibadah_list as $ri) {
            $jarak = hitungJarak($p['lat'], $p['lng'], $ri['lat'], $ri['lng']);
            if ($jarak <= $ri['radius'] && $jarak < $jarak_min) {
                $jarak_min   = $jarak;
                $terdekat_id = $ri['id'];
            }
        }
        
        // Only update if a matching rumah_ibadah is found, since we already set all to NULL above
        if ($terdekat_id !== "NULL") {
            $conn->query("UPDATE penduduk_miskin SET id_rumah_ibadah = $terdekat_id WHERE id = " . $p['id']);
        }
    }
}

// ── AUTO RESET BULANAN ────────────────────────────────────────────────────────
function autoResetBulanan($conn) {
    $bulanIni = date('Y-m');
    $conn->query("UPDATE penduduk_miskin
                  SET status_bantuan = 'belum', bulan_status = '$bulanIni'
                  WHERE bulan_status IS NULL OR bulan_status != '$bulanIni'");
}

// ── UPLOAD FOTO HELPER ────────────────────────────────────────────────────────
function uploadFoto($field, $dir, $prefix) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    
    $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
    
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts)) return null;
    
    // Validasi MIME type sebenarnya dari isi file
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES[$field]['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime, $allowed_mimes)) return null;
    
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) return null;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = $prefix . '_' . time() . '.' . $ext;
    return move_uploaded_file($_FILES[$field]['tmp_name'], $dir . $filename) ? $filename : null;
}

// ── HELPER: ADMIN ONLY ────────────────────────────────────────────────────────
function requireAdmin($role) {
    if ($role !== 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'Akses ditolak. Fitur ini hanya untuk admin.']);
        exit;
    }
}

// ── GET DATA ──────────────────────────────────────────────────────────────────
if ($action == 'get_data') {
    autoResetBulanan($conn);

    $data = ['rumah_ibadah' => [], 'penduduk_miskin' => [], 'statistik' => []];

    $ri = $conn->query("
        SELECT ri.*,
               u.nama_lengkap AS koordinator_nama,
               u.no_wa        AS koordinator_wa
        FROM rumah_ibadah ri
        LEFT JOIN users u ON u.id_rumah_ibadah = ri.id
                          AND u.role = 'koordinator'
                          AND u.id = (
                              SELECT id FROM users
                              WHERE id_rumah_ibadah = ri.id AND role = 'koordinator'
                              LIMIT 1
                          )
    ");
    while ($row = $ri->fetch_assoc()) $data['rumah_ibadah'][] = $row;

    // Koordinator hanya menerima PM yang di-cover RI-nya
    // Admin & pengambil_kebijakan mendapat semua data
    if ($role === 'koordinator' && $my_ri_id) {
        $pm = $conn->query("
            SELECT p.*, r.nama as nama_cover,
                   COALESCE(DATEDIFF(NOW(), MAX(h.tanggal_penyaluran)), 365) AS hari_tanpa_bantuan,
                   ROUND(
                       (p.jumlah_anggota / GREATEST((SELECT MAX(jumlah_anggota) FROM penduduk_miskin), 1)) * 40
                       + (LEAST(COALESCE(DATEDIFF(NOW(), MAX(h.tanggal_penyaluran)), 365), 365) / 365.0) * 60
                   , 1) AS skor_prioritas
            FROM penduduk_miskin p
            LEFT JOIN rumah_ibadah r ON p.id_rumah_ibadah = r.id
            LEFT JOIN histori_bantuan h ON h.id_penduduk_miskin = p.id
            WHERE p.id_rumah_ibadah = $my_ri_id
            GROUP BY p.id
            ORDER BY skor_prioritas DESC
        ");
    } else {
        $pm = $conn->query("
            SELECT p.*, r.nama as nama_cover,
                   COALESCE(DATEDIFF(NOW(), MAX(h.tanggal_penyaluran)), 365) AS hari_tanpa_bantuan,
                   ROUND(
                       (p.jumlah_anggota / GREATEST((SELECT MAX(jumlah_anggota) FROM penduduk_miskin), 1)) * 40
                       + (LEAST(COALESCE(DATEDIFF(NOW(), MAX(h.tanggal_penyaluran)), 365), 365) / 365.0) * 60
                   , 1) AS skor_prioritas
            FROM penduduk_miskin p
            LEFT JOIN rumah_ibadah r ON p.id_rumah_ibadah = r.id
            LEFT JOIN histori_bantuan h ON h.id_penduduk_miskin = p.id
            GROUP BY p.id
            ORDER BY skor_prioritas DESC
        ");
    }
    while ($row = $pm->fetch_assoc()) $data['penduduk_miskin'][] = $row;

    $total_pm       = 0; // hanya yang sudah punya koordinat
    $total_ri       = count($data['rumah_ibadah']);
    $ter_cover      = 0;
    $sudah_terima   = 0;
    $total_jiwa     = 0;
    $belum_validasi = 0; // import CSV yang belum digeocoding

    foreach ($data['penduduk_miskin'] as $p) {
        if (empty($p['lat']) || empty($p['lng'])) {
            $belum_validasi++;
            continue; // skip dari kalkulasi utama
        }
        $total_pm++;
        $total_jiwa += (int)$p['jumlah_anggota'];
        if ($p['id_rumah_ibadah'] !== null) {
            $ter_cover++;
            if ($p['status_bantuan'] === 'sudah') $sudah_terima++;
        }
    }

    $data['statistik'] = [
        'total_ri'        => $total_ri,
        'total_pm'        => $total_pm,
        'total_jiwa'      => $total_jiwa,
        'belum_validasi'  => $belum_validasi,
        'ter_cover'       => $ter_cover,
        'belum_cover'     => $total_pm - $ter_cover,
        'sudah_terima'    => $sudah_terima,
        'belum_terima'    => $ter_cover - $sudah_terima,
        'pct_cover'       => $total_pm  > 0 ? round($ter_cover    / $total_pm  * 100) : 0,
        'pct_terima'      => $ter_cover > 0 ? round($sudah_terima / $ter_cover * 100) : 0,
        'bulan'           => ['','Januari','Februari','Maret','April','Mei','Juni',
                              'Juli','Agustus','September','Oktober','November','Desember'][(int)date('n')]
                              . ' ' . date('Y'),
    ];

    echo json_encode($data);
}

// ── TAMBAH RUMAH IBADAH ───────────────────────────────────────────────────────
if ($action == 'tambah_ri' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    requireAdmin($role);
    $nama   = $_POST['nama'];  $jenis  = $_POST['jenis'] ?? 'Masjid';
    $alamat = $_POST['alamat'];
    
    // Validasi koordinat
    if (!isset($_POST['lat']) || !isset($_POST['lng']) || !is_numeric($_POST['lat']) || !is_numeric($_POST['lng'])) {
        echo json_encode(['status' => 'error', 'message' => 'Titik koordinat lokasi tidak valid atau belum dipilih.']);
        exit;
    }
    $lat = (float)$_POST['lat']; 
    $lng = (float)$_POST['lng'];

    $stmt = $conn->prepare("INSERT INTO rumah_ibadah (nama, jenis, alamat, lat, lng) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssdd", $nama, $jenis, $alamat, $lat, $lng);
    $stmt->execute();
    updateCoverage($conn);
    echo json_encode(['status' => 'success']);
}

// ── EDIT RUMAH IBADAH ─────────────────────────────────────────────────────────
if ($action == 'edit_ri' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    requireAdmin($role);
    $id     = (int)$_POST['id'];
    $nama   = $_POST['nama'];  $jenis  = $_POST['jenis'] ?? 'Masjid';
    $alamat = $_POST['alamat'];
    $stmt = $conn->prepare("UPDATE rumah_ibadah SET nama=?, jenis=?, alamat=? WHERE id=?");
    $stmt->bind_param("sssi", $nama, $jenis, $alamat, $id);
    $stmt->execute();
    echo json_encode(['status' => 'success']);
}

// ── HAPUS RUMAH IBADAH ────────────────────────────────────────────────────────
if ($action == 'delete_ri' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    requireAdmin($role);
    $id = (int)$_POST['id'];
    $conn->query("UPDATE penduduk_miskin SET id_rumah_ibadah = NULL WHERE id_rumah_ibadah = $id");
    $conn->query("DELETE FROM rumah_ibadah WHERE id = $id");
    updateCoverage($conn);
    echo json_encode(['status' => 'success']);
}

// ── TAMBAH PENDUDUK MISKIN ────────────────────────────────────────────────────
if ($action == 'tambah_penduduk' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    requireAdmin($role);
    $nama   = $_POST['nama_kepala'];
    $jumlah = (int)$_POST['jumlah_anggota'];
    $alamat = $_POST['alamat'] ?? '';
    
    // Validasi koordinat
    if (!isset($_POST['lat']) || !isset($_POST['lng']) || !is_numeric($_POST['lat']) || !is_numeric($_POST['lng'])) {
        echo json_encode(['status' => 'error', 'message' => 'Titik koordinat lokasi tidak valid atau belum dipilih.']);
        exit;
    }
    $lat = (float)$_POST['lat']; 
    $lng = (float)$_POST['lng'];

    $stmt = $conn->prepare("INSERT INTO penduduk_miskin (nama_kepala, jumlah_anggota, alamat, lat, lng) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sisdd", $nama, $jumlah, $alamat, $lat, $lng);
    $stmt->execute();
    $new_id = $conn->insert_id;
    $foto = uploadFoto('foto_rumah', 'uploads/foto_rumah/', "rumah_{$new_id}");
    if ($foto) $conn->query("UPDATE penduduk_miskin SET foto_rumah = '$foto' WHERE id = $new_id");
    updateCoverage($conn);
    echo json_encode(['status' => 'success', 'id' => $new_id]);
}

// ── EDIT PENDUDUK MISKIN ──────────────────────────────────────────────────────
if ($action == 'edit_pm' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    requireAdmin($role);
    $id     = (int)$_POST['id'];
    $nama   = $_POST['nama_kepala'];
    $jumlah = (int)$_POST['jumlah_anggota'];
    $alamat = $_POST['alamat'] ?? '';
    $stmt = $conn->prepare("UPDATE penduduk_miskin SET nama_kepala=?, jumlah_anggota=?, alamat=? WHERE id=?");
    $stmt->bind_param("sisi", $nama, $jumlah, $alamat, $id);
    $stmt->execute();
    $foto = uploadFoto('foto_rumah', 'uploads/foto_rumah/', "rumah_{$id}");
    if ($foto) {
        $old = $conn->query("SELECT foto_rumah FROM penduduk_miskin WHERE id=$id")->fetch_assoc();
        if (!empty($old['foto_rumah'])) {
            $old_path = 'uploads/foto_rumah/' . $old['foto_rumah'];
            if (file_exists($old_path)) unlink($old_path);
        }
        $conn->query("UPDATE penduduk_miskin SET foto_rumah='$foto' WHERE id=$id");
    }
    echo json_encode(['status' => 'success']);
}

// ── HAPUS PENDUDUK MISKIN ─────────────────────────────────────────────────────
if ($action == 'delete_pm' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    requireAdmin($role);
    $id = (int)$_POST['id'];
    $conn->query("DELETE FROM penduduk_miskin WHERE id = $id");
    echo json_encode(['status' => 'success']);
}

// ── UPDATE RADIUS ─────────────────────────────────────────────────────────────
if ($action == 'update_radius' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    requireAdmin($role);
    $id = (int)$_POST['id']; $radius = (int)$_POST['radius'];
    $conn->query("UPDATE rumah_ibadah SET radius = $radius WHERE id = $id");
    updateCoverage($conn);
    echo json_encode(['status' => 'success']);
}

// ── TOGGLE STATUS BANTUAN ─────────────────────────────────────────────────────
// Admin: semua PM. Koordinator: hanya PM di RI-nya. Pengambil kebijakan: tidak boleh.
if ($action == 'toggle_status' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($role === 'pengambil_kebijakan') {
        echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']); exit;
    }
    
    $id     = (int)$_POST['id'];
    $status = $_POST['status'];
    if ($role === 'koordinator') {
        $chk = $conn->query("SELECT id_rumah_ibadah FROM penduduk_miskin WHERE id=$id")->fetch_assoc();
        if (!$chk || $chk['id_rumah_ibadah'] != $my_ri_id) {
            echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']); exit;
        }
        // Koordinator tidak boleh membatalkan status sudah terima
        if ($role === 'koordinator' && $status === 'belum') {
            echo json_encode(['status' => 'error', 'message' => 'Koordinator tidak dapat membatalkan status penerimaan bantuan.']); exit;
        }
    }
    $bulan = date('Y-m');
    $stmt = $conn->prepare("UPDATE penduduk_miskin SET status_bantuan=?, bulan_status=? WHERE id=?");
    $stmt->bind_param("ssi", $status, $bulan, $id);
    $stmt->execute();
    echo json_encode(['status' => 'success']);
}

// ── RESET SEMUA STATUS (MANUAL) ───────────────────────────────────────────────
if ($action == 'reset_bulanan' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    requireAdmin($role);
    $bulan = date('Y-m');
    $conn->query("UPDATE penduduk_miskin SET status_bantuan = 'belum', bulan_status = '$bulan'");
    echo json_encode(['status' => 'success']);
}

// ── TANDAI SUDAH TERIMA ───────────────────────────────────────────────────────
if ($action == 'tandai_sudah' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($role === 'pengambil_kebijakan') {
        echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']); exit;
    }
    $id         = (int)$_POST['id'];
    $keterangan = trim($_POST['keterangan'] ?? '');
    $bulan      = (int)date('n');
    $tahun      = (int)date('Y');
    $bulan_status = date('Y-m');

    $res = $conn->query("SELECT id_rumah_ibadah FROM penduduk_miskin WHERE id = $id");
    $pm  = $res->fetch_assoc();
    if (!$pm || !$pm['id_rumah_ibadah']) {
        echo json_encode(['status' => 'error', 'message' => 'Penduduk belum ter-cover rumah ibadah.']);
        exit;
    }
    $id_ri = (int)$pm['id_rumah_ibadah'];

    if ($role === 'koordinator' && $id_ri !== $my_ri_id) {
        echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']);
        exit;
    }

    $foto_bukti = uploadFoto('foto_bukti', 'uploads/foto_bukti/', "bukti_{$id}_{$bulan}{$tahun}");
    if (!$foto_bukti) {
        echo json_encode(['status' => 'error', 'message' => 'Foto bukti wajib diupload (jpg/png/webp, maks 5MB).']);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO histori_bantuan (id_penduduk_miskin, id_rumah_ibadah, bulan, tahun, foto_bukti, keterangan)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("iiiiss", $id, $id_ri, $bulan, $tahun, $foto_bukti, $keterangan);
    $stmt->execute();

    $stmt2 = $conn->prepare("UPDATE penduduk_miskin SET status_bantuan='sudah', bulan_status=? WHERE id=?");
    $stmt2->bind_param("si", $bulan_status, $id);
    $stmt2->execute();

    echo json_encode(['status' => 'success']);
}

// ── GET HISTORI BANTUAN (per-KK) ──────────────────────────────────────────────
if ($action == 'get_histori') {
    $id = (int)($_GET['id_pm'] ?? 0);
    // Koordinator hanya bisa lihat histori PM di bawah RI-nya
    // Admin & pengambil_kebijakan boleh lihat semua
    if ($role === 'koordinator') {
        $chk = $conn->query("SELECT id_rumah_ibadah FROM penduduk_miskin WHERE id=$id")->fetch_assoc();
        if (!$chk || $chk['id_rumah_ibadah'] != $my_ri_id) {
            echo json_encode([]); exit;
        }
    }
    $res = $conn->query("
        SELECT h.id, h.tanggal_penyaluran, h.bulan, h.tahun,
               h.foto_bukti, h.keterangan,
               r.nama AS nama_ri
        FROM histori_bantuan h
        LEFT JOIN rumah_ibadah r ON h.id_rumah_ibadah = r.id
        WHERE h.id_penduduk_miskin = $id
        ORDER BY h.tanggal_penyaluran DESC
        LIMIT 36
    ");
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    echo json_encode($rows);
}

// ── GET HISTORI GLOBAL (Tab 3) ────────────────────────────────────────────────
if ($action == 'get_histori_global') {
    $limit = min((int)($_GET['limit'] ?? 30), 100);
    // Koordinator hanya lihat histori RI-nya; admin & pengambil_kebijakan lihat semua
    $where = ($role === 'koordinator' && $my_ri_id) ? "WHERE h.id_rumah_ibadah = $my_ri_id" : '';
    $res = $conn->query("
        SELECT h.id, h.tanggal_penyaluran, h.bulan, h.tahun,
               h.foto_bukti, h.keterangan,
               p.nama_kepala,
               r.nama AS nama_ri, r.jenis AS jenis_ri
        FROM histori_bantuan h
        LEFT JOIN penduduk_miskin p ON h.id_penduduk_miskin = p.id
        LEFT JOIN rumah_ibadah r ON h.id_rumah_ibadah = r.id
        $where
        ORDER BY h.tanggal_penyaluran DESC
        LIMIT $limit
    ");
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    echo json_encode($rows);
}

// ── GET USERS (admin only) ────────────────────────────────────────────────────
if ($action == 'get_users') {
    requireAdmin($role);
    $res = $conn->query("
        SELECT u.id, u.username, u.nama_lengkap, u.role, u.id_rumah_ibadah,
               r.nama AS nama_ri
        FROM users u
        LEFT JOIN rumah_ibadah r ON u.id_rumah_ibadah = r.id
        WHERE u.role IN ('koordinator', 'pengambil_kebijakan')
        ORDER BY u.role, u.nama_lengkap
    ");
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    echo json_encode($rows);
}

// ── TAMBAH USER ───────────────────────────────────────────────────────────────
if ($action == 'tambah_user' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    requireAdmin($role);
    $username  = trim($_POST['username'] ?? '');
    $password  = $_POST['password'] ?? '';
    $nama      = trim($_POST['nama_lengkap'] ?? '');
    $no_wa     = trim($_POST['no_wa'] ?? '');
    $new_role  = $_POST['role'] ?? 'koordinator';
    $id_ri     = (int)($_POST['id_rumah_ibadah'] ?? 0) ?: null;

    // Validasi role yang diizinkan
    $allowed_roles = ['koordinator', 'pengambil_kebijakan'];
    if (!in_array($new_role, $allowed_roles)) {
        echo json_encode(['status' => 'error', 'message' => 'Role tidak valid.']); exit;
    }
    // Pengambil kebijakan tidak perlu RI
    if ($new_role === 'pengambil_kebijakan') $id_ri = null;

    if (!$username || !$password || !$nama) {
        echo json_encode(['status' => 'error', 'message' => 'Data tidak lengkap.']); exit;
    }
    $escaped = mysqli_real_escape_string($conn, $username);
    $chk = $conn->query("SELECT id FROM users WHERE username = '$escaped'")->fetch_assoc();
    if ($chk) {
        echo json_encode(['status' => 'error', 'message' => 'Username sudah digunakan.']); exit;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, role, id_rumah_ibadah, nama_lengkap, no_wa) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssiss", $username, $hash, $new_role, $id_ri, $nama, $no_wa);
    $stmt->execute();
    echo json_encode(['status' => 'success']);
}

// ── EDIT USER ─────────────────────────────────────────────────────────────────
if ($action == 'edit_user' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    requireAdmin($role);
    $id        = (int)$_POST['id'];
    $username  = trim($_POST['username'] ?? '');
    $nama      = trim($_POST['nama_lengkap'] ?? '');
    $no_wa     = trim($_POST['no_wa'] ?? '');
    $password  = $_POST['password'] ?? '';
    $new_role  = $_POST['role'] ?? 'koordinator';
    $id_ri     = (int)($_POST['id_rumah_ibadah'] ?? 0) ?: null;

    $allowed_roles = ['koordinator', 'pengambil_kebijakan'];
    if (!in_array($new_role, $allowed_roles)) {
        echo json_encode(['status' => 'error', 'message' => 'Role tidak valid.']); exit;
    }
    if ($new_role === 'pengambil_kebijakan') $id_ri = null;

    $escaped = mysqli_real_escape_string($conn, $username);
    $chk = $conn->query("SELECT id FROM users WHERE username='$escaped' AND id != $id")->fetch_assoc();
    if ($chk) {
        echo json_encode(['status' => 'error', 'message' => 'Username sudah digunakan.']); exit;
    }
    if ($password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET username=?, password=?, role=?, nama_lengkap=?, no_wa=?, id_rumah_ibadah=? WHERE id=?");
        $stmt->bind_param("sssssii", $username, $hash, $new_role, $nama, $no_wa, $id_ri, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET username=?, role=?, nama_lengkap=?, no_wa=?, id_rumah_ibadah=? WHERE id=?");
        $stmt->bind_param("ssssii", $username, $new_role, $nama, $no_wa, $id_ri, $id);
    }
    $stmt->execute();
    echo json_encode(['status' => 'success']);
}

// ── HAPUS USER ────────────────────────────────────────────────────────────────
if ($action == 'delete_user' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    requireAdmin($role);
    $id = (int)$_POST['id'];
    if ($id === (int)$_SESSION['user_id']) {
        echo json_encode(['status' => 'error', 'message' => 'Tidak bisa menghapus akun sendiri.']); exit;
    }
    $conn->query("DELETE FROM users WHERE id=$id AND role IN ('koordinator','pengambil_kebijakan')");
    echo json_encode(['status' => 'success']);
}

// ── GET GEOCODING QUEUE ───────────────────────────────────────────────────────
if ($action == 'get_geocoding_queue') {
    requireAdmin($role);
    $res = $conn->query("
        SELECT id, nama_kepala, jumlah_anggota, alamat, status_geocoding
        FROM penduduk_miskin
        WHERE lat IS NULL OR lng IS NULL
        ORDER BY id DESC
    ");
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    echo json_encode($rows);
}

// ── UPDATE LOKASI (dari antrean geocoding) ────────────────────────────────────
if ($action == 'update_lokasi' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    requireAdmin($role);
    $id  = (int)$_POST['id'];
    $lat = (float)$_POST['lat'];
    $lng = (float)$_POST['lng'];
    $conn->query("UPDATE penduduk_miskin SET lat=$lat, lng=$lng, status_geocoding='sukses' WHERE id=$id");
    updateCoverage($conn);
    echo json_encode(['status' => 'success']);
}

// ── EXPORT LAPORAN (CSV) ──────────────────────────────────────────────────────
// ── EXPORT LAPORAN (xlsx) ──────────────────────────────────────────────────────
if ($action == 'export_laporan') {
    $bulan = (int)($_GET['bulan'] ?? date('n'));
    $tahun = (int)($_GET['tahun'] ?? date('Y'));

    $bulan_nama = ['','Januari','Februari','Maret','April','Mei','Juni',
                   'Juli','Agustus','September','Oktober','November','Desember'];
    $periode    = ($bulan_nama[$bulan] ?? $bulan).' '.$tahun;

    // ── DATA ──────────────────────────────────────────────────────────────────
    $total_ri   = (int)$conn->query("SELECT COUNT(*) n FROM rumah_ibadah")->fetch_assoc()['n'];
    $total_pm   = (int)$conn->query("SELECT COUNT(*) n FROM penduduk_miskin WHERE lat IS NOT NULL")->fetch_assoc()['n'];
    $total_jiwa = (int)$conn->query("SELECT COALESCE(SUM(jumlah_anggota),0) n FROM penduduk_miskin WHERE lat IS NOT NULL")->fetch_assoc()['n'];
    $ter_cover  = (int)$conn->query("SELECT COUNT(*) n FROM penduduk_miskin WHERE lat IS NOT NULL AND id_rumah_ibadah IS NOT NULL")->fetch_assoc()['n'];
    $sudah      = (int)$conn->query("SELECT COUNT(DISTINCT id_penduduk_miskin) n FROM histori_bantuan WHERE bulan=$bulan AND tahun=$tahun")->fetch_assoc()['n'];
    $belum      = $ter_cover - $sudah;
    $blm_cover  = $total_pm - $ter_cover;
    $pct_cov    = $total_pm  > 0 ? round($ter_cover / $total_pm  * 100, 1) : 0;
    $pct_ter    = $ter_cover > 0 ? round($sudah     / $ter_cover * 100, 1) : 0;

    $ri_q = $conn->query("
        SELECT r.nama AS nama_ri, r.jenis,
               COUNT(DISTINCT p.id) AS total_kk,
               COALESCE(SUM(p.jumlah_anggota),0) AS jiwa,
               COUNT(DISTINCT h.id_penduduk_miskin) AS sudah_kk,
               COUNT(DISTINCT p.id)-COUNT(DISTINCT h.id_penduduk_miskin) AS belum_kk
        FROM rumah_ibadah r
        LEFT JOIN penduduk_miskin p ON p.id_rumah_ibadah=r.id AND p.lat IS NOT NULL
        LEFT JOIN histori_bantuan h ON h.id_penduduk_miskin=p.id AND h.bulan=$bulan AND h.tahun=$tahun
        GROUP BY r.id, r.nama, r.jenis ORDER BY r.nama
    ");
    $rekap = []; while ($row=$ri_q->fetch_assoc()) $rekap[]=$row;

    $det_q = $conn->query("
        SELECT p.nama_kepala, p.jumlah_anggota, p.alamat,
               r.nama AS nama_ri, r.jenis AS jenis_ri,
               h.tanggal_penyaluran, h.keterangan,
               CASE WHEN h.id IS NOT NULL THEN 'Sudah Terima' ELSE 'Belum Terima' END AS status
        FROM penduduk_miskin p
        LEFT JOIN rumah_ibadah r ON r.id=p.id_rumah_ibadah
        LEFT JOIN histori_bantuan h ON h.id_penduduk_miskin=p.id AND h.bulan=$bulan AND h.tahun=$tahun
        WHERE p.lat IS NOT NULL AND p.id_rumah_ibadah IS NOT NULL
        ORDER BY r.nama, h.tanggal_penyaluran IS NULL ASC, p.nama_kepala
    ");
    $detail=[]; while ($row=$det_q->fetch_assoc()) $detail[]=$row;

    $unc_q = $conn->query("
        SELECT p.nama_kepala, p.jumlah_anggota, p.alamat
        FROM penduduk_miskin p WHERE p.lat IS NOT NULL AND p.id_rumah_ibadah IS NULL
        ORDER BY p.nama_kepala
    ");
    $uncov=[]; while ($row=$unc_q->fetch_assoc()) $uncov[]=$row;

    // ── SHARED STYLES ─────────────────────────────────────────────────────────
    // Hanya header kolom tabel yang berwarna. Data rows bersih, font gelap.
    $H1   = ['bold'=>true, 'halign'=>'center', 'height'=>22];
    $H2   = ['bold'=>true, 'height'=>18];
    $COL  = ['bold'=>true, 'bg'=>'334155', 'color'=>'FFFFFF', 'halign'=>'center', 'height'=>16];
    $DATA = [];
    $WARN = ['italic'=>true, 'color'=>'6B7280'];
    $S_OK = ['color'=>'065F46'];     // teks hijau: sudah terima
    $S_NOK= ['color'=>'991B1B'];     // teks merah: belum terima
    $S_AMB= ['color'=>'92400E'];     // teks coklat: perlu perhatian

    // ── SHEET 1: RINGKASAN ────────────────────────────────────────────────────
    $xlsx = new SimpleXLSX();
    $s1   = $xlsx->addSheet('Ringkasan');
    $s1->setColWidths([36, 18, 30, 15, 15, 15, 15, 15]);

    // PERBAIKAN: 'merge' dipindah ke parameter ke-3 (Cell Styles) di indeks 0
    $s1->writeRow(['LAPORAN DISTRIBUSI BANTUAN SOSIAL', '', '', '', '', '', '', ''], $H1, [0 => ['merge'=>8]]);
    
    // PERBAIKAN: Memisahkan style teks dengan merge cell
    $s1->writeRow(["Periode: $periode  |  Digenerate: ".date('d/m/Y H:i'), '', '', '', '', '', '', ''], ['halign'=>'center','color'=>'888888'], [0 => ['merge'=>8]]);
    $s1->writeBlank();

    // PERBAIKAN: Section header Ringkasan Eksekutif
    $s1->writeRow(['RINGKASAN EKSEKUTIF','','','','','','',''], $H2, [0 => ['merge'=>8]]);
    $s1->writeRow(['Metrik','Nilai','Keterangan'], $COL);

    $stats = [
        ['Total Rumah Ibadah',                  $total_ri,   ''],
        ['Total KK Terdaftar (berkoordinat)',    $total_pm,   ''],
        ['Total Jiwa',                           $total_jiwa, ''],
        ['KK Ter-cover Rumah Ibadah',            $ter_cover,  $pct_cov.'% dari total KK'],
        ['KK Belum Ter-cover',                   $blm_cover,  ''],
        ['Sudah Menerima Bantuan (periode ini)', $sudah,      $pct_ter.'% dari KK ter-cover'],
        ['Belum Menerima Bantuan',               $belum,      'dari KK yang ter-cover'],
    ];
    foreach ($stats as $i => $st) {
        $bg = $DATA;
        $s1->writeRow($st, $bg, [0=>['bold'=>true]]);
    }
    
    // PERBAIKAN: Catatan kaki / Warning
    $s1->writeRow(['* Status distribusi dari histori penyaluran aktual, bukan status sementara yang direset tiap bulan.'], $WARN, [0 => ['merge'=>8]]);
    $s1->writeBlank();

    // Rekap per RI
    // PERBAIKAN: Section header Rekap Per RI
    $s1->writeRow(['REKAP PER RUMAH IBADAH','','','','','','',''], $H2, [0 => ['merge'=>8]]);
    $s1->writeRow(['No','Nama Rumah Ibadah','Jenis','Total KK','Total Jiwa','Sudah','Belum','% Distribusi'], $COL);
    foreach ($rekap as $i => $r) {
        $pct = $r['total_kk']>0 ? round($r['sudah_kk']/$r['total_kk']*100,1) : 0;
        $bg = $DATA;
        $s1->writeRow([$i+1,$r['nama_ri'],$r['jenis'],$r['total_kk'],$r['jiwa'],
                       $r['sudah_kk'],$r['belum_kk'],$pct.'%'], $bg,
                      [0=>['halign'=>'center'],3=>['halign'=>'center'],4=>['halign'=>'center'],5=>['halign'=>'center'],6=>['halign'=>'center'],7=>['halign'=>'center']]);
    }

// ── SHEET 2: DETAIL ───────────────────────────────────────────────────────
    $s2 = $xlsx->addSheet('Detail Distribusi');
    $s2->setColWidths([5, 28, 10, 42, 16, 22, 32]);
    
    // PERBAIKAN: Judul Sheet 2
    $s2->writeRow(["DETAIL DISTRIBUSI — $periode",'','','','','',''], $H1, [0 => ['merge'=>7]]);

    $cur_ri = null; $no = 0;
    foreach ($detail as $r) {
        if ($r['nama_ri'] !== $cur_ri) {
            $cur_ri = $r['nama_ri']; $no = 0;
            $s2->writeBlank();
            
            // PERBAIKAN: Nama Rumah Ibadah Grouping Header
            $s2->writeRow(['  '.$r['nama_ri'].' ('.$r['jenis_ri'].')','','','','','',''], $H2, [0 => ['merge'=>7]]);
            $s2->writeRow(['No','Nama Kepala Keluarga','Jml Anggota','Alamat',
                           'Status','Tgl Penyaluran','Keterangan'], $COL);
        }
        $no++;
        $sudah = $r['status']==='Sudah Terima';
        $statusStyle = $sudah ? $S_OK : $S_NOK;
        $s2->writeRow([$no,$r['nama_kepala'],$r['jumlah_anggota'],$r['alamat']??'-',$r['status'],$r['tanggal_penyaluran']??'-',$r['keterangan']??'-'],$DATA,[0=>['halign'=>'center'],2=>['halign'=>'center'],4=>array_merge($statusStyle,['halign'=>'center'])]);
    }

// ── SHEET 3: BELUM TER-COVER ──────────────────────────────────────────────
    if (!empty($uncov)) {
        $s3 = $xlsx->addSheet('Belum Ter-cover');
        $s3->setColWidths([5, 28, 12, 45, 24]);
        
        // PERBAIKAN: Judul Sheet 3
        $s3->writeRow(['PENDUDUK BELUM TER-COVER RUMAH IBADAH','','','',''], $H1, [0 => ['merge'=>5]]);
        
        // PERBAIKAN: Warning text Sheet 3
        $s3->writeRow([count($uncov).' KK belum memiliki rumah ibadah penanggung jawab. Perlu penugasan segera.', '','','',''], $WARN, [0 => ['merge'=>5]]);
        $s3->writeRow(['No','Nama Kepala Keluarga','Jml Anggota','Alamat','Keterangan'], $COL);
        foreach ($uncov as $i => $r) {
            $bg = $DATA;
            $s3->writeRow([$i+1,$r['nama_kepala'],$r['jumlah_anggota'],
                           $r['alamat']??'-','Perlu penugasan RI'],
                          $bg,
                          [0=>['halign'=>'center'],2=>['halign'=>'center'],4=>$S_AMB]);
        }
    }

    $bln_str = str_pad($bulan, 2, '0', STR_PAD_LEFT);
    $xlsx->download("laporan_bansos_{$bln_str}_{$tahun}.xlsx");
}
?>