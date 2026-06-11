-- ============================================================
--  WebGIS Bantuan Sosial — Setup Database
--  Informatika UNTAN · GIS Project
--  Jalankan file ini sekali pada database kosong.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
--  1. RUMAH IBADAH
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rumah_ibadah (
    id      INT          AUTO_INCREMENT PRIMARY KEY,
    nama    VARCHAR(255) NOT NULL,
    jenis   VARCHAR(50)  NOT NULL DEFAULT 'Masjid'
                         COMMENT 'Masjid | Gereja Protestan | Gereja Katolik | Vihara | Pura | Kelenteng',
    alamat  TEXT         NULL,
    radius  INT          NOT NULL DEFAULT 500 COMMENT 'Radius cakupan dalam meter',
    lat     DOUBLE       NOT NULL,
    lng     DOUBLE       NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  2. PENDUDUK MISKIN
--     lat & lng boleh NULL untuk data yang belum digeocoding
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS penduduk_miskin (
    id                INT          AUTO_INCREMENT PRIMARY KEY,
    nama_kepala       VARCHAR(255) NOT NULL,
    jumlah_anggota    INT          NOT NULL DEFAULT 1,
    lat               DOUBLE       NULL DEFAULT NULL,
    lng               DOUBLE       NULL DEFAULT NULL,
    id_rumah_ibadah   INT          NULL DEFAULT NULL,
    foto_rumah        VARCHAR(255) NULL DEFAULT NULL COMMENT 'Nama file di uploads/foto_rumah/',
    status_bantuan    ENUM('sudah','belum') NOT NULL DEFAULT 'belum',
    bulan_status      VARCHAR(7)   NULL DEFAULT NULL COMMENT 'Format YYYY-MM',
    alamat            VARCHAR(500) NULL DEFAULT NULL,
    status_geocoding  ENUM('sukses','gagal') NULL DEFAULT NULL
                      COMMENT 'NULL = input manual, sukses/gagal = dari import CSV',
    CONSTRAINT fk_pm_ri
        FOREIGN KEY (id_rumah_ibadah)
        REFERENCES rumah_ibadah (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  3. HISTORI BANTUAN
--     Rekam jejak penyaluran per KK per bulan
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS histori_bantuan (
    id                  INT      AUTO_INCREMENT PRIMARY KEY,
    id_penduduk_miskin  INT      NOT NULL,
    id_rumah_ibadah     INT      NOT NULL,
    tanggal_penyaluran  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    bulan               INT      NOT NULL COMMENT '1–12',
    tahun               INT      NOT NULL COMMENT 'Contoh: 2026',
    foto_bukti          VARCHAR(255) NOT NULL COMMENT 'Nama file di uploads/foto_bukti/',
    keterangan          TEXT     NULL COMMENT 'Catatan logistik opsional',
    CONSTRAINT fk_hb_pm
        FOREIGN KEY (id_penduduk_miskin)
        REFERENCES penduduk_miskin (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_hb_ri
        FOREIGN KEY (id_rumah_ibadah)
        REFERENCES rumah_ibadah (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  4. USERS
--     role admin              : akses penuh
--     role koordinator        : hanya RI yang ditugaskan
--     role pengambil_kebijakan: read-only semua data
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT          AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(100) NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL COMMENT 'bcrypt hash — gunakan password_hash()',
    role            ENUM('admin','koordinator','pengambil_kebijakan')
                                 NOT NULL DEFAULT 'koordinator',
    id_rumah_ibadah INT          NULL DEFAULT NULL
                    COMMENT 'Hanya diisi untuk role koordinator',
    nama_lengkap    VARCHAR(255) NULL DEFAULT NULL,
    no_wa           VARCHAR(20)  NULL DEFAULT NULL
                    COMMENT 'Hanya relevan untuk role koordinator',
    CONSTRAINT fk_user_ri
        FOREIGN KEY (id_rumah_ibadah)
        REFERENCES rumah_ibadah (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
--  5. AKUN PENGGUNA AWAL (Semua Role - Password: password)
-- ------------------------------------------------------------
INSERT INTO users (username, password, role, nama_lengkap)
VALUES 
('admin', '$2y$12$LFS3b.HIcFbkwh5wVCpuz.cwkvrmYhtA7h73OWZBcjlHI5EwPoVWm', 'admin', 'Administrator'),
('kebijakan', '$2y$12$LFS3b.HIcFbkwh5wVCpuz.cwkvrmYhtA7h73OWZBcjlHI5EwPoVWm', 'pengambil_kebijakan', 'Pengambil Kebijakan'),
('koord1', '$2y$12$LFS3b.HIcFbkwh5wVCpuz.cwkvrmYhtA7h73OWZBcjlHI5EwPoVWm', 'koordinator', 'Koordinator Wilayah 1'),
('koord2', '$2y$12$LFS3b.HIcFbkwh5wVCpuz.cwkvrmYhtA7h73OWZBcjlHI5EwPoVWm', 'koordinator', 'Koordinator Wilayah 2');

-- ============================================================
--  Struktur direktori upload yang harus dibuat di server:
--    uploads/
--    uploads/foto_rumah/
--    uploads/foto_bukti/
--  Pastikan folder tersebut writable oleh web server (chmod 755).
-- ============================================================