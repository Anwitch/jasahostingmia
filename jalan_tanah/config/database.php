<?php
// ============================================
// config/database.php
// ============================================

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_NAME', getenv('DB_NAME') ?: 'db_jalan');

// Path untuk upload foto laporan (relatif dari root project)
define('UPLOAD_DIR', __DIR__ . '/../uploads/laporan/');
define('UPLOAD_URL', 'uploads/laporan/');
define('MAX_FILE_SIZE', 8 * 1024 * 1024); // 8MB
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

function getConnection(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_error) {
        http_response_code(500);
        die(json_encode(['status' => 'error', 'message' => 'Koneksi database gagal: ' . $conn->connect_error]));
    }
    
    // Check if database exists
    $dbCheck = $conn->query("SHOW DATABASES LIKE '" . DB_NAME . "'");
    $dbExists = ($dbCheck && $dbCheck->num_rows > 0);
    
    if (!$dbExists) {
        $conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->select_db(DB_NAME);
        
        // Load db_jalan.sql to seed tables
        $sqlPath = __DIR__ . '/../database/db_jalan.sql';
        if (file_exists($sqlPath)) {
            $sqlContent = file_get_contents($sqlPath);
            // Remove USE command from raw SQL to avoid conflicts
            $sqlContent = preg_replace('/USE\s+[a-zA-Z0-9_]+;/i', '', $sqlContent);
            $conn->multi_query($sqlContent);
            // Clear multi-query results to prevent synch error
            do {
                if ($res = $conn->store_result()) {
                    $res->free();
                }
            } while ($conn->next_result());
        }
    } else {
        $conn->select_db(DB_NAME);
    }
    
    $conn->set_charset('utf8mb4');
    return $conn;
}

// CORS & JSON headers — panggil sekali dari tiap API entry point
function setCorsHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// Helper: baca JSON body
function getJsonInput(): ?array {
    $raw = file_get_contents('php://input');
    if (!$raw) return null;
    $data = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
}

// Helper: validasi & encode GeoJSON dari array koordinat [lat,lng]
// Frontend tetap kirim [lat,lng] (Leaflet-style), kita konversi ke GeoJSON [lng,lat]
function buildGeoJsonLine(array $coords): string {
    $gjCoords = array_map(fn($p) => [(float)$p[1], (float)$p[0]], $coords);
    return json_encode(['type' => 'LineString', 'coordinates' => $gjCoords]);
}

function buildGeoJsonPolygon(array $coords): string {
    $gjCoords = array_map(fn($p) => [(float)$p[1], (float)$p[0]], $coords);
    // tutup ring jika belum
    if ($gjCoords[0] !== end($gjCoords)) $gjCoords[] = $gjCoords[0];
    return json_encode(['type' => 'Polygon', 'coordinates' => [$gjCoords]]);
}

function buildGeoJsonPoint(float $lat, float $lng): string {
    return json_encode(['type' => 'Point', 'coordinates' => [$lng, $lat]]);
}

// Helper: parse GeoJSON → array [lat,lng] (untuk dikirim ke frontend/Leaflet)
function parseGeoJsonToLatLng(?string $geomStr): array {
    if (!$geomStr) return [];
    $geom = json_decode($geomStr, true);
    if (!$geom) return [];

    switch ($geom['type'] ?? '') {
        case 'LineString':
            return array_map(fn($c) => [$c[1], $c[0]], $geom['coordinates']);
        case 'Polygon':
            return array_map(fn($c) => [$c[1], $c[0]], $geom['coordinates'][0]);
        case 'Point':
            return [$geom['coordinates'][1], $geom['coordinates'][0]];
        default:
            return [];
    }
}
