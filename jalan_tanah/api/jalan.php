<?php
// api/jalan.php — CRUD Data Jalan (GeoJSON LineString)

require_once '../config/database.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$conn   = getConnection();

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id   = intval($_GET['id']);
            $stmt = $conn->prepare("SELECT id, nama_jalan, status_jalan, panjang_meter, keterangan, geom, created_at, updated_at FROM data_jalan WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Data tidak ditemukan']); break; }
            $row['koordinat'] = parseGeoJsonToLatLng($row['geom']);
            unset($row['geom']);
            echo json_encode(['status'=>'success','data'=>$row]);
        } else {
            // Filter support: ?status=Nasional&min_panjang=100&max_panjang=5000&q=nama
            $where  = ['1=1'];
            $params = [];
            $types  = '';

            if (!empty($_GET['status'])) {
                $where[]  = 'status_jalan = ?';
                $params[] = $_GET['status'];
                $types   .= 's';
            }
            if (!empty($_GET['min_panjang'])) {
                $where[]  = 'panjang_meter >= ?';
                $params[] = (float)$_GET['min_panjang'];
                $types   .= 'd';
            }
            if (!empty($_GET['max_panjang'])) {
                $where[]  = 'panjang_meter <= ?';
                $params[] = (float)$_GET['max_panjang'];
                $types   .= 'd';
            }
            if (!empty($_GET['q'])) {
                $where[]  = 'nama_jalan LIKE ?';
                $params[] = '%' . $_GET['q'] . '%';
                $types   .= 's';
            }

            $sql  = 'SELECT id, nama_jalan, status_jalan, panjang_meter, keterangan, geom, created_at FROM data_jalan WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
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

        $nama    = trim($input['nama_jalan']   ?? '');
        $status  = trim($input['status_jalan'] ?? '');
        $panjang = (float)($input['panjang_meter'] ?? 0);
        $ket     = trim($input['keterangan']   ?? '');
        $coords  = $input['koordinat']          ?? [];

        if (!$nama || !$status || count($coords) < 2) {
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Nama, status, dan minimal 2 koordinat wajib diisi']);
            break;
        }
        if (!in_array($status, ['Nasional','Provinsi','Kabupaten'])) {
            http_response_code(400); echo json_encode(['status'=>'error','message'=>'Status tidak valid']); break;
        }

        $geom = buildGeoJsonLine($coords);
        $stmt = $conn->prepare("INSERT INTO data_jalan (nama_jalan,status_jalan,panjang_meter,keterangan,geom) VALUES (?,?,?,?,?)");
        $stmt->bind_param("ssdss", $nama, $status, $panjang, $ket, $geom);

        if ($stmt->execute()) {
            echo json_encode(['status'=>'success','message'=>'Data jalan berhasil ditambahkan','id'=>$conn->insert_id]);
        } else {
            http_response_code(500); echo json_encode(['status'=>'error','message'=>'Gagal menyimpan: '.$conn->error]);
        }
        break;

    case 'PUT':
        $input = getJsonInput();
        $id    = intval($_GET['id'] ?? 0);
        if (!$id || !$input) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'ID dan input wajib ada']); break; }

        $nama    = trim($input['nama_jalan']   ?? '');
        $status  = trim($input['status_jalan'] ?? '');
        $panjang = (float)($input['panjang_meter'] ?? 0);
        $ket     = trim($input['keterangan']   ?? '');
        $coords  = $input['koordinat']          ?? [];

        if (!$nama || !$status) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Nama dan status wajib diisi']); break; }

        $geom = buildGeoJsonLine($coords);
        $stmt = $conn->prepare("UPDATE data_jalan SET nama_jalan=?,status_jalan=?,panjang_meter=?,keterangan=?,geom=? WHERE id=?");
        $stmt->bind_param("ssdssi", $nama, $status, $panjang, $ket, $geom, $id);

        if ($stmt->execute()) {
            echo json_encode(['status'=>'success','message'=>'Data jalan berhasil diperbarui']);
        } else {
            http_response_code(500); echo json_encode(['status'=>'error','message'=>'Gagal memperbarui: '.$conn->error]);
        }
        break;

    case 'DELETE':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'ID tidak valid']); break; }

        $stmt = $conn->prepare("DELETE FROM data_jalan WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            echo json_encode(['status'=>'success','message'=>'Data jalan berhasil dihapus']);
        } else {
            http_response_code(404); echo json_encode(['status'=>'error','message'=>'Data tidak ditemukan']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['status'=>'error','message'=>'Method tidak diizinkan']);
}

$conn->close();
