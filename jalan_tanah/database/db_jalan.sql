-- ============================================
-- WebGIS - Manajemen Jalan, Parsil & Laporan Jalan Rusak
-- Database Schema (GeoJSON-based)
-- ============================================

CREATE DATABASE IF NOT EXISTS db_jalan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_jalan;

-- ============================================
-- Tabel Data Jalan (GeoJSON LineString)
-- ============================================
CREATE TABLE IF NOT EXISTS data_jalan (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nama_jalan    VARCHAR(255) NOT NULL,
    status_jalan  ENUM('Nasional','Provinsi','Kabupaten') NOT NULL,
    panjang_meter DOUBLE NOT NULL DEFAULT 0,
    keterangan    TEXT,
    -- GeoJSON LineString: {"type":"LineString","coordinates":[[lng,lat],...]}
    geom          JSON NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================
-- Tabel Data Parsil Tanah (GeoJSON Polygon)
-- ============================================
CREATE TABLE IF NOT EXISTS data_parsil (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    nama_parsil        VARCHAR(255) NOT NULL,
    pemilik            VARCHAR(255) NOT NULL,
    status_sertifikat  ENUM('SHM','HGB','HGU','HP') NOT NULL,
    nomor_sertifikat   VARCHAR(100),
    luas_meter2        DOUBLE NOT NULL DEFAULT 0,
    keterangan         TEXT,
    -- GeoJSON Polygon: {"type":"Polygon","coordinates":[[[lng,lat],...,[lng,lat]]]}
    geom               JSON NOT NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================
-- Tabel Laporan Jalan Rusak (GeoJSON Point)
-- ============================================
CREATE TABLE IF NOT EXISTS laporan_jalan_rusak (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nama_pelapor  VARCHAR(255),                           -- opsional
    nama_jalan    VARCHAR(255) NOT NULL,                  -- untuk analisis frekuensi per jalan
    deskripsi     TEXT,
    foto_path     VARCHAR(500),                           -- path relatif file foto
    foto_lat      DOUBLE,                                 -- dari metadata exif atau manual
    foto_lng      DOUBLE,                                 -- dari metadata exif atau manual
    foto_datetime DATETIME,                               -- dari metadata exif
    -- GeoJSON Point: {"type":"Point","coordinates":[lng,lat]}
    geom          JSON NOT NULL,
    status        ENUM('pending','verified','resolved') NOT NULL DEFAULT 'pending',
    tanggal_input TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Index untuk query geospasial & analitik
CREATE INDEX idx_laporan_tanggal   ON laporan_jalan_rusak(tanggal_input);
CREATE INDEX idx_laporan_nama_jalan ON laporan_jalan_rusak(nama_jalan);
CREATE INDEX idx_laporan_status    ON laporan_jalan_rusak(status);
CREATE INDEX idx_jalan_status      ON data_jalan(status_jalan);
CREATE INDEX idx_parsil_status     ON data_parsil(status_sertifikat);

-- ============================================
-- Sample Data Jalan (GeoJSON)
-- ============================================
INSERT INTO data_jalan (nama_jalan, status_jalan, panjang_meter, keterangan, geom) VALUES
(
    'Jalan Trans Kalimantan', 'Nasional', 2500.50,
    'Jalan utama lintas Kalimantan',
    '{"type":"LineString","coordinates":[[109.3425,0.0263],[109.3500,0.0270],[109.3580,0.0280],[109.3650,0.0290]]}'
),
(
    'Jalan Soekarno-Hatta', 'Provinsi', 1800.75,
    'Jalan provinsi kawasan kota',
    '{"type":"LineString","coordinates":[[109.3300,0.0200],[109.3380,0.0210],[109.3460,0.0225]]}'
),
(
    'Jalan Parit Baru', 'Kabupaten', 950.25,
    'Jalan kabupaten menuju kawasan industri',
    '{"type":"LineString","coordinates":[[109.3200,0.0150],[109.3270,0.0160],[109.3320,0.0175]]}'
);

-- ============================================
-- Sample Data Parsil (GeoJSON)
-- ============================================
INSERT INTO data_parsil (nama_parsil, pemilik, status_sertifikat, nomor_sertifikat, luas_meter2, keterangan, geom) VALUES
(
    'Kavling A1', 'Budi Santoso', 'SHM', 'SHM-001/2020', 450.50,
    'Kavling perumahan blok A',
    '{"type":"Polygon","coordinates":[[[109.3320,0.0230],[109.3330,0.0230],[109.3330,0.0235],[109.3320,0.0235],[109.3320,0.0230]]]}'
),
(
    'Kavling B3', 'PT Maju Bersama', 'HGB', 'HGB-023/2019', 1250.00,
    'Kavling komersial blok B',
    '{"type":"Polygon","coordinates":[[[109.3350,0.0240],[109.3365,0.0240],[109.3365,0.0250],[109.3350,0.0250],[109.3350,0.0240]]]}'
),
(
    'Lahan Usaha C1', 'CV Subur Makmur', 'HGU', 'HGU-007/2018', 5800.00,
    'Lahan perkebunan',
    '{"type":"Polygon","coordinates":[[[109.3270,0.0180],[109.3295,0.0180],[109.3295,0.0195],[109.3270,0.0195],[109.3270,0.0180]]]}'
);

-- ============================================
-- Sample Laporan Jalan Rusak
-- ============================================
INSERT INTO laporan_jalan_rusak (nama_pelapor, nama_jalan, deskripsi, foto_lat, foto_lng, geom, status, tanggal_input) VALUES
('Ahmad Fauzi',   'Jalan Trans Kalimantan', 'Aspal berlubang besar, berbahaya saat hujan', 0.0268, 109.3460, '{"type":"Point","coordinates":[109.3460,0.0268]}', 'pending',  NOW() - INTERVAL 5 DAY),
('Siti Rahayu',   'Jalan Trans Kalimantan', 'Jalan retak dan bergelombang',                0.0271, 109.3465, '{"type":"Point","coordinates":[109.3465,0.0271]}', 'pending',  NOW() - INTERVAL 8 DAY),
('Budi Hartono',  'Jalan Trans Kalimantan', 'Lubang besar di tengah jalan',                0.0269, 109.3462, '{"type":"Point","coordinates":[109.3462,0.0269]}', 'verified', NOW() - INTERVAL 3 DAY),
('Dewi Lestari',  'Jalan Soekarno-Hatta',  'Marka jalan hilang, genangan air',            0.0215, 109.3385, '{"type":"Point","coordinates":[109.3385,0.0215]}', 'pending',  NOW() - INTERVAL 15 DAY),
('Eko Prasetyo',  'Jalan Soekarno-Hatta',  'Bahu jalan rusak',                            0.0218, 109.3388, '{"type":"Point","coordinates":[109.3388,0.0218]}', 'pending',  NOW() - INTERVAL 20 DAY),
('Fitri Handayani','Jalan Parit Baru',      'Aspal terkelupas',                            0.0165, 109.3275, '{"type":"Point","coordinates":[109.3275,0.0165]}', 'resolved', NOW() - INTERVAL 45 DAY),
(NULL,            'Jalan Trans Kalimantan', 'Permukaan jalan sangat rusak parah',          0.0272, 109.3468, '{"type":"Point","coordinates":[109.3468,0.0272]}', 'pending',  NOW() - INTERVAL 2 DAY),
('Hendra Wijaya', 'Jalan Trans Kalimantan', 'Lubang dalam di tepi jalan',                  0.0266, 109.3458, '{"type":"Point","coordinates":[109.3458,0.0266]}', 'pending',  NOW() - INTERVAL 1 DAY);
