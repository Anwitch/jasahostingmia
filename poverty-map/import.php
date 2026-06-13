<?php
/**
 * import.php — Bulk Import CSV via SSE (Server-Sent Events)
 *
 * Mendukung dua tipe import:
 *   ?type=penduduk  → INSERT ke penduduk_miskin
 *   ?type=ri        → INSERT ke rumah_ibadah
 *
 * Format CSV Penduduk (header baris 1 diabaikan):
 *   Nama Kepala Keluarga | Jumlah Anggota | Alamat | RT | RW | Kelurahan | Kecamatan
 *
 * Format CSV Rumah Ibadah (header baris 1 diabaikan):
 *   Nama | Jenis | Alamat | Radius(opsional)
 *   Jenis: Masjid / Gereja Protestan / Gereja Katolik / Vihara / Pura / Kelenteng
 *   Jika Jenis kosong → default Masjid. Jika Radius kosong → default 500.
 *
 * Catatan: Koordinat (lat/lng) TIDAK diisi saat import — admin melengkapi
 *          koordinat secara manual lewat klik peta setelah import selesai.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'koneksi.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo "data: " . json_encode(['type'=>'error','msg'=>'Akses ditolak.']) . "\n\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['csv_file'])) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Tidak ada file yang diunggah.']);
    exit;
}

$import_type = $_GET['type'] ?? 'penduduk'; // 'penduduk' | 'ri'
if (!in_array($import_type, ['penduduk','ri'])) $import_type = 'penduduk';

// ── SSE HEADER ────────────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
set_time_limit(0);
ob_implicit_flush(true);
if (ob_get_level()) ob_end_flush();

function sse($type, $data) {
    echo "data: " . json_encode(array_merge(['type' => $type], $data)) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ── VALIDASI FILE ─────────────────────────────────────────────────────────────
$file = $_FILES['csv_file']['tmp_name'];
if (!$file || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    sse('error', ['msg' => 'File gagal diunggah.']); exit;
}

$ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'txt'])) {
    sse('error', ['msg' => 'Hanya file .csv yang diterima. File yang diunggah: .'.$ext]); exit;
}

// Batas ukuran file: 2MB
if ($_FILES['csv_file']['size'] > 2 * 1024 * 1024) {
    sse('error', ['msg' => 'Ukuran file terlalu besar (maks 2MB).']); exit;
}

// ── BACA & VALIDASI STRUKTUR CSV ──────────────────────────────────────────────
$raw = file_get_contents($file);

// Cek apakah file terlihat seperti teks biasa (bukan binary/gambar/dll)
if (!mb_check_encoding($raw, 'UTF-8') && !mb_check_encoding($raw, 'ISO-8859-1')) {
    sse('error', ['msg' => 'File tidak dapat dibaca sebagai teks. Pastikan file adalah CSV yang valid.']); exit;
}

// Deteksi delimiter
$firstLine = explode("\n", $raw)[0] ?? '';
$delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

$handle = fopen($file, 'r');
$header = fgetcsv($handle, 0, $delim);
if (!$header) { sse('error', ['msg' => 'File CSV kosong atau tidak dapat dibaca.']); exit; }

// Validasi jumlah kolom header sesuai tipe
$min_cols = $import_type === 'ri' ? 3 : 2;
if (count($header) < $min_cols) {
    $expected = $import_type === 'ri'
        ? 'minimal 3 kolom: Nama, Jenis, Alamat'
        : 'minimal 2 kolom: Nama KK, Jumlah Anggota';
    sse('error', ['msg' => "Format CSV tidak sesuai. Tipe '$import_type' membutuhkan $expected. File ini hanya punya ".count($header)." kolom."]); exit;
}

// Batas baris: maks 500
$total = 0;
while (fgetcsv($handle, 0, $delim)) $total++;
rewind($handle);
fgetcsv($handle, 0, $delim); // skip header

if ($total === 0) { sse('error', ['msg' => 'Tidak ada baris data di CSV (hanya header).']); exit; }
if ($total > 500) {
    sse('error', ['msg' => "File berisi $total baris. Maksimum 500 baris per import untuk menghindari timeout geocoding. Pecah menjadi beberapa file."]); exit;
}

sse('start', ['total' => $total, 'msg' => "Memproses $total baris (".($import_type==='ri'?'Rumah Ibadah':'Penduduk').")..."]);

// Geocoding dihapus — koordinat diinput manual lewat klik peta setelah import.

// ── VALIDASI NILAI HELPER ─────────────────────────────────────────────────────
$JENIS_VALID = ['Masjid','Gereja Protestan','Gereja Katolik','Vihara','Pura','Kelenteng'];

function sanitizeStr($conn, $val, $maxLen = 255) {
    $val = trim($val ?? '');
    if (mb_strlen($val) > $maxLen) $val = mb_substr($val, 0, $maxLen);
    return $conn->real_escape_string($val);
}

// ── PROSES TIAP BARIS ─────────────────────────────────────────────────────────
$sukses = 0; $gagal = 0; $dilewati = 0; $row_num = 0;
$inserted_pm_ids = []; // track ID baru untuk updateCoverage yang tepat

while (($row = fgetcsv($handle, 0, $delim)) !== false) {
    $row_num++;
    $row = array_map('trim', $row);

    // Skip baris benar-benar kosong
    if (count(array_filter($row, fn($v) => $v !== '')) === 0) {
        $dilewati++;
        sse('row', ['num'=>$row_num,'total'=>$total,'status'=>'skip','nama'=>'(baris kosong)','msg'=>'Dilewati.']);
        continue;
    }

    // Validasi nama tidak boleh kosong
    $nama_raw = $row[0] ?? '';
    if (empty($nama_raw)) {
        $dilewati++;
        sse('row', ['num'=>$row_num,'total'=>$total,'status'=>'skip','nama'=>'(kosong)','msg'=>'Nama tidak boleh kosong, baris dilewati.']);
        continue;
    }

    if ($import_type === 'ri') {
        // ── IMPORT RUMAH IBADAH ──────────────────────────────────────────────
        $nama   = sanitizeStr($conn, $row[0], 255);
        $jenis  = trim($row[1] ?? '');
        if (!in_array($jenis, $JENIS_VALID)) $jenis = 'Masjid'; // default jika tidak valid
        $jenis  = sanitizeStr($conn, $jenis, 50);
        $alamat = sanitizeStr($conn, $row[2] ?? '', 500);
        $radius = isset($row[3]) && is_numeric($row[3]) ? max(100, min(2000, (int)$row[3])) : 500;

        // Simpan tanpa koordinat — admin klik peta untuk melengkapi
        $conn->query("INSERT INTO rumah_ibadah (nama, jenis, alamat, radius, lat, lng)
                      VALUES ('$nama', '$jenis', '$alamat', $radius, 0, 0)");
        $sukses++;
        sse('row', ['num'=>$row_num,'total'=>$total,'status'=>'sukses','nama'=>$row[0],
                    'msg'=>"$jenis · radius {$radius}m · koordinat perlu dilengkapi manual"]);

    } else {
        // ── IMPORT PENDUDUK ──────────────────────────────────────────────────
        $nama   = sanitizeStr($conn, $row[0], 255);
        $jumlah = isset($row[1]) ? max(1, min(99, (int)$row[1])) : 1; // maks 99 anggota
        $alamat = trim(
            ($row[2]??'')
            . ($row[3]??'' ? ' RT '.($row[3]) : '')
            . ($row[4]??'' ? ' RW '.($row[4]) : '')
            . ($row[5]??'' ? ', Kel. '.($row[5]) : '')
            . ($row[6]??'' ? ', Kec. '.($row[6]) : '')
        );
        $alamat_db = sanitizeStr($conn, $alamat, 500);
        // Simpan tanpa koordinat — admin klik peta untuk melengkapi
        $conn->query("INSERT INTO penduduk_miskin (nama_kepala, jumlah_anggota, alamat, lat, lng)
                      VALUES ('$nama', $jumlah, '$alamat_db', NULL, NULL)");
        $inserted_pm_ids[] = (int)$conn->insert_id;
        $sukses++;
        sse('row', ['num'=>$row_num,'total'=>$total,'status'=>'sukses','nama'=>$row[0],
                    'msg'=>'Tersimpan · koordinat perlu dilengkapi manual']);
    }


}
fclose($handle);

// ── UPDATE COVERAGE (hanya untuk PM yang baru diimport, bukan semua) ──────────
if ($import_type === 'penduduk' && !empty($inserted_pm_ids)) {
    $ids_str  = implode(',', $inserted_pm_ids);
    $ri_all   = $conn->query("SELECT * FROM rumah_ibadah");
    $ri_list  = [];
    while ($ri = $ri_all->fetch_assoc()) $ri_list[] = $ri;

    $penduduk = $conn->query("SELECT id, lat, lng FROM penduduk_miskin WHERE id IN ($ids_str) AND lat IS NOT NULL AND lng IS NOT NULL");
    while ($p = $penduduk->fetch_assoc()) {
        $jarak_min = INF; $terdekat_id = 'NULL';
        foreach ($ri_list as $ri) {
            $earthR = 6371000;
            $dLat = deg2rad($ri['lat'] - $p['lat']); $dLon = deg2rad($ri['lng'] - $p['lng']);
            $a = sin($dLat/2)**2 + cos(deg2rad($p['lat'])) * cos(deg2rad($ri['lat'])) * sin($dLon/2)**2;
            $jarak = $earthR * 2 * atan2(sqrt($a), sqrt(1-$a));
            if ($jarak <= $ri['radius'] && $jarak < $jarak_min) { $jarak_min = $jarak; $terdekat_id = $ri['id']; }
        }
        $conn->query("UPDATE penduduk_miskin SET id_rumah_ibadah = $terdekat_id WHERE id = " . $p['id']);
    }
}

// ── DONE ──────────────────────────────────────────────────────────────────────
$label = $import_type === 'ri' ? 'Rumah Ibadah' : 'Penduduk';
$msg   = "Import $label selesai. $sukses data berhasil diimpor.";
if ($dilewati > 0) $msg .= " ($dilewati baris dilewati karena kosong/invalid.)";
$msg  .= " Koordinat perlu dilengkapi manual melalui klik peta.";

sse('done', ['sukses'=>$sukses,'gagal'=>$gagal,'dilewati'=>$dilewati,'total'=>$total,'msg'=>$msg]);