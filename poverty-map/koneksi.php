<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "webgis_bansos";

// 1. Koneksi awal ke MySQL host tanpa memilih database
$conn = new mysqli($host, $user, $pass);
if ($conn->connect_error) {
    die("Koneksi MySQL gagal: " . $conn->connect_error);
}

// 2. Cek apakah database sudah ada
$db_selected = $conn->select_db($db);

if (!$db_selected) {
    // 3. Buat database baru jika belum ada
    if ($conn->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        $conn->select_db($db);
        
        // 4. Baca dan eksekusi setup.sql
        $sqlPath = __DIR__ . '/setup.sql';
        if (file_exists($sqlPath)) {
            $sql = file_get_contents($sqlPath);
            if ($conn->multi_query($sql)) {
                // Konsumsi semua hasil dari multi_query untuk mengosongkan buffer MySQL
                do {
                    if ($result = $conn->store_result()) {
                        $result->free();
                    }
                } while ($conn->next_result());
            }
        }
    } else {
        die("Gagal membuat database: " . $conn->error);
    }
}

// 5. Konfigurasi encoding UTF-8
$conn->set_charset("utf8mb4");
?>