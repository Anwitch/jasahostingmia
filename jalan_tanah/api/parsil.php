<?php
// api/parsil.php — CRUD Data Parsil Tanah (GeoJSON Polygon)

require_once '../config/database.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$conn   = getConnection();

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id   = intval($_GET['id']);
            $stmt = $conn->prepare("SELECT id, nama_parsil, pemilik, status_sertifikat, nomor_sertifikat, luas_meter2, keterangan, geom, created_at FROM data_parsil WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Data tidak ditemukan']); break; }
            $row['koordinat'] = parseGeoJsonToLatLng($row['geom']);
            unset($row['geom']);
            echo json_encode(['status'=>'success','data'=>$row]);
        } else {
            $where  = ['1=1'];
            $params = [];
            $types  = '';

            if (!empty($_GET['status'])) {
                $where[]  = 'status_sertifikat = ?';
                $params[] = $_GET['status'];
                $types   .= 's';
            }
            if (!empty($_GET['min_luas'])) {
                $where[]  = 'luas_meter2 >= ?';
                $params[] = (float)$_GET['min_luas'];
                $types   .= 'd';
            }
            if (!empty($_GET['max_luas'])) {
                $where[]  = 'luas_meter2 <= ?';
                $params[] = (float)$_GET['max_luas'];
                $types   .= 'd';
            }
            if (!empty($_GET['q'])) {
                $where[]  = '(nama_parsil LIKE ? OR pemilik LIKE ?)';
                $params[] = '%'.$_GET['q'].'%';
                $params[] = '%'.$_GET['q'].'%';
                $types   .= 'ss';
            }

            $sql  = 'SELECT id, nama_parsil, pemilik, status_sertifikat, nomor_sertifikat, luas_meter2, keterangan, geom, created_at FROM data_parsil WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
            $stmt = $conn->prepare($sql);
            if ($params) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            $data = array_map(function($row) {
                $row['koordinat'] = parseGeoJsonToLatLng($row['geom']);
                unset($row['geom']);
                return $row;
            }, $rows);

            echo json_encode(['status'=>'success','data'=>$data,'total'=>count($data)]);
        }
        break;

    case 'POST':
        $input = getJsonInput();
        if (!$input) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Input tidak valid']); break; }

        $nama    = trim($input['nama_parsil']       ?? '');
        $pemilik = trim($input['pemilik']           ?? '');
        $status  = trim($input['status_sertifikat'] ?? '');
        $nosert  = trim($input['nomor_sertifikat']  ?? '');
        $luas    = (float)($input['luas_meter2']    ?? 0);
        $ket     = trim($input['keterangan']        ?? '');
        $coords  = $input['koordinat']               ?? [];

        if (!$nama || !$pemilik || !$status || count($coords) < 3) {
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Nama, pemilik, status, dan minimal 3 koordinat wajib diisi']);
            break;
        }
        if (!in_array($status, ['SHM','HGB','HGU','HP'])) {
            http_response_code(400); echo json_encode(['status'=>'error','message'=>'Status tidak valid']); break;
        }

        $geom = buildGeoJsonPolygon($coords);
        $stmt = $conn->prepare("INSERT INTO data_parsil (nama_parsil,pemilik,status_sertifikat,nomor_sertifikat,luas_meter2,keterangan,geom) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("ssssdss", $nama, $pemilik, $status, $nosert, $luas, $ket, $geom);

        if ($stmt->execute()) {
            echo json_encode(['status'=>'success','message'=>'Data parsil berhasil ditambahkan','id'=>$conn->insert_id]);
        } else {
            http_response_code(500); echo json_encode(['status'=>'error','message'=>'Gagal menyimpan: '.$conn->error]);
        }
        break;

    case 'PUT':
        $input = getJsonInput();
        $id    = intval($_GET['id'] ?? 0);
        if (!$id || !$input) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'ID dan input wajib ada']); break; }

        $nama    = trim($input['nama_parsil']       ?? '');
        $pemilik = trim($input['pemilik']           ?? '');
        $status  = trim($input['status_sertifikat'] ?? '');
        $nosert  = trim($input['nomor_sertifikat']  ?? '');
        $luas    = (float)($input['luas_meter2']    ?? 0);
        $ket     = trim($input['keterangan']        ?? '');
        $coords  = $input['koordinat']               ?? [];

        if (!$nama || !$pemilik || !$status) {
            http_response_code(400); echo json_encode(['status'=>'error','message'=>'Nama, pemilik, dan status wajib diisi']); break;
        }

        $geom = buildGeoJsonPolygon($coords);
        $stmt = $conn->prepare("UPDATE data_parsil SET nama_parsil=?,pemilik=?,status_sertifikat=?,nomor_sertifikat=?,luas_meter2=?,keterangan=?,geom=? WHERE id=?");
        $stmt->bind_param("ssssdssi", $nama, $pemilik, $status, $nosert, $luas, $ket, $geom, $id);

        if ($stmt->execute()) {
            echo json_encode(['status'=>'success','message'=>'Data parsil berhasil diperbarui']);
        } else {
            http_response_code(500); echo json_encode(['status'=>'error','message'=>'Gagal memperbarui: '.$conn->error]);
        }
        break;

    case 'DELETE':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'ID tidak valid']); break; }

        $stmt = $conn->prepare("DELETE FROM data_parsil WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            echo json_encode(['status'=>'success','message'=>'Data parsil berhasil dihapus']);
        } else {
            http_response_code(404); echo json_encode(['status'=>'error','message'=>'Data tidak ditemukan']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['status'=>'error','message'=>'Method tidak diizinkan']);
}

$conn->close();
