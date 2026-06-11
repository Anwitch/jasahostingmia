<?php
// api/laporan.php — CRUD + Analitik Laporan Jalan Rusak

require_once '../config/database.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$conn   = getConnection();
$action = $_GET['action'] ?? '';

// ---- Endpoint analitik terpisah ----
if ($method === 'GET' && $action === 'analytics') {
    handleAnalytics($conn);
    $conn->close();
    exit;
}

switch ($method) {

    // ==========================================
    // GET — list dengan filter
    // ==========================================
    case 'GET':
        if (isset($_GET['id'])) {
            $id   = intval($_GET['id']);
            $stmt = $conn->prepare("SELECT * FROM laporan_jalan_rusak WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Tidak ditemukan']); break; }
            $row = formatLaporanRow($row);
            echo json_encode(['status'=>'success','data'=>$row]);
        } else {
            $where  = ['1=1'];
            $params = [];
            $types  = '';

            // Filter: bulan terakhir (default), atau rentang tahun
            if (!empty($_GET['bulan_terakhir'])) {
                $bulan    = intval($_GET['bulan_terakhir']);
                $where[]  = 'tanggal_input >= DATE_SUB(NOW(), INTERVAL ? MONTH)';
                $params[] = $bulan;
                $types   .= 'i';
            } elseif (!empty($_GET['tahun_terakhir'])) {
                $tahun    = intval($_GET['tahun_terakhir']);
                $where[]  = 'tanggal_input >= DATE_SUB(NOW(), INTERVAL ? YEAR)';
                $params[] = $tahun;
                $types   .= 'i';
            }

            if (!empty($_GET['status'])) {
                $where[]  = 'status = ?';
                $params[] = $_GET['status'];
                $types   .= 's';
            }
            if (!empty($_GET['nama_jalan'])) {
                $where[]  = 'nama_jalan LIKE ?';
                $params[] = '%'.$_GET['nama_jalan'].'%';
                $types   .= 's';
            }
            if (!empty($_GET['q'])) {
                $where[]  = '(nama_jalan LIKE ? OR deskripsi LIKE ? OR nama_pelapor LIKE ?)';
                $params[] = '%'.$_GET['q'].'%';
                $params[] = '%'.$_GET['q'].'%';
                $params[] = '%'.$_GET['q'].'%';
                $types   .= 'sss';
            }

            $sql  = 'SELECT * FROM laporan_jalan_rusak WHERE ' . implode(' AND ', $where) . ' ORDER BY tanggal_input DESC';
            $stmt = $conn->prepare($sql);
            if ($params) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $data = array_map('formatLaporanRow', $rows);

            // Hitung cluster (radius 50m, min 3 laporan → urgent)
            $clusters = buildClusters($data, 50, 3);

            echo json_encode(['status'=>'success','data'=>$data,'total'=>count($data),'clusters'=>$clusters]);
        }
        break;

    // ==========================================
    // POST — Tambah laporan (multipart/form-data)
    // ==========================================
    case 'POST':
        $nama_jalan  = trim($_POST['nama_jalan']  ?? '');
        $deskripsi   = trim($_POST['deskripsi']   ?? '');
        $nama_pelapor= trim($_POST['nama_pelapor']?? '');
        $lat         = (float)($_POST['lat']      ?? 0);
        $lng         = (float)($_POST['lng']      ?? 0);

        if (!$nama_jalan || !$lat || !$lng) {
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Nama jalan dan koordinat wajib diisi']);
            break;
        }

        $foto_path     = null;
        $foto_lat      = null;
        $foto_lng      = null;
        $foto_datetime = null;

        // Handle upload foto
        if (!empty($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES['foto']['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, ALLOWED_TYPES)) {
                http_response_code(400);
                echo json_encode(['status'=>'error','message'=>'Format foto tidak didukung (JPG/PNG/WebP)']);
                break;
            }
            if ($_FILES['foto']['size'] > MAX_FILE_SIZE) {
                http_response_code(400);
                echo json_encode(['status'=>'error','message'=>'Ukuran foto melebihi batas 8MB']);
                break;
            }

            // Baca EXIF sebelum pindah file
            $exifData = @exif_read_data($_FILES['foto']['tmp_name']);
            if ($exifData) {
                // Ekstrak koordinat GPS dari EXIF
                $gpsCoords = extractExifGps($exifData);
                if ($gpsCoords) {
                    $foto_lat = $gpsCoords['lat'];
                    $foto_lng = $gpsCoords['lng'];
                }
                // Ekstrak datetime
                $dt = $exifData['DateTimeOriginal'] ?? $exifData['DateTime'] ?? null;
                if ($dt) {
                    $foto_datetime = date('Y-m-d H:i:s', strtotime(str_replace(':', '-', substr($dt, 0, 10)) . substr($dt, 10)));
                }
            }

            // Jika GPS tidak ada di EXIF, pakai koordinat yang dikirim user
            if (!$foto_lat) $foto_lat = $lat;
            if (!$foto_lng) $foto_lng = $lng;

            if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
            $ext       = match($mime) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg' };
            $filename  = 'laporan_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            $destPath  = UPLOAD_DIR . $filename;

            if (!move_uploaded_file($_FILES['foto']['tmp_name'], $destPath)) {
                http_response_code(500);
                echo json_encode(['status'=>'error','message'=>'Gagal menyimpan foto']);
                break;
            }
            $foto_path = UPLOAD_URL . $filename;
        }

        // Koordinat point: prioritaskan GPS foto, fallback ke input user
        $pointLat = $foto_lat ?? $lat;
        $pointLng = $foto_lng ?? $lng;
        $geom     = buildGeoJsonPoint($pointLat, $pointLng);

        $stmt = $conn->prepare(
            "INSERT INTO laporan_jalan_rusak (nama_pelapor,nama_jalan,deskripsi,foto_path,foto_lat,foto_lng,foto_datetime,geom)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $npVal = $nama_pelapor ?: null;
        $stmt->bind_param("ssssddss", $npVal, $nama_jalan, $deskripsi, $foto_path, $foto_lat, $foto_lng, $foto_datetime, $geom);

        if ($stmt->execute()) {
            echo json_encode(['status'=>'success','message'=>'Laporan berhasil dikirim','id'=>$conn->insert_id]);
        } else {
            http_response_code(500);
            echo json_encode(['status'=>'error','message'=>'Gagal menyimpan: '.$conn->error]);
        }
        break;

    // ==========================================
    // PUT — Update status laporan
    // ==========================================
    case 'PUT':
        $input = getJsonInput();
        $id    = intval($_GET['id'] ?? 0);
        if (!$id || !$input) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'ID dan input wajib ada']); break; }

        $status = trim($input['status'] ?? '');
        if (!in_array($status, ['pending','verified','resolved'])) {
            http_response_code(400); echo json_encode(['status'=>'error','message'=>'Status tidak valid']); break;
        }

        $stmt = $conn->prepare("UPDATE laporan_jalan_rusak SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $id);
        if ($stmt->execute()) {
            echo json_encode(['status'=>'success','message'=>'Status laporan diperbarui']);
        } else {
            http_response_code(500); echo json_encode(['status'=>'error','message'=>$conn->error]);
        }
        break;

    // ==========================================
    // DELETE
    // ==========================================
    case 'DELETE':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'ID tidak valid']); break; }

        // Hapus file foto juga
        $stmt = $conn->prepare("SELECT foto_path FROM laporan_jalan_rusak WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && $row['foto_path']) {
            $fullPath = __DIR__ . '/../' . $row['foto_path'];
            if (file_exists($fullPath)) @unlink($fullPath);
        }

        $stmt = $conn->prepare("DELETE FROM laporan_jalan_rusak WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            echo json_encode(['status'=>'success','message'=>'Laporan berhasil dihapus']);
        } else {
            http_response_code(404); echo json_encode(['status'=>'error','message'=>'Data tidak ditemukan']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['status'=>'error','message'=>'Method tidak diizinkan']);
}

