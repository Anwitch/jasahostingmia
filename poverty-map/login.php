<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'koneksi.php';

// Handle logout action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Jika sudah login, langsung ke dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, role, id_rumah_ibadah, nama_lengkap FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true); // cegah session fixation
            $_SESSION['user_id']         = $user['id'];
            $_SESSION['username']        = $user['username'];
            $_SESSION['role']            = $user['role'];
            $_SESSION['id_rumah_ibadah'] = $user['id_rumah_ibadah'];
            $_SESSION['nama_lengkap']    = $user['nama_lengkap'] ?: $user['username'];
            header('Location: index.php');
            exit;
        } else {
            // Delay untuk memperlambat brute-force
            sleep(1);
            $error = 'Username atau password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — WebGIS Poverty Map</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: linear-gradient(135deg, #030e2c 0%, #05143b 50%, #163372 100%); }
        .card-shadow { box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-sm">

        <!-- Logo / Judul -->
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold text-white tracking-wide">WebGIS Poverty Map</h1>
            <p class="text-blue-200 text-sm mt-1">Informatika UNTAN &mdash; GIS Project</p>
        </div>

        <!-- Card Login -->
        <div class="bg-white rounded-2xl p-7 card-shadow">
            <?php if ($error): ?>
            <div class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-lg flex items-start gap-2">
                <span class="mt-0.5 flex-shrink-0">&#9888;</span>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">Username</label>
                    <input type="text" name="username"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        placeholder="Masukkan username"
                        class="w-full px-3 py-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                        autofocus required>
                </div>
                <div class="mb-6">
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="pwInput"
                            placeholder="Masukkan password"
                            class="w-full px-3 py-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition pr-10"
                            required>
                        <button type="button" onclick="togglePw()"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-sm"
                            tabindex="-1" id="pwToggle">&#128065;</button>
                    </div>
                </div>
                <button type="submit"
                    class="w-full bg-blue-700 hover:bg-blue-800 text-white font-bold py-2.5 rounded-lg transition text-sm tracking-wide">
                    Masuk
                </button>
            </form>
        </div>
    </div>

    <script>
    function togglePw() {
        const i = document.getElementById('pwInput');
        const b = document.getElementById('pwToggle');
        if (i.type === 'password') { i.type = 'text';     b.innerHTML = '&#128683;'; }
        else                       { i.type = 'password'; b.innerHTML = '&#128065;'; }
    }
    </script>
</body>
</html>