$conn->close();

// ============================================
// HELPERS
// ============================================

function formatLaporanRow(array $row): array {
    $geom = json_decode($row['geom'] ?? '{}', true);
    $row['lat'] = $geom['coordinates'][1] ?? null;
    $row['lng'] = $geom['coordinates'][0] ?? null;
    unset($row['geom']);
    return $row;
}

function extractExifGps(array $exif): ?array {
    if (empty($exif['GPSLatitude']) || empty($exif['GPSLongitude'])) return null;
    $lat = gpsToDecimal($exif['GPSLatitude'],  $exif['GPSLatitudeRef']  ?? 'N');
    $lng = gpsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
    return ['lat' => $lat, 'lng' => $lng];
}

function gpsToDecimal(array $parts, string $ref): float {
    $deg = evalFraction($parts[0]);
    $min = evalFraction($parts[1]);
    $sec = evalFraction($parts[2]);
    $dec = $deg + ($min / 60) + ($sec / 3600);
    return in_array($ref, ['S','W']) ? -$dec : $dec;
}

function evalFraction(string $frac): float {
    if (strpos($frac, '/') === false) return (float)$frac;
    [$n, $d] = explode('/', $frac);
    return $d ? (float)$n / (float)$d : 0;
}

/**
 * Hitung jarak Haversine antara dua titik (meter)
 */
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R  = 6371000;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lng2 - $lng1);
    $a  = sin($dp/2)**2 + cos($p1)*cos($p2)*sin($dl/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

/**
 * Bangun cluster sederhana: greedy radius clustering
 * Laporan dalam radius $radiusM meter dianggap satu cluster.
 * Cluster dengan >= $minCount laporan ditandai urgent.
 */
function buildClusters(array $laporan, float $radiusM, int $minCount): array {
    $clusters  = [];
    $assigned  = [];

    foreach ($laporan as $i => $l) {
        if (isset($assigned[$i])) continue;
        if ($l['lat'] === null) continue;

        $cluster   = [$i];
        $assigned[$i] = true;

        foreach ($laporan as $j => $m) {
            if ($j === $i || isset($assigned[$j])) continue;
            if ($m['lat'] === null) continue;
            if (haversine($l['lat'], $l['lng'], $m['lat'], $m['lng']) <= $radiusM) {
                $cluster[] = $j;
                $assigned[$j] = true;
            }
        }

        if (count($cluster) >= $minCount) {
            // Centroid
            $cLat = array_sum(array_map(fn($k) => $laporan[$k]['lat'], $cluster)) / count($cluster);
            $cLng = array_sum(array_map(fn($k) => $laporan[$k]['lng'], $cluster)) / count($cluster);
            $ids  = array_map(fn($k) => $laporan[$k]['id'], $cluster);
            $jalanNames = array_unique(array_map(fn($k) => $laporan[$k]['nama_jalan'], $cluster));

            $clusters[] = [
                'lat'        => $cLat,
                'lng'        => $cLng,
                'count'      => count($cluster),
                'ids'        => $ids,
                'nama_jalan' => implode(', ', $jalanNames),
                'urgent'     => count($cluster) >= $minCount,
            ];
        }
    }

    return $clusters;
}

/**
 * Analitik: frekuensi kerusakan per jalan, per periode
 */
function handleAnalytics(mysqli $conn): void {
    $tahun = intval($_GET['tahun'] ?? 1);
    if (!in_array($tahun, [1,2,3,5,10])) $tahun = 1;

    // Frekuensi per jalan
    $stmt = $conn->prepare(
        "SELECT nama_jalan,
                COUNT(*) AS total,
                SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) AS resolved,
                SUM(CASE WHEN status='verified' THEN 1 ELSE 0 END) AS verified,
                SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END) AS pending,
                MIN(tanggal_input) AS pertama,
                MAX(tanggal_input) AS terakhir
         FROM laporan_jalan_rusak
         WHERE tanggal_input >= DATE_SUB(NOW(), INTERVAL ? YEAR)
         GROUP BY nama_jalan
         ORDER BY total DESC"
    );
    $stmt->bind_param("i", $tahun);
    $stmt->execute();
    $perJalan = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Tren per bulan (12 bulan terakhir)
    $tren = $conn->query(
        "SELECT DATE_FORMAT(tanggal_input,'%Y-%m') AS bulan, COUNT(*) AS total
         FROM laporan_jalan_rusak
         WHERE tanggal_input >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
         GROUP BY bulan ORDER BY bulan ASC"
    )->fetch_all(MYSQLI_ASSOC);

    // Sering rusak: jalan dengan total >= 3 dalam rentang waktu
    $seringRusak = array_filter($perJalan, fn($r) => (int)$r['total'] >= 3);

    echo json_encode([
        'status'       => 'success',
        'per_jalan'    => $perJalan,
        'tren_bulanan' => $tren,
        'sering_rusak' => array_values($seringRusak),
        'rentang_tahun'=> $tahun,
    ]);
}
