<?php
session_start();
require '../koneksi.php';
$koneksi = $conn;

// Authentication check
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

// User data
$user_id = $_SESSION['user']['id'];
$namaPengguna = $_SESSION['user']['nama_lengkap'];
$level = $_SESSION['user']['level'];
$inisial = strtoupper(substr($namaPengguna, 0, 1));
$current_page = basename($_SERVER['PHP_SELF']);

// Get statistics for dashboard
if ($current_page == 'dashboard.php') {
    $stats = [
        'trip' => getCount($koneksi, 'trip_saya', $user_id),
        'penginapan' => getFeaturedCount($koneksi, 'penginapan'),
        'wisata' => getFeaturedCount($koneksi, 'wisata'),
        'kuliner' => getFeaturedCount($koneksi, 'kuliner'),
        'transportasi' => getFeaturedCount($koneksi, 'transportasi')
    ];
}

// Handle transportasi form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['item_id'])) {
    $item_id = $_POST['item_id'];
    $tanggal = $_POST['tanggal'];
    $waktu = $_POST['waktu'];
    $jumlah_orang = $_POST['jumlah_orang'];
    $min_budget = $_POST['min_budget'];
    $max_budget = $_POST['max_budget'];
    $lokasi_berangkat = $_POST['lokasi_berangkat'];
    $kota_tujuan = $_POST['kota_tujuan'];
    
    // Perbaikan di sini: Penanganan array yang benar
    $kategori_minat = isset($_POST['kategori_minat']) ? implode(',', $_POST['kategori_minat']) : '';
    
    // Get transportasi details
    $stmt = $koneksi->prepare("SELECT * FROM transportasi WHERE id = ?");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $transportasi = $stmt->get_result()->fetch_assoc();
    
    // Calculate total cost
    $biaya = $transportasi['harga'] * $jumlah_orang;
    
    // Apply discount if available
    if ($transportasi['is_promosi'] && $transportasi['harga_diskon'] > 0) {
        $biaya = $transportasi['harga_diskon'] * $jumlah_orang;
    }
    
    // Insert into trip_saya
    $insert_stmt = $koneksi->prepare("
        INSERT INTO trip_saya 
        (user_id, item_type, item_id, tanggal_kunjungan, waktu_kunjungan, biaya, 
        jumlah_orang, min_budget, max_budget, keterangan) 
        VALUES (?, 'transportasi', ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $keterangan = "Berangkat dari: " . $lokasi_berangkat . " | Kota Tujuan: " . $kota_tujuan . " | Minat: " . $kategori_minat;
    $insert_stmt->bind_param(
        "isssdiiis", 
        $user_id, 
        $item_id, 
        $tanggal, 
        $waktu, 
        $biaya,
        $jumlah_orang,
        $min_budget,
        $max_budget,
        $keterangan
    );
    
    if ($insert_stmt->execute()) {
        $success_message = "Transportasi berhasil ditambahkan ke trip Anda!";
        
        // Also save trip preferences for recommendations
        $pref_stmt = $koneksi->prepare("
            INSERT INTO trip_preferensi 
            (user_id, lokasi_tujuan, tanggal_mulai, durasi, kategori_minat) 
            VALUES (?, ?, ?, 1, ?)
        ");
        $pref_stmt->bind_param("isss", $user_id, $kota_tujuan, $tanggal, $kategori_minat);
        $pref_stmt->execute();
    } else {
        $error_message = "Gagal menambahkan transportasi ke trip: " . $koneksi->error;
    }
}

// Get filter parameters for transportasi
$filter_jenis = isset($_GET['jenis']) ? $_GET['jenis'] : '';
$filter_promosi = isset($_GET['promosi']) ? 1 : 0;
$filter_rekomendasi = isset($_GET['rekomendasi']) ? 1 : 0;
$filter_lokasi = isset($_GET['lokasi']) ? $_GET['lokasi'] : '';
$filter_harga_min = isset($_GET['harga_min']) ? (float)$_GET['harga_min'] : 0;
$filter_harga_max = isset($_GET['harga_max']) ? (float)$_GET['harga_max'] : 0;

// Build query with filters for transportasi
if ($current_page == 'transportasi.php') {
    $query = "SELECT * FROM transportasi WHERE status = 'approved'";
    $params = [];
    $types = '';

    if (!empty($filter_jenis)) {
        $query .= " AND jenis = ?";
        $params[] = $filter_jenis;
        $types .= 's';
    }

    if ($filter_promosi) {
        $query .= " AND is_promosi = 1";
    }

    if ($filter_rekomendasi) {
        $query .= " AND is_rekomendasi = 1";
    }

    if (!empty($filter_lokasi)) {
        $query .= " AND lokasi LIKE ?";
        $params[] = "%$filter_lokasi%";
        $types .= 's';
    }

    // Add price range filter
    if ($filter_harga_min > 0) {
        $query .= " AND (harga >= ? OR (is_promosi = 1 AND harga_diskon >= ?))";
        $params[] = $filter_harga_min;
        $params[] = $filter_harga_min;
        $types .= 'dd';
    }

    if ($filter_harga_max > 0) {
        $query .= " AND (harga <= ? OR (is_promosi = 1 AND harga_diskon <= ?))";
        $params[] = $filter_harga_max;
        $params[] = $filter_harga_max;
        $types .= 'dd';
    }

    $query .= " ORDER BY is_rekomendasi DESC, is_promosi DESC, nama_layanan ASC";

    $stmt = $koneksi->prepare($query);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $transportasi_items = $stmt->get_result();
}

// Get popular cities for destination dropdown
$popular_cities = ['Jakarta', 'Bandung', 'Yogyakarta', 'Bali', 'Surabaya', 'Medan', 'Makassar'];

// Helper functions
function getCount($conn, $table, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM $table WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

function getFeaturedCount($conn, $table) {
    $result = $conn->query("SELECT COUNT(*) as total FROM $table WHERE status='approved'");
    return $result->fetch_assoc()['total'];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ucfirst(str_replace('.php', '', $current_page)) ?> - Rencana-IN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        :root {
            --primary: #4CAF50;
            --primary-dark: #3d8b40;
            --secondary: #6c757d;
            --light: #f8f9fa;
            --dark: #343a40;
            --white: #ffffff;
            --sidebar-width: 250px;
            --header-height: 60px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: #495057;
            overflow-x: hidden;
        }

        /* Sidebar Styles */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--white);
            position: fixed;
            top: 0;
            left: 0;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .sidebar-brand img {
            width: 36px;
            height: 36px;
            margin-right: 10px;
            object-fit: contain;
        }

        .sidebar-brand h2 {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            color: var(--primary);
            white-space: nowrap;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 0;
        }

        .nav-item {
            position: relative;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .nav-link:hover, .nav-link.active {
            color: var(--primary);
            background-color: rgba(76, 175, 80, 0.1);
        }

        .nav-link.active {
            font-weight: 500;
            border-left: 3px solid var(--primary);
        }

        .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        /* Main Content Styles */
        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        .header {
            height: var(--header-height);
            background-color: var(--white);
            box-shadow: 0 0.15rem 1.75rem 0 rgba(33, 40, 50, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark);
            cursor: pointer;
            margin-right: 1rem;
        }

        .search-bar {
            position: relative;
            width: 300px;
            flex-grow: 1;
            max-width: 400px;
            margin: 0 1rem;
        }

        .search-bar input {
            width: 100%;
            padding: 0.375rem 0.75rem 0.375rem 2.5rem;
            border-radius: 0.25rem;
            border: 1px solid #e1e5eb;
            background-color: #f5f7fa;
            font-size: 0.875rem;
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
            font-size: 0.875rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            margin-left: auto;
        }

        .user-menu .dropdown-toggle {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .user-menu .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            margin-right: 0.5rem;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        /* Content Styles */
        .content {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Dashboard Specific Styles */
        .welcome-banner {
            background: linear-gradient(135deg, #4CAF50, #3d8b40);
            color: white;
            padding: 2rem;
            border-radius: 0.75rem;
            margin-bottom: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border-left: 4px solid #4CAF50;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: white;
            border-radius: 0.5rem;
            text-decoration: none;
            color: #2d3748;
            transition: all 0.2s;
        }

        /* Item List Styles */
        .trip-container {
            background: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .trip-list {
            margin-top: 1.5rem;
        }

        .trip-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #e1e5eb;
        }

        .trip-item:last-child {
            border-bottom: none;
        }

        .trip-item h4 {
            margin-bottom: 0.25rem;
        }

        .trip-item small {
            color: #6c757d;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #e1e5eb;
            border-radius: 0.25rem;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-secondary {
            background-color: var(--secondary);
        }
        
        .checkbox-group {
            display: flex;
            gap: 1rem;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Badges */
        .promo-badge {
            background-color: #ff5722;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            margin-left: 0.5rem;
        }
        
        .recommend-badge {
            background-color: #2196f3;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            margin-left: 0.5rem;
        }
        
        /* Price Styles */
        .price-original {
            text-decoration: line-through;
            color: #6c757d;
            margin-right: 0.5rem;
        }
        
        .price-discount {
            color: #e53935;
            font-weight: bold;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 2rem;
            border-radius: 0.5rem;
            width: 90%;
            max-width: 500px;
            position: relative;
        }
        
        .close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        /* Alert Messages */
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0.25rem;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        /* Location Picker */
        .location-picker {
            margin-top: 1rem;
        }
        
        .location-options {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .location-btn {
            padding: 0.25rem 0.5rem;
            background: #e9ecef;
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            font-size: 0.8rem;
        }
        
        .location-btn.active {
            background: var(--primary);
            color: white;
        }
        
        /* Map Container */
        #map-container {
            height: 200px;
            width: 100%;
            margin-top: 1rem;
            border-radius: 0.25rem;
            overflow: hidden;
            border: 1px solid #e1e5eb;
            display: none;
        }
        
        #map {
            height: 100%;
            width: 100%;
        }
        
        /* Additional styles for new features */
        .price-range-slider {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .price-range-slider input {
            flex: 1;
        }
        
        .price-range-values {
            display: flex;
            justify-content: space-between;
            margin-top: 0.5rem;
        }
        
        .interest-checkboxes {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .interest-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Responsive Styles */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .content {
                padding: 1.5rem 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .trip-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .interest-checkboxes {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Sidebar Overlay -->
    <div class="sidebar-overlay"></div>

    <!-- Sidebar Navigation -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="../asset/logo.png" alt="Rencana-IN Logo">
            <h2>Rencana-IN</h2>
        </div>
        <nav class="sidebar-nav">
            <ul style="list-style: none; padding: 0; margin: 0;">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="wisata.php" class="nav-link <?= $current_page == 'wisata.php' ? 'active' : '' ?>">
                        <i class="fas fa-umbrella-beach"></i>
                        <span>Wisata</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="transportasi.php" class="nav-link <?= $current_page == 'transportasi.php' ? 'active' : '' ?>">
                        <i class="fas fa-bus"></i>
                        <span>Transportasi</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="kuliner.php" class="nav-link <?= $current_page == 'kuliner.php' ? 'active' : '' ?>">
                        <i class="fas fa-utensils"></i>
                        <span>Kuliner</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="penginapan.php" class="nav-link <?= $current_page == 'penginapan.php' ? 'active' : '' ?>">
                        <i class="fas fa-hotel"></i>
                        <span>Penginapan</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="trip_saya.php" class="nav-link <?= $current_page == 'trip_saya.php' ? 'active' : '' ?>">
                        <i class="fas fa-map-marked-alt"></i>
                        <span>Trip Saya</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <!-- Main Content Area -->
    <div class="main">
        <header class="header">
            <button class="menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Cari...">
            </div>
            <div class="user-menu">
                <a href="#" class="dropdown-toggle">
                    <div class="avatar">
                        <?= $inisial ?>
                    </div>
                    <span><?= htmlspecialchars($namaPengguna) ?></span>
                    <i class="fas fa-caret-down ml-2"></i>
                </a>
            </div>
        </header>
        
        <div class="content">
            <?php if ($current_page == 'dashboard.php'): ?>
                <!-- Dashboard Content -->
                <div class="welcome-banner">
                    <h1>Selamat Datang, <?= htmlspecialchars($namaPengguna) ?></h1>
                    <p>Mulai rencanakan perjalanan Anda dengan Rencana-IN</p>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3><i class="fas fa-suitcase"></i> Trip Saya</h3>
                        <p class="stat-value"><?= $stats['trip'] ?></p>
                    </div>
                    <div class="stat-card">
                        <h3><i class="fas fa-hotel"></i> Penginapan</h3>
                        <p class="stat-value"><?= $stats['penginapan'] ?></p>
                    </div>
                    <div class="stat-card">
                        <h3><i class="fas fa-umbrella-beach"></i> Wisata</h3>
                        <p class="stat-value"><?= $stats['wisata'] ?></p>
                    </div>
                    <div class="stat-card">
                        <h3><i class="fas fa-utensils"></i> Kuliner</h3>
                        <p class="stat-value"><?= $stats['kuliner'] ?></p>
                    </div>
                    <div class="stat-card">
                        <h3><i class="fas fa-bus"></i> Transportasi</h3>
                        <p class="stat-value"><?= $stats['transportasi'] ?></p>
                    </div>
                </div>
                
                <h2>Quick Actions</h2>
                <div class="quick-actions">
                    <a href="trip_saya.php" class="action-btn">
                        <i class="fas fa-plus-circle"></i>
                        <span>Buat Trip Baru</span>
                    </a>
                    <a href="wisata.php" class="action-btn">
                        <i class="fas fa-search"></i>
                        <span>Cari Wisata</span>
                    </a>
                    <a href="penginapan.php" class="action-btn">
                        <i class="fas fa-hotel"></i>
                        <span>Cari Penginapan</span>
                    </a>
                    <a href="transportasi.php" class="action-btn">
                        <i class="fas fa-bus"></i>
                        <span>Cari Transportasi</span>
                    </a>
                </div>

            <?php elseif ($current_page == 'penginapan.php'): ?>
                <!-- Penginapan Content -->
                <div class="trip-container">
                    <h2>Penginapan Tersedia</h2>
                    <?php 
                    $penginapan_items = $koneksi->query("
                        SELECT * FROM penginapan 
                        WHERE status = 'approved'
                        ORDER BY nama_penginapan ASC
                    ");
                    ?>
                    
                    <?php if ($penginapan_items->num_rows > 0): ?>
                    <div class="trip-list">
                        <?php while($item = $penginapan_items->fetch_assoc()): ?>
                        <div class="trip-item">
                            <div>
                                <h4><?= htmlspecialchars($item['nama_penginapan']) ?></h4>
                                <small><?= htmlspecialchars($item['lokasi']) ?></small>
                            </div>
                            <div>
                                Rp <?= number_format($item['harga_per_malam'], 0, ',', '.') ?>/malam
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <p>Belum ada penginapan yang tersedia</p>
                    <?php endif; ?>
                </div>

            <?php elseif ($current_page == 'transportasi.php'): ?>
                <!-- Transportasi Content -->
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success">
                        <?= $success_message ?>
                        <a href="trip_saya.php" class="btn" style="margin-left: 1rem; padding: 0.25rem 0.5rem;">
                            Lihat Trip Saya
                        </a>
                    </div>
                <?php elseif (isset($error_message)): ?>
                    <div class="alert alert-danger">
                        <?= $error_message ?>
                    </div>
                <?php endif; ?>

                <div class="filter-section">
                    <h2>Filter Transportasi</h2>
                    <form method="GET" class="filter-form">
                        <div class="form-group">
                            <label for="jenis">Jenis Transportasi</label>
                            <select id="jenis" name="jenis" class="form-control">
                                <option value="">Semua Jenis</option>
                                <option value="mobil" <?= $filter_jenis == 'mobil' ? 'selected' : '' ?>>Mobil</option>
                                <option value="motor" <?= $filter_jenis == 'motor' ? 'selected' : '' ?>>Motor</option>
                                <option value="bus" <?= $filter_jenis == 'bus' ? 'selected' : '' ?>>Bus</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="lokasi">Lokasi</label>
                            <input type="text" id="lokasi" name="lokasi" class="form-control" 
                                   value="<?= htmlspecialchars($filter_lokasi) ?>" 
                                   placeholder="Lokasi transportasi">
                            <small id="current-location" style="display: block; margin-top: 0.5rem;"></small>
                        </div>
                        
                        <div class="form-group">
                            <label>Range Harga (Rp)</label>
                            <div class="price-range-slider">
                                <input type="range" id="harga_range" min="0" max="5000000" step="50000" 
                                       value="<?= $filter_harga_max ?: 5000000 ?>" 
                                       oninput="updatePriceRange(this.value, document.getElementById('harga_min'))">
                                <input type="number" id="harga_max" name="harga_max" class="form-control" 
                                       min="0" value="<?= $filter_harga_max ?>" placeholder="Max" 
                                       style="width: 100px;" onchange="updateSlider(this.value)">
                            </div>
                            <div class="price-range-values">
                                <span>0</span>
                                <span>5.000.000</span>
                            </div>
                            <input type="hidden" id="harga_min" name="harga_min" value="<?= $filter_harga_min ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Filter Lainnya</label>
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="promosi" <?= $filter_promosi ? 'checked' : '' ?>>
                                    Promosi
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="rekomendasi" <?= $filter_rekomendasi ? 'checked' : '' ?>>
                                    Rekomendasi
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <a href="transportasi.php" class="btn btn-secondary">
                                <i class="fas fa-sync-alt"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <div class="trip-container">
                    <h2>Transportasi Tersedia</h2>
                    
                    <?php if ($transportasi_items->num_rows > 0): ?>
                        <div class="trip-list">
                            <?php while($item = $transportasi_items->fetch_assoc()): ?>
                                <div class="trip-item">
                                    <div>
                                        <h4>
                                            <?= htmlspecialchars($item['nama_layanan']) ?>
                                            <?php if ($item['is_promosi']): ?>
                                                <span class="promo-badge">Promo</span>
                                            <?php endif; ?>
                                            <?php if ($item['is_rekomendasi']): ?>
                                                <span class="recommend-badge">Rekomendasi</span>
                                            <?php endif; ?>
                                        </h4>
                                        <small>
                                            <?= ucfirst($item['jenis']) ?> - 
                                            Kapasitas: <?= $item['kapasitas'] ?> orang - 
                                            Lokasi: <?= htmlspecialchars($item['lokasi']) ?>
                                        </small>
                                        <p><?= htmlspecialchars($item['deskripsi']) ?></p>
                                        <small>Rute: <?= htmlspecialchars($item['rute']) ?></small>
                                    </div>
                                    <div style="text-align: right;">
                                        <?php if ($item['is_promosi'] && $item['harga_diskon'] > 0): ?>
                                            <span class="price-original">
                                                Rp <?= number_format($item['harga'], 0, ',', '.') ?>
                                            </span>
                                            <span class="price-discount">
                                                Rp <?= number_format($item['harga_diskon'], 0, ',', '.') ?>
                                            </span>
                                        <?php else: ?>
                                            Rp <?= number_format($item['harga'], 0, ',', '.') ?>
                                        <?php endif; ?>
                                        <small style="display: block; color: #666;">per orang</small>
                                        <button class="btn" onclick="openBookingModal(<?= $item['id'] ?>, '<?= htmlspecialchars($item['nama_layanan']) ?>')" 
                                                style="display: block; width: 100%; margin-top: 0.5rem;">
                                            <i class="fas fa-plus"></i> Tambahkan
                                        </button>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p>Tidak ada transportasi yang tersedia dengan filter yang dipilih.</p>
                    <?php endif; ?>
                </div>

                <!-- Booking Modal -->
                <div id="bookingModal" class="modal">
                    <div class="modal-content">
                        <span class="close" onclick="closeModal()">&times;</span>
                        <h2 id="modalTitle">Tambahkan ke Trip</h2>
                        <form id="bookingForm" method="POST">
                            <input type="hidden" name="item_id" id="modalItemId">
                            
                            <div class="form-group">
                                <label for="tanggal">Tanggal</label>
                                <input type="date" id="tanggal" name="tanggal" class="form-control" required min="<?= date('Y-m-d') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="waktu">Waktu</label>
                                <input type="time" id="waktu" name="waktu" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="jumlah_orang">Jumlah Orang</label>
                                <input type="number" id="jumlah_orang" name="jumlah_orang" class="form-control" 
                                       min="1" max="50" value="1" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="min_budget">Budget Minimal (Rp)</label>
                                <input type="number" id="min_budget" name="min_budget" class="form-control" 
                                       min="0" value="0" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="max_budget">Budget Maksimal (Rp)</label>
                                <input type="number" id="max_budget" name="max_budget" class="form-control" 
                                       min="0" value="0" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="kota_tujuan">Kota Tujuan</label>
                                <select id="kota_tujuan" name="kota_tujuan" class="form-control" required>
                                    <option value="">Pilih Kota Tujuan</option>
                                    <?php foreach ($popular_cities as $city): ?>
                                        <option value="<?= htmlspecialchars($city) ?>"><?= htmlspecialchars($city) ?></option>
                                    <?php endforeach; ?>
                                    <option value="other">Lainnya</option>
                                </select>
                                <input type="text" id="other_kota_tujuan" name="other_kota_tujuan" class="form-control" 
                                       style="margin-top: 0.5rem; display: none;" placeholder="Masukkan kota tujuan">
                            </div>
                            
                            <div class="form-group">
                                <label>Kategori Minat (Pilih 1 atau lebih)</label>
                                <div class="interest-checkboxes">
                                    <label class="interest-checkbox">
                                        <input type="checkbox" name="kategori_minat[]" value="Alam"> Alam
                                    </label>
                                    <label class="interest-checkbox">
                                        <input type="checkbox" name="kategori_minat[]" value="Sejarah"> Sejarah
                                    </label>
                                    <label class="interest-checkbox">
                                        <input type="checkbox" name="kategori_minat[]" value="Budaya"> Budaya
                                    </label>
                                    <label class="interest-checkbox">
                                        <input type="checkbox" name="kategori_minat[]" value="Hiburan"> Hiburan
                                    </label>
                                    <label class="interest-checkbox">
                                        <input type="checkbox" name="kategori_minat[]" value="Belanja"> Belanja
                                    </label>
                                    <label class="interest-checkbox">
                                        <input type="checkbox" name="kategori_minat[]" value="Kuliner"> Kuliner
                                    </label>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="lokasi_berangkat">Lokasi Keberangkatan</label>
                                <div class="location-picker">
                                    <div class="location-options">
                                        <button type="button" class="location-btn active" data-type="manual">
                                            <i class="fas fa-keyboard"></i> Manual
                                        </button>
                                        <button type="button" class="location-btn" data-type="current">
                                            <i class="fas fa-location-arrow"></i> Lokasi Saya
                                        </button>
                                        <button type="button" class="location-btn" data-type="map">
                                            <i class="fas fa-map-marked-alt"></i> Pilih di Peta
                                        </button>
                                    </div>
                                    
                                    <input type="text" id="lokasi_berangkat" name="lokasi_berangkat" 
                                           class="form-control" placeholder="Masukkan alamat keberangkatan" required>
                                    
                                    <div id="map-container">
                                        <div id="map"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn" style="margin-top: 1rem;">
                                <i class="fas fa-save"></i> Simpan
                            </button>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- Default Content for Other Pages -->
                <div class="trip-container">
                    <h2><?= ucfirst(str_replace('.php', '', $current_page)) ?></h2>
                    <p>Halaman ini sedang dalam pengembangan.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Include JavaScript libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <script>
        // Toggle sidebar on mobile
        document.querySelector('.menu-toggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('show');
            document.querySelector('.sidebar-overlay').style.display = 'block';
        });

        document.querySelector('.sidebar-overlay').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.remove('show');
            this.style.display = 'none';
        });

        // Initialize Select2 for dropdowns
        $(document).ready(function() {
            $('select').select2();
        });

        // Initialize map and location services
        let map;
        let marker;
        let currentLocation = null;
        
        // Initialize Leaflet map
        function initMap() {
            map = L.map('map').setView([-6.2088, 106.8456], 12); // Default to Jakarta
            
            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            // Add click listener to map
            map.on('click', function(e) {
                placeMarker(e.latlng);
                updateLocationField(e.latlng);
            });
        }
        
        // Place marker on map
        function placeMarker(location) {
            if (marker) {
                marker.setLatLng(location);
            } else {
                marker = L.marker(location, {
                    draggable: true
                }).addTo(map);
                
                marker.on('dragend', function() {
                    updateLocationField(marker.getLatLng());
                });
            }
            
            map.panTo(location);
        }
        
        // Update location field based on marker or current location
        function updateLocationField(latLng) {
            if (!latLng) return;
            
            // Use Nominatim for reverse geocoding
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${latLng.lat}&lon=${latLng.lng}`)
                .then(response => response.json())
                .then(data => {
                    const address = data.display_name || `${latLng.lat.toFixed(4)}, ${latLng.lng.toFixed(4)}`;
                    document.getElementById('lokasi_berangkat').value = address;
                })
                .catch(() => {
                    document.getElementById('lokasi_berangkat').value = 
                        `${latLng.lat.toFixed(4)}, ${latLng.lng.toFixed(4)}`;
                });
        }
        
        // Get current location
        function useCurrentLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        currentLocation = {
                            lat: position.coords.latitude,
                            lng: position.coords.longitude
                        };
                        
                        if (map) {
                            map.setView(currentLocation, 15);
                            placeMarker(currentLocation);
                        }
                        updateLocationField(currentLocation);
                        
                        // Update current location display
                        document.getElementById('current-location').textContent = 
                            `Lokasi Anda saat ini: ${currentLocation.lat.toFixed(4)}, ${currentLocation.lng.toFixed(4)}`;
                    },
                    function(error) {
                        let message = "Tidak dapat mendapatkan lokasi saat ini: ";
                        switch(error.code) {
                            case error.PERMISSION_DENIED:
                                message += "Pengguna menolak permintaan geolokasi.";
                                break;
                            case error.POSITION_UNAVAILABLE:
                                message += "Informasi lokasi tidak tersedia.";
                                break;
                            case error.TIMEOUT:
                                message += "Permintaan lokasi telah habis waktunya.";
                                break;
                            case error.UNKNOWN_ERROR:
                                message += "Terjadi kesalahan yang tidak diketahui.";
                                break;
                        }
                        alert(message);
                    },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            } else {
                alert("Geolokasi tidak didukung oleh browser Anda.");
            }
        }
        
        // Location picker buttons
        document.addEventListener('DOMContentLoaded', function() {
            const locationBtns = document.querySelectorAll('.location-btn');
            
            locationBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    // Toggle active state
                    locationBtns.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    const type = this.dataset.type;
                    const mapContainer = document.getElementById('map-container');
                    
                    if (type === 'current') {
                        mapContainer.style.display = 'none';
                        useCurrentLocation();
                    } else if (type === 'map') {
                        mapContainer.style.display = 'block';
                        if (!map) {
                            initMap();
                        }
                        if (currentLocation) {
                            map.setView(currentLocation, 15);
                            placeMarker(currentLocation);
                        }
                    } else {
                        mapContainer.style.display = 'none';
                    }
                });
            });
        });
        
        // Booking modal functions
        function openBookingModal(itemId, itemName) {
            document.getElementById('modalTitle').textContent = `Tambahkan ${itemName} ke Trip`;
            document.getElementById('modalItemId').value = itemId;
            document.getElementById('bookingModal').style.display = 'block';
            
            // Set default date to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('tanggal').value = today;
            
            // Set default time to current hour + 1
            const now = new Date();
            now.setHours(now.getHours() + 1);
            document.getElementById('waktu').value = `${String(now.getHours()).padStart(2, '0')}:00`;
            
            // Reset location picker
            document.getElementById('map-container').style.display = 'none';
            document.querySelector('.location-btn[data-type="manual"]').click();
            
            // Try to get current location
            useCurrentLocation();
        }
        
        function closeModal() {
            document.getElementById('bookingModal').style.display = 'none';
            document.getElementById('map-container').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('bookingModal');
            if (event.target == modal) {
                closeModal();
            }
        }
        
        // New functions for enhanced features
        function updatePriceRange(maxValue, minInput) {
            document.getElementById('harga_max').value = maxValue;
            // Auto-set min to 20% of max if not set
            if (!minInput.value || parseFloat(minInput.value) <= 0) {
                minInput.value = Math.floor(maxValue * 0.2);
            }
        }
        
        function updateSlider(value) {
            document.getElementById('harga_range').value = value;
        }
        
        // Handle other city input
        document.getElementById('kota_tujuan').addEventListener('change', function() {
            const otherInput = document.getElementById('other_kota_tujuan');
            if (this.value === 'other') {
                otherInput.style.display = 'block';
                otherInput.required = true;
            } else {
                otherInput.style.display = 'none';
                otherInput.required = false;
            }
        });
        
        // Validate at least one interest is checked
        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('input[name="kategori_minat[]"]:checked');
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Silakan pilih minimal satu kategori minat');
            }
            
            // If "other" city is selected, use that value
            const kotaTujuan = document.getElementById('kota_tujuan');
            if (kotaTujuan.value === 'other') {
                const otherInput = document.getElementById('other_kota_tujuan');
                if (otherInput.value.trim() === '') {
                    e.preventDefault();
                    alert('Silakan masukkan kota tujuan');
                } else {
                    // Create a hidden input to submit the other city value
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'kota_tujuan';
                    hiddenInput.value = otherInput.value;
                    this.appendChild(hiddenInput);
                }
            }
        });
        
        // Initialize Select2 for dropdowns
        $(document).ready(function() {
            $('#kota_tujuan').select2({
                placeholder: "Pilih Kota Tujuan",
                allowClear: true
            });
        });
    </script>
</body>
</html>

<?php
// Cek apakah session sudah dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validasi path file koneksi.php
if (!file_exists('../koneksi.php')) {
    die("File koneksi.php tidak ditemukan!");
}

require '../koneksi.php'; // Memuat koneksi database (mendefinisikan $conn)

// Pastikan $conn didefinisikan di koneksi.php
if (!isset($conn)) {
    die("Koneksi database gagal: \$conn tidak didefinisikan di koneksi.php");
}

$koneksi = $conn; // Definisikan $koneksi sebagai alias untuk $conn
$user_id = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

// Ambil ID kuliner dari URL
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fungsi untuk mendapatkan data kuliner
function getKulinerData($koneksi, $id) {
    $query = "SELECT * FROM kuliner WHERE id = ?";
    $stmt = $koneksi->prepare($query);
    
    if (!$stmt) {
        die("Error preparing statement: " . $koneksi->error);
    }
    
    $stmt->bind_param('i', $id);
    
    if (!$stmt->execute()) {
        die("Error executing query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header("Location: kuliner.php");
        exit();
    }
    
    return $result->fetch_assoc();
}

// Fungsi untuk mendapatkan review
function getReviews($koneksi, $id) {
    $query = "SELECT r.*, u.nama_lengkap 
             FROM reviews r 
             JOIN user u ON r.user_id = u.id 
             WHERE r.item_type = 'kuliner' AND r.item_id = ?
             ORDER BY r.created_at DESC";
             
    $stmt = $koneksi->prepare($query);
    
    if (!$stmt) {
        die("Error preparing review statement: " . $koneksi->error);
    }
    
    $stmt->bind_param('i', $id);
    
    if (!$stmt->execute()) {
        die("Error executing review query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Fungsi untuk menghitung rating rata-rata
function calculateAverageRating($reviews) {
    if (empty($reviews)) {
        return 0;
    }
    
    $total = 0;
    foreach ($reviews as $review) {
        $total += (int)$review['rating'];
    }
    
    return round($total / count($reviews), 1);
}

// Ambil data kuliner
$kuliner = getKulinerData($koneksi, $id);

// Ambil data review
$reviews = getReviews($koneksi, $id);

// Hitung rating rata-rata
$avg_rating = calculateAverageRating($reviews);

// Proses tambah review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    if (!$user_id) {
        header("Location: ../login.php");
        exit();
    }
    
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $komentar = isset($_POST['komentar']) ? trim($_POST['komentar']) : '';
    
    // Validasi input
    if ($rating < 1 || $rating > 5 || empty($komentar)) {
        $error_message = "Rating harus antara 1-5 dan komentar tidak boleh kosong";
    } else {
        $query = "INSERT INTO reviews (user_id, item_type, item_id, rating, komentar) 
                 VALUES (?, 'kuliner', ?, ?, ?)";
        $stmt = $koneksi->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param('iiss', $user_id, $id, $rating, $komentar);
            
            if ($stmt->execute()) {
                header("Location: detail_kuliner.php?id=$id");
                exit();
            } else {
                $error_message = "Gagal menyimpan review: " . $stmt->error;
            }
        } else {
            $error_message = "Error preparing review statement: " . $koneksi->error;
        }
    }
}

// Proses tambah ke trip
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_trip'])) {
    if (!$user_id) {
        header("Location: ../login.php");
        exit();
    }
    
    $jumlah_orang = isset($_POST['jumlah_orang']) ? (int)$_POST['jumlah_orang'] : 1;
    $biaya = $kuliner['harga'] * $jumlah_orang;
    $tanggal_kunjungan = isset($_POST['tanggal_kunjungan']) ? $_POST['tanggal_kunjungan'] : date('Y-m-d');
    $waktu_kunjungan = isset($_POST['waktu_kunjungan']) ? $_POST['waktu_kunjungan'] : '08:00';
    
    // Insert ke tabel trip_saya
    $query = "INSERT INTO trip_saya (user_id, item_type, item_id, tanggal_kunjungan, 
             waktu_kunjungan, biaya, jumlah_orang) 
             VALUES (?, 'kuliner', ?, ?, ?, ?, ?)";
    
    $stmt = $koneksi->prepare($query);
    
    if ($stmt) {
        $stmt->bind_param('isssdi', $user_id, $id, $tanggal_kunjungan, 
                         $waktu_kunjungan, $biaya, $jumlah_orang);
        
        if ($stmt->execute()) {
            $success_message = "Kuliner berhasil ditambahkan ke trip Anda!";
        } else {
            $error_message = "Gagal menambahkan kuliner ke trip: " . $stmt->error;
        }
    } else {
        $error_message = "Error preparing trip statement: " . $koneksi->error;
    }
}

// Ambil data user untuk tampilan
$namaPengguna = isset($_SESSION['user']['nama_lengkap']) ? $_SESSION['user']['nama_lengkap'] : 'Pengguna';
$inisial = strtoupper(substr($namaPengguna, 0, 1));
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($kuliner['nama_tempat']) ?> - Rencana-IN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4CAF50;
            --primary-dark: #388E3C;
            --primary-light: #C8E6C9;
            --secondary: #757575;
            --light: #FAFAFA;
            --dark: #212121;
            --white: #FFFFFF;
            --gray-light: #EEEEEE;
            --gray: #BDBDBD;
            --error: #D32F2F;
            --warning: #FFA000;
            --info: #1976D2;
            --sidebar-width: 280px;
            --header-height: 70px;
            --border-radius: 8px;
            --box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Roboto', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #F5F5F5;
            color: var(--dark);
            line-height: 1.6;
        }

        /* Layout Structure */
        .main-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--white);
            box-shadow: var(--box-shadow);
            position: fixed;
            height: 100vh;
            z-index: 100;
            transition: var(--transition);
            overflow-y: auto;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-light);
        }

        .sidebar-brand img {
            width: 40px;
            height: 40px;
            margin-right: 10px;
        }

        .sidebar-brand h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-item {
            margin: 0.25rem 0.5rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .nav-link:hover, .nav-link.active {
            background-color: var(--primary-light);
            color: var(--primary-dark);
        }

        .nav-link.active {
            font-weight: 500;
        }

        .nav-link i {
            margin-right: 12px;
            width: 24px;
            text-align: center;
            font-size: 1.1rem;
        }

        /* Main Content Area */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        /* Header Styles */
        .header {
            height: var(--header-height);
            background-color: var(--white);
            box-shadow: var(--box-shadow);
            display: flex;
            align-items: center;
            padding: 0 2rem;
            position: sticky;
            top: 0;
            z-index: 90;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--dark);
            cursor: pointer;
            margin-right: 1rem;
        }

        .search-bar {
            flex-grow: 1;
            max-width: 500px;
            position: relative;
        }

        .search-bar input {
            width: 100%;
            padding: 0.7rem 1rem 0.7rem 3rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
        }

        .user-menu {
            margin-left: auto;
            display: flex;
            align-items: center;
        }

        .user-menu .dropdown-toggle {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--dark);
        }

        .user-menu .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 0.75rem;
        }

        /* Detail Page Styles */
        .detail-container {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        .detail-header {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 992px) {
            .detail-header {
                flex-direction: column;
            }
        }

        .detail-gallery {
            flex: 1;
            min-width: 0;
        }

        .main-image {
            width: 100%;
            height: 400px;
            border-radius: var(--border-radius);
            object-fit: cover;
            box-shadow: var(--box-shadow);
            margin-bottom: 1rem;
        }

        .thumbnail-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
        }

        @media (max-width: 768px) {
            .thumbnail-container {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .thumbnail {
            width: 100%;
            height: 90px;
            border-radius: var(--border-radius);
            object-fit: cover;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
        }

        .thumbnail:hover, .thumbnail.active {
            border-color: var(--primary);
            transform: translateY(-3px);
        }

        .detail-info {
            flex: 1;
            min-width: 0;
        }

        .detail-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .detail-badge {
            display: inline-block;
            background-color: var(--primary);
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
        }

        .detail-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .detail-meta-item {
            display: flex;
            align-items: center;
            color: var(--secondary);
            font-size: 0.95rem;
        }

        .detail-meta-item i {
            margin-right: 0.5rem;
            color: var(--primary);
        }

        .detail-price {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary);
            margin: 1.5rem 0;
        }

        .detail-description {
            line-height: 1.7;
            color: var(--secondary);
            margin-bottom: 1.5rem;
        }

        /* Booking Form */
        .booking-form {
            background-color: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-top: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }

        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.8rem 1.5rem;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            text-decoration: none;
        }

        .btn i {
            margin-right: 0.5rem;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-secondary {
            background-color: var(--white);
            color: var(--dark);
            border: 1px solid var(--gray-light);
        }

        .btn-secondary:hover {
            background-color: var(--gray-light);
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        @media (max-width: 576px) {
            .btn-group {
                flex-direction: column;
            }
        }

        /* Sections */
        .section {
            background-color: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
        }

        .section-title i {
            margin-right: 0.75rem;
        }

        /* Reviews */
        .review-item {
            padding: 1.5rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .review-item:last-child {
            border-bottom: none;
        }

        .review-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .review-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 1rem;
        }

        .review-name {
            font-weight: 500;
            color: var(--dark);
        }

        .review-date {
            font-size: 0.8rem;
            color: var(--secondary);
        }

        .review-rating {
            margin-left: auto;
            color: var(--warning);
        }

        .review-text {
            color: var(--secondary);
            line-height: 1.7;
        }

        /* Rating Input */
        .rating-input {
            margin-bottom: 1rem;
        }

        .rating-input i {
            color: var(--gray);
            cursor: pointer;
            font-size: 1.5rem;
            margin-right: 0.5rem;
            transition: var(--transition);
        }

        .rating-input i.active {
            color: var(--warning);
        }

        textarea {
            width: 100%;
            padding: 1rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            min-height: 120px;
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
        }

        .alert i {
            margin-right: 0.75rem;
            font-size: 1.2rem;
        }

        .alert-success {
            background-color: rgba(76, 175, 80, 0.1);
            color: var(--primary-dark);
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .alert-error {
            background-color: rgba(211, 47, 47, 0.1);
            color: var(--error);
            border: 1px solid rgba(211, 47, 47, 0.2);
        }

        /* Mobile Responsiveness */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 99;
                display: none;
            }

            .sidebar-overlay.show {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .detail-container {
                padding: 1rem;
            }

            .header {
                padding: 0 1rem;
            }

            .search-bar {
                display: none;
            }
        }

        @media (max-width: 576px) {
            .thumbnail-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .detail-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay"></div>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <img src="../asset/logo.png" alt="Rencana-IN Logo">
                <h2>Rencana-IN</h2>
            </div>
            <nav class="sidebar-nav">
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="wisata.php" class="nav-link">
                            <i class="fas fa-umbrella-beach"></i>
                            <span>Wisata</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="transportasi.php" class="nav-link">
                            <i class="fas fa-bus"></i>
                            <span>Transportasi</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="kuliner.php" class="nav-link active">
                            <i class="fas fa-utensils"></i>
                            <span>Kuliner</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="penginapan.php" class="nav-link">
                            <i class="fas fa-hotel"></i>
                            <span>Penginapan</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="trip_saya.php" class="nav-link">
                            <i class="fas fa-map-marked-alt"></i>
                            <span>Trip Saya</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="../logout.php" class="nav-link">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <div class="main-content">
            <header class="header">
                <button class="menu-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Cari kuliner, wisata, penginapan...">
                </div>
                <div class="user-menu">
                    <a href="#" class="dropdown-toggle">
                        <div class="avatar">
                            <?= $inisial ?>
                        </div>
                        <span><?= htmlspecialchars($namaPengguna) ?></span>
                    </a>
                </div>
            </header>

            <div class="detail-container">
                <!-- Notification Messages -->
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?= $success_message ?>
                        <a href="trip_saya.php" class="btn btn-primary" style="margin-left: 1rem; padding: 0.5rem 1rem;">
                            Lihat Trip
                        </a>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
                    </div>
                <?php endif; ?>

                <!-- Kuliner Detail Header -->
                <div class="detail-header">
                    <div class="detail-gallery">
                        <img src="<?= htmlspecialchars($kuliner['gambar']) ?>" alt="<?= htmlspecialchars($kuliner['nama_tempat']) ?>" class="main-image" id="main-image">
                        <div class="thumbnail-container">
                            <img src="<?= htmlspecialchars($kuliner['gambar']) ?>" class="thumbnail active" onclick="changeImage(this)">
                            <!-- Additional thumbnails can be added here -->
                        </div>
                    </div>
                    
                    <div class="detail-info">
                        <h1 class="detail-title"><?= htmlspecialchars($kuliner['nama_tempat']) ?></h1>
                        <span class="detail-badge"><?= htmlspecialchars(ucfirst($kuliner['kategori'])) ?></span>
                        
                        <div class="detail-meta">
                            <div class="detail-meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <?= htmlspecialchars($kuliner['lokasi']) ?>
                            </div>
                            <div class="detail-meta-item">
                                <i class="fas fa-clock"></i>
                                Jam Operasional: <?= htmlspecialchars($kuliner['jam_operasional']) ?>
                            </div>
                            <div class="detail-meta-item">
                                <i class="fas fa-star"></i>
                                Rating: <?= $avg_rating ?> (<?= count($reviews) ?> ulasan)
                            </div>
                        </div>
                        
                        <div class="detail-price">
                            Rp <?= number_format($kuliner['harga'], 0, ',', '.') ?> / menu
                        </div>
                        
                        <div class="detail-description">
                            <?= nl2br(htmlspecialchars($kuliner['deskripsi'])) ?>
                        </div>
                        
                        <!-- Booking Form -->
                        <?php if (isset($_SESSION['user']['id'])): ?>
                            <form method="POST" class="booking-form">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="tanggal_kunjungan">Tanggal Kunjungan</label>
                                        <input type="date" id="tanggal_kunjungan" name="tanggal_kunjungan" 
                                               class="form-control" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="waktu_kunjungan">Waktu Kunjungan</label>
                                        <input type="time" id="waktu_kunjungan" name="waktu_kunjungan" 
                                               class="form-control" value="08:00" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="jumlah_orang">Jumlah Orang</label>
                                        <input type="number" id="jumlah_orang" name="jumlah_orang" 
                                               class="form-control" min="1" value="1" required>
                                    </div>
                                </div>
                                
                                <div class="btn-group">
                                    <button type="submit" name="add_to_trip" class="btn btn-primary">
                                        <i class="fas fa-plus"></i> Tambah ke Trip
                                    </button>
                                    <a href="kuliner.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> Kembali
                                    </a>
                                </div>
                            </form>
                        <?php else: ?>
                            <p>Silakan <a href="../login.php">login</a> untuk menambahkan ke trip.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Reviews Section -->
                <div class="section">
                    <h3 class="section-title"><i class="fas fa-star"></i> Ulasan (<?= count($reviews) ?>)</h3>
                    
                    <?php if (count($reviews) > 0): ?>
                        <?php foreach ($reviews as $review): ?>
                            <div class="review-item">
                                <div class="review-header">
                                    <div class="review-avatar">
                                        <?= strtoupper(substr($review['nama_lengkap'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="review-name"><?= htmlspecialchars($review['nama_lengkap']) ?></div>
                                        <div class="review-date"><?= date('d M Y', strtotime($review['created_at'])) ?></div>
                                    </div>
                                    <div class="review-rating">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star<?= $i <= $review['rating'] ? '' : '-o' ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <div class="review-text">
                                    <?= nl2br(htmlspecialchars($review['komentar'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Belum ada ulasan untuk kuliner ini.</p>
                    <?php endif; ?>
                    
                    <?php if ($user_id): ?>
                        <hr style="margin: 2rem 0;">
                        <h4>Tambah Ulasan</h4>
                        <form method="POST">
                            <div class="rating-input">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star<?= $i <= 3 ? ' active' : '' ?>" data-rating="<?= $i ?>"></i>
                                <?php endfor; ?>
                                <input type="hidden" name="rating" id="rating-value" value="3" required>
                            </div>
                            <textarea name="komentar" placeholder="Bagikan pengalaman Anda..." required></textarea>
                            <button type="submit" name="submit_review" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Kirim Ulasan
                            </button>
                        </form>
                    <?php else: ?>
                        <p>Silakan <a href="../login.php">login</a> untuk menambahkan ulasan.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.querySelector('.sidebar');
            const sidebarOverlay = document.querySelector('.sidebar-overlay');
            const menuToggle = document.querySelector('.menu-toggle');

            // Toggle sidebar on menu button click
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
                sidebarOverlay.classList.toggle('show');
            });

            // Close sidebar when clicking on overlay
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');
            });

            // Change main image when thumbnail is clicked
            function changeImage(element) {
                const mainImage = document.getElementById('main-image');
                mainImage.src = element.src;
                
                // Update active thumbnail
                document.querySelectorAll('.thumbnail').forEach(thumb => {
                    thumb.classList.remove('active');
                });
                element.classList.add('active');
            }

            // Rating input functionality
            const stars = document.querySelectorAll('.rating-input i');
            const ratingValue = document.getElementById('rating-value');
            
            stars.forEach(star => {
                star.addEventListener('click', function() {
                    const rating = this.getAttribute('data-rating');
                    ratingValue.value = rating;
                    
                    stars.forEach((s, index) => {
                        if (index < rating) {
                            s.classList.add('active');
                        } else {
                            s.classList.remove('active');
                        }
                    });
                });
            });

            // Close sidebar on window resize if screen is large enough
            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                }
            });
        });
    </script>
</body>
</html>

<?php
// Cek apakah session sudah dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validasi path file koneksi.php
if (!file_exists('../koneksi.php')) {
    die("File koneksi.php tidak ditemukan!");
}

require '../koneksi.php'; // Memuat koneksi database (mendefinisikan $conn)

// Pastikan $conn didefinisikan di koneksi.php
if (!isset($conn)) {
    die("Koneksi database gagal: \$conn tidak didefinisikan di koneksi.php");
}

$koneksi = $conn; // Definisikan $koneksi sebagai alias untuk $conn
$user_id = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

// Ambil ID penginapan dari URL
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fungsi untuk mendapatkan data penginapan
function getPenginapanData($koneksi, $id) {
    $query = "SELECT * FROM penginapan WHERE id = ?";
    $stmt = $koneksi->prepare($query);
    
    if (!$stmt) {
        die("Error preparing statement: " . $koneksi->error);
    }
    
    $stmt->bind_param('i', $id);
    
    if (!$stmt->execute()) {
        die("Error executing query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header("Location: penginapan.php");
        exit();
    }
    
    return $result->fetch_assoc();
}

// Fungsi untuk mendapatkan review
function getReviews($koneksi, $id) {
    $query = "SELECT r.*, u.nama_lengkap 
             FROM reviews r 
             JOIN user u ON r.user_id = u.id 
             WHERE r.item_type = 'penginapan' AND r.item_id = ?
             ORDER BY r.created_at DESC";
             
    $stmt = $koneksi->prepare($query);
    
    if (!$stmt) {
        die("Error preparing review statement: " . $koneksi->error);
    }
    
    $stmt->bind_param('i', $id);
    
    if (!$stmt->execute()) {
        die("Error executing review query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Fungsi untuk menghitung rating rata-rata
function calculateAverageRating($reviews) {
    if (empty($reviews)) {
        return 0;
    }
    
    $total = 0;
    foreach ($reviews as $review) {
        $total += (int)$review['rating'];
    }
    
    return round($total / count($reviews), 1);
}

// Ambil data penginapan
$penginapan = getPenginapanData($koneksi, $id);

// Ambil data review
$reviews = getReviews($koneksi, $id);

// Hitung rating rata-rata
$avg_rating = calculateAverageRating($reviews);

// Proses tambah review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    if (!$user_id) {
        header("Location: ../login.php");
        exit();
    }
    
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $komentar = isset($_POST['komentar']) ? trim($_POST['komentar']) : '';
    
    // Validasi input
    if ($rating < 1 || $rating > 5 || empty($komentar)) {
        $error_message = "Rating harus antara 1-5 dan komentar tidak boleh kosong";
    } else {
        $query = "INSERT INTO reviews (user_id, item_type, item_id, rating, komentar) 
                 VALUES (?, 'penginapan', ?, ?, ?)";
        $stmt = $koneksi->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param('iiss', $user_id, $id, $rating, $komentar);
            
            if ($stmt->execute()) {
                header("Location: detail_penginapan.php?id=$id");
                exit();
            } else {
                $error_message = "Gagal menyimpan review: " . $stmt->error;
            }
        } else {
            $error_message = "Error preparing review statement: " . $koneksi->error;
        }
    }
}

// Proses tambah ke trip
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_trip'])) {
    if (!$user_id) {
        header("Location: ../login.php");
        exit();
    }
    
    $tanggal_checkin = $_POST['tanggal_checkin'];
    $tanggal_checkout = $_POST['tanggal_checkout'];
    
    // Hitung jumlah malam
    $checkin = new DateTime($tanggal_checkin);
    $checkout = new DateTime($tanggal_checkout);
    $jumlah_malam = $checkin->diff($checkout)->days;
    $total_biaya = $penginapan['harga_per_malam'] * $jumlah_malam;
    
    // Validasi jumlah malam minimal 1
    if ($jumlah_malam < 1) {
        $error_message = "Durasi menginap minimal 1 malam";
    } else {
        // Insert ke tabel trip_saya
        $query = "INSERT INTO trip_saya (user_id, item_type, item_id, tanggal_kunjungan, 
                 waktu_kunjungan, biaya, jumlah_orang, keterangan) 
                 VALUES (?, 'penginapan', ?, ?, ?, ?, ?, ?)";
        
        $stmt = $koneksi->prepare($query);
        
        if ($stmt) {
            $keterangan = "Check-in: $tanggal_checkin, Check-out: $tanggal_checkout ($jumlah_malam malam)";
            $jumlah_orang = 1; // Default 1 orang untuk penginapan
            
            $stmt->bind_param('isssdis', $user_id, $id, $tanggal_checkin, 
                             $tanggal_checkout, $total_biaya, $jumlah_orang, $keterangan);
            
            if ($stmt->execute()) {
                $success_message = "Penginapan berhasil ditambahkan ke trip Anda!";
            } else {
                $error_message = "Gagal menambahkan penginapan ke trip: " . $stmt->error;
            }
        } else {
            $error_message = "Error preparing trip statement: " . $koneksi->error;
        }
    }
}

// Ambil data user untuk tampilan
$namaPengguna = isset($_SESSION['user']['nama_lengkap']) ? $_SESSION['user']['nama_lengkap'] : 'Pengguna';
$inisial = strtoupper(substr($namaPengguna, 0, 1));
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($penginapan['nama_penginapan']) ?> - Rencana-IN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ROOT VARIABEL UNTUK KONSISTENSI WARNA DAN UKURAN */
        :root {
            --primary: #4CAF50; /* Hijau utama */
            --primary-dark: #3d8b40; /* Versi gelap hijau */
            --primary-light: rgba(76, 175, 80, 0.1); /* Light green for backgrounds */
            --secondary: #6c757d; /* Abu-abu sekunder */
            --light: #f8f9fa; /* Background terang */
            --dark: #343a40; /* Warna teks gelap */
            --white: #ffffff; /* Putih */
            --gray-light: #e9ecef; /* Light gray for borders */
            --sidebar-width: 250px;
            --header-height: 60px;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --warning: #ffc107; /* Warna untuk rating */
            --gray: #ced4da; /* Warna abu-abu */
            --transition: all 0.3s ease;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
        }

        /* SIDEBAR STYLING */
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--white);
            position: fixed;
            top: 0;
            left: 0;
            box-shadow: var(--box-shadow);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
        }

        .sidebar-brand {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .sidebar-brand img {
            width: 36px;
            height: 36px;
            margin-right: 10px;
            object-fit: contain;
        }

        .sidebar-brand h2 {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            color: var(--primary);
            white-space: nowrap;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 0;
        }

        .nav-item {
            position: relative;
            margin: 0.25rem 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            transition: var(--transition);
            white-space: nowrap;
        }

        .nav-link:hover, .nav-link.active {
            color: var(--primary);
            background-color: var(--primary-light);
        }

        .nav-link.active {
            font-weight: 500;
            border-left: 3px solid var(--primary);
        }

        .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        /* HEADER DI BAGIAN ATAS */
        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: var(--transition);
        }

        .header {
            height: var(--header-height);
            background-color: var(--white);
            box-shadow: 0 0.15rem 1.75rem 0 rgba(33, 40, 50, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark);
            cursor: pointer;
            margin-right: 1rem;
        }

        .search-bar {
            position: relative;
            width: 300px;
            flex-grow: 1;
            max-width: 400px;
            margin: 0 1rem;
        }

        .search-bar input {
            width: 100%;
            padding: 0.375rem 0.75rem 0.375rem 2.5rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
            background-color: var(--light);
            font-size: 0.875rem;
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
            font-size: 0.875rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            margin-left: auto;
        }

        .user-menu .dropdown-toggle {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .user-menu .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            margin-right: 0.5rem;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        /* OVERLAY UNTUK MOBILE SIDEBAR */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        .sidebar-overlay.show {
            display: block;
        }

        /* Detail Content */
        .detail-container {
            padding: 2rem;
        }

        .detail-header {
            display: flex;
            flex-direction: column;
            margin-bottom: 2rem;
        }

        .detail-title {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .detail-location {
            display: flex;
            align-items: center;
            color: var(--secondary);
            margin-bottom: 1rem;
        }

        .detail-location i {
            margin-right: 0.5rem;
        }

        .detail-rating {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .detail-rating-stars {
            color: var(--warning);
            margin-right: 0.5rem;
        }

        .detail-rating-count {
            color: var(--secondary);
            font-size: 0.9rem;
        }

        .detail-gallery {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .detail-main-image {
            grid-column: span 2;
            grid-row: span 2;
            height: 400px;
            overflow: hidden;
            border-radius: var(--border-radius);
        }

        .detail-secondary-image {
            height: 195px;
            overflow: hidden;
            border-radius: var(--border-radius);
        }

        .detail-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .detail-main-image:hover .detail-image,
        .detail-secondary-image:hover .detail-image {
            transform: scale(1.05);
        }

        .section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.3rem;
            margin-bottom: 1.5rem;
            color: var(--dark);
            display: flex;
            align-items: center;
        }

        .section-title i {
            margin-right: 0.8rem;
            color: var(--primary);
        }

        .detail-description {
            line-height: 1.8;
            color: var(--secondary);
            margin-bottom: 1.5rem;
        }

        .detail-facilities {
            display: flex;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-bottom: 1.5rem;
        }

        .facility-badge {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .booking-form {
            margin-top: 2rem;
        }

        .price-container {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .price-main {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-right: 1rem;
        }

        .price-unit {
            color: var(--secondary);
            font-size: 0.9rem;
        }

        .price-discount {
            text-decoration: line-through;
            color: var(--secondary);
            font-size: 1rem;
            margin-right: 0.5rem;
        }

        .date-range-group {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .date-range-item {
            flex: 1;
            min-width: 200px;
        }

        .date-range-item label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .date-range-item input {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
        }

        .date-range-item input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-secondary {
            background-color: var(--white);
            color: var(--dark);
            border: 1px solid var(--gray-light);
        }

        .btn-secondary:hover {
            background-color: var(--gray-light);
        }

        .btn i {
            margin-right: 0.5rem;
        }

        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
        }

        .alert-success {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--primary-dark);
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .alert-error {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }

        /* Additional styles for reviews section */
        .review-item {
            padding: 1.5rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .review-item:last-child {
            border-bottom: none;
        }

        .review-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .review-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 1rem;
        }

        .review-name {
            font-weight: 500;
            color: var(--dark);
        }

        .review-date {
            font-size: 0.8rem;
            color: var(--secondary);
        }

        .review-rating {
            margin-left: auto;
            color: var(--warning);
        }

        .review-text {
            color: var(--secondary);
            line-height: 1.7;
        }

        /* Rating Input */
        .rating-input {
            margin-bottom: 1rem;
        }

        .rating-input i {
            color: var(--gray);
            cursor: pointer;
            font-size: 1.5rem;
            margin-right: 0.5rem;
            transition: var(--transition);
        }

        .rating-input i.active {
            color: var(--warning);
        }

        textarea {
            width: 100%;
            padding: 1rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            min-height: 120px;
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .detail-container {
                padding-top: calc(var(--header-height) + 1rem);
            }
        }

        @media (max-width: 768px) {
            .detail-gallery {
                grid-template-columns: 1fr;
            }

            .detail-main-image {
                grid-column: span 1;
                grid-row: span 1;
                height: 250px;
            }

            .detail-secondary-image {
                height: 120px;
            }

            .date-range-item {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Overlay untuk sidebar saat tampil di mobile -->
    <div class="sidebar-overlay"></div>

    <!-- Sidebar navigasi utama -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="../asset/logo.png" alt="Rencana-IN Logo">
            <h2>Rencana-IN</h2>
        </div>
        <nav class="sidebar-nav">
            <ul style="list-style: none; padding: 0; margin: 0;">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="wisata.php" class="nav-link <?= $current_page == 'wisata.php' ? 'active' : '' ?>">
                        <i class="fas fa-umbrella-beach"></i>
                        <span>Wisata</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="transportasi.php" class="nav-link <?= $current_page == 'transportasi.php' ? 'active' : '' ?>">
                        <i class="fas fa-bus"></i>
                        <span>Transportasi</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="kuliner.php" class="nav-link <?= $current_page == 'kuliner.php' ? 'active' : '' ?>">
                        <i class="fas fa-utensils"></i>
                        <span>Kuliner</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="penginapan.php" class="nav-link <?= $current_page == 'penginapan.php' ? 'active' : '' ?>">
                        <i class="fas fa-hotel"></i>
                        <span>Penginapan</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="trip_saya.php" class="nav-link <?= $current_page == 'trip_saya.php' ? 'active' : '' ?>">
                        <i class="fas fa-map-marked-alt"></i>
                        <span>Trip Saya</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main">
        <header class="header">
            <button class="menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Cari...">
            </div>
            <div class="user-menu">
                <a href="#" class="dropdown-toggle">
                    <div class="avatar">
                        <?= $inisial ?>
                    </div>
                    <span><?= htmlspecialchars($namaPengguna) ?></span>
                    <i class="fas fa-caret-down ml-2"></i>
                </a>
            </div>
        </header>

        <div class="detail-container">
            <!-- Notification Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $success_message ?>
                    <a href="trip_saya.php" class="btn btn-primary" style="margin-left: 1rem; padding: 0.5rem 1rem;">
                        Lihat Trip
                    </a>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
                </div>
            <?php endif; ?>

            <!-- Detail Header -->
            <div class="detail-header">
                <h1 class="detail-title"><?= htmlspecialchars($penginapan['nama_penginapan']) ?></h1>
                <div class="detail-location">
                    <i class="fas fa-map-marker-alt"></i>
                    <span><?= htmlspecialchars($penginapan['lokasi']) ?></span>
                </div>
                <div class="detail-rating">
                    <div class="detail-rating-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star<?= $i <= floor($avg_rating) ? '' : '-o' ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <div class="detail-rating-count">
                        <?= $avg_rating ?> (<?= count($reviews) ?> ulasan)
                    </div>
                </div>
            </div>

            <!-- Gallery -->
            <div class="detail-gallery">
                <div class="detail-main-image">
                    <img src="<?= htmlspecialchars($penginapan['gambar']) ?>" alt="<?= htmlspecialchars($penginapan['nama_penginapan']) ?>" class="detail-image">
                </div>
                <!-- Additional images can be added here -->
                <div class="detail-secondary-image">
                    <img src="<?= htmlspecialchars($penginapan['gambar']) ?>" alt="<?= htmlspecialchars($penginapan['nama_penginapan']) ?>" class="detail-image">
                </div>
                <div class="detail-secondary-image">
                    <img src="<?= htmlspecialchars($penginapan['gambar']) ?>" alt="<?= htmlspecialchars($penginapan['nama_penginapan']) ?>" class="detail-image">
                </div>
            </div>

            <!-- Description Section -->
            <div class="section">
                <h3 class="section-title"><i class="fas fa-info-circle"></i> Deskripsi</h3>
                <p class="detail-description"><?= nl2br(htmlspecialchars($penginapan['deskripsi'])) ?></p>
            </div>

            <!-- Facilities Section -->
            <div class="section">
                <h3 class="section-title"><i class="fas fa-umbrella-beach"></i> Fasilitas</h3>
                <div class="detail-facilities">
                    <?php 
                    $fasilitas = explode(',', $penginapan['fasilitas']);
                    foreach ($fasilitas as $fasilitas_item): 
                        if (!empty(trim($fasilitas_item))):
                    ?>
                        <span class="facility-badge"><?= htmlspecialchars(trim($fasilitas_item)) ?></span>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
            </div>

            <!-- Booking Section -->
            <div class="section">
                <h3 class="section-title"><i class="fas fa-calendar-alt"></i> Pesan Sekarang</h3>
                
                <div class="price-container">
                    <?php if ($penginapan['harga_diskon'] > 0): ?>
                        <span class="price-discount">Rp <?= number_format($penginapan['harga_per_malam'], 0, ',', '.') ?></span>
                        <span class="price-main">Rp <?= number_format($penginapan['harga_diskon'], 0, ',', '.') ?></span>
                    <?php else: ?>
                        <span class="price-main">Rp <?= number_format($penginapan['harga_per_malam'], 0, ',', '.') ?></span>
                    <?php endif; ?>
                    <span class="price-unit">/ malam</span>
                </div>
                
                <form method="POST" class="booking-form">
                    <div class="date-range-group">
                        <div class="date-range-item">
                            <label for="checkin">Check-in</label>
                            <input type="date" id="checkin" name="tanggal_checkin" required>
                        </div>
                        <div class="date-range-item">
                            <label for="checkout">Check-out</label>
                            <input type="date" id="checkout" name="tanggal_checkout" required>
                        </div>
                    </div>
                    
                    <?php if ($user_id): ?>
                        <button type="submit" name="add_to_trip" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Tambahkan ke Trip
                        </button>
                    <?php else: ?>
                        <a href="../login.php" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt"></i> Login untuk Memesan
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Kontak Section -->
            <div class="section">
                <h3 class="section-title"><i class="fas fa-phone-alt"></i> Kontak</h3>
                <p><i class="fas fa-phone"></i> <?= htmlspecialchars($penginapan['kontak'] ?? 'Belum tersedia') ?></p>
                <?php if (!empty($penginapan['alamat'])): ?>
                    <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($penginapan['alamat']) ?></p>
                <?php endif; ?>
            </div>
            
            <!-- Reviews Section -->
            <div class="section">
                <h3 class="section-title"><i class="fas fa-star"></i> Ulasan (<?= count($reviews) ?>)</h3>
                
                <?php if (count($reviews) > 0): ?>
                    <?php foreach ($reviews as $review): ?>
                        <div class="review-item">
                            <div class="review-header">
                                <div class="review-avatar">
                                    <?= strtoupper(substr($review['nama_lengkap'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="review-name"><?= htmlspecialchars($review['nama_lengkap']) ?></div>
                                    <div class="review-date"><?= date('d M Y', strtotime($review['created_at'])) ?></div>
                                </div>
                                <div class="review-rating">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star<?= $i <= $review['rating'] ? '' : '-o' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <div class="review-text">
                                <?= nl2br(htmlspecialchars($review['komentar'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Belum ada ulasan untuk penginapan ini.</p>
                <?php endif; ?>
                
                <?php if ($user_id): ?>
                    <hr style="margin: 2rem 0;">
                    <h4>Tambah Ulasan</h4>
                    <form method="POST">
                        <div class="rating-input">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star<?= $i <= 3 ? ' active' : '' ?>" data-rating="<?= $i ?>"></i>
                            <?php endfor; ?>
                            <input type="hidden" name="rating" id="rating-value" value="3" required>
                        </div>
                        <textarea name="komentar" placeholder="Bagikan pengalaman Anda..." required></textarea>
                        <button type="submit" name="submit_review" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Kirim Ulasan
                        </button>
                    </form>
                <?php else: ?>
                    <p>Silakan <a href="../login.php">login</a> untuk menambahkan ulasan.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.querySelector('.sidebar');
            const sidebarOverlay = document.querySelector('.sidebar-overlay');
            const menuToggle = document.querySelector('.menu-toggle');

            // Tampilkan dan sembunyikan sidebar saat tombol ditekan
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
                sidebarOverlay.classList.toggle('show');
            });

            // Klik overlay untuk menutup sidebar
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');
            });

            // Tangani resize jendela untuk menutup sidebar di layar besar
            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                }
            });

            // Rating input functionality
            const stars = document.querySelectorAll('.rating-input i');
            const ratingValue = document.getElementById('rating-value');
            
            if (stars.length > 0) {
                stars.forEach(star => {
                    star.addEventListener('click', function() {
                        const rating = this.getAttribute('data-rating');
                        ratingValue.value = rating;
                        
                        stars.forEach((s, index) => {
                            if (index < rating) {
                                s.classList.add('active');
                            } else {
                                s.classList.remove('active');
                            }
                        });
                    });
                });
            }

            // Date validation
            const checkinInput = document.getElementById('checkin');
            const checkoutInput = document.getElementById('checkout');
            
            if (checkinInput && checkoutInput) {
                // Set minimum checkout date to checkin date + 1 day
                checkinInput.addEventListener('change', function() {
                    const checkinDate = new Date(this.value);
                    const minCheckout = new Date(checkinDate);
                    minCheckout.setDate(minCheckout.getDate() + 1);
                    
                    checkoutInput.min = minCheckout.toISOString().split('T')[0];
                    
                    // If current checkout is before new min date, update it
                    if (new Date(checkoutInput.value) < minCheckout) {
                        checkoutInput.value = minCheckout.toISOString().split('T')[0];
                    }
                });
                
                // Initial setup
                const today = new Date();
                const tomorrow = new Date(today);
                tomorrow.setDate(tomorrow.getDate() + 1);
                
                checkinInput.min = today.toISOString().split('T')[0];
                checkoutInput.min = tomorrow.toISOString().split('T')[0];
                
                // Set default values
                checkinInput.value = today.toISOString().split('T')[0];
                checkoutInput.value = tomorrow.toISOString().split('T')[0];
            }
        });
    </script>
</body>
</html>

<?php
// Cek apakah session sudah dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validasi path file koneksi.php
if (!file_exists('../koneksi.php')) {
    die("File koneksi.php tidak ditemukan!");
}

require '../koneksi.php'; // Memuat koneksi database (mendefinisikan $conn)

// Pastikan $conn didefinisikan di koneksi.php
if (!isset($conn)) {
    die("Koneksi database gagal: \$conn tidak didefinisikan di koneksi.php");
}

$koneksi = $conn; // Definisikan $koneksi sebagai alias untuk $conn

$id = (int)$_GET['id'];
$user_id = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

// Fungsi untuk mendapatkan data transportasi
function getTransportasiData($koneksi, $id) {
    $query = "SELECT * FROM transportasi WHERE id = ?";
    $stmt = $koneksi->prepare($query);
    
    if (!$stmt) {
        die("Error preparing statement: " . $koneksi->error);
    }
    
    $stmt->bind_param('i', $id);
    
    if (!$stmt->execute()) {
        die("Error executing query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header("Location: transportasi.php");
        exit();
    }
    
    return $result->fetch_assoc();
}

// Fungsi untuk mendapatkan review
function getReviews($koneksi, $id) {
    $query = "SELECT r.*, u.nama_lengkap 
             FROM reviews r 
             JOIN user u ON r.user_id = u.id 
             WHERE r.item_type = 'transportasi' AND r.item_id = ?
             ORDER BY r.created_at DESC";
             
    $stmt = $koneksi->prepare($query);
    
    if (!$stmt) {
        die("Error preparing review statement: " . $koneksi->error);
    }
    
    $stmt->bind_param('i', $id);
    
    if (!$stmt->execute()) {
        die("Error executing review query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Fungsi untuk menghitung rating rata-rata
function calculateAverageRating($reviews) {
    if (empty($reviews)) {
        return 0;
    }
    
    $total = 0;
    foreach ($reviews as $review) {
        $total += (int)$review['rating'];
    }
    
    return round($total / count($reviews), 1);
}

// Ambil data transportasi
$transportasi = getTransportasiData($koneksi, $id);

// Ambil data review
$reviews = getReviews($koneksi, $id);

// Hitung rating rata-rata
$avg_rating = calculateAverageRating($reviews);

// Proses tambah review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    if (!$user_id) {
        header("Location: ../login.php");
        exit();
    }
    
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $komentar = isset($_POST['komentar']) ? trim($_POST['komentar']) : '';
    
    // Validasi input
    if ($rating < 1 || $rating > 5 || empty($komentar)) {
        $error_message = "Rating harus antara 1-5 dan komentar tidak boleh kosong";
    } else {
        $query = "INSERT INTO reviews (user_id, item_type, item_id, rating, komentar) 
                 VALUES (?, 'transportasi', ?, ?, ?)";
        $stmt = $koneksi->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param('iiss', $user_id, $id, $rating, $komentar);
            
            if ($stmt->execute()) {
                header("Location: detail_transportasi.php?id=$id");
                exit();
            } else {
                $error_message = "Gagal menyimpan review: " . $stmt->error;
            }
        } else {
            $error_message = "Error preparing review statement: " . $koneksi->error;
        }
    }
}

// Proses tambah ke trip
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_trip'])) {
    if (!$user_id) {
        header("Location: ../login.php");
        exit();
    }
    
    $biaya = ($transportasi['harga_diskon'] > 0) ? $transportasi['harga_diskon'] : $transportasi['harga'];
    $tanggal_kunjungan = isset($_POST['tanggal_kunjungan']) ? $_POST['tanggal_kunjungan'] : date('Y-m-d');
    $waktu_kunjungan = isset($_POST['waktu_kunjungan']) ? $_POST['waktu_kunjungan'] : '08:00';
    $jumlah_orang = isset($_POST['jumlah_orang']) ? (int)$_POST['jumlah_orang'] : 1;
    
    // Validasi jumlah orang tidak melebihi kapasitas
    if ($jumlah_orang > $transportasi['kapasitas']) {
        $error_message = "Jumlah orang melebihi kapasitas tersedia";
    } else {
        $query = "INSERT INTO trip_saya (user_id, item_type, item_id, tanggal_kunjungan, 
                 waktu_kunjungan, biaya, jumlah_orang) 
                 VALUES (?, 'transportasi', ?, ?, ?, ?, ?)";
        
        $stmt = $koneksi->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param('isssdi', $user_id, $id, $tanggal_kunjungan, 
                             $waktu_kunjungan, $biaya, $jumlah_orang);
            
            if ($stmt->execute()) {
                $success_message = "Transportasi berhasil ditambahkan ke trip Anda!";
            } else {
                $error_message = "Gagal menambahkan transportasi ke trip: " . $stmt->error;
            }
        } else {
            $error_message = "Error preparing trip statement: " . $koneksi->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($transportasi['nama_layanan']) ?> - Rencana-IN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4CAF50;
            --primary-dark: #388E3C;
            --primary-light: #C8E6C9;
            --secondary: #757575;
            --light: #FAFAFA;
            --dark: #212121;
            --white: #FFFFFF;
            --gray-light: #EEEEEE;
            --gray: #BDBDBD;
            --error: #D32F2F;
            --warning: #FFA000;
            --info: #1976D2;
            --sidebar-width: 280px;
            --header-height: 70px;
            --border-radius: 8px;
            --box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Roboto', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #F5F5F5;
            color: var(--dark);
            line-height: 1.6;
        }

        /* Layout Structure */
        .main-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--white);
            box-shadow: var(--box-shadow);
            position: fixed;
            height: 100vh;
            z-index: 100;
            transition: var(--transition);
            overflow-y: auto;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-light);
        }

        .sidebar-brand img {
            width: 40px;
            height: 40px;
            margin-right: 10px;
        }

        .sidebar-brand h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-item {
            margin: 0.25rem 0.5rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .nav-link:hover, .nav-link.active {
            background-color: var(--primary-light);
            color: var(--primary-dark);
        }

        .nav-link.active {
            font-weight: 500;
        }

        .nav-link i {
            margin-right: 12px;
            width: 24px;
            text-align: center;
            font-size: 1.1rem;
        }

        /* Main Content Area */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        /* Header Styles */
        .header {
            height: var(--header-height);
            background-color: var(--white);
            box-shadow: var(--box-shadow);
            display: flex;
            align-items: center;
            padding: 0 2rem;
            position: sticky;
            top: 0;
            z-index: 90;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--dark);
            cursor: pointer;
            margin-right: 1rem;
        }

        .search-bar {
            flex-grow: 1;
            max-width: 500px;
            position: relative;
        }

        .search-bar input {
            width: 100%;
            padding: 0.7rem 1rem 0.7rem 3rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
        }

        .user-menu {
            margin-left: auto;
            display: flex;
            align-items: center;
        }

        .user-menu .dropdown-toggle {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--dark);
        }

        .user-menu .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 0.75rem;
        }

        /* Detail Page Styles */
        .detail-container {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        .detail-header {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 992px) {
            .detail-header {
                flex-direction: column;
            }
        }

        .detail-gallery {
            flex: 1;
            min-width: 0;
        }

        .main-image {
            width: 100%;
            height: 400px;
            border-radius: var(--border-radius);
            object-fit: cover;
            box-shadow: var(--box-shadow);
            margin-bottom: 1rem;
        }

        .thumbnail-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
        }

        @media (max-width: 768px) {
            .thumbnail-container {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .thumbnail {
            width: 100%;
            height: 90px;
            border-radius: var(--border-radius);
            object-fit: cover;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
        }

        .thumbnail:hover, .thumbnail.active {
            border-color: var(--primary);
            transform: translateY(-3px);
        }

        .detail-info {
            flex: 1;
            min-width: 0;
        }

        .detail-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .detail-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .detail-meta-item {
            display: flex;
            align-items: center;
            color: var(--secondary);
            font-size: 0.95rem;
        }

        .detail-meta-item i {
            margin-right: 0.5rem;
            color: var(--primary);
        }

        .detail-price {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary);
            margin: 1.5rem 0;
        }

        .detail-price del {
            font-size: 1.2rem;
            color: var(--secondary);
            margin-right: 0.5rem;
        }

        .detail-description {
            line-height: 1.7;
            color: var(--secondary);
            margin-bottom: 1.5rem;
        }

        /* Booking Form */
        .booking-form {
            background-color: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-top: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }

        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.8rem 1.5rem;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            text-decoration: none;
        }

        .btn i {
            margin-right: 0.5rem;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-secondary {
            background-color: var(--white);
            color: var(--dark);
            border: 1px solid var(--gray-light);
        }

        .btn-secondary:hover {
            background-color: var(--gray-light);
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        @media (max-width: 576px) {
            .btn-group {
                flex-direction: column;
            }
        }

        /* Sections */
        .section {
            background-color: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
        }

        .section-title i {
            margin-right: 0.75rem;
        }

        /* Reviews */
        .review-item {
            padding: 1.5rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .review-item:last-child {
            border-bottom: none;
        }

        .review-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .review-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 1rem;
        }

        .review-name {
            font-weight: 500;
            color: var(--dark);
        }

        .review-date {
            font-size: 0.8rem;
            color: var(--secondary);
        }

        .review-rating {
            margin-left: auto;
            color: var(--warning);
        }

        .review-text {
            color: var(--secondary);
            line-height: 1.7;
        }

        /* Rating Input */
        .rating-input {
            margin-bottom: 1rem;
        }

        .rating-input i {
            color: var(--gray);
            cursor: pointer;
            font-size: 1.5rem;
            margin-right: 0.5rem;
            transition: var(--transition);
        }

        .rating-input i.active {
            color: var(--warning);
        }

        textarea {
            width: 100%;
            padding: 1rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            min-height: 120px;
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
        }

        .alert i {
            margin-right: 0.75rem;
            font-size: 1.2rem;
        }

        .alert-success {
            background-color: rgba(76, 175, 80, 0.1);
            color: var(--primary-dark);
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .alert-error {
            background-color: rgba(211, 47, 47, 0.1);
            color: var(--error);
            border: 1px solid rgba(211, 47, 47, 0.2);
        }

        /* Mobile Responsiveness */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 99;
                display: none;
            }

            .sidebar-overlay.show {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .detail-container {
                padding: 1rem;
            }

            .header {
                padding: 0 1rem;
            }

            .search-bar {
                display: none;
            }
        }

        @media (max-width: 576px) {
            .thumbnail-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .detail-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay"></div>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <img src="../asset/logo.png" alt="Rencana-IN Logo">
                <h2>Rencana-IN</h2>
            </div>
            <nav class="sidebar-nav">
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="wisata.php" class="nav-link">
                            <i class="fas fa-umbrella-beach"></i>
                            <span>Wisata</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="transportasi.php" class="nav-link active">
                            <i class="fas fa-bus"></i>
                            <span>Transportasi</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="kuliner.php" class="nav-link">
                            <i class="fas fa-utensils"></i>
                            <span>Kuliner</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="penginapan.php" class="nav-link">
                            <i class="fas fa-hotel"></i>
                            <span>Penginapan</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="trip_saya.php" class="nav-link">
                            <i class="fas fa-map-marked-alt"></i>
                            <span>Trip Saya</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="../logout.php" class="nav-link">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <div class="main-content">
            <header class="header">
                <button class="menu-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Cari transportasi, wisata, penginapan...">
                </div>
                <div class="user-menu">
                    <a href="#" class="dropdown-toggle">
                        <div class="avatar">
                            <?= isset($_SESSION['user']['nama_lengkap']) ? strtoupper(substr($_SESSION['user']['nama_lengkap'], 0, 1)) : 'P' ?>
                        </div>
                        <span><?= isset($_SESSION['user']['nama_lengkap']) ? htmlspecialchars($_SESSION['user']['nama_lengkap']) : 'Pengguna' ?></span>
                    </a>
                </div>
            </header>

            <div class="detail-container">
                <!-- Notification Messages -->
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?= $success_message ?>
                        <a href="trip_saya.php" class="btn btn-primary" style="margin-left: 1rem; padding: 0.5rem 1rem;">
                            Lihat Trip
                        </a>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
                    </div>
                <?php endif; ?>

                <!-- Transportasi Detail Header -->
                <div class="detail-header">
                    <div class="detail-gallery">
                        <img src="<?= htmlspecialchars($transportasi['gambar']) ?>" alt="<?= htmlspecialchars($transportasi['nama_layanan']) ?>" class="main-image" id="main-image">
                        <div class="thumbnail-container">
                            <img src="<?= htmlspecialchars($transportasi['gambar']) ?>" class="thumbnail active" onclick="changeImage(this)">
                            <!-- Additional thumbnails can be added here -->
                        </div>
                    </div>
                    
                    <div class="detail-info">
                        <h1 class="detail-title"><?= htmlspecialchars($transportasi['nama_layanan']) ?></h1>
                        
                        <div class="detail-meta">
                            <div class="detail-meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <?= htmlspecialchars($transportasi['lokasi']) ?>
                            </div>
                            <div class="detail-meta-item">
                                <i class="fas fa-users"></i>
                                Kapasitas: <?= htmlspecialchars($transportasi['kapasitas']) ?> orang
                            </div>
                            <div class="detail-meta-item">
                                <i class="fas fa-star"></i>
                                Rating: <?= $avg_rating ?> (<?= count($reviews) ?> ulasan)
                            </div>
                        </div>
                        
                        <div class="detail-price">
                            <?php if ($transportasi['harga_diskon'] > 0): ?>
                                <del>Rp <?= number_format($transportasi['harga'], 0, ',', '.') ?></del>
                                Rp <?= number_format($transportasi['harga_diskon'], 0, ',', '.') ?>
                            <?php else: ?>
                                Rp <?= number_format($transportasi['harga'], 0, ',', '.') ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="detail-description">
                            <?= nl2br(htmlspecialchars($transportasi['deskripsi'])) ?>
                        </div>
                        
                        <!-- Booking Form -->
                        <form method="POST" class="booking-form">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="tanggal_kunjungan">Tanggal Kunjungan</label>
                                    <input type="date" id="tanggal_kunjungan" name="tanggal_kunjungan" class="form-control" 
                                           value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="waktu_kunjungan">Waktu Kunjungan</label>
                                    <input type="time" id="waktu_kunjungan" name="waktu_kunjungan" class="form-control" 
                                           value="08:00" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="jumlah_orang">Jumlah Orang</label>
                                <input type="number" id="jumlah_orang" name="jumlah_orang" class="form-control" 
                                       min="1" max="<?= $transportasi['kapasitas'] ?>" value="1" required>
                            </div>
                            
                            <div class="btn-group">
                                <button type="submit" name="add_to_trip" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Tambah ke Trip
                                </button>
                                <a href="transportasi.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Kembali
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Rute Section -->
                <div class="section">
                    <h3 class="section-title"><i class="fas fa-route"></i> Rute Perjalanan</h3>
                    <p><?= nl2br(htmlspecialchars($transportasi['rute'])) ?></p>
                </div>
                
                <!-- Kontak Section -->
                <div class="section">
                    <h3 class="section-title"><i class="fas fa-phone-alt"></i> Kontak</h3>
                    <p><i class="fas fa-phone"></i> <?= htmlspecialchars($transportasi['kontak']) ?></p>
                </div>
                
                <!-- Reviews Section -->
                <div class="section">
                    <h3 class="section-title"><i class="fas fa-star"></i> Ulasan (<?= count($reviews) ?>)</h3>
                    
                    <?php if (count($reviews) > 0): ?>
                        <?php foreach ($reviews as $review): ?>
                            <div class="review-item">
                                <div class="review-header">
                                    <div class="review-avatar">
                                        <?= strtoupper(substr($review['nama_lengkap'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="review-name"><?= htmlspecialchars($review['nama_lengkap']) ?></div>
                                        <div class="review-date"><?= date('d M Y', strtotime($review['created_at'])) ?></div>
                                    </div>
                                    <div class="review-rating">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star<?= $i <= $review['rating'] ? '' : '-o' ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <div class="review-text">
                                    <?= nl2br(htmlspecialchars($review['komentar'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Belum ada ulasan untuk transportasi ini.</p>
                    <?php endif; ?>
                    
                    <?php if ($user_id): ?>
                        <hr style="margin: 2rem 0;">
                        <h4>Tambah Ulasan</h4>
                        <form method="POST">
                            <div class="rating-input">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star<?= $i <= 3 ? ' active' : '' ?>" data-rating="<?= $i ?>"></i>
                                <?php endfor; ?>
                                <input type="hidden" name="rating" id="rating-value" value="3" required>
                            </div>
                            <textarea name="komentar" placeholder="Bagikan pengalaman Anda..." required></textarea>
                            <button type="submit" name="submit_review" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Kirim Ulasan
                            </button>
                        </form>
                    <?php else: ?>
                        <p>Silakan <a href="../login.php">login</a> untuk menambahkan ulasan.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.querySelector('.sidebar');
            const sidebarOverlay = document.querySelector('.sidebar-overlay');
            const menuToggle = document.querySelector('.menu-toggle');

            // Toggle sidebar on menu button click
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
                sidebarOverlay.classList.toggle('show');
            });

            // Close sidebar when clicking on overlay
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');
            });

            // Change main image when thumbnail is clicked
            function changeImage(element) {
                const mainImage = document.getElementById('main-image');
                mainImage.src = element.src;
                
                // Update active thumbnail
                document.querySelectorAll('.thumbnail').forEach(thumb => {
                    thumb.classList.remove('active');
                });
                element.classList.add('active');
            }

            // Rating input functionality
            const stars = document.querySelectorAll('.rating-input i');
            const ratingValue = document.getElementById('rating-value');
            
            stars.forEach(star => {
                star.addEventListener('click', function() {
                    const rating = this.getAttribute('data-rating');
                    ratingValue.value = rating;
                    
                    stars.forEach((s, index) => {
                        if (index < rating) {
                            s.classList.add('active');
                        } else {
                            s.classList.remove('active');
                        }
                    });
                });
            });

            // Validate number of people doesn't exceed capacity
            const jumlahInput = document.getElementById('jumlah_orang');
            if (jumlahInput) {
                jumlahInput.addEventListener('change', function() {
                    const maxCapacity = parseInt(this.max);
                    if (this.value > maxCapacity) {
                        alert(`Maaf, kapasitas maksimal adalah ${maxCapacity} orang`);
                        this.value = maxCapacity;
                    }
                });
            }

            // Close sidebar on window resize if screen is large enough
            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                }
            });
        });
    </script>
</body>
</html>

<?php
// Cek apakah session sudah dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validasi path file koneksi.php
if (!file_exists('../koneksi.php')) {
    die("File koneksi.php tidak ditemukan!");
}

require '../koneksi.php'; // Memuat koneksi database (mendefinisikan $conn)

// Pastikan $conn didefinisikan di koneksi.php
if (!isset($conn)) {
    die("Koneksi database gagal: \$conn tidak didefinisikan di koneksi.php");
}

$koneksi = $conn; // Definisikan $koneksi sebagai alias untuk $conn

// Ambil data user
$namaPengguna = isset($_SESSION['user']['nama_lengkap']) ? $_SESSION['user']['nama_lengkap'] : 'Pengguna';
$inisial = strtoupper(substr($namaPengguna, 0, 1));
$user_id = isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : null;
$current_page = basename($_SERVER['PHP_SELF']);

// Ambil ID wisata dari URL
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Query untuk mendapatkan detail wisata, termasuk deskripsi
$query = "SELECT id, nama_wisata, kategori, lokasi, harga_tiket, jam_operasional, deskripsi, gambar FROM wisata WHERE id = ?";
$stmt = $koneksi->prepare($query);
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$wisata = $result->fetch_assoc();

// Jika wisata tidak ditemukan, redirect ke halaman wisata
if (!$wisata) {
    header("Location: wisata.php");
    exit();
}

// Fungsi untuk mendapatkan ulasan
function getReviews($koneksi, $id) {
    $query = "SELECT r.*, u.nama_lengkap 
             FROM reviews r 
             JOIN user u ON r.user_id = u.id 
             WHERE r.item_type = 'wisata' AND r.item_id = ?
             ORDER BY r.created_at DESC";
             
    $stmt = $koneksi->prepare($query);
    
    if (!$stmt) {
        die("Error preparing review statement: " . $koneksi->error);
    }
    
    $stmt->bind_param('i', $id);
    
    if (!$stmt->execute()) {
        die("Error executing review query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Fungsi untuk menghitung rating rata-rata
function calculateAverageRating($reviews) {
    if (empty($reviews)) {
        return 0;
    }
    
    $total = 0;
    foreach ($reviews as $review) {
        $total += (int)$review['rating'];
    }
    
    return round($total / count($reviews), 1);
}

// Ambil data review
$reviews = getReviews($koneksi, $id);

// Hitung rating rata-rata
$avg_rating = calculateAverageRating($reviews);

// Proses tambah review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    if (!$user_id) {
        header("Location: ../login.php");
        exit();
    }
    
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $komentar = isset($_POST['komentar']) ? trim($_POST['komentar']) : '';
    
    // Validasi input
    if ($rating < 1 || $rating > 5 || empty($komentar)) {
        $error_message = "Rating harus antara 1-5 dan komentar tidak boleh kosong";
    } else {
        $query = "INSERT INTO reviews (user_id, item_type, item_id, rating, komentar) 
                 VALUES (?, 'wisata', ?, ?, ?)";
        $stmt = $koneksi->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param('iiss', $user_id, $id, $rating, $komentar);
            
            if ($stmt->execute()) {
                header("Location: detail_wisata.php?id=$id");
                exit();
            } else {
                $error_message = "Gagal menyimpan review: " . $stmt->error;
            }
        } else {
            $error_message = "Error preparing review statement: " . $koneksi->error;
        }
    }
}

// Proses tambah ke trip
if (isset($_POST['add_to_trip'])) {
    $item_id = $wisata['id'];
    $biaya_per_tiket = $wisata['harga_tiket'];
    $tanggal_kunjungan = $_POST['tanggal_kunjungan'];
    $waktu_kunjungan = $_POST['waktu_kunjungan'];
    $jumlah_orang = isset($_POST['jumlah_orang']) ? (int)$_POST['jumlah_orang'] : 1;
    
    // Hitung biaya total
    $biaya_total = $biaya_per_tiket * $jumlah_orang;
    
    // Validasi
    $errors = [];
    
    if ($jumlah_orang <= 0) {
        $errors[] = "Jumlah orang harus lebih dari 0";
    }
    
    if (empty($tanggal_kunjungan) || empty($waktu_kunjungan)) {
        $errors[] = "Tanggal dan waktu kunjungan harus diisi";
    }

    if (empty($errors)) {
        // Insert ke tabel trip_saya
        $insert_query = "INSERT INTO trip_saya (user_id, item_type, item_id, tanggal_kunjungan, 
                        waktu_kunjungan, biaya, jumlah_orang) 
                        VALUES (?, 'wisata', ?, ?, ?, ?, ?)";
        $insert_stmt = $koneksi->prepare($insert_query);
        $insert_stmt->bind_param('isssdi', $user_id, $item_id, $tanggal_kunjungan, 
                                $waktu_kunjungan, $biaya_total, $jumlah_orang);

        if ($insert_stmt->execute()) {
            $_SESSION['success_message'] = "Wisata berhasil ditambahkan ke trip Anda!";
            header("Location: trip_saya.php");
            exit();
        } else {
            $error_message = "Gagal menambahkan wisata ke trip: " . $insert_stmt->error;
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($wisata['nama_wisata']) ?> - Rencana-IN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4CAF50;
            --primary-dark: #388E3C;
            --primary-light: #C8E6C9;
            --secondary: #757575;
            --light: #FAFAFA;
            --dark: #212121;
            --white: #FFFFFF;
            --gray-light: #EEEEEE;
            --gray: #BDBDBD;
            --error: #D32F2F;
            --warning: #FFA000;
            --info: #1976D2;
            --sidebar-width: 280px;
            --header-height: 70px;
            --border-radius: 8px;
            --box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Roboto', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #F5F5F5;
            color: var(--dark);
            line-height: 1.6;
        }

        /* Layout Structure */
        .main-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--white);
            box-shadow: var(--box-shadow);
            position: fixed;
            height: 100vh;
            z-index: 100;
            transition: var(--transition);
            overflow-y: auto;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-light);
        }

        .sidebar-brand img {
            width: 40px;
            height: 40px;
            margin-right: 10px;
        }

        .sidebar-brand h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-item {
            margin: 0.25rem 0.5rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .nav-link:hover, .nav-link.active {
            background-color: var(--primary-light);
            color: var(--primary-dark);
        }

        .nav-link.active {
            font-weight: 500;
        }

        .nav-link i {
            margin-right: 12px;
            width: 24px;
            text-align: center;
            font-size: 1.1rem;
        }

        /* Main Content Area */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
        }

        /* Header Styles */
        .header {
            height: var(--header-height);
            background-color: var(--white);
            box-shadow: var(--box-shadow);
            display: flex;
            align-items: center;
            padding: 0 2rem;
            position: sticky;
            top: 0;
            z-index: 90;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--dark);
            cursor: pointer;
            margin-right: 1rem;
        }

        .search-bar {
            flex-grow: 1;
            max-width: 500px;
            position: relative;
        }

        .search-bar input {
            width: 100%;
            padding: 0.7rem 1rem 0.7rem 3rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
        }

        .user-menu {
            margin-left: auto;
            display: flex;
            align-items: center;
        }

        .user-menu .dropdown-toggle {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--dark);
        }

        .user-menu .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 0.75rem;
        }

        /* Detail Page Styles */
        .detail-container {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        .detail-header {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 992px) {
            .detail-header {
                flex-direction: column;
            }
        }

        .detail-gallery {
            flex: 1;
            min-width: 0;
        }

        .main-image {
            width: 100%;
            height: 400px;
            border-radius: var(--border-radius);
            object-fit: cover;
            box-shadow: var(--box-shadow);
            margin-bottom: 1rem;
        }

        .thumbnail-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
        }

        @media (max-width: 768px) {
            .thumbnail-container {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .thumbnail {
            width: 100%;
            height: 90px;
            border-radius: var(--border-radius);
            object-fit: cover;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
        }

        .thumbnail:hover, .thumbnail.active {
            border-color: var(--primary);
            transform: translateY(-3px);
        }

        .detail-info {
            flex: 1;
            min-width: 0;
        }

        .detail-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
        }

        .detail-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .detail-meta-item {
            display: flex;
            align-items: center;
            color: var(--secondary);
            font-size: 0.95rem;
        }

        .detail-meta-item i {
            margin-right: 0.5rem;
            color: var(--primary);
        }

        .detail-price {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary);
            margin: 1.5rem 0;
        }

        .detail-price del {
            font-size: 1.2rem;
            color: var(--secondary);
            margin-right: 0.5rem;
        }

        .detail-description {
            line-height: 1.7;
            color: var(--secondary);
            margin-bottom: 1.5rem;
        }

        /* Booking Form */
        .booking-form {
            background-color: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-top: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }

        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.8rem 1.5rem;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            text-decoration: none;
        }

        .btn i {
            margin-right: 0.5rem;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-secondary {
            background-color: var(--white);
            color: var(--dark);
            border: 1px solid var(--gray-light);
        }

        .btn-secondary:hover {
            background-color: var(--gray-light);
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        @media (max-width: 576px) {
            .btn-group {
                flex-direction: column;
            }
        }

        /* Sections */
        .section {
            background-color: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
        }

        .section-title i {
            margin-right: 0.75rem;
        }

        /* Reviews */
        .review-item {
            padding: 1.5rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .review-item:last-child {
            border-bottom: none;
        }

        .review-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .review-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 1rem;
        }

        .review-name {
            font-weight: 500;
            color: var(--dark);
        }

        .review-date {
            font-size: 0.8rem;
            color: var(--secondary);
        }

        .review-rating {
            margin-left: auto;
            color: var(--warning);
        }

        .review-text {
            color: var(--secondary);
            line-height: 1.7;
        }

        /* Rating Input */
        .rating-input {
            margin-bottom: 1rem;
        }

        .rating-input i {
            color: var(--gray);
            cursor: pointer;
            font-size: 1.5rem;
            margin-right: 0.5rem;
            transition: var(--transition);
        }

        .rating-input i.active {
            color: var(--warning);
        }

        textarea {
            width: 100%;
            padding: 1rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            min-height: 120px;
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
        }

        .alert i {
            margin-right: 0.75rem;
            font-size: 1.2rem;
        }

        .alert-success {
            background-color: rgba(76, 175, 80, 0.1);
            color: var(--primary-dark);
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .alert-error {
            background-color: rgba(211, 47, 47, 0.1);
            color: var(--error);
            border: 1px solid rgba(211, 47, 47, 0.2);
        }

        /* Mobile Responsiveness */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 99;
                display: none;
            }

            .sidebar-overlay.show {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .detail-container {
                padding: 1rem;
            }

            .header {
                padding: 0 1rem;
            }

            .search-bar {
                display: none;
            }
        }

        @media (max-width: 576px) {
            .thumbnail-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .detail-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay"></div>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <img src="../asset/logo.png" alt="Rencana-IN Logo">
                <h2>Rencana-IN</h2>
            </div>
            <nav class="sidebar-nav">
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="wisata.php" class="nav-link <?= $current_page == 'wisata.php' ? 'active' : '' ?>">
                            <i class="fas fa-umbrella-beach"></i>
                            <span>Wisata</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="transportasi.php" class="nav-link <?= $current_page == 'transportasi.php' ? 'active' : '' ?>">
                            <i class="fas fa-bus"></i>
                            <span>Transportasi</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="kuliner.php" class="nav-link <?= $current_page == 'kuliner.php' ? 'active' : '' ?>">
                            <i class="fas fa-utensils"></i>
                            <span>Kuliner</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="penginapan.php" class="nav-link <?= $current_page == 'penginapan.php' ? 'active' : '' ?>">
                            <i class="fas fa-hotel"></i>
                            <span>Penginapan</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="trip_saya.php" class="nav-link <?= $current_page == 'trip_saya.php' ? 'active' : '' ?>">
                            <i class="fas fa-map-marked-alt"></i>
                            <span>Trip Saya</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="../logout.php" class="nav-link">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <div class="main-content">
            <header class="header">
                <button class="menu-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Cari wisata, transportasi, penginapan...">
                </div>
                <div class="user-menu">
                    <a href="#" class="dropdown-toggle">
                        <div class="avatar">
                            <?= $inisial ?>
                        </div>
                        <span><?= htmlspecialchars($namaPengguna) ?></span>
                    </a>
                </div>
            </header>

            <div class="detail-container">
                <!-- Notification Messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?= $_SESSION['success_message'] ?>
                        <?php unset($_SESSION['success_message']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
                    </div>
                <?php endif; ?>

                <!-- Wisata Detail Header -->
                <div class="detail-header">
                    <div class="detail-gallery">
                        <img src="<?= htmlspecialchars($wisata['gambar']) ?>" alt="<?= htmlspecialchars($wisata['nama_wisata']) ?>" class="main-image" id="main-image">
                        <div class="thumbnail-container">
                            <img src="<?= htmlspecialchars($wisata['gambar']) ?>" class="thumbnail active" onclick="changeImage(this)">
                            <!-- Additional thumbnails can be added here -->
                        </div>
                    </div>
                    
                    <div class="detail-info">
                        <h1 class="detail-title"><?= htmlspecialchars($wisata['nama_wisata']) ?></h1>
                        
                        <div class="detail-meta">
                            <div class="detail-meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <?= htmlspecialchars($wisata['lokasi']) ?>
                            </div>
                            <div class="detail-meta-item">
                                <i class="fas fa-clock"></i>
                                <?= htmlspecialchars($wisata['jam_operasional']) ?>
                            </div>
                            <div class="detail-meta-item">
                                <i class="fas fa-star"></i>
                                Rating: <?= $avg_rating ?> (<?= count($reviews) ?> ulasan)
                            </div>
                        </div>
                        
                        <div class="detail-price">
                            Rp <?= number_format($wisata['harga_tiket'], 0, ',', '.') ?> / orang
                        </div>
                        
                        <div class="detail-description">
                            <?= isset($wisata['deskripsi']) ? nl2br(htmlspecialchars($wisata['deskripsi'])) : 'Deskripsi tidak tersedia.' ?>
                        </div>
                        
                        <!-- Booking Form -->
                        <form method="POST" class="booking-form">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="tanggal_kunjungan">Tanggal Kunjungan</label>
                                    <input type="date" id="tanggal_kunjungan" name="tanggal_kunjungan" class="form-control" 
                                           value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="waktu_kunjungan">Waktu Kunjungan</label>
                                    <input type="time" id="waktu_kunjungan" name="waktu_kunjungan" class="form-control" 
                                           value="08:00" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="jumlah_orang">Jumlah Orang</label>
                                <input type="number" id="jumlah_orang" name="jumlah_orang" class="form-control" 
                                       min="1" value="1" required>
                            </div>
                            
                            <div class="btn-group">
                                <button type="submit" name="add_to_trip" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Tambah ke Trip
                                </button>
                                <a href="wisata.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Kembali
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Reviews Section -->
                <div class="section">
                    <h3 class="section-title"><i class="fas fa-star"></i> Ulasan (<?= count($reviews) ?>)</h3>
                    
                    <?php if (count($reviews) > 0): ?>
                        <?php foreach ($reviews as $review): ?>
                            <div class="review-item">
                                <div class="review-header">
                                    <div class="review-avatar">
                                        <?= strtoupper(substr($review['nama_lengkap'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="review-name"><?= htmlspecialchars($review['nama_lengkap']) ?></div>
                                        <div class="review-date"><?= date('d M Y', strtotime($review['created_at'])) ?></div>
                                    </div>
                                    <div class="review-rating">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star<?= $i <= $review['rating'] ? '' : '-o' ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <div class="review-text">
                                    <?= nl2br(htmlspecialchars($review['komentar'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Belum ada ulasan untuk wisata ini.</p>
                    <?php endif; ?>
                    
                    <?php if ($user_id): ?>
                        <hr style="margin: 2rem 0;">
                        <h4>Tambah Ulasan</h4>
                        <form method="POST">
                            <div class="rating-input">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star<?= $i <= 3 ? ' active' : '' ?>" data-rating="<?= $i ?>"></i>
                                <?php endfor; ?>
                                <input type="hidden" name="rating" id="rating-value" value="3" required>
                            </div>
                            <textarea name="komentar" placeholder="Bagikan pengalaman Anda..." required></textarea>
                            <button type="submit" name="submit_review" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Kirim Ulasan
                            </button>
                        </form>
                    <?php else: ?>
                        <p>Silakan <a href="../login.php">login</a> untuk menambahkan ulasan.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.querySelector('.sidebar');
            const sidebarOverlay = document.querySelector('.sidebar-overlay');
            const menuToggle = document.querySelector('.menu-toggle');

            // Toggle sidebar on menu button click
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
                sidebarOverlay.classList.toggle('show');
            });

            // Close sidebar when clicking on overlay
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');
            });

            // Change main image when thumbnail is clicked
            function changeImage(element) {
                const mainImage = document.getElementById('main-image');
                mainImage.src = element.src;
                
                // Update active thumbnail
                document.querySelectorAll('.thumbnail').forEach(thumb => {
                    thumb.classList.remove('active');
                });
                element.classList.add('active');
            }

            // Rating input functionality
            const stars = document.querySelectorAll('.rating-input i');
            const ratingValue = document.getElementById('rating-value');
            
            stars.forEach(star => {
                star.addEventListener('click', function() {
                    const rating = this.getAttribute('data-rating');
                    ratingValue.value = rating;
                    
                    stars.forEach((s, index) => {
                        if (index < rating) {
                            s.classList.add('active');
                        } else {
                            s.classList.remove('active');
                        }
                    });
                });
            });

            // Close sidebar on window resize if screen is large enough
            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                }
            });
        });
    </script>
</body>
</html>

<?php
// Start session and include database connection
session_start();
require '../koneksi.php';

// Check if database connection is established
if (!isset($conn)) {
    die("Database connection failed");
}

// Authentication check
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

// User data
$user_id = $_SESSION['user']['id'];
$namaPengguna = $_SESSION['user']['nama_lengkap'] ?? 'Pengguna';
$level = $_SESSION['user']['level'] ?? 'user';
$inisial = !empty($namaPengguna) ? strtoupper(substr($namaPengguna, 0, 1)) : 'P';
$current_page = basename($_SERVER['PHP_SELF']);

// Get unique locations for autocomplete
$locations_query = "SELECT DISTINCT lokasi FROM kuliner WHERE status = 'approved'";
$locations_result = $conn->query($locations_query);
$locations = [];
if ($locations_result) {
    while ($row = $locations_result->fetch_assoc()) {
        $locations[] = $row['lokasi'];
    }
} else {
    die("Error fetching locations: " . $conn->error);
}

// Query parameters with proper sanitization
$search_query = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';
$kategori_filter = isset($_GET['kategori']) ? $conn->real_escape_string($_GET['kategori']) : '';
$budget_filter = isset($_GET['budget']) ? (int)$_GET['budget'] : 0;
$lokasi_filter = isset($_GET['lokasi']) ? $conn->real_escape_string(trim($_GET['lokasi'])) : '';
$promo_filter = isset($_GET['promo']) ? $_GET['promo'] : '';
$recommendation_filter = isset($_GET['recommendation']) ? $_GET['recommendation'] : '';
$participants = isset($_GET['participants']) ? max(1, (int)$_GET['participants']) : 1;

// Build the query with prepared statements
$sql = "SELECT * FROM kuliner WHERE status = 'approved'";
$types = '';
$params = [];

if (!empty($search_query)) {
    $sql .= " AND nama_tempat LIKE ?";
    $types .= 's';
    $params[] = "%$search_query%";
}

if (!empty($kategori_filter)) {
    $sql .= " AND kategori = ?";
    $types .= 's';
    $params[] = $kategori_filter;
}

if ($budget_filter > 0) {
    $sql .= " AND harga <= ?";
    $types .= 'i';
    $params[] = $budget_filter;
}

if (!empty($lokasi_filter)) {
    $sql .= " AND lokasi LIKE ?";
    $types .= 's';
    $params[] = "%$lokasi_filter%";
}

if ($promo_filter === 'yes') {
    $sql .= " AND is_promosi = 1";
}

if ($recommendation_filter === 'yes') {
    $sql .= " AND is_rekomendasi = 1";
}

// Prepare and execute the query
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Prepare failed: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    die("Execute failed: " . $stmt->error);
}

$result = $stmt->get_result();

// Process adding item to trip
if (isset($_POST['add_to_trip'])) {
    $item_id = (int)$_POST['item_id'];
    $biaya = (float)$_POST['biaya'];
    $tanggal_kunjungan = $conn->real_escape_string($_POST['tanggal_kunjungan']);
    $waktu_kunjungan = $conn->real_escape_string($_POST['waktu_kunjungan']);
    $jumlah_orang = (int)$_POST['jumlah_orang'];
    
    // Calculate total cost
    $total_biaya = $biaya * $jumlah_orang;

    // Prepare insert statement
    $insert_query = "INSERT INTO trip_saya (user_id, item_type, item_id, tanggal_kunjungan, waktu_kunjungan, biaya, jumlah_orang, keterangan) 
                    VALUES (?, 'kuliner', ?, ?, ?, ?, ?, ?)";

    $insert_stmt = $conn->prepare($insert_query);
    if ($insert_stmt === false) {
        die('Prepare error: ' . $conn->error);
    }

    $keterangan = "Kuliner - $jumlah_orang orang";
    $insert_stmt->bind_param('isssdis', $user_id, $item_id, $tanggal_kunjungan, $waktu_kunjungan, $total_biaya, $jumlah_orang, $keterangan);

    if ($insert_stmt->execute()) {
        $_SESSION['success_message'] = "Kuliner berhasil ditambahkan ke trip Anda!";
    } else {
        $_SESSION['error_message'] = "Gagal menambahkan kuliner ke trip: " . $insert_stmt->error;
    }

    header("Location: kuliner.php");
    exit;
}
?>



<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kuliner - Rencana-IN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/leaflet.css" />
    <style>
        :root {
            --primary: #4CAF50;
            --primary-dark: #3d8b40;
            --primary-light: rgba(76, 175, 80, 0.1);
            --secondary: #6c757d;
            --light: #f8f9fa;
            --dark: #343a40;
            --white: #ffffff;
            --gray-light: #e9ecef;
            --sidebar-width: 250px;
            --header-height: 60px;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --promo-color: #ff6b6b;
            --recommend-color: #4ecdc4;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
        }

        /* Sidebar Styles */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--white);
            position: fixed;
            top: 0;
            left: 0;
            box-shadow: var(--box-shadow);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        .sidebar-brand {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .sidebar-brand img {
            width: 36px;
            height: 36px;
            margin-right: 10px;
            object-fit: contain;
        }

        .sidebar-brand h2 {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            color: var(--primary);
            white-space: nowrap;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 0;
        }

        .nav-item {
            position: relative;
            margin: 0.25rem 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .nav-link:hover, .nav-link.active {
            color: var(--primary);
            background-color: var(--primary-light);
        }

        .nav-link.active {
            font-weight: 500;
            border-left: 3px solid var(--primary);
        }

        .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        /* Main Content Styles */
        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        .header {
            height: var(--header-height);
            background-color: var(--white);
            box-shadow: 0 0.15rem 1.75rem 0 rgba(33, 40, 50, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark);
            cursor: pointer;
            margin-right: 1rem;
        }

        .search-bar {
            position: relative;
            width: 300px;
            flex-grow: 1;
            max-width: 400px;
            margin: 0 1rem;
        }

        .search-bar input {
            width: 100%;
            padding: 0.375rem 0.75rem 0.375rem 2.5rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
            background-color: var(--light);
            font-size: 0.875rem;
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
            font-size: 0.875rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            margin-left: auto;
        }

        .user-menu .dropdown-toggle {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .user-menu .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            margin-right: 0.5rem;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        /* Content Area */
        .content {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 1.75rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
        }

        .page-header h1 i {
            margin-right: 0.8rem;
            color: var(--primary);
        }

        .page-header p {
            color: var(--secondary);
        }

        /* Location Detection Section */
        .location-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .location-title {
            font-size: 1.2rem;
            margin-bottom: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
        }

        .location-title i {
            margin-right: 0.8rem;
            color: var(--primary);
        }

        .location-form {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .location-input {
            flex: 1;
            position: relative;
        }

        .location-input input {
            width: 100%;
            padding: 0.7rem 0.7rem 0.7rem 2.5rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
        }

        .location-input i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
        }

        .location-map {
            height: 200px;
            margin-top: 1rem;
            border-radius: var(--border-radius);
            overflow: hidden;
            display: none;
        }

        /* Category Filter */
        .category-filter {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .category-btn {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            background-color: var(--gray-light);
            color: var(--dark);
            transition: all 0.3s;
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .category-btn.active {
            background-color: var(--primary);
            color: white;
        }

        .category-btn.promo {
            background-color: var(--promo-color);
            color: white;
        }

        .category-btn.rekomendasi {
            background-color: var(--recommend-color);
            color: white;
        }

        /* Search and Filter Section */
        .filter-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .filter-title {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            color: var(--dark);
            display: flex;
            align-items: center;
        }

        .filter-title i {
            margin-right: 0.8rem;
            color: var(--primary);
        }

        .filter-row {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.6rem;
            font-weight: 500;
            color: var(--dark);
        }

        .filter-group select, 
        .filter-group input {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            background-color: var(--white);
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .filter-group select:focus, 
        .filter-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-secondary {
            background-color: var(--white);
            color: var(--dark);
            border: 1px solid var(--gray-light);
        }

        .btn-secondary:hover {
            background-color: var(--gray-light);
        }

        .btn i {
            margin-right: 0.5rem;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
        }

        .alert-success {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--primary-dark);
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .alert-error {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }

        .alert i {
            margin-right: 10px;
        }

        /* Kuliner Grid */
        .kuliner-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .kuliner-card {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            transition: all 0.3s;
        }

        .kuliner-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }

        .kuliner-img-container {
            height: 200px;
            overflow: hidden;
            position: relative;
        }

        .kuliner-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }

        .kuliner-card:hover .kuliner-img {
            transform: scale(1.05);
        }

        .kuliner-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background-color: var(--primary);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .kuliner-badge.promo {
            background-color: var(--promo-color);
        }

        .kuliner-badge.rekomendasi {
            background-color: var(--recommend-color);
        }

        .kuliner-body {
            padding: 1.5rem;
        }

        .kuliner-title {
            font-size: 1.2rem;
            margin-bottom: 0.8rem;
            color: var(--dark);
            font-weight: 600;
        }

        .kuliner-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.2rem;
        }

        .kuliner-meta-item {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            color: var(--secondary);
        }

        .kuliner-meta-item i {
            margin-right: 0.5rem;
            color: var(--primary);
        }

        .kuliner-price {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 1.5rem;
        }

        .kuliner-actions {
            display: flex;
            gap: 0.8rem;
        }

        .kuliner-actions .btn {
            flex: 1;
            padding: 0.7rem;
            font-size: 0.9rem;
        }

        /* Date time group */
        .date-time-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-bottom: 1rem;
        }

        .date-time-item {
            flex: 1;
            min-width: 120px;
        }

        .date-time-item label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: var(--secondary);
        }

        .date-time-item input {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
        }

        /* Autocomplete dropdown */
        .autocomplete-items {
            position: absolute;
            border: 1px solid var(--gray-light);
            border-bottom: none;
            border-top: none;
            z-index: 99;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            max-height: 200px;
            overflow-y: auto;
        }

        .autocomplete-items div {
            padding: 10px;
            cursor: pointer;
            background-color: #fff;
            border-bottom: 1px solid var(--gray-light);
        }

        .autocomplete-items div:hover {
            background-color: var(--primary-light);
        }

        .no-results {
            text-align: center;
            padding: 3rem;
            color: var(--secondary);
            font-size: 1.1rem;
            grid-column: 1 / -1;
        }

        .no-results i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--gray-light);
        }

        /* Responsive Styles */
        @media (max-width: 1200px) {
            .kuliner-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .content {
                padding-top: calc(var(--header-height) + 1rem);
            }
        }

        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
                gap: 1rem;
            }

            .filter-group {
                min-width: 100%;
            }

            .kuliner-actions {
                flex-direction: column;
            }
            
            .search-bar {
                display: none;
            }

            .location-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .category-filter {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Sidebar Overlay -->
    <div class="sidebar-overlay"></div>

    <!-- Sidebar Navigation -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="../asset/logo.png" alt="Rencana-IN Logo">
            <h2>Rencana-IN</h2>
        </div>
        <nav class="sidebar-nav">
            <ul style="list-style: none; padding: 0; margin: 0;">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="wisata.php" class="nav-link <?= $current_page == 'wisata.php' ? 'active' : '' ?>">
                        <i class="fas fa-umbrella-beach"></i>
                        <span>Wisata</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="transportasi.php" class="nav-link <?= $current_page == 'transportasi.php' ? 'active' : '' ?>">
                        <i class="fas fa-bus"></i>
                        <span>Transportasi</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="kuliner.php" class="nav-link <?= $current_page == 'kuliner.php' ? 'active' : '' ?>">
                        <i class="fas fa-utensils"></i>
                        <span>Kuliner</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="penginapan.php" class="nav-link <?= $current_page == 'penginapan.php' ? 'active' : '' ?>">
                        <i class="fas fa-hotel"></i>
                        <span>Penginapan</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="trip_saya.php" class="nav-link <?= $current_page == 'trip_saya.php' ? 'active' : '' ?>">
                        <i class="fas fa-map-marked-alt"></i>
                        <span>Trip Saya</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <!-- Main Content Area -->
    <div class="main">
        <header class="header">
            <button class="menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Cari kuliner...">
            </div>
            <div class="user-menu">
                <a href="#" class="dropdown-toggle">
                    <div class="avatar">
                        <?= $inisial ?>
                    </div>
                    <span><?= htmlspecialchars($namaPengguna) ?></span>
                    <i class="fas fa-caret-down ml-2"></i>
                </a>
            </div>
        </header>
        
        <div class="content">
            <div class="page-header">
                <h1><i class="fas fa-utensils"></i> Daftar Kuliner</h1>
                <p>Temukan berbagai pilihan kuliner menarik untuk perjalanan Anda</p>
            </div>
            
            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $_SESSION['success_message'] ?>
                    <a href="trip_saya.php" class="btn btn-primary" style="margin-left: 1rem; padding: 0.3rem 0.8rem; font-size: 0.8rem;">
                        Lihat Trip
                    </a>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error_message'] ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Location Detection Section -->
            <div class="location-section">
                <h3 class="location-title"><i class="fas fa-map-marker-alt"></i> Lokasi Saat Ini</h3>
                <div class="location-form">
                    <div class="location-input">
                        <i class="fas fa-search-location"></i>
                        <input type="text" id="lokasi-sekarang" placeholder="Masukkan lokasi Anda atau gunakan deteksi otomatis" value="<?= htmlspecialchars($lokasi_filter) ?>">
                        <div id="autocomplete-container" class="autocomplete-items"></div>
                    </div>
                    <button type="button" id="deteksi-lokasi" class="btn btn-primary">
                        <i class="fas fa-location-arrow"></i> Deteksi Otomatis
                    </button>
                </div>
                <div id="peta-lokasi" class="location-map"></div>
            </div>

            <!-- Category Filter -->
            <div class="category-filter">
                <a href="kuliner.php" class="category-btn <?= empty($promo_filter) && empty($recommendation_filter) ? 'active' : '' ?>">
                    <i class="fas fa-list"></i> Semua
                </a>
                <a href="kuliner.php?promo=yes" class="category-btn promo <?= $promo_filter === 'yes' ? 'active' : '' ?>">
                    <i class="fas fa-tag"></i> Promosi
                </a>
                <a href="kuliner.php?recommendation=yes" class="category-btn rekomendasi <?= $recommendation_filter === 'yes' ? 'active' : '' ?>">
                    <i class="fas fa-star"></i> Rekomendasi
                </a>
            </div>

            <!-- Search and Filter Section -->
            <div class="filter-section">
                <h3 class="filter-title"><i class="fas fa-sliders-h"></i> Filter Kuliner</h3>
                <form method="GET" action="kuliner.php">
                    <input type="hidden" name="promo" value="<?= $promo_filter ?>">
                    <input type="hidden" name="recommendation" value="<?= $recommendation_filter ?>">
                    
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="search"><i class="fas fa-search"></i> Cari Kuliner</label>
                            <input type="text" id="search" name="search" placeholder="Nama tempat kuliner..." value="<?= htmlspecialchars($search_query) ?>">
                        </div>
                        <div class="filter-group">
                            <label for="kategori"><i class="fas fa-tags"></i> Kategori</label>
                            <select id="kategori" name="kategori">
                                <option value="">Semua Kategori</option>
                                <option value="halal" <?= $kategori_filter == 'halal' ? 'selected' : '' ?>>Halal</option>
                                <option value="khas_daerah" <?= $kategori_filter == 'khas_daerah' ? 'selected' : '' ?>>Khas Daerah</option>
                                <option value="vegan" <?= $kategori_filter == 'vegan' ? 'selected' : '' ?>>Vegan</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="lokasi"><i class="fas fa-city"></i> Lokasi</label>
                            <input type="text" id="lokasi" name="lokasi" placeholder="Pilih lokasi" value="<?= htmlspecialchars($lokasi_filter) ?>">
                        </div>
                        <div class="filter-group">
                            <label for="budget"><i class="fas fa-money-bill-wave"></i> Budget Maksimum (Rp)</label>
                            <input type="number" id="budget" name="budget" placeholder="Maksimum budget" value="<?= $budget_filter > 0 ? $budget_filter : '' ?>">
                        </div>
                        <div class="filter-group">
                            <label for="participants"><i class="fas fa-users"></i> Jumlah Orang</label>
                            <input type="number" id="participants" name="participants" min="1" value="<?= $participants ?>">
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Terapkan Filter
                        </button>
                        <a href="kuliner.php" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset Filter
                        </a>
                    </div>
                </form>
            </div>

            <!-- Kuliner List -->
            <div class="kuliner-grid">
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <div class="kuliner-card">
                            <div class="kuliner-img-container">
                                <img src="../uploads/kuliner/<?= htmlspecialchars($row['gambar']) ?>" 
                                     alt="<?= htmlspecialchars($row['nama_tempat']) ?>" 
                                     class="kuliner-img">
                                <?php if ($row['is_promosi'] == 1): ?>
                                    <span class="kuliner-badge promo">Promosi</span>
                                <?php elseif ($row['is_rekomendasi'] == 1): ?>
                                    <span class="kuliner-badge rekomendasi">Rekomendasi</span>
                                <?php endif; ?>
                            </div>
                            <div class="kuliner-body">
                                <h3 class="kuliner-title"><?= htmlspecialchars($row['nama_tempat']) ?></h3>
                                
                                <div class="kuliner-meta">
                                    <div class="kuliner-meta-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?= htmlspecialchars($row['lokasi']) ?>
                                    </div>
                                    <div class="kuliner-meta-item">
                                        <i class="fas fa-tag"></i>
                                        <?= ucfirst($row['kategori']) ?>
                                    </div>
                                </div>
                                
                                <div class="kuliner-price">
                                    Rp <?= number_format($row['harga'], 0, ',', '.') ?>
                                </div>
                                
                                <?php if (isset($_SESSION['user']['id'])): ?>
                                    <form method="POST" action="kuliner.php?<?= http_build_query($_GET) ?>">
                                        <input type="hidden" name="item_id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="biaya" value="<?= $row['harga'] ?>">
                                        
                                        <div class="date-time-group">
                                            <div class="date-time-item">
                                                <label for="tanggal_<?= $row['id'] ?>">Tanggal</label>
                                                <input type="date" id="tanggal_<?= $row['id'] ?>" name="tanggal_kunjungan" value="<?= date('Y-m-d') ?>" required>
                                            </div>
                                            <div class="date-time-item">
                                                <label for="waktu_<?= $row['id'] ?>">Waktu</label>
                                                <input type="time" id="waktu_<?= $row['id'] ?>" name="waktu_kunjungan" value="12:00" required>
                                            </div>
                                            <div class="date-time-item">
                                                <label for="jumlah_<?= $row['id'] ?>">Jumlah Orang</label>
                                                <input type="number" id="jumlah_<?= $row['id'] ?>" name="jumlah_orang" min="1" value="<?= $participants ?>" required>
                                            </div>
                                        </div>
                                        
                                        <div class="kuliner-actions">
                                            <button type="submit" name="add_to_trip" class="btn btn-primary">
                                                <i class="fas fa-plus"></i> Tambah ke Trip
                                            </button>
                                            <a href="detail_kuliner.php?id=<?= $row['id'] ?>" class="btn btn-secondary">
                                                <i class="fas fa-info-circle"></i> Detail
                                            </a>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="alert alert-error" style="margin-top: 1rem; padding: 0.8rem; text-align: center;">
                                        <i class="fas fa-exclamation-triangle"></i> Anda harus login untuk menambahkan ke trip
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-results">
                        <i class="fas fa-utensils"></i>
                        <h3>Tidak ada kuliner yang ditemukan</h3>
                        <p>Coba gunakan filter yang berbeda atau cari dengan kata kunci lain</p>
                        <a href="kuliner.php" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-undo"></i> Reset Filter
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/leaflet.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.querySelector('.sidebar');
            const sidebarOverlay = document.querySelector('.sidebar-overlay');
            const menuToggle = document.querySelector('.menu-toggle');

            // Toggle sidebar on menu button click
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
                sidebarOverlay.classList.toggle('show');
            });

            // Close sidebar when overlay is clicked
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');
            });

            // Auto-close sidebar on large screens
            function handleResize() {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                }
            }

            window.addEventListener('resize', handleResize);
            handleResize(); // Run once on load

            // Autocomplete for locations
            const lokasiInput = document.getElementById('lokasi-sekarang');
            const autocompleteContainer = document.getElementById('autocomplete-container');
            const daftarLokasi = <?= json_encode($locations) ?>;
            
            let peta;
            let marker;

            // Function to show autocomplete
            function showAutocomplete(str) {
                autocompleteContainer.innerHTML = '';
                
                if (str.length === 0) {
                    autocompleteContainer.style.display = 'none';
                    return;
                }
                
                const filtered = daftarLokasi.filter(lokasi => 
                    lokasi.toLowerCase().includes(str.toLowerCase())
                );
                
                if (filtered.length > 0) {
                    filtered.forEach(lokasi => {
                        const item = document.createElement('div');
                        item.innerHTML = `<strong>${lokasi.substr(0, str.length)}</strong>${lokasi.substr(str.length)}`;
                        item.addEventListener('click', function() {
                            lokasiInput.value = lokasi;
                            autocompleteContainer.style.display = 'none';
                        });
                        autocompleteContainer.appendChild(item);
                    });
                    autocompleteContainer.style.display = 'block';
                } else {
                    autocompleteContainer.style.display = 'none';
                }
            }
            
            // Event listener for location input
            lokasiInput.addEventListener('input', function() {
                showAutocomplete(this.value);
            });
            
            // Close autocomplete when clicking outside
            document.addEventListener('click', function(e) {
                if (e.target !== lokasiInput) {
                    autocompleteContainer.style.display = 'none';
                }
            });
            
            // Automatic location detection
            document.getElementById('deteksi-lokasi').addEventListener('click', function() {
                const petaLokasi = document.getElementById('peta-lokasi');
                
                if (navigator.geolocation) {
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mendeteksi...';
                    this.disabled = true;
                    
                    navigator.geolocation.getCurrentPosition(
                        function(position) {
                            const lat = position.coords.latitude;
                            const lng = position.coords.longitude;
                            
                            // Initialize map if not already
                            if (!peta) {
                                petaLokasi.style.display = 'block';
                                peta = L.map('peta-lokasi').setView([lat, lng], 13);
                                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                                }).addTo(peta);
                            } else {
                                peta.setView([lat, lng], 13);
                            }
                            
                            // Remove old marker if exists
                            if (marker) {
                                peta.removeLayer(marker);
                            }
                            
                            // Add new marker
                            marker = L.marker([lat, lng]).addTo(peta)
                                .bindPopup('Lokasi Anda saat ini').openPopup();
                            
                            // Reverse geocoding to get location name
                            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                                .then(response => response.json())
                                .then(data => {
                                    let lokasiName = data.address.city || data.address.town || data.address.village || '';
                                    if (data.address.state) {
                                        lokasiName += lokasiName ? ', ' + data.address.state : data.address.state;
                                    }
                                    
                                    if (lokasiName) {
                                        lokasiInput.value = lokasiName;
                                    }
                                    
                                    document.getElementById('deteksi-lokasi').innerHTML = '<i class="fas fa-location-arrow"></i> Deteksi Otomatis';
                                    document.getElementById('deteksi-lokasi').disabled = false;
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    document.getElementById('deteksi-lokasi').innerHTML = '<i class="fas fa-location-arrow"></i> Deteksi Otomatis';
                                    document.getElementById('deteksi-lokasi').disabled = false;
                                });
                        },
                        function(error) {
                            console.error('Error getting location:', error);
                            alert('Gagal mendapatkan lokasi. Pastikan Anda mengizinkan akses lokasi.');
                            document.getElementById('deteksi-lokasi').innerHTML = '<i class="fas fa-location-arrow"></i> Deteksi Otomatis';
                            document.getElementById('deteksi-lokasi').disabled = false;
                        }
                    );
                } else {
                    alert('Browser Anda tidak mendukung geolocation.');
                }
            });
            
            // Sync date inputs with today if empty
            const today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(input => {
                if (!input.value) {
                    input.value = today;
                }
            });
        });
    </script>
</body>
</html>

<?php
// Cek apakah session sudah dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validasi path file koneksi.php
if (!file_exists('../koneksi.php')) {
    die("File koneksi.php tidak ditemukan!");
}

require '../koneksi.php'; // Memuat koneksi database (mendefinisikan $conn)

// Pastikan $conn didefinisikan di koneksi.php
if (!isset($conn)) {
    die("Koneksi database gagal: \$conn tidak didefinisikan di koneksi.php");
}

$koneksi = $conn; // Definisikan $koneksi sebagai alias untuk $conn

// Periksa apakah user sudah login, jika tidak redirect ke login
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

// Ambil data user
$namaPengguna = $_SESSION['user']['nama_lengkap'] ?? 'Pengguna';
$inisial = !empty($namaPengguna) ? strtoupper(substr($namaPengguna, 0, 1)) : 'P';
$current_page = basename($_SERVER['PHP_SELF']);

// Proses filter penginapan
$nama = isset($_GET['nama']) ? $_GET['nama'] : '';
$lokasi = isset($_GET['lokasi']) ? $_GET['lokasi'] : '';
$kategori = isset($_GET['kategori']) ? $_GET['kategori'] : '';
$harga_min = isset($_GET['harga_min']) ? intval($_GET['harga_min']) : 0;
$harga_max = isset($_GET['harga_max']) ? intval($_GET['harga_max']) : 0;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'nama_asc';
$filter_kategori = isset($_GET['filter_kategori']) ? $_GET['filter_kategori'] : '';

// Query untuk mendapatkan data penginapan dengan filter
$query = "SELECT * FROM penginapan WHERE status = 'approved'";
$params = [];
$types = ''; // Variable for parameter types

if (!empty($nama)) {
    $query .= " AND nama_penginapan LIKE ?";
    $params[] = "%$nama%";
    $types .= 's'; // Add 's' for string
}

if (!empty($lokasi)) {
    $query .= " AND lokasi LIKE ?";
    $params[] = "%$lokasi%";
    $types .= 's'; // Add 's' for string
}

if (!empty($kategori)) {
    $query .= " AND kategori = ?";
    $params[] = $kategori;
    $types .= 's'; // Add 's' for string
}

if ($harga_min > 0) {
    $query .= " AND (harga_per_malam >= ? OR (harga_diskon > 0 AND harga_diskon >= ?))";
    $params[] = $harga_min;
    $params[] = $harga_min;
    $types .= 'ii'; // Add 'i' for integer
}

if ($harga_max > 0) {
    $query .= " AND (harga_per_malam <= ? OR (harga_diskon > 0 AND harga_diskon <= ?))";
    $params[] = $harga_max;
    $params[] = $harga_max;
    $types .= 'ii'; // Add 'i' for integer
}

// Filter kategori (promosi/rekomendasi)
if (!empty($filter_kategori)) {
    if ($filter_kategori == 'promosi') {
        $query .= " AND is_promosi = 1";
    } elseif ($filter_kategori == 'rekomendasi') {
        $query .= " AND is_rekomendasi = 1";
    }
}

// Sorting
switch ($sort) {
    case 'nama_desc':
        $query .= " ORDER BY nama_penginapan DESC";
        break;
    case 'harga_asc':
        $query .= " ORDER BY COALESCE(NULLIF(harga_diskon, 0), harga_per_malam) ASC";
        break;
    case 'harga_desc':
        $query .= " ORDER BY COALESCE(NULLIF(harga_diskon, 0), harga_per_malam) DESC";
        break;
    default:
        $query .= " ORDER BY nama_penginapan ASC";
}

$stmt = $koneksi->prepare($query);

// Check if the statement was prepared successfully
if ($stmt === false) {
    die('MySQL prepare error: ' . $koneksi->error);
}

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$penginapan = $result->fetch_all(MYSQLI_ASSOC);

// Ambil daftar kota unik untuk rekomendasi lokasi
$kota_query = "SELECT DISTINCT lokasi FROM penginapan ORDER BY lokasi";
$kota_result = $koneksi->query($kota_query);
$daftar_kota = [];
while ($row = $kota_result->fetch_assoc()) {
    $daftar_kota[] = $row['lokasi'];
}

// Proses tambah ke trip
if (isset($_POST['add_to_trip'])) {
    // Pastikan user sudah login
    if (!isset($_SESSION['user']['id'])) {
        $error_message = "Anda harus login untuk menambahkan ke trip";
    } else {
        $item_id = $_POST['item_id'];
        $biaya_per_malam = $_POST['biaya_per_malam'];
        $tanggal_checkin = $_POST['tanggal_checkin'];
        $tanggal_checkout = $_POST['tanggal_checkout'];
        $user_id = $_SESSION['user']['id'];
        
        // Hitung jumlah malam
        $checkin = new DateTime($tanggal_checkin);
        $checkout = new DateTime($tanggal_checkout);
        $jumlah_malam = $checkin->diff($checkout)->days;
        $total_biaya = $biaya_per_malam * $jumlah_malam;
        
        // Insert ke tabel trip_saya
        $insert_query = "INSERT INTO trip_saya (user_id, item_type, item_id, tanggal_kunjungan, waktu_kunjungan, biaya, keterangan) 
                        VALUES (?, 'penginapan', ?, ?, ?, ?, ?)";
        
        // Prepare the statement for inserting the trip
        $insert_stmt = $koneksi->prepare($insert_query);
        if ($insert_stmt === false) {
            die('MySQL prepare error: ' . $koneksi->error);
        }

        // Format keterangan
        $keterangan = "Check-in: $tanggal_checkin, Check-out: $tanggal_checkout ($jumlah_malam malam)";
        
        // Bind parameters and execute the insert
        $insert_stmt->bind_param('isssds', $user_id, $item_id, $tanggal_checkin, $tanggal_checkout, $total_biaya, $keterangan);
        
        if ($insert_stmt->execute()) {
            $success_message = "Penginapan berhasil ditambahkan ke trip Anda!";
        } else {
            $error_message = "Gagal menambahkan penginapan ke trip: " . $insert_stmt->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Penginapan - Rencana-IN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/leaflet.css" />
    <style>
        /* ROOT VARIABEL UNTUK KONSISTENSI WARNA DAN UKURAN */
        :root {
            --primary: #4CAF50; /* Hijau utama */
            --primary-dark: #3d8b40; /* Versi gelap hijau */
            --primary-light: rgba(76, 175, 80, 0.1); /* Light green for backgrounds */
            --secondary: #6c757d; /* Abu-abu sekunder */
            --light: #f8f9fa; /* Background terang */
            --dark: #343a40; /* Warna teks gelap */
            --white: #ffffff; /* Putih */
            --gray-light: #e9ecef; /* Light gray for borders */
            --sidebar-width: 250px;
            --header-height: 60px;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --promo-color: #ff6b6b; /* Warna untuk promosi */
            --recommend-color: #4ecdc4; /* Warna untuk rekomendasi */
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
        }

        /* SIDEBAR STYLING */
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--white);
            position: fixed;
            top: 0;
            left: 0;
            box-shadow: var(--box-shadow);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        .sidebar-brand {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .sidebar-brand img {
            width: 36px;
            height: 36px;
            margin-right: 10px;
            object-fit: contain;
        }

        .sidebar-brand h2 {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            color: var(--primary);
            white-space: nowrap;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 0;
        }

        .nav-item {
            position: relative;
            margin: 0.25rem 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .nav-link:hover, .nav-link.active {
            color: var(--primary);
            background-color: var(--primary-light);
        }

        .nav-link.active {
            font-weight: 500;
            border-left: 3px solid var(--primary);
        }

        .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        /* HEADER DI BAGIAN ATAS */
        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        .header {
            height: var(--header-height);
            background-color: var(--white);
            box-shadow: 0 0.15rem 1.75rem 0 rgba(33, 40, 50, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark);
            cursor: pointer;
            margin-right: 1rem;
        }

        .search-bar {
            position: relative;
            width: 300px;
            flex-grow: 1;
            max-width: 400px;
            margin: 0 1rem;
        }

        .search-bar input {
            width: 100%;
            padding: 0.375rem 0.75rem 0.375rem 2.5rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
            background-color: var(--light);
            font-size: 0.875rem;
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
            font-size: 0.875rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            margin-left: auto;
        }

        .user-menu .dropdown-toggle {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .user-menu .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            margin-right: 0.5rem;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        /* OVERLAY UNTUK MOBILE SIDEBAR */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        .sidebar-overlay.show {
            display: block;
        }

        /* Penginapan Content */
        .penginapan-container {
            padding: 2rem;
        }

        .filter-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .filter-title {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            color: var(--dark);
            display: flex;
            align-items: center;
        }

        .filter-title i {
            margin-right: 0.8rem;
            color: var(--primary);
        }

        .filter-row {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.6rem;
            font-weight: 500;
            color: var(--dark);
        }

        .filter-group select, 
        .filter-group input {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            background-color: var(--white);
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .filter-group select:focus, 
        .filter-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-secondary {
            background-color: var(--white);
            color: var(--dark);
            border: 1px solid var(--gray-light);
        }

        .btn-secondary:hover {
            background-color: var(--gray-light);
        }

        .btn i {
            margin-right: 0.5rem;
        }

        /* Penginapan Grid */
        .penginapan-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .penginapan-card {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            transition: all 0.3s;
        }

        .penginapan-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }

        .penginapan-img-container {
            height: 200px;
            overflow: hidden;
            position: relative;
        }

        .penginapan-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }

        .penginapan-card:hover .penginapan-img {
            transform: scale(1.05);
        }

        .penginapan-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background-color: var(--primary);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .penginapan-body {
            padding: 1.5rem;
        }

        .penginapan-title {
            font-size: 1.2rem;
            margin-bottom: 0.8rem;
            color: var(--dark);
            font-weight: 600;
        }

        .penginapan-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.2rem;
        }

        .penginapan-meta-item {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            color: var(--secondary);
        }

        .penginapan-meta-item i {
            margin-right: 0.5rem;
            color: var(--primary);
        }

        .penginapan-price {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 1.5rem;
        }

        .penginapan-fasilitas {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .fasilitas-badge {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .penginapan-actions {
            display: flex;
            gap: 0.8rem;
        }

        .penginapan-actions .btn {
            flex: 1;
            padding: 0.7rem;
            font-size: 0.9rem;
        }

        .no-results {
            text-align: center;
            padding: 3rem;
            color: var(--secondary);
            font-size: 1.1rem;
            grid-column: 1 / -1;
        }

        .no-results i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--gray-light);
        }

        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
        }

        .alert-success {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--primary-dark);
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .alert-error {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }

        /* New styles for location detection */
        .location-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .location-title {
            font-size: 1.2rem;
            margin-bottom: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
        }

        .location-title i {
            margin-right: 0.8rem;
            color: var(--primary);
        }

        .location-form {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .location-input {
            flex: 1;
            position: relative;
        }

        .location-input input {
            width: 100%;
            padding: 0.7rem 0.7rem 0.7rem 2.5rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
        }

        .location-input i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
        }

        .location-map {
            height: 200px;
            margin-top: 1rem;
            border-radius: var(--border-radius);
            overflow: hidden;
            display: none;
        }

        /* Badge styles for categories */
        .penginapan-badge.promo {
            background-color: var(--promo-color);
        }

        .penginapan-badge.rekomendasi {
            background-color: var(--recommend-color);
        }

        .category-filter {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .category-btn {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            background-color: var(--gray-light);
            color: var(--dark);
            transition: all 0.3s;
        }

        .category-btn.active {
            background-color: var(--primary);
            color: white;
        }

        .category-btn.promo {
            background-color: var(--promo-color);
            color: white;
        }

        .category-btn.rekomendasi {
            background-color: var(--recommend-color);
            color: white;
        }

        /* Autocomplete dropdown */
        .autocomplete-items {
            position: absolute;
            border: 1px solid var(--gray-light);
            border-bottom: none;
            border-top: none;
            z-index: 99;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            max-height: 200px;
            overflow-y: auto;
        }

        .autocomplete-items div {
            padding: 10px;
            cursor: pointer;
            background-color: #fff;
            border-bottom: 1px solid var(--gray-light);
        }

        .autocomplete-items div:hover {
            background-color: var(--primary-light);
        }

        /* Date time input improvements */
        .date-range-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-bottom: 1rem;
        }

        .date-range-item {
            flex: 1;
            min-width: 120px;
        }

        .date-range-item label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: var(--secondary);
        }

        .date-range-item input {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
        }

        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .penginapan-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .penginapan-container {
                padding-top: calc(var(--header-height) + 1rem);
            }
        }

        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
                gap: 1rem;
            }

            .filter-group {
                min-width: 100%;
            }

            .penginapan-actions {
                flex-direction: column;
            }
            
            .search-bar {
                display: none;
            }

            .location-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .category-filter {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
  <!-- Overlay untuk sidebar saat tampil di mobile -->
    <div class="sidebar-overlay"></div>

    <!-- Sidebar navigasi utama -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="../asset/logo.png" alt="Rencana-IN Logo">
            <h2>Rencana-IN</h2>
        </div>
        <nav class="sidebar-nav">
            <ul style="list-style: none; padding: 0; margin: 0;">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="wisata.php" class="nav-link <?= $current_page == 'wisata.php' ? 'active' : '' ?>">
                        <i class="fas fa-umbrella-beach"></i>
                        <span>Wisata</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="transportasi.php" class="nav-link <?= $current_page == 'transportasi.php' ? 'active' : '' ?>">
                        <i class="fas fa-bus"></i>
                        <span>Transportasi</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="kuliner.php" class="nav-link <?= $current_page == 'kuliner.php' ? 'active' : '' ?>">
                        <i class="fas fa-utensils"></i>
                        <span>Kuliner</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="penginapan.php" class="nav-link <?= $current_page == 'penginapan.php' ? 'active' : '' ?>">
                        <i class="fas fa-hotel"></i>
                        <span>Penginapan</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="trip_saya.php" class="nav-link <?= $current_page == 'trip_saya.php' ? 'active' : '' ?>">
                        <i class="fas fa-map-marked-alt"></i>
                        <span>Trip Saya</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main">
        <header class="header">
            <button class="menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Cari penginapan...">
            </div>
            <div class="user-menu">
                <a href="#" class="dropdown-toggle">
                    <div class="avatar">
                        <?= htmlspecialchars($inisial) ?>
                    </div>
                    <span><?= htmlspecialchars($namaPengguna) ?></span>
                    <i class="fas fa-caret-down ml-2"></i>
                </a>
            </div>
        </header>

        <div class="penginapan-container">
            <!-- Notification Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $success_message ?>
                    <a href="trip_saya.php" class="btn btn-primary" style="margin-left: 1rem; padding: 0.3rem 0.8rem; font-size: 0.8rem;">
                        Lihat Trip
                    </a>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
                </div>
            <?php endif; ?>

            <!-- Location Detection Section -->
            <div class="location-section">
                <h3 class="location-title"><i class="fas fa-map-marker-alt"></i> Lokasi Saat Ini</h3>
                <div class="location-form">
                    <div class="location-input">
                        <i class="fas fa-search-location"></i>
                        <input type="text" id="lokasi-sekarang" placeholder="Masukkan lokasi Anda atau gunakan deteksi otomatis" value="<?= htmlspecialchars($lokasi) ?>">
                        <div id="autocomplete-container" class="autocomplete-items"></div>
                    </div>
                    <button type="button" id="deteksi-lokasi" class="btn btn-primary">
                        <i class="fas fa-location-arrow"></i> Deteksi Otomatis
                    </button>
                </div>
                <div id="peta-lokasi" class="location-map"></div>
            </div>

            <!-- Category Filter -->
            <div class="filter-section">
                <div class="category-filter">
                    <a href="penginapan.php" class="category-btn <?= empty($filter_kategori) ? 'active' : '' ?>">
                        <i class="fas fa-list"></i> Semua
                    </a>
                    <a href="penginapan.php?filter_kategori=promosi" class="category-btn promo <?= $filter_kategori == 'promosi' ? 'active' : '' ?>">
                        <i class="fas fa-tag"></i> Promosi
                    </a>
                    <a href="penginapan.php?filter_kategori=rekomendasi" class="category-btn rekomendasi <?= $filter_kategori == 'rekomendasi' ? 'active' : '' ?>">
                        <i class="fas fa-star"></i> Rekomendasi
                    </a>
                </div>
                
                <h3 class="filter-title"><i class="fas fa-sliders-h"></i> Filter Penginapan</h3>
                <form method="GET" action="penginapan.php">
                    <input type="hidden" name="filter_kategori" value="<?= $filter_kategori ?>">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="nama"><i class="fas fa-hotel"></i> Nama Penginapan</label>
                            <input type="text" id="nama" name="nama" placeholder="Cari nama penginapan" value="<?= htmlspecialchars($nama) ?>">
                        </div>
                        <div class="filter-group">
                            <label for="lokasi"><i class="fas fa-map-marker-alt"></i> Lokasi</label>
                            <input type="text" id="lokasi" name="lokasi" placeholder="Kota atau daerah" value="<?= htmlspecialchars($lokasi) ?>">
                        </div>
                        <div class="filter-group">
                            <label for="kategori"><i class="fas fa-tags"></i> Kategori</label>
                            <select id="kategori" name="kategori">
                                <option value="">Semua Kategori</option>
                                <option value="hotel" <?= $kategori == 'hotel' ? 'selected' : '' ?>>Hotel</option>
                                <option value="villa" <?= $kategori == 'villa' ? 'selected' : '' ?>>Villa</option>
                                <option value="apartemen" <?= $kategori == 'apartemen' ? 'selected' : '' ?>>Apartemen</option>
                                <option value="guest_house" <?= $kategori == 'guest_house' ? 'selected' : '' ?>>Guest House</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="harga_min"><i class="fas fa-money-bill-wave"></i> Harga Minimum (Rp/malam)</label>
                            <input type="number" id="harga_min" name="harga_min" placeholder="Minimum" value="<?= $harga_min > 0 ? $harga_min : '' ?>">
                        </div>
                        <div class="filter-group">
                            <label for="harga_max"><i class="fas fa-money-bill-wave"></i> Harga Maksimum (Rp/malam)</label>
                            <input type="number" id="harga_max" name="harga_max" placeholder="Maksimum" value="<?= $harga_max > 0 ? $harga_max : '' ?>">
                        </div>
                        <div class="filter-group">
                            <label for="sort"><i class="fas fa-sort"></i> Urutkan Berdasarkan</label>
                            <select id="sort" name="sort">
                                <option value="nama_asc" <?= $sort == 'nama_asc' ? 'selected' : '' ?>>Nama (A-Z)</option>
                                <option value="nama_desc" <?= $sort == 'nama_desc' ? 'selected' : '' ?>>Nama (Z-A)</option>
                                <option value="harga_asc" <?= $sort == 'harga_asc' ? 'selected' : '' ?>>Harga Terendah</option>
                                <option value="harga_desc" <?= $sort == 'harga_desc' ? 'selected' : '' ?>>Harga Tertinggi</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Terapkan Filter
                        </button>
                        <a href="penginapan.php" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset Filter
                        </a>
                    </div>
                </form>
            </div>

            <!-- Penginapan List -->
            <div class="penginapan-grid">
                <?php if (count($penginapan) > 0): ?>
                    <?php foreach ($penginapan as $item): ?>
                        <div class="penginapan-card">
                            <div class="penginapan-img-container">
                                <img src="<?= htmlspecialchars($item['gambar']) ?>" alt="<?= htmlspecialchars($item['nama_penginapan']) ?>" class="penginapan-img">
                                <?php if ($item['is_promosi'] == 1): ?>
                                    <span class="penginapan-badge promo">Promosi</span>
                                <?php elseif ($item['is_rekomendasi'] == 1): ?>
                                    <span class="penginapan-badge rekomendasi">Rekomendasi</span>
                                <?php else: ?>
                                    <span class="penginapan-badge"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $item['kategori']))) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="penginapan-body">
                                <h3 class="penginapan-title"><?= htmlspecialchars($item['nama_penginapan']) ?></h3>
                                
                                <?php if (!empty($item['deskripsi'])): ?>
                                    <p class="penginapan-desc"><?= htmlspecialchars($item['deskripsi']) ?></p>
                                <?php endif; ?>
                                
                                <div class="penginapan-meta">
                                    <div class="penginapan-meta-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?= htmlspecialchars($item['lokasi']) ?>
                                    </div>
                                    <div class="penginapan-meta-item">
                                        <i class="fas fa-star"></i>
                                        Rating: <?= htmlspecialchars($item['rating']) ?>/5
                                    </div>
                                </div>
                                
                                <!-- Fasilitas -->
                                <?php 
                                $fasilitas = explode(',', $item['fasilitas']);
                                if (!empty($fasilitas[0])):
                                ?>
                                <div class="penginapan-fasilitas">
                                    <?php foreach ($fasilitas as $fasilitas_item): ?>
                                        <span class="fasilitas-badge"><?= htmlspecialchars(trim($fasilitas_item)) ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="penginapan-price">
                                    <?php if ($item['harga_diskon'] > 0): ?>
                                        <span style="text-decoration: line-through; color: var(--secondary); font-size: 0.9em; margin-right: 0.5rem;">
                                            Rp <?= number_format($item['harga_per_malam'], 0, ',', '.') ?>
                                        </span>
                                        Rp <?= number_format($item['harga_diskon'], 0, ',', '.') ?>
                                    <?php else: ?>
                                        Rp <?= number_format($item['harga_per_malam'], 0, ',', '.') ?>
                                    <?php endif; ?>
                                    <span style="font-size: 0.9em; color: var(--secondary);">/malam</span>
                                </div>
                                
                                <?php if (isset($_SESSION['user']['id'])): ?>
                                    <form method="POST" action="penginapan.php?<?= http_build_query($_GET) ?>">
                                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                        <input type="hidden" name="biaya_per_malam" value="<?= ($item['harga_diskon'] > 0) ? $item['harga_diskon'] : $item['harga_per_malam'] ?>">
                                        
                                        <div class="date-range-group">
                                            <div class="date-range-item">
                                                <label for="checkin_<?= $item['id'] ?>">Check-in</label>
                                                <input type="date" id="checkin_<?= $item['id'] ?>" name="tanggal_checkin" value="<?= date('Y-m-d') ?>" required>
                                            </div>
                                            <div class="date-range-item">
                                                <label for="checkout_<?= $item['id'] ?>">Check-out</label>
                                                <input type="date" id="checkout_<?= $item['id'] ?>" name="tanggal_checkout" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                                            </div>
                                        </div>
                                        
                                        <div class="penginapan-actions">
                                            <button type="submit" name="add_to_trip" class="btn btn-primary">
                                                <i class="fas fa-plus"></i> Tambah ke Trip
                                            </button>
                                            <a href="detail_penginapan.php?id=<?= $item['id'] ?>" class="btn btn-secondary">
                                                <i class="fas fa-info-circle"></i> Detail
                                            </a>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="alert alert-error" style="margin-top: 1rem; padding: 0.8rem; text-align: center;">
                                        <i class="fas fa-exclamation-triangle"></i> Anda harus login untuk menambahkan ke trip
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-results">
                        <i class="fas fa-hotel"></i>
                        <h3>Tidak ada penginapan yang ditemukan</h3>
                        <p>Coba gunakan filter yang berbeda atau cari dengan kata kunci lain</p>
                        <a href="penginapan.php" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-undo"></i> Reset Filter
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/leaflet.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.querySelector('.sidebar');
            const sidebarOverlay = document.querySelector('.sidebar-overlay');
            const menuToggle = document.querySelector('.menu-toggle');

            // Tampilkan dan sembunyikan sidebar saat tombol ditekan
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
                sidebarOverlay.classList.toggle('show');
            });

            // Klik overlay untuk menutup sidebar
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');
            });

            // Tangani resize jendela untuk menutup sidebar di layar besar
            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                }
            });

            // Autocomplete untuk lokasi
            const lokasiInput = document.getElementById('lokasi-sekarang');
            const autocompleteContainer = document.getElementById('autocomplete-container');
            const lokasiFilterInput = document.getElementById('lokasi');
            const daftarKota = <?= json_encode($daftar_kota) ?>;
            
            let peta;
            let marker;

            // Fungsi untuk menampilkan autocomplete
            function showAutocomplete(str) {
                autocompleteContainer.innerHTML = '';
                
                if (str.length === 0) {
                    autocompleteContainer.style.display = 'none';
                    return;
                }
                
                const filtered = daftarKota.filter(kota => 
                    kota.toLowerCase().includes(str.toLowerCase())
                );
                
                if (filtered.length > 0) {
                    filtered.forEach(kota => {
                        const item = document.createElement('div');
                        item.innerHTML = `<strong>${kota.substr(0, str.length)}</strong>${kota.substr(str.length)}`;
                        item.addEventListener('click', function() {
                            lokasiInput.value = kota;
                            lokasiFilterInput.value = kota;
                            autocompleteContainer.style.display = 'none';
                        });
                        autocompleteContainer.appendChild(item);
                    });
                    autocompleteContainer.style.display = 'block';
                } else {
                    autocompleteContainer.style.display = 'none';
                }
            }
            
            // Event listener untuk input lokasi
            lokasiInput.addEventListener('input', function() {
                showAutocomplete(this.value);
            });
            
            // Sinkronkan input lokasi dengan filter
            lokasiInput.addEventListener('change', function() {
                lokasiFilterInput.value = this.value;
            });
            
            // Tutup autocomplete saat klik di luar
            document.addEventListener('click', function(e) {
                if (e.target !== lokasiInput) {
                    autocompleteContainer.style.display = 'none';
                }
            });
            
            // Deteksi lokasi otomatis
            document.getElementById('deteksi-lokasi').addEventListener('click', function() {
                const petaLokasi = document.getElementById('peta-lokasi');
                
                if (navigator.geolocation) {
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mendeteksi...';
                    this.disabled = true;
                    
                    navigator.geolocation.getCurrentPosition(
                        function(position) {
                            const lat = position.coords.latitude;
                            const lng = position.coords.longitude;
                            
                            // Inisialisasi peta jika belum ada
                            if (!peta) {
                                petaLokasi.style.display = 'block';
                                peta = L.map('peta-lokasi').setView([lat, lng], 13);
                                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                                }).addTo(peta);
                            } else {
                                peta.setView([lat, lng], 13);
                            }
                            
                            // Hapus marker lama jika ada
                            if (marker) {
                                peta.removeLayer(marker);
                            }
                            
                            // Tambahkan marker baru
                            marker = L.marker([lat, lng]).addTo(peta)
                                .bindPopup('Lokasi Anda saat ini').openPopup();
                            
                            // Reverse geocoding untuk mendapatkan nama lokasi
                            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                                .then(response => response.json())
                                .then(data => {
                                    let lokasiName = data.address.city || data.address.town || data.address.village || '';
                                    if (data.address.state) {
                                        lokasiName += lokasiName ? ', ' + data.address.state : data.address.state;
                                    }
                                    
                                    if (lokasiName) {
                                        lokasiInput.value = lokasiName;
                                        lokasiFilterInput.value = lokasiName;
                                    }
                                    
                                    document.getElementById('deteksi-lokasi').innerHTML = '<i class="fas fa-location-arrow"></i> Deteksi Otomatis';
                                    document.getElementById('deteksi-lokasi').disabled = false;
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    document.getElementById('deteksi-lokasi').innerHTML = '<i class="fas fa-location-arrow"></i> Deteksi Otomatis';
                                    document.getElementById('deteksi-lokasi').disabled = false;
                                });
                        },
                        function(error) {
                            console.error('Error getting location:', error);
                            alert('Gagal mendapatkan lokasi. Pastikan Anda mengizinkan akses lokasi.');
                            document.getElementById('deteksi-lokasi').innerHTML = '<i class="fas fa-location-arrow"></i> Deteksi Otomatis';
                            document.getElementById('deteksi-lokasi').disabled = false;
                        }
                    );
                } else {
                    alert('Browser Anda tidak mendukung geolocation.');
                }
            });
            
            // Validasi tanggal check-out harus setelah check-in
            document.querySelectorAll('input[name="tanggal_checkin"]').forEach(checkinInput => {
                const itemId = checkinInput.id.split('_')[1];
                const checkoutInput = document.getElementById(`checkout_${itemId}`);
                
                checkinInput.addEventListener('change', function() {
                    const checkinDate = new Date(this.value);
                    const checkoutDate = new Date(checkoutInput.value);
                    
                    if (checkoutDate <= checkinDate) {
                        // Set check-out ke 1 hari setelah check-in
                        const nextDay = new Date(checkinDate);
                        nextDay.setDate(nextDay.getDate() + 1);
                        checkoutInput.valueAsDate = nextDay;
                    }
                });
            });
            
            // Validasi tanggal check-in tidak boleh sebelum hari ini
            const today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[name="tanggal_checkin"]').forEach(input => {
                input.min = today;
                if (!input.value || input.value < today) {
                    input.value = today;
                }
            });
            
            // Validasi tanggal check-out minimal 1 hari setelah check-in
            document.querySelectorAll('input[name="tanggal_checkout"]').forEach(checkoutInput => {
                const itemId = checkoutInput.id.split('_')[1];
                const checkinInput = document.getElementById(`checkin_${itemId}`);
                
                checkoutInput.addEventListener('change', function() {
                    const checkinDate = new Date(checkinInput.value);
                    const checkoutDate = new Date(this.value);
                    
                    if (checkoutDate <= checkinDate) {
                        alert('Tanggal check-out harus setelah tanggal check-in');
                        const nextDay = new Date(checkinDate);
                        nextDay.setDate(nextDay.getDate() + 1);
                        this.valueAsDate = nextDay;
                    }
                });
            });
        });
    </script>
</body>
</html>

<?php
session_start();
require '../koneksi.php';

if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bayar'])) {
    $total_biaya = floatval($_POST['total_biaya']);
    $metode = $_POST['metode'];
    
    // Handle file upload
    $target_dir = "../uploads/pembayaran/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_name = time() . '_' . basename($_FILES["bukti_pembayaran"]["name"]);
    $target_file = $target_dir . $file_name;
    $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
    
    // Check if image file is a actual image or fake image
    $check = getimagesize($_FILES["bukti_pembayaran"]["tmp_name"]);
    if ($check === false) {
        $_SESSION['error_message'] = "File yang diunggah bukan gambar.";
        header("Location: trip_saya.php");
        exit();
    }
    
    // Check file size (max 2MB)
    if ($_FILES["bukti_pembayaran"]["size"] > 2000000) {
        $_SESSION['error_message'] = "Ukuran file terlalu besar (maks. 2MB).";
        header("Location: trip_saya.php");
        exit();
    }
    
    // Allow certain file formats
    if (!in_array($imageFileType, ['jpg', 'jpeg', 'png'])) {
        $_SESSION['error_message'] = "Hanya format JPG, JPEG, PNG yang diperbolehkan.";
        header("Location: trip_saya.php");
        exit();
    }
    
    // Try to upload file
    if (move_uploaded_file($_FILES["bukti_pembayaran"]["tmp_name"], $target_file)) {
        // Insert payment data to database
        $query = "INSERT INTO pembayaran (user_id, jumlah, metode, bukti_pembayaran, status) 
                  VALUES (?, ?, ?, ?, 'pending')";
        $stmt = $koneksi->prepare($query);
        $stmt->bind_param('idss', $user_id, $total_biaya, $metode, $file_name);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Bukti pembayaran berhasil diunggah. Menunggu verifikasi admin.";
        } else {
            $_SESSION['error_message'] = "Gagal menyimpan data pembayaran: " . $stmt->error;
            unlink($target_file); // Delete uploaded file if database insert fails
        }
    } else {
        $_SESSION['error_message'] = "Maaf, terjadi kesalahan saat mengunggah file.";
    }
    
    header("Location: trip_saya.php");
    exit();
}
?>

<?php
// Cek apakah session sudah dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validasi path file koneksi.php
if (!file_exists('../koneksi.php')) {
    die("File koneksi.php tidak ditemukan!");
}

require '../koneksi.php'; // Memuat koneksi database (mendefinisikan $conn)

// Pastikan $conn didefinisikan di koneksi.php
if (!isset($conn)) {
    die("Koneksi database gagal: \$conn tidak didefinisikan di koneksi.php");
}

$koneksi = $conn; // Definisikan $koneksi sebagai alias untuk $conn

// Periksa apakah user sudah login, jika tidak redirect ke login
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

// Ambil data user
$namaPengguna = $_SESSION['user']['nama_lengkap'] ?? 'Pengguna';
$inisial = !empty($namaPengguna) ? strtoupper(substr($namaPengguna, 0, 1)) : 'P';
$current_page = basename($_SERVER['PHP_SELF']);

// Proses filter transportasi
$jenis = isset($_GET['jenis']) ? $_GET['jenis'] : '';
$lokasi = isset($_GET['lokasi']) ? $_GET['lokasi'] : '';
$harga_min = isset($_GET['harga_min']) ? intval($_GET['harga_min']) : 0;
$harga_max = isset($_GET['harga_max']) ? intval($_GET['harga_max']) : 0;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'nama_asc';
$kategori = isset($_GET['kategori']) ? $_GET['kategori'] : '';

// Query untuk mendapatkan data transportasi dengan filter
$query = "SELECT * FROM transportasi WHERE status = 'approved'";
$params = [];
$types = ''; // Variable for parameter types

if (!empty($jenis)) {
    $query .= " AND jenis = ?";
    $params[] = $jenis;
    $types .= 's'; // Add 's' for string
}

if (!empty($lokasi)) {
    $query .= " AND lokasi LIKE ?";
    $params[] = "%$lokasi%";
    $types .= 's'; // Add 's' for string
}

if ($harga_min > 0) {
    $query .= " AND (harga >= ? OR (harga_diskon > 0 AND harga_diskon >= ?))";
    $params[] = $harga_min;
    $params[] = $harga_min;
    $types .= 'ii'; // Add 'i' for integer
}

if ($harga_max > 0) {
    $query .= " AND (harga <= ? OR (harga_diskon > 0 AND harga_diskon <= ?))";
    $params[] = $harga_max;
    $params[] = $harga_max;
    $types .= 'ii'; // Add 'i' for integer
}

// Filter kategori (promosi/rekomendasi)
if (!empty($kategori)) {
    if ($kategori == 'promosi') {
        $query .= " AND is_promosi = 1";
    } elseif ($kategori == 'rekomendasi') {
        $query .= " AND is_rekomendasi = 1";
    }
}

// Sorting
switch ($sort) {
    case 'nama_desc':
        $query .= " ORDER BY nama_layanan DESC";
        break;
    case 'harga_asc':
        $query .= " ORDER BY COALESCE(NULLIF(harga_diskon, 0), harga) ASC";
        break;
    case 'harga_desc':
        $query .= " ORDER BY COALESCE(NULLIF(harga_diskon, 0), harga) DESC";
        break;
    default:
        $query .= " ORDER BY nama_layanan ASC";
}

$stmt = $koneksi->prepare($query);

// Check if the statement was prepared successfully
if ($stmt === false) {
    die('MySQL prepare error: ' . $koneksi->error);
}

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$transportasi = $result->fetch_all(MYSQLI_ASSOC);

// Ambil daftar kota unik untuk rekomendasi lokasi
$kota_query = "SELECT DISTINCT lokasi FROM transportasi ORDER BY lokasi";
$kota_result = $koneksi->query($kota_query);
$daftar_kota = [];
while ($row = $kota_result->fetch_assoc()) {
    $daftar_kota[] = $row['lokasi'];
}

// Proses tambah ke trip
if (isset($_POST['add_to_trip'])) {
    // Pastikan user sudah login
    if (!isset($_SESSION['user']['id'])) {
        $error_message = "Anda harus login untuk menambahkan ke trip";
    } else {
        $item_id = $_POST['item_id'];
        $biaya_per_unit = $_POST['biaya'];
        $tanggal_mulai = $_POST['tanggal_kunjungan'];
        $jumlah_orang = $_POST['jumlah_orang'];
        $user_id = $_SESSION['user']['id'];
        $jenis_kendaraan = $_POST['jenis_kendaraan'];
        
        // Hitung total biaya berdasarkan durasi untuk kendaraan pribadi
        if ($jenis_kendaraan == 'pribadi') {
            $tanggal_akhir = $_POST['tanggal_akhir'];
            $waktu_kunjungan = '00:00:00'; // Tidak perlu waktu spesifik
            
            // Hitung jumlah hari
            $datetime1 = new DateTime($tanggal_mulai);
            $datetime2 = new DateTime($tanggal_akhir);
            $interval = $datetime1->diff($datetime2);
            $jumlah_hari = $interval->days + 1; // Termasuk hari pertama
            
            $total_biaya = $biaya_per_unit * $jumlah_hari * $jumlah_orang;
            
            // Query untuk kendaraan pribadi (dengan tanggal_akhir)
            $insert_query = "INSERT INTO trip_saya (user_id, item_type, item_id, tanggal_kunjungan, tanggal_akhir, waktu_kunjungan, biaya, jumlah_orang, jenis_kendaraan) 
                            VALUES (?, 'transportasi', ?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $koneksi->prepare($insert_query);
            if ($insert_stmt === false) {
                die('MySQL prepare error: ' . $koneksi->error);
            }
            $insert_stmt->bind_param('issssdis', $user_id, $item_id, $tanggal_mulai, $tanggal_akhir, $waktu_kunjungan, $total_biaya, $jumlah_orang, $jenis_kendaraan);
        } else {
            $waktu_kunjungan = $_POST['waktu_kunjungan'];
            $total_biaya = $biaya_per_unit * $jumlah_orang;
            
            // Query untuk transportasi umum (tanpa tanggal_akhir)
            $insert_query = "INSERT INTO trip_saya (user_id, item_type, item_id, tanggal_kunjungan, waktu_kunjungan, biaya, jumlah_orang, jenis_kendaraan) 
                            VALUES (?, 'transportasi', ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $koneksi->prepare($insert_query);
            if ($insert_stmt === false) {
                die('MySQL prepare error: ' . $koneksi->error);
            }
            $insert_stmt->bind_param('isssdis', $user_id, $item_id, $tanggal_mulai, $waktu_kunjungan, $total_biaya, $jumlah_orang, $jenis_kendaraan);
        }
        
        if ($insert_stmt->execute()) {
            $success_message = "Transportasi berhasil ditambahkan ke trip Anda!";
        } else {
            $error_message = "Gagal menambahkan transportasi ke trip: " . $insert_stmt->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transportasi - Rencana-IN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/leaflet.css" />
    <style>
        /* ROOT VARIABEL UNTUK KONSISTENSI WARNA DAN UKURAN */
        :root {
            --primary: #4CAF50; /* Hijau utama */
            --primary-dark: #3d8b40; /* Versi gelap hijau */
            --primary-light: rgba(76, 175, 80, 0.1); /* Light green for backgrounds */
            --secondary: #6c757d; /* Abu-abu sekunder */
            --light: #f8f9fa; /* Background terang */
            --dark: #343a40; /* Warna teks gelap */
            --white: #ffffff; /* Putih */
            --gray-light: #e9ecef; /* Light gray for borders */
            --sidebar-width: 250px;
            --header-height: 60px;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --promo-color: #ff6b6b; /* Warna untuk promosi */
            --recommend-color: #4ecdc4; /* Warna untuk rekomendasi */
            --pribadi-color: #5f6caf; /* Warna untuk kendaraan pribadi */
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
        }

        /* SIDEBAR STYLING */
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--white);
            position: fixed;
            top: 0;
            left: 0;
            box-shadow: var(--box-shadow);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        .sidebar-brand {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .sidebar-brand img {
            width: 36px;
            height: 36px;
            margin-right: 10px;
            object-fit: contain;
        }

        .sidebar-brand h2 {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            color: var(--primary);
            white-space: nowrap;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 0;
        }

        .nav-item {
            position: relative;
            margin: 0.25rem 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .nav-link:hover, .nav-link.active {
            color: var(--primary);
            background-color: var(--primary-light);
        }

        .nav-link.active {
            font-weight: 500;
            border-left: 3px solid var(--primary);
        }

        .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        /* HEADER DI BAGIAN ATAS */
        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        .header {
            height: var(--header-height);
            background-color: var(--white);
            box-shadow: 0 0.15rem 1.75rem 0 rgba(33, 40, 50, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark);
            cursor: pointer;
            margin-right: 1rem;
        }

        .search-bar {
            position: relative;
            width: 300px;
            flex-grow: 1;
            max-width: 400px;
            margin: 0 1rem;
        }

        .search-bar input {
            width: 100%;
            padding: 0.375rem 0.75rem 0.375rem 2.5rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
            background-color: var(--light);
            font-size: 0.875rem;
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
            font-size: 0.875rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            margin-left: auto;
        }

        .user-menu .dropdown-toggle {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .user-menu .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            margin-right: 0.5rem;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        /* OVERLAY UNTUK MOBILE SIDEBAR */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        .sidebar-overlay.show {
            display: block;
        }

        /* Transportasi Content */
        .transportasi-container {
            padding: 2rem;
        }

        .filter-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .filter-title {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            color: var(--dark);
            display: flex;
            align-items: center;
        }

        .filter-title i {
            margin-right: 0.8rem;
            color: var(--primary);
        }

        .filter-row {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.6rem;
            font-weight: 500;
            color: var(--dark);
        }

        .filter-group select, 
        .filter-group input {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            background-color: var(--white);
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .filter-group select:focus, 
        .filter-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-secondary {
            background-color: var(--white);
            color: var(--dark);
            border: 1px solid var(--gray-light);
        }

        .btn-secondary:hover {
            background-color: var(--gray-light);
        }

        .btn i {
            margin-right: 0.5rem;
        }

        /* Transportasi Grid */
        .transportasi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .transportasi-card {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            transition: all 0.3s;
        }

        .transportasi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }

        .transportasi-img-container {
            height: 200px;
            overflow: hidden;
            position: relative;
        }

        .transportasi-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }

        .transportasi-card:hover .transportasi-img {
            transform: scale(1.05);
        }

        .transportasi-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .transportasi-badge.pribadi {
            background-color: var(--pribadi-color);
        }

        .transportasi-badge.promo {
            background-color: var(--promo-color);
        }

        .transportasi-badge.rekomendasi {
            background-color: var(--recommend-color);
        }

        .transportasi-badge.mobil {
            background-color: #6c757d;
        }

        .transportasi-badge.motor {
            background-color: #6c757d;
        }

        .transportasi-badge.bus {
            background-color: #6c757d;
        }

        .transportasi-body {
            padding: 1.5rem;
        }

        .transportasi-title {
            font-size: 1.2rem;
            margin-bottom: 0.8rem;
            color: var(--dark);
            font-weight: 600;
        }

        .transportasi-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.2rem;
        }

        .transportasi-meta-item {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            color: var(--secondary);
        }

        .transportasi-meta-item i {
            margin-right: 0.5rem;
            color: var(--primary);
        }

        .transportasi-price {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 1.5rem;
        }

        .transportasi-actions {
            display: flex;
            gap: 0.8rem;
        }

        .transportasi-actions .btn {
            flex: 1;
            padding: 0.7rem;
            font-size: 0.9rem;
        }

        .no-results {
            text-align: center;
            padding: 3rem;
            color: var(--secondary);
            font-size: 1.1rem;
            grid-column: 1 / -1;
        }

        .no-results i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--gray-light);
        }

        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
        }

        .alert-success {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--primary-dark);
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .alert-error {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }

        /* New styles for location detection */
        .location-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .location-title {
            font-size: 1.2rem;
            margin-bottom: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
        }

        .location-title i {
            margin-right: 0.8rem;
            color: var(--primary);
        }

        .location-form {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .location-input {
            flex: 1;
            position: relative;
        }

        .location-input input {
            width: 100%;
            padding: 0.7rem 0.7rem 0.7rem 2.5rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
        }

        .location-input i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
        }

        .location-map {
            height: 200px;
            margin-top: 1rem;
            border-radius: var(--border-radius);
            overflow: hidden;
            display: none;
        }

        /* Category filter */
        .category-filter {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .category-btn {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            background-color: var(--gray-light);
            color: var(--dark);
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .category-btn.active {
            background-color: var(--primary);
            color: white;
        }

        .category-btn.promo {
            background-color: var(--promo-color);
            color: white;
        }

        .category-btn.rekomendasi {
            background-color: var(--recommend-color);
            color: white;
        }

        /* Autocomplete dropdown */
        .autocomplete-items {
            position: absolute;
            border: 1px solid var(--gray-light);
            border-bottom: none;
            border-top: none;
            z-index: 99;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            max-height: 200px;
            overflow-y: auto;
        }

        .autocomplete-items div {
            padding: 10px;
            cursor: pointer;
            background-color: #fff;
            border-bottom: 1px solid var(--gray-light);
        }

        .autocomplete-items div:hover {
            background-color: var(--primary-light);
        }

        /* Date time input improvements */
        .date-time-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-bottom: 1rem;
        }

        .date-time-item {
            flex: 1;
            min-width: 120px;
        }

        .date-time-item label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: var(--secondary);
        }

        .date-time-item input {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
        }

        /* Duration display */
        .duration-display {
            font-size: 0.9rem;
            color: var(--secondary);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
        }

        .duration-display i {
            margin-right: 0.5rem;
            color: var(--primary);
        }

        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .transportasi-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .transportasi-container {
                padding-top: calc(var(--header-height) + 1rem);
            }
        }

        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
                gap: 1rem;
            }

            .filter-group {
                min-width: 100%;
            }

            .transportasi-actions {
                flex-direction: column;
            }
            
            .search-bar {
                display: none;
            }

            .location-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .category-filter {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
  <!-- Overlay untuk sidebar saat tampil di mobile -->
    <div class="sidebar-overlay"></div>

    <!-- Sidebar navigasi utama -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="../asset/logo.png" alt="Rencana-IN Logo">
            <h2>Rencana-IN</h2>
        </div>
        <nav class="sidebar-nav">
            <ul style="list-style: none; padding: 0; margin: 0;">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="wisata.php" class="nav-link <?= $current_page == 'wisata.php' ? 'active' : '' ?>">
                        <i class="fas fa-umbrella-beach"></i>
                        <span>Wisata</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="transportasi.php" class="nav-link <?= $current_page == 'transportasi.php' ? 'active' : '' ?>">
                        <i class="fas fa-bus"></i>
                        <span>Transportasi</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="kuliner.php" class="nav-link <?= $current_page == 'kuliner.php' ? 'active' : '' ?>">
                        <i class="fas fa-utensils"></i>
                        <span>Kuliner</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="penginapan.php" class="nav-link <?= $current_page == 'penginapan.php' ? 'active' : '' ?>">
                        <i class="fas fa-hotel"></i>
                        <span>Penginapan</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="trip_saya.php" class="nav-link <?= $current_page == 'trip_saya.php' ? 'active' : '' ?>">
                        <i class="fas fa-map-marked-alt"></i>
                        <span>Trip Saya</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main">
        <header class="header">
            <button class="menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Cari transportasi...">
            </div>
            <div class="user-menu">
                <a href="#" class="dropdown-toggle">
                    <div class="avatar">
                        <?= htmlspecialchars($inisial) ?>
                    </div>
                    <span><?= htmlspecialchars($namaPengguna) ?></span>
                    <i class="fas fa-caret-down ml-2"></i>
                </a>
            </div>
        </header>

        <div class="transportasi-container">
            <!-- Notification Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $success_message ?>
                    <a href="trip_saya.php" class="btn btn-primary" style="margin-left: 1rem; padding: 0.3rem 0.8rem; font-size: 0.8rem;">
                        Lihat Trip
                    </a>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
                </div>
            <?php endif; ?>

            <!-- Location Detection Section -->
            <div class="location-section">
                <h3 class="location-title"><i class="fas fa-map-marker-alt"></i> Lokasi Saat Ini</h3>
                <div class="location-form">
                    <div class="location-input">
                        <i class="fas fa-search-location"></i>
                        <input type="text" id="lokasi-sekarang" placeholder="Masukkan lokasi Anda atau gunakan deteksi otomatis" value="<?= htmlspecialchars($lokasi) ?>">
                        <div id="autocomplete-container" class="autocomplete-items"></div>
                    </div>
                    <button type="button" id="deteksi-lokasi" class="btn btn-primary">
                        <i class="fas fa-location-arrow"></i> Deteksi Otomatis
                    </button>
                </div>
                <div id="peta-lokasi" class="location-map"></div>
            </div>

            <!-- Category Filter -->
            <div class="filter-section">
                <div class="category-filter">
                    <a href="transportasi.php" class="category-btn <?= empty($kategori) ? 'active' : '' ?>">
                        <i class="fas fa-list"></i> Semua
                    </a>
                    <a href="transportasi.php?kategori=promosi" class="category-btn promo <?= $kategori == 'promosi' ? 'active' : '' ?>">
                        <i class="fas fa-tag"></i> Promosi
                    </a>
                    <a href="transportasi.php?kategori=rekomendasi" class="category-btn rekomendasi <?= $kategori == 'rekomendasi' ? 'active' : '' ?>">
                        <i class="fas fa-star"></i> Rekomendasi
                    </a>
                </div>
                
                <h3 class="filter-title"><i class="fas fa-sliders-h"></i> Filter Transportasi</h3>
                <form method="GET" action="transportasi.php">
                    <input type="hidden" name="kategori" value="<?= $kategori ?>">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="jenis"><i class="fas fa-car"></i> Jenis Transportasi</label>
                            <select id="jenis" name="jenis">
                                <option value="">Semua Jenis</option>
                                <option value="mobil" <?= $jenis == 'mobil' ? 'selected' : '' ?>>Mobil</option>
                                <option value="motor" <?= $jenis == 'motor' ? 'selected' : '' ?>>Motor</option>
                                <option value="bus" <?= $jenis == 'bus' ? 'selected' : '' ?>>Bus</option>
                                <option value="pribadi" <?= $jenis == 'pribadi' ? 'selected' : '' ?>>Kendaraan Pribadi</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="lokasi"><i class="fas fa-map-marker-alt"></i> Lokasi Layanan</label>
                            <input type="text" id="lokasi" name="lokasi" placeholder="Kota atau daerah" value="<?= htmlspecialchars($lokasi) ?>">
                        </div>
                    </div>
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="harga_min"><i class="fas fa-money-bill-wave"></i> Harga Minimum (Rp)</label>
                            <input type="number" id="harga_min" name="harga_min" placeholder="Minimum" value="<?= $harga_min > 0 ? $harga_min : '' ?>">
                        </div>
                        <div class="filter-group">
                            <label for="harga_max"><i class="fas fa-money-bill-wave"></i> Harga Maksimum (Rp)</label>
                            <input type="number" id="harga_max" name="harga_max" placeholder="Maksimum" value="<?= $harga_max > 0 ? $harga_max : '' ?>">
                        </div>
                        <div class="filter-group">
                            <label for="sort"><i class="fas fa-sort"></i> Urutkan Berdasarkan</label>
                            <select id="sort" name="sort">
                                <option value="nama_asc" <?= $sort == 'nama_asc' ? 'selected' : '' ?>>Nama (A-Z)</option>
                                <option value="nama_desc" <?= $sort == 'nama_desc' ? 'selected' : '' ?>>Nama (Z-A)</option>
                                <option value="harga_asc" <?= $sort == 'harga_asc' ? 'selected' : '' ?>>Harga Terendah</option>
                                <option value="harga_desc" <?= $sort == 'harga_desc' ? 'selected' : '' ?>>Harga Tertinggi</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Terapkan Filter
                        </button>
                        <a href="transportasi.php" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset Filter
                        </a>
                    </div>
                </form>
            </div>

            <!-- Transportasi List -->
            <div class="transportasi-grid">
                <?php if (count($transportasi) > 0): ?>
                    <?php foreach ($transportasi as $item): ?>
                        <div class="transportasi-card">
                            <div class="transportasi-img-container">
                                <img src="<?= htmlspecialchars($item['gambar']) ?>" alt="<?= htmlspecialchars($item['nama_layanan']) ?>" class="transportasi-img">
                                <?php if ($item['is_promosi'] == 1): ?>
                                    <span class="transportasi-badge promo">Promosi</span>
                                <?php elseif ($item['is_rekomendasi'] == 1): ?>
                                    <span class="transportasi-badge rekomendasi">Rekomendasi</span>
                                <?php else: ?>
                                    <span class="transportasi-badge <?= $item['jenis'] ?>"><?= htmlspecialchars(ucfirst($item['jenis'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="transportasi-body">
                                <h3 class="transportasi-title"><?= htmlspecialchars($item['nama_layanan']) ?></h3>
                                
                                <?php if (!empty($item['deskripsi'])): ?>
                                    <p class="transportasi-desc"><?= htmlspecialchars($item['deskripsi']) ?></p>
                                <?php endif; ?>
                                
                                <div class="transportasi-meta">
                                    <div class="transportasi-meta-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?= htmlspecialchars($item['lokasi']) ?>
                                    </div>
                                    <div class="transportasi-meta-item">
                                        <i class="fas fa-users"></i>
                                        Kapasitas: <?= htmlspecialchars($item['kapasitas']) ?>
                                    </div>
                                </div>
                                
                                <?php if ($item['jenis'] == 'pribadi'): ?>
                                <div class="transportasi-meta">
                                    <div class="transportasi-meta-item">
                                        <i class="fas fa-car"></i>
                                        Tipe: <?= htmlspecialchars($item['tipe_kendaraan'] ?? 'Standard') ?>
                                    </div>
                                    <div class="transportasi-meta-item">
                                        <i class="fas fa-gas-pump"></i>
                                        Bahan Bakar: <?= htmlspecialchars($item['bahan_bakar'] ?? 'Bensin') ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="transportasi-meta">
                                    <div class="transportasi-meta-item">
                                        <i class="fas fa-route"></i>
                                        <?= htmlspecialchars($item['rute']) ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="transportasi-price">
                                    <?php if ($item['harga_diskon'] > 0): ?>
                                        <span style="text-decoration: line-through; color: var(--secondary); font-size: 0.9em; margin-right: 0.5rem;">
                                            Rp <?= number_format($item['harga'], 0, ',', '.') ?>
                                        </span>
                                        Rp <?= number_format($item['harga_diskon'], 0, ',', '.') ?>
                                    <?php else: ?>
                                        Rp <?= number_format($item['harga'], 0, ',', '.') ?>
                                    <?php endif; ?>
                                    <small style="display: block; font-size: 0.8em; color: var(--secondary);">
                                        / hari
                                    </small>
                                </div>
                                
                                <?php if (isset($_SESSION['user']['id'])): ?>
                                    <form method="POST" action="transportasi.php?<?= http_build_query($_GET) ?>">
    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
    <input type="hidden" name="jenis_kendaraan" value="<?= $item['jenis'] ?>">
    <input type="hidden" name="biaya" value="<?= ($item['harga_diskon'] > 0) ? $item['harga_diskon'] : $item['harga'] ?>">
    
    <div class="date-time-group">
        <div class="date-time-item">
            <label for="tanggal_<?= $item['id'] ?>">
                <?= $item['jenis'] == 'pribadi' ? 'Tanggal Mulai' : 'Tanggal' ?>
            </label>
            <input type="date" id="tanggal_<?= $item['id'] ?>" name="tanggal_kunjungan" value="<?= date('Y-m-d') ?>" required>
        </div>
        
        <?php if ($item['jenis'] == 'pribadi'): ?>
            <div class="date-time-item">
                <label for="tanggal_akhir_<?= $item['id'] ?>">Tanggal Akhir</label>
                <input type="date" id="tanggal_akhir_<?= $item['id'] ?>" name="tanggal_akhir" 
                       value="<?= date('Y-m-d', strtotime('+1 day')) ?>" 
                       min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
            </div>
        <?php else: ?>
            <input type="hidden" name="tanggal_akhir" value="NULL">
            <div class="date-time-item">
                <label for="waktu_<?= $item['id'] ?>">Waktu</label>
                <input type="time" id="waktu_<?= $item['id'] ?>" name="waktu_kunjungan" value="08:00" required>
            </div>
        <?php endif; ?>
        
        <div class="date-time-item">
            <label for="jumlah_<?= $item['id'] ?>">
                <?= $item['jenis'] == 'pribadi' ? 'Jumlah Unit' : 'Jumlah Orang' ?>
            </label>
            <input type="number" id="jumlah_<?= $item['id'] ?>" name="jumlah_orang" min="1" max="<?= $item['kapasitas'] ?>" value="1" required>
        </div>
    </div>
    
    <?php if ($item['jenis'] == 'pribadi'): ?>
    <div class="duration-display">
        <i class="fas fa-calendar-alt"></i>
        <span id="duration-text_<?= $item['id'] ?>">Durasi: 1 hari</span>
    </div>
    <?php endif; ?>
    
    <div class="transportasi-actions">
        <button type="submit" name="add_to_trip" class="btn btn-primary">
            <i class="fas fa-plus"></i> Tambah ke Trip
        </button>
        <a href="detail_transportasi.php?id=<?= $item['id'] ?>" class="btn btn-secondary">
            <i class="fas fa-info-circle"></i> Detail
        </a>
    </div>
</form>
                                <?php else: ?>
                                    <div class="alert alert-error" style="margin-top: 1rem; padding: 0.8rem; text-align: center;">
                                        <i class="fas fa-exclamation-triangle"></i> Anda harus login untuk menambahkan ke trip
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-results">
                        <i class="fas fa-car-alt"></i>
                        <h3>Tidak ada transportasi yang ditemukan</h3>
                        <p>Coba gunakan filter yang berbeda atau cari dengan kata kunci lain</p>
                        <a href="transportasi.php" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-undo"></i> Reset Filter
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/leaflet.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.querySelector('.sidebar');
            const sidebarOverlay = document.querySelector('.sidebar-overlay');
            const menuToggle = document.querySelector('.menu-toggle');

            // Tampilkan dan sembunyikan sidebar saat tombol ditekan
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
                sidebarOverlay.classList.toggle('show');
            });

            // Klik overlay untuk menutup sidebar
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');
            });

            // Tangani resize jendela untuk menutup sidebar di layar besar
            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                }
            });

            // Autocomplete untuk lokasi
            const lokasiInput = document.getElementById('lokasi-sekarang');
            const autocompleteContainer = document.getElementById('autocomplete-container');
            const lokasiFilterInput = document.getElementById('lokasi');
            const daftarKota = <?= json_encode($daftar_kota) ?>;
            
            let peta;
            let marker;

            // Fungsi untuk menampilkan autocomplete
            function showAutocomplete(str) {
                autocompleteContainer.innerHTML = '';
                
                if (str.length === 0) {
                    autocompleteContainer.style.display = 'none';
                    return;
                }
                
                const filtered = daftarKota.filter(kota => 
                    kota.toLowerCase().includes(str.toLowerCase())
                );
                
                if (filtered.length > 0) {
                    filtered.forEach(kota => {
                        const item = document.createElement('div');
                        item.innerHTML = `<strong>${kota.substr(0, str.length)}</strong>${kota.substr(str.length)}`;
                        item.addEventListener('click', function() {
                            lokasiInput.value = kota;
                            lokasiFilterInput.value = kota;
                            autocompleteContainer.style.display = 'none';
                        });
                        autocompleteContainer.appendChild(item);
                    });
                    autocompleteContainer.style.display = 'block';
                } else {
                    autocompleteContainer.style.display = 'none';
                }
            }
            
            // Event listener untuk input lokasi
            lokasiInput.addEventListener('input', function() {
                showAutocomplete(this.value);
            });
            
            // Sinkronkan input lokasi dengan filter
            lokasiInput.addEventListener('change', function() {
                lokasiFilterInput.value = this.value;
            });
            
            // Tutup autocomplete saat klik di luar
            document.addEventListener('click', function(e) {
                if (e.target !== lokasiInput) {
                    autocompleteContainer.style.display = 'none';
                }
            });
            
            // Deteksi lokasi otomatis
            document.getElementById('deteksi-lokasi').addEventListener('click', function() {
                const petaLokasi = document.getElementById('peta-lokasi');
                
                if (navigator.geolocation) {
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mendeteksi...';
                    this.disabled = true;
                    
                    navigator.geolocation.getCurrentPosition(
                        function(position) {
                            const lat = position.coords.latitude;
                            const lng = position.coords.longitude;
                            
                            // Inisialisasi peta jika belum ada
                            if (!peta) {
                                petaLokasi.style.display = 'block';
                                peta = L.map('peta-lokasi').setView([lat, lng], 13);
                                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                                }).addTo(peta);
                            } else {
                                peta.setView([lat, lng], 13);
                            }
                            
                            // Hapus marker lama jika ada
                            if (marker) {
                                peta.removeLayer(marker);
                            }
                            
                            // Tambahkan marker baru
                            marker = L.marker([lat, lng]).addTo(peta)
                                .bindPopup('Lokasi Anda saat ini').openPopup();
                            
                            // Reverse geocoding untuk mendapatkan nama lokasi
                            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                                .then(response => response.json())
                                .then(data => {
                                    let lokasiName = data.address.city || data.address.town || data.address.village || '';
                                    if (data.address.state) {
                                        lokasiName += lokasiName ? ', ' + data.address.state : data.address.state;
                                    }
                                    
                                    if (lokasiName) {
                                        lokasiInput.value = lokasiName;
                                        lokasiFilterInput.value = lokasiName;
                                    }
                                    
                                    document.getElementById('deteksi-lokasi').innerHTML = '<i class="fas fa-location-arrow"></i> Deteksi Otomatis';
                                    document.getElementById('deteksi-lokasi').disabled = false;
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    document.getElementById('deteksi-lokasi').innerHTML = '<i class="fas fa-location-arrow"></i> Deteksi Otomatis';
                                    document.getElementById('deteksi-lokasi').disabled = false;
                                });
                        },
                        function(error) {
                            console.error('Error getting location:', error);
                            alert('Gagal mendapatkan lokasi. Pastikan Anda mengizinkan akses lokasi.');
                            document.getElementById('deteksi-lokasi').innerHTML = '<i class="fas fa-location-arrow"></i> Deteksi Otomatis';
                            document.getElementById('deteksi-lokasi').disabled = false;
                        }
                    );
                } else {
                    alert('Browser Anda tidak mendukung geolocation.');
                }
            });
            
            // Sinkronkan input tanggal dengan hari ini jika kosong
            const today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(input => {
                if (!input.value) {
                    input.value = today;
                }
            });
            
            // Validasi jumlah orang tidak melebihi kapasitas
            document.querySelectorAll('input[name="jumlah_orang"]').forEach(input => {
                input.addEventListener('change', function() {
                    const maxCapacity = parseInt(this.max);
                    if (this.value > maxCapacity) {
                        alert(`Maaf, kapasitas maksimal adalah ${maxCapacity} unit`);
                        this.value = maxCapacity;
                    }
                });
            });
            
            // Hitung durasi dan total harga untuk kendaraan pribadi
            document.querySelectorAll('form').forEach(form => {
                const jenisKendaraan = form.querySelector('input[name="jenis_kendaraan"]').value;
                
                if (jenisKendaraan === 'pribadi') {
                    const itemId = form.querySelector('input[name="item_id"]').value;
                    const hargaPerHari = parseFloat(form.querySelector('input[name="biaya"]').value);
                    const tanggalMulai = form.querySelector('input[name="tanggal_kunjungan"]');
                    const tanggalAkhir = form.querySelector('input[name="tanggal_akhir"]');
                    const jumlahUnit = form.querySelector('input[name="jumlah_orang"]');
                    const durationText = document.getElementById(`duration-text_${itemId}`);
                    
                    // Fungsi untuk update durasi
                    function updateDuration() {
                        const startDate = new Date(tanggalMulai.value);
                        const endDate = new Date(tanggalAkhir.value);
                        const diffTime = Math.abs(endDate - startDate);
                        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                        const unit = parseInt(jumlahUnit.value);
                        
                        durationText.textContent = `Durasi: ${diffDays} hari (Total: Rp ${(hargaPerHari * diffDays * unit).toLocaleString('id-ID')})`;
                    }
                    
                    // Event listeners
                    tanggalMulai.addEventListener('change', function() {
                        // Set minimal tanggal akhir adalah tanggal mulai + 1 hari
                        const minDate = new Date(this.value);
                        minDate.setDate(minDate.getDate() + 1);
                        tanggalAkhir.min = minDate.toISOString().split('T')[0];
                        
                        // Jika tanggal akhir sekarang lebih kecil dari minimal, update
                        if (new Date(tanggalAkhir.value) < minDate) {
                            tanggalAkhir.value = minDate.toISOString().split('T')[0];
                        }
                        
                        updateDuration();
                    });
                    
                    tanggalAkhir.addEventListener('change', updateDuration);
                    jumlahUnit.addEventListener('change', updateDuration);
                    
                    // Inisialisasi pertama
                    updateDuration();
                }
            });
        });
    </script>
</body>
</html>

<?php
// Cek apakah session sudah dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validasi path file koneksi.php
if (!file_exists('../koneksi.php')) {
    die("File koneksi.php tidak ditemukan!");
}

require '../koneksi.php'; // Memuat koneksi database (mendefinisikan $conn)

// Pastikan $conn didefinisikan di koneksi.php
if (!isset($conn)) {
    die("Koneksi database gagal: \$conn tidak didefinisikan di koneksi.php");
}

$koneksi = $conn; // Definisikan $koneksi sebagai alias untuk $conn

require '../vendor/autoload.php';  // Memuat PHPMailer melalui autoload

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Initialize variables
$success_message = '';
$error_message = '';
$total_biaya = 0;
$breakdown_biaya = [
    'wisata' => 0,
    'kuliner' => 0,
    'transportasi' => 0,
    'penginapan' => 0
];

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

// Ambil data pengguna
$namaPengguna = isset($_SESSION['user']['nama_lengkap']) ? $_SESSION['user']['nama_lengkap'] : 'Pengguna';
$inisial = strtoupper(substr($namaPengguna, 0, 1));
$current_page = basename($_SERVER['PHP_SELF']);
$user_id = $_SESSION['user']['id'];

// Proses tambah item ke trip
if (isset($_POST['tambah_item'])) {
    $item_type = $_POST['item_type'];
    $item_id = intval($_POST['item_id']);
    $nama_item = $koneksi->real_escape_string($_POST['nama_item']);
    $tanggal_kunjungan = $_POST['tanggal_kunjungan'];
    $waktu_kunjungan = $_POST['waktu_kunjungan'];
    $min_budget = floatval($_POST['min_budget']);
    $max_budget = floatval($_POST['max_budget']);
    $keterangan = $koneksi->real_escape_string($_POST['keterangan'] ?? '');

    // Validasi input
    if (empty($tanggal_kunjungan) || empty($waktu_kunjungan)) {
        $_SESSION['error_message'] = "Tanggal dan waktu kunjungan wajib diisi!";
        header("Location: trip_saya.php");
        exit();
    }

    // Cek apakah item sudah ada di trip
    $query_cek = "SELECT id FROM trip_saya WHERE user_id = ? AND item_type = ? AND item_id = ?";
    $stmt_cek = $koneksi->prepare($query_cek);
    $stmt_cek->bind_param('isi', $user_id, $item_type, $item_id);
    $stmt_cek->execute();
    $result_cek = $stmt_cek->get_result();

    if ($result_cek->num_rows > 0) {
        $_SESSION['error_message'] = "Item ini sudah ada dalam trip Anda!";
        header("Location: trip_saya.php");
        exit();
    }

    // Tambahkan item ke trip
    $query_tambah = "INSERT INTO trip_saya (user_id, item_type, item_id, nama_item, tanggal_kunjungan, waktu_kunjungan, min_budget, max_budget, keterangan) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt_tambah = $koneksi->prepare($query_tambah);
    $stmt_tambah->bind_param('isisssdds', $user_id, $item_type, $item_id, $nama_item, $tanggal_kunjungan, $waktu_kunjungan, $min_budget, $max_budget, $keterangan);

    if ($stmt_tambah->execute()) {
        $_SESSION['success_message'] = "Item berhasil ditambahkan ke trip Anda!";
    } else {
        $_SESSION['error_message'] = "Gagal menambahkan item: " . $stmt_tambah->error;
    }

    header("Location: trip_saya.php");
    exit();
}

// Proses edit item trip
if (isset($_POST['edit_item'])) {
    $id_edit = intval($_POST['id_edit']);
    $tanggal_kunjungan = $_POST['tanggal_kunjungan'];
    $waktu_kunjungan = $_POST['waktu_kunjungan'];
    $min_budget = floatval($_POST['min_budget']);
    $max_budget = floatval($_POST['max_budget']);
    $keterangan = $koneksi->real_escape_string($_POST['keterangan'] ?? '');

    // Validasi input
    if (empty($tanggal_kunjungan) || empty($waktu_kunjungan)) {
        $_SESSION['error_message'] = "Tanggal dan waktu kunjungan wajib diisi!";
        header("Location: trip_saya.php");
        exit();
    }

    // Update item trip
    $query_edit = "UPDATE trip_saya SET 
                    tanggal_kunjungan = ?, 
                    waktu_kunjungan = ?, 
                    min_budget = ?, 
                    max_budget = ?, 
                    keterangan = ? 
                  WHERE id = ? AND user_id = ?";
    $stmt_edit = $koneksi->prepare($query_edit);
    $stmt_edit->bind_param('ssddsii', $tanggal_kunjungan, $waktu_kunjungan, $min_budget, $max_budget, $keterangan, $id_edit, $user_id);

    if ($stmt_edit->execute()) {
        $_SESSION['success_message'] = "Item trip berhasil diperbarui!";
    } else {
        $_SESSION['error_message'] = "Gagal memperbarui item: " . $stmt_edit->error;
    }

    header("Location: trip_saya.php");
    exit();
}

// Proses hapus item dari trip
if (isset($_GET['hapus'])) {
    $id_hapus = intval($_GET['hapus']);
    // Query untuk hapus item
    $query_hapus = "DELETE FROM trip_saya WHERE id = ? AND user_id = ?";
    $stmt_hapus = $koneksi->prepare($query_hapus);
    
    if ($stmt_hapus === false) {
        die('Query prepare failed: ' . $koneksi->error);
    }
    
    $stmt_hapus->bind_param('ii', $id_hapus, $user_id);
    
    if ($stmt_hapus->execute()) {
        $_SESSION['success_message'] = "Item berhasil dihapus dari trip Anda!";
        header("Location: trip_saya.php");
        exit();
    } else {
        $_SESSION['error_message'] = "Gagal menghapus item dari trip: " . $stmt_hapus->error;
    }
}

// Proses pembayaran
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bayar'])) {
    $total_biaya = floatval($_POST['total_biaya']);
    $metode = $_POST['metode'];
    
    // Handle file upload
    $target_dir = "../uploads/pembayaran/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_name = time() . '_' . basename($_FILES["bukti_pembayaran"]["name"]);
    $target_file = $target_dir . $file_name;
    $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
    
    // Check if image file is a actual image or fake image
    $check = getimagesize($_FILES["bukti_pembayaran"]["tmp_name"]);
    if ($check === false) {
        $_SESSION['error_message'] = "File yang diunggah bukan gambar.";
        header("Location: trip_saya.php");
        exit();
    }
    
    // Check file size (max 2MB)
    if ($_FILES["bukti_pembayaran"]["size"] > 2000000) {
        $_SESSION['error_message'] = "Ukuran file terlalu besar (maks. 2MB).";
        header("Location: trip_saya.php");
        exit();
    }
    
    // Allow certain file formats
    if (!in_array($imageFileType, ['jpg', 'jpeg', 'png'])) {
        $_SESSION['error_message'] = "Hanya format JPG, JPEG, PNG yang diperbolehkan.";
        header("Location: trip_saya.php");
        exit();
    }
    
    // Try to upload file
    if (move_uploaded_file($_FILES["bukti_pembayaran"]["tmp_name"], $target_file)) {
        // Insert payment data to database
        $query = "INSERT INTO pembayaran (user_id, jumlah, metode, bukti_pembayaran, status) 
                  VALUES (?, ?, ?, ?, 'pending')";
        $stmt = $koneksi->prepare($query);
        $stmt->bind_param('idss', $user_id, $total_biaya, $metode, $file_name);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Bukti pembayaran berhasil diunggah. Menunggu verifikasi admin.";
        } else {
            $_SESSION['error_message'] = "Gagal menyimpan data pembayaran: " . $stmt->error;
            unlink($target_file); // Delete uploaded file if database insert fails
        }
    } else {
        $_SESSION['error_message'] = "Maaf, terjadi kesalahan saat mengunggah file.";
    }
    
    header("Location: trip_saya.php");
    exit();
}

// Ambil data trip saya dan harga dari database
$query_trip = "
    SELECT ts.*, 
        CASE 
            WHEN ts.item_type = 'wisata' THEN w.nama_wisata
            WHEN ts.item_type = 'kuliner' THEN k.nama_tempat
            WHEN ts.item_type = 'transportasi' THEN t.nama_layanan
            WHEN ts.item_type = 'penginapan' THEN p.nama_penginapan
        END AS nama_item,
        CASE
            WHEN ts.item_type = 'wisata' THEN w.harga_tiket
            WHEN ts.item_type = 'kuliner' THEN k.harga
            WHEN ts.item_type = 'transportasi' THEN t.harga
            WHEN ts.item_type = 'penginapan' THEN p.harga_per_malam
        END AS harga_satuan
    FROM trip_saya ts
    LEFT JOIN wisata w ON ts.item_type = 'wisata' AND ts.item_id = w.id
    LEFT JOIN kuliner k ON ts.item_type = 'kuliner' AND ts.item_id = k.id
    LEFT JOIN transportasi t ON ts.item_type = 'transportasi' AND ts.item_id = t.id
    LEFT JOIN penginapan p ON ts.item_type = 'penginapan' AND ts.item_id = p.id
    WHERE ts.user_id = ?
    ORDER BY ts.tanggal_kunjungan, ts.waktu_kunjungan";

$stmt_trip = $koneksi->prepare($query_trip);
$stmt_trip->bind_param('i', $user_id);
$stmt_trip->execute();
$result_trip = $stmt_trip->get_result();
$items_trip = $result_trip->fetch_all(MYSQLI_ASSOC);

// Validasi wajib wisata dan transportasi
$hasWisata = false;
$hasTransportasi = false;

foreach ($items_trip as $item) {
    if ($item['item_type'] == 'wisata') {
        $hasWisata = true;
    }
    if ($item['item_type'] == 'transportasi') {
        $hasTransportasi = true;
    }
}

// Calculate total cost and breakdown
foreach ($items_trip as $item) {
    $total_biaya += $item['harga_satuan'];
    $breakdown_biaya[$item['item_type']] += $item['harga_satuan'];
}

// Ambil data preferensi
$query_preferensi = "SELECT * FROM trip_preferensi WHERE user_id = ?";
$stmt_preferensi = $koneksi->prepare($query_preferensi);
$stmt_preferensi->bind_param('i', $user_id);
$stmt_preferensi->execute();
$result_preferensi = $stmt_preferensi->get_result();
$preferensi = $result_preferensi->fetch_assoc();

// Proses Simpan Itinerary
if (isset($_POST['simpan_itinerary'])) {
    if (!$hasWisata) {
        $_SESSION['error_message'] = "Anda wajib memilih setidaknya satu destinasi wisata.";
        header("Location: trip_saya.php");
        exit();
    }
    
    if (!$hasTransportasi) {
        $_SESSION['error_message'] = "Anda wajib memilih setidaknya satu layanan transportasi.";
        header("Location: trip_saya.php");
        exit();
    }
    
    // Proses penyimpanan itinerary (contoh: hanya menampilkan pesan sukses)
    $_SESSION['success_message'] = "Itinerary berhasil disimpan!";
    header("Location: trip_saya.php");
    exit();
}

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Cek apakah sudah ada pembayaran untuk trip ini
$query_pembayaran = "SELECT * FROM pembayaran WHERE user_id = ? AND trip_id IS NULL ORDER BY created_at DESC LIMIT 1";
$stmt_pembayaran = $koneksi->prepare($query_pembayaran);
$stmt_pembayaran->bind_param('i', $user_id);
$stmt_pembayaran->execute();
$result_pembayaran = $stmt_pembayaran->get_result();
$pembayaran = $result_pembayaran->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Saya - Rencana-IN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.css">
    <style>
        :root {
            --primary: #4CAF50;
            --primary-dark: #3d8b40;
            --primary-light: rgba(76, 175, 80, 0.1);
            --secondary: #6c757d;
            --light: #f8f9fa;
            --dark: #343a40;
            --white: #ffffff;
            --gray-light: #e9ecef;
            --sidebar-width: 250px;
            --header-height: 60px;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
        }

        /* Sidebar Styles */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--white);
            position: fixed;
            top: 0;
            left: 0;
            box-shadow: var(--box-shadow);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
        }

        .sidebar-brand {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .sidebar-brand img {
            width: 36px;
            height: 36px;
            margin-right: 10px;
            object-fit: contain;
        }

        .sidebar-brand h2 {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            color: var(--primary);
            white-space: nowrap;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 0;
        }

        .nav-item {
            position: relative;
            margin: 0.25rem 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            transition: var(--transition);
            white-space: nowrap;
        }

        .nav-link:hover, .nav-link.active {
            color: var(--primary);
            background-color: var(--primary-light);
        }

        .nav-link.active {
            font-weight: 500;
            border-left: 3px solid var(--primary);
        }

        .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        /* Header Styles */
        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: var(--transition);
        }

        .header {
            height: var(--header-height);
            background-color: var(--white);
            box-shadow: 0 0.15rem 1.75rem 0 rgba(33, 40, 50, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark);
            cursor: pointer;
            margin-right: 1rem;
        }

        .search-bar {
            position: relative;
            width: 300px;
            flex-grow: 1;
            max-width: 400px;
            margin: 0 1rem;
        }

        .search-bar input {
            width: 100%;
            padding: 0.375rem 0.75rem 0.375rem 2.5rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
            background-color: var(--light);
            font-size: 0.875rem;
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
            font-size: 0.875rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            margin-left: auto;
        }

        .user-menu .dropdown-toggle {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .user-menu .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            margin-right: 0.5rem;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        /* Main Content Styles */
        .trip-container {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Alert Styles */
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
        }

        .alert-success {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--primary-dark);
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .alert-error {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }

        .alert-warning {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }

        /* Preferensi Section */
        .preferensi-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            color: var(--dark);
            display: flex;
            align-items: center;
        }

        .section-title i {
            margin-right: 0.8rem;
            color: var(--primary);
        }

        .form-row {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.6rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-group input, 
        .form-group select, 
        .form-group textarea {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            background-color: var(--white);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-group input:focus, 
        .form-group select:focus, 
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
        }

        .checkbox-item input {
            margin-right: 0.5rem;
        }

        /* Trip Items Section */
        .trip-items-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .trip-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--gray-light);
            transition: var(--transition);
        }

        .trip-item:hover {
            background-color: var(--primary-light);
        }

        .trip-item-info {
            flex: 1;
        }

        .trip-item-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .trip-item-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.9rem;
            color: var(--secondary);
        }

        .trip-item-meta i {
            margin-right: 0.3rem;
            color: var(--primary);
        }

        .trip-item-price {
            font-weight: 700;
            color: var(--primary);
            margin: 0 1rem;
            min-width: 120px;
            text-align: right;
        }

        .trip-item-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Button Styles */
        .btn {
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-sm {
            padding: 0.3rem 0.7rem;
            font-size: 0.8rem;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background-color: var(--white);
            color: var(--dark);
            border: 1px solid var(--gray-light);
        }

        .btn-secondary:hover {
            background-color: var(--gray-light);
            transform: translateY(-2px);
        }

        .btn-danger {
            background-color: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
            transform: translateY(-2px);
        }

        .btn i {
            margin-right: 0.5rem;
        }

        /* Itinerary Section */
        .itinerary-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .itinerary-day {
            margin-bottom: 2rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .itinerary-day-header {
            background-color: var(--primary);
            color: white;
            padding: 0.8rem 1.2rem;
            font-weight: 600;
        }

        .itinerary-day-content {
            padding: 1rem;
        }

        .itinerary-item {
            display: flex;
            align-items: center;
            padding: 0.8rem 0;
            border-bottom: 1px dashed var(--gray-light);
        }

        .itinerary-item:last-child {
            border-bottom: none;
        }

        .itinerary-time {
            font-weight: 600;
            min-width: 80px;
            margin-right: 1rem;
        }

        .itinerary-detail {
            flex: 1;
        }

        .itinerary-item-title {
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .itinerary-item-meta {
            font-size: 0.9rem;
            color: var(--secondary);
        }

        .itinerary-item-type {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-right: 0.5rem;
        }

        .type-wisata {
            background-color: #e3f2fd;
            color: #1976d2;
        }

        .type-kuliner {
            background-color: #fff8e1;
            color: #ff8f00;
        }

        .type-transportasi {
            background-color: #e8f5e9;
            color: #388e3c;
        }

        .type-penginapan {
            background-color: #f3e5f5;
            color: #8e24aa;
        }

        /* Biaya Section */
        .biaya-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .biaya-total {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .biaya-breakdown {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .biaya-category {
            background-color: var(--primary-light);
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
        }

        .biaya-category-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--primary-dark);
        }

        .biaya-category-amount {
            font-weight: 700;
        }

        .biaya-per-orang {
            text-align: center;
            margin-top: 1.5rem;
        }

        .orang-input {
            width: 60px;
            text-align: center;
            padding: 0.3rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            margin: 0 0.5rem;
        }

        /* Simpan Section */
        .simpan-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .export-options {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            justify-content: center;
        }

        /* Edit Urutan Form */
        .edit-urutan-btn {
            margin-bottom: 1rem;
        }

        .form-edit-urutan {
            display: none;
            margin-top: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: var(--border-radius);
        }

        .form-edit-urutan.show {
            display: block;
        }

        .urutan-item {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            background: white;
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
        }

        .urutan-item-handle {
            cursor: move;
            margin-right: 1rem;
            color: var(--secondary);
        }

        .urutan-item-details {
            flex: 1;
        }

        .urutan-item-controls {
            display: flex;
            gap: 0.5rem;
        }

        .time-input {
            width: 80px;
            padding: 0.3rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
        }

        .date-input {
            width: 120px;
            padding: 0.3rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
        }

        .budget-input {
            width: 100px;
            padding: 0.3rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            margin-left: 0.5rem;
        }

        /* Pembayaran Section */
        .pembayaran-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .radio-group {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .radio-group label {
            display: flex;
            align-items: center;
            cursor: pointer;
        }

        .radio-group input[type="radio"] {
            margin-right: 0.5rem;
        }

        #formPembayaran input[type="file"] {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            background-color: var(--white);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
        }

        .modal-content {
            background-color: var(--white);
            margin: 5% auto;
            padding: 20px;
            border-radius: var(--border-radius);
            width: 80%;
            max-width: 600px;
            position: relative;
        }

        .close-modal {
            position: absolute;
            right: 20px;
            top: 10px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .modal-image {
            width: 100%;
            max-height: 500px;
            object-fit: contain;
            margin-top: 1rem;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
        }

        /* Responsive Styles */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .trip-container {
                padding-top: calc(var(--header-height) + 1rem);
            }
        }

        @media (max-width: 768px) {
            .trip-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .trip-item-price {
                text-align: left;
                margin: 0.5rem 0;
                width: 100%;
            }

            .trip-item-actions {
                width: 100%;
                justify-content: flex-end;
            }

            .form-row {
                flex-direction: column;
                gap: 1rem;
            }

            .biaya-breakdown {
                grid-template-columns: 1fr;
            }

            .urutan-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .urutan-item-controls {
                width: 100%;
                margin-top: 0.5rem;
                justify-content: space-between;
                flex-wrap: wrap;
            }

            .time-input, .date-input, .budget-input {
                width: 100%;
                margin-left: 0;
                margin-top: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Sidebar Overlay -->
    <div class="sidebar-overlay"></div>

    <!-- Sidebar Navigation -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="../asset/logo.png" alt="Rencana-IN Logo">
            <h2>Rencana-IN</h2>
        </div>
        <nav class="sidebar-nav">
            <ul style="list-style: none; padding: 0; margin: 0;">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="wisata.php" class="nav-link <?= $current_page == 'wisata.php' ? 'active' : '' ?>">
                        <i class="fas fa-umbrella-beach"></i>
                        <span>Wisata</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="transportasi.php" class="nav-link <?= $current_page == 'transportasi.php' ? 'active' : '' ?>">
                        <i class="fas fa-bus"></i>
                        <span>Transportasi</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="kuliner.php" class="nav-link <?= $current_page == 'kuliner.php' ? 'active' : '' ?>">
                        <i class="fas fa-utensils"></i>
                        <span>Kuliner</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="penginapan.php" class="nav-link <?= $current_page == 'penginapan.php' ? 'active' : '' ?>">
                        <i class="fas fa-hotel"></i>
                        <span>Penginapan</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="trip_saya.php" class="nav-link <?= $current_page == 'trip_saya.php' ? 'active' : '' ?>">
                        <i class="fas fa-map-marked-alt"></i>
                        <span>Trip Saya</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main">
        <header class="header">
            <button class="menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Cari...">
            </div>
            <div class="user-menu">
                <a href="#" class="dropdown-toggle">
                    <div class="avatar">
                        <?= $inisial ?>
                    </div>
                    <span><?= htmlspecialchars($namaPengguna) ?></span>
                    <i class="fas fa-caret-down ml-2"></i>
                </a>
            </div>
        </header>

        <div class="trip-container">
            <!-- Notification Messages -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $success_message ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
                </div>
            <?php endif; ?>

            <!-- Section Preferensi Perjalanan -->
            <div class="preferensi-section">
                <h2 class="section-title"><i class="fas fa-sliders-h"></i> Preferensi Perjalanan</h2>
                <form method="POST" action="trip_saya.php">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="lokasi_tujuan">Lokasi Tujuan</label>
                            <input type="text" id="lokasi_tujuan" name="lokasi_tujuan" value="<?= isset($preferensi['lokasi_tujuan']) ? htmlspecialchars($preferensi['lokasi_tujuan']) : '' ?>" placeholder="Kota atau daerah tujuan" required>
                        </div>
                        <div class="form-group">
                            <label for="tanggal_mulai">Tanggal Mulai</label>
                            <input type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= isset($preferensi['tanggal_mulai']) ? htmlspecialchars($preferensi['tanggal_mulai']) : date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="durasi">Durasi (hari)</label>
                            <input type="number" id="durasi" name="durasi" min="1" max="30" value="<?= isset($preferensi['durasi']) ? htmlspecialchars($preferensi['durasi']) : '3' ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Kategori Minat</label>
                        <div class="checkbox-group">
                            <?php 
                            $kategori_minat = isset($preferensi['kategori_minat']) ? explode(',', $preferensi['kategori_minat']) : [];
                            $kategori_options = ['Alam', 'Budaya', 'Kuliner', 'Sejarah', 'Religi', 'Hiburan', 'Belanja'];
                            
                            foreach ($kategori_options as $kategori): 
                                $checked = in_array($kategori, $kategori_minat) ? 'checked' : '';
                            ?>
                            <div class="checkbox-item">
                                <input type="checkbox" id="kategori_<?= strtolower($kategori) ?>" name="kategori_minat[]" value="<?= $kategori ?>" <?= $checked ?>>
                                <label for="kategori_<?= strtolower($kategori) ?>"><?= $kategori ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-actions" style="text-align: right; margin-top: 1.5rem;">
                        <button type="submit" name="simpan_preferensi" class="btn btn-primary">
                            <i class="fas fa-save"></i> Simpan Preferensi
                        </button>
                    </div>
                </form>
            </div>

            <!-- Section Daftar Item Trip -->
            <div class="trip-items-section">
                <h2 class="section-title"><i class="fas fa-list"></i> Daftar Item Trip</h2>
                
                <?php if (!$hasWisata || !$hasTransportasi): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Perhatian!</strong> Anda wajib memesan minimal:
                    <ul>
                        <?php if (!$hasWisata): ?><li>Satu destinasi wisata</li><?php endif; ?>
                        <?php if (!$hasTransportasi): ?><li>Satu layanan transportasi</li><?php endif; ?>
                    </ul>
                    Item lainnya bersifat opsional.
                </div>
                <?php endif; ?>
                
                <?php if (count($items_trip) > 0): ?>
                    <?php foreach ($items_trip as $item): ?>
                        <div class="trip-item">
                            <div class="trip-item-info">
                                <div class="trip-item-title">
                                    <?= htmlspecialchars($item['nama_item']) ?>
                                    <span class="itinerary-item-type type-<?= $item['item_type'] ?>">
                                        <?= ucfirst($item['item_type']) ?>
                                    </span>
                                </div>
                                <div class="trip-item-meta">
                                    <span><i class="fas fa-calendar-alt"></i> <?= date('d M Y', strtotime($item['tanggal_kunjungan'])) ?></span>
                                    <span><i class="fas fa-clock"></i> <?= date('H:i', strtotime($item['waktu_kunjungan'])) ?></span>
                                    <?php if ($item['min_budget'] > 0 || $item['max_budget'] > 0): ?>
                                        <span><i class="fas fa-money-bill-wave"></i> 
                                            Budget: Rp <?= number_format($item['min_budget'], 0, ',', '.') ?> 
                                            - Rp <?= number_format($item['max_budget'], 0, ',', '.') ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['keterangan'])): ?>
                                        <span><i class="fas fa-info-circle"></i> <?= htmlspecialchars($item['keterangan']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="trip-item-price">
                                Rp <?= number_format($item['harga_satuan'], 0, ',', '.') ?>
                            </div>
                            <div class="trip-item-actions">
                                <button class="btn btn-secondary btn-sm btn-edit-item" data-id="<?= $item['id'] ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <a href="trip_saya.php?hapus=<?= $item['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Apakah Anda yakin ingin menghapus item ini dari trip?')">
                                    <i class="fas fa-trash"></i> Hapus
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 2rem; color: var(--secondary);">
                        <i class="fas fa-box-open" style="font-size: 3rem; margin-bottom: 1rem; color: var(--gray-light);"></i>
                        <p>Belum ada item di trip Anda</p>
                        <p>Mulai tambahkan item dari menu Wisata, Kuliner, Transportasi, atau Penginapan</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (count($items_trip) > 0): ?>
                <!-- Section Itinerary -->
                <div class="itinerary-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h2 class="section-title"><i class="fas fa-route"></i> Itinerary Perjalanan</h2>
                        <div>
                            <button id="toggleEditUrutan" class="btn btn-secondary edit-urutan-btn">
                                <i class="fas fa-edit"></i> Edit Urutan Kunjungan
                            </button>
                            <button type="button" name="generate_itinerary" class="btn btn-secondary">
                                <i class="fas fa-sync-alt"></i> Generate Ulang
                            </button>
                        </div>
                    </div>
                    
                    <!-- Form Edit Urutan (hidden by default) -->
                    <form method="POST" action="trip_saya.php" class="form-edit-urutan" id="formEditUrutan">
                        <div id="urutanContainer">
                            <?php foreach ($items_trip as $item): ?>
                                <div class="urutan-item" data-id="<?= $item['id'] ?>">
                                    <div class="urutan-item-handle">
                                        <i class="fas fa-grip-vertical"></i>
                                    </div>
                                    <div class="urutan-item-details">
                                        <strong><?= htmlspecialchars($item['nama_item']) ?></strong>
                                        <span class="itinerary-item-type type-<?= $item['item_type'] ?>">
                                            <?= ucfirst($item['item_type']) ?>
                                        </span>
                                    </div>
                                    <div class="urutan-item-controls">
                                        <input type="date" name="urutan[<?= $item['id'] ?>][tanggal]" 
                                            value="<?= $item['tanggal_kunjungan'] ?>" class="date-input" required>
                                        <input type="time" name="urutan[<?= $item['id'] ?>][waktu]" 
                                            value="<?= date('H:i', strtotime($item['waktu_kunjungan'])) ?>" class="time-input" required>
                                        <input type="number" name="urutan[<?= $item['id'] ?>][min_budget]" 
                                            value="<?= $item['min_budget'] ?>" class="budget-input" placeholder="Min Budget" min="0" step="10000">
                                        <input type="number" name="urutan[<?= $item['id'] ?>][max_budget]" 
                                            value="<?= $item['max_budget'] ?>" class="budget-input" placeholder="Max Budget" min="0" step="10000">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="text-align: right; margin-top: 1rem;">
                            <button type="button" id="cancelEditUrutan" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Batal
                            </button>
                            <button type="submit" name="edit_urutan" class="btn btn-primary">
                                <i class="fas fa-save"></i> Simpan Perubahan
                            </button>
                        </div>
                    </form>
                    
                    <!-- Regular Itinerary Display -->
                    <div id="regularItinerary">
                        <?php 
                        // Group items by date for itinerary display
                        $items_by_date = [];
                        foreach ($items_trip as $item) {
                            $date = $item['tanggal_kunjungan'];
                            if (!isset($items_by_date[$date])) {
                                $items_by_date[$date] = [];
                            }
                            $items_by_date[$date][] = $item;
                        }
                        
                        $day_counter = 1;
                        foreach ($items_by_date as $date => $items): 
                            $formatted_date = date('d F Y', strtotime($date));
                        ?>
                            <div class="itinerary-day">
                                <div class="itinerary-day-header">
                                    Hari <?= $day_counter++ ?>: <?= $formatted_date ?>
                                </div>
                                <div class="itinerary-day-content">
                                    <?php foreach ($items as $item): ?>
                                        <div class="itinerary-item">
                                            <div class="itinerary-time">
                                                <?= date('H:i', strtotime($item['waktu_kunjungan'])) ?>
                                            </div>
                                            <div class="itinerary-detail">
                                                <div class="itinerary-item-title">
                                                    <?= htmlspecialchars($item['nama_item']) ?>
                                                    <span class="itinerary-item-type type-<?= $item['item_type'] ?>">
                                                        <?= ucfirst($item['item_type']) ?>
                                                    </span>
                                                </div>
                                                <div class="itinerary-item-meta">
                                                    <?php if ($item['min_budget'] > 0 || $item['max_budget'] > 0): ?>
                                                        <span>Budget: Rp <?= number_format($item['min_budget'], 0, ',', '.') ?> - Rp <?= number_format($item['max_budget'], 0, ',', '.') ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['keterangan'])): ?>
                                                        <?= htmlspecialchars($item['keterangan']) ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Section Estimasi Biaya -->
                <div class="biaya-section">
                    <h2 class="section-title"><i class="fas fa-money-bill-alt"></i> Estimasi Biaya</h2>

                    <div class="biaya-total">
                        Total Biaya: Rp <span id="total-biaya"><?= number_format($total_biaya, 0, ',', '.') ?></span>
                    </div>

                    <div class="biaya-breakdown">
                        <div class="biaya-category">
                            <div class="biaya-category-title">Wisata</div>
                            <div class="biaya-category-amount">Rp <span class="category-amount" data-category="wisata"><?= number_format($breakdown_biaya['wisata'], 0, ',', '.') ?></span></div>
                        </div>
                        <div class="biaya-category">
                            <div class="biaya-category-title">Kuliner</div>
                            <div class="biaya-category-amount">Rp <span class="category-amount" data-category="kuliner"><?= number_format($breakdown_biaya['kuliner'], 0, ',', '.') ?></span></div>
                        </div>
                        <div class="biaya-category">
                            <div class="biaya-category-title">Transportasi</div>
                            <div class="biaya-category-amount">Rp <span class="category-amount" data-category="transportasi"><?= number_format($breakdown_biaya['transportasi'], 0, ',', '.') ?></span></div>
                        </div>
                        <div class="biaya-category">
                            <div class="biaya-category-title">Penginapan</div>
                            <div class="biaya-category-amount">Rp <span class="category-amount" data-category="penginapan"><?= number_format($breakdown_biaya['penginapan'], 0, ',', '.') ?></span></div>
                        </div>
                    </div>

                    <div class="biaya-per-orang">
                        <label for="orang">Jumlah Orang:</label>
                        <input type="number" id="orang" class="orang-input" value="1" min="1" onchange="updateTotalByPeople()">
                        <span id="per-orang-text">(Rp <?= number_format($total_biaya, 0, ',', '.') ?> per orang)</span>
                    </div>
                </div>

                <!-- Section Simpan & Ekspor -->
                <div class="simpan-section">
                    <div class="export-options">
                        <button type="button" class="btn btn-primary" id="exportPdf">
                            <i class="fas fa-file-download"></i> Ekspor ke PDF
                        </button>
                        <button type="button" class="btn btn-primary" id="exportExcel">
                            <i class="fas fa-file-excel"></i> Ekspor ke Excel
                        </button>
                    </div>

                    <form method="POST" action="trip_saya.php" onsubmit="return validateBeforeSave()">
                        <div style="text-align: center; margin-top: 1.5rem;">
                            <button type="submit" name="simpan_itinerary" class="btn btn-primary"
                                <?= (!$hasWisata || !$hasTransportasi) ? 'disabled' : '' ?>>
                                <i class="fas fa-save"></i> Simpan Itinerary
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Section Pembayaran -->
                <div class="pembayaran-section">
                    <h2 class="section-title"><i class="fas fa-credit-card"></i> Pembayaran</h2>
                    
                    <?php if ($pembayaran): 
                        if ($pembayaran['status'] == 'success'): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Pembayaran Anda sebesar Rp <?= number_format($pembayaran['jumlah'], 0, ',', '.') ?> telah berhasil!
                            </div>
                        <?php elseif ($pembayaran['status'] == 'pending'): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-info-circle"></i> Pembayaran Anda sebesar Rp <?= number_format($pembayaran['jumlah'], 0, ',', '.') ?> sedang menunggu verifikasi.
                                <a href="#" id="lihatBuktiPembayaran">Lihat bukti pembayaran</a>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i> Pembayaran Anda sebesar Rp <?= number_format($pembayaran['jumlah'], 0, ',', '.') ?> gagal. Silakan coba lagi.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <form method="POST" action="trip_saya.php" enctype="multipart/form-data" id="formPembayaran">
                        <input type="hidden" name="total_biaya" value="<?= $total_biaya ?>">
                        
                        <div class="form-group">
                            <label>Metode Pembayaran</label>
                            <div class="radio-group">
                                <label>
                                    <input type="radio" name="metode" value="qris" checked> 
                                    <span>QRIS</span>
                                </label>
                                <label>
                                    <input type="radio" name="metode" value="transfer"> 
                                    <span>Transfer Bank</span>
                                </label>
                            </div>
                        </div>
                        
                        <div id="qrisSection">
                            <div class="form-group">
                                <label>Instruksi Pembayaran QRIS</label>
                                <ol style="margin-left: 1.5rem; margin-top: 0.5rem;">
                                    <li>Buka aplikasi mobile banking atau e-wallet Anda</li>
                                    <li>Pilih fitur pembayaran QRIS</li>
                                    <li>Scan kode QR berikut</li>
                                </ol>
                                
                                <div style="text-align: center; margin: 1rem 0;">
                                    <img src="qris.jpg" alt="QRIS Code" style="max-width: 200px; border: 1px solid #ddd; padding: 10px;">
                                    <p style="font-size: 0.9rem; color: #666;">Scan QR code di atas untuk melakukan pembayaran</p>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="bukti_pembayaran">Unggah Bukti Pembayaran (Screenshot Pembayaran)</label>
                                <input type="file" id="bukti_pembayaran" name="bukti_pembayaran" accept="image/*" required>
                                <p style="font-size: 0.8rem; color: #666;">Format: JPG, PNG (Maks. 2MB)</p>
                            </div>
                        </div>
                        
                        <div id="transferSection" style="display: none;">
                            <div class="form-group">
                                <label>Rekening Tujuan</label>
                                <p style="margin-top: 0.5rem;">
                                    Bank: BCA<br>
                                    No. Rekening: 1234567890<br>
                                    Atas Nama: Rencana-IN Travel
                                </p>
                            </div>
                            
                            <div class="form-group">
                                <label for="bukti_transfer">Unggah Bukti Transfer</label>
                                <input type="file" id="bukti_transfer" name="bukti_pembayaran" accept="image/*" disabled>
                                <p style="font-size: 0.8rem; color: #666;">Format: JPG, PNG (Maks. 2MB)</p>
                            </div>
                        </div>
                        
                        <div style="text-align: center; margin-top: 1.5rem;">
                            <button type="submit" name="bayar" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Kirim Bukti Pembayaran
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Tambah Item -->
    <div class="modal" id="modalTambahItem">
        <div class="modal-content" style="max-width: 600px;">
            <span class="close-modal">&times;</span>
            <h3><i class="fas fa-plus-circle"></i> Tambah Item ke Trip</h3>
            <form method="POST" action="trip_saya.php">
                <input type="hidden" name="item_type" id="modal_item_type">
                <input type="hidden" name="item_id" id="modal_item_id">
                <input type="hidden" name="nama_item" id="modal_nama_item">
                
                <div class="form-group">
                    <label for="tambah_tanggal">Tanggal Kunjungan</label>
                    <input type="date" id="tambah_tanggal" name="tanggal_kunjungan" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="tambah_waktu">Waktu Kunjungan</label>
                    <input type="time" id="tambah_waktu" name="waktu_kunjungan" class="form-control" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="tambah_min_budget">Min Budget (Rp)</label>
                        <input type="number" id="tambah_min_budget" name="min_budget" class="form-control" min="0" step="10000">
                    </div>
                    <div class="form-group">
                        <label for="tambah_max_budget">Max Budget (Rp)</label>
                        <input type="number" id="tambah_max_budget" name="max_budget" class="form-control" min="0" step="10000">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="tambah_keterangan">Keterangan (Opsional)</label>
                    <textarea id="tambah_keterangan" name="keterangan" class="form-control" rows="3"></textarea>
                </div>
                
                <div style="text-align: right; margin-top: 1rem;">
                    <button type="button" class="btn btn-secondary close-modal-btn">Batal</button>
                    <button type="submit" name="tambah_item" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Edit Item -->
    <div class="modal" id="modalEditItem">
        <div class="modal-content" style="max-width: 600px;">
            <span class="close-modal">&times;</span>
            <h3><i class="fas fa-edit"></i> Edit Item Trip</h3>
            <form method="POST" action="trip_saya.php">
                <input type="hidden" name="id_edit" id="edit_id">
                
                <div class="form-group">
                    <label for="edit_tanggal">Tanggal Kunjungan</label>
                    <input type="date" id="edit_tanggal" name="tanggal_kunjungan" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_waktu">Waktu Kunjungan</label>
                    <input type="time" id="edit_waktu" name="waktu_kunjungan" class="form-control" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_min_budget">Min Budget (Rp)</label>
                        <input type="number" id="edit_min_budget" name="min_budget" class="form-control" min="0" step="10000">
                    </div>
                    <div class="form-group">
                        <label for="edit_max_budget">Max Budget (Rp)</label>
                        <input type="number" id="edit_max_budget" name="max_budget" class="form-control" min="0" step="10000">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit_keterangan">Keterangan (Opsional)</label>
                    <textarea id="edit_keterangan" name="keterangan" class="form-control" rows="3"></textarea>
                </div>
                
                <div style="text-align: right; margin-top: 1rem;">
                    <button type="button" class="btn btn-secondary close-modal-btn">Batal</button>
                    <button type="submit" name="edit_item" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>

    <script>
        // Menangani perhitungan biaya berdasarkan jumlah orang
        document.addEventListener('DOMContentLoaded', function() {
            const itemPrices = <?php echo json_encode($breakdown_biaya); ?>; // Data harga per kategori
            const itemsTrip = <?php echo json_encode($items_trip); ?>; // Data item trip

            function updateTotalByPeople() {
                const jumlahOrang = parseInt(document.getElementById('orang').value) || 1;

                // Update biaya total per kategori
                let totalWisata = itemPrices['wisata'] * jumlahOrang;
                let totalKuliner = itemPrices['kuliner'] * jumlahOrang;
                let totalTransportasi = itemPrices['transportasi'] * jumlahOrang;
                let totalPenginapan = itemPrices['penginapan'] * jumlahOrang;

                // Update UI dengan harga baru
                document.querySelector('.category-amount[data-category="wisata"]').textContent = totalWisata.toLocaleString('id-ID');
                document.querySelector('.category-amount[data-category="kuliner"]').textContent = totalKuliner.toLocaleString('id-ID');
                document.querySelector('.category-amount[data-category="transportasi"]').textContent = totalTransportasi.toLocaleString('id-ID');
                document.querySelector('.category-amount[data-category="penginapan"]').textContent = totalPenginapan.toLocaleString('id-ID');

                // Total biaya keseluruhan
                const totalBiaya = totalWisata + totalKuliner + totalTransportasi + totalPenginapan;
                document.getElementById('total-biaya').textContent = totalBiaya.toLocaleString('id-ID');

                // Update harga per orang
                document.getElementById('per-orang-text').textContent = `(Rp ${(totalBiaya / jumlahOrang).toLocaleString('id-ID')} per orang)`;
            }

            // Memperbarui ketika jumlah orang berubah
            document.getElementById('orang').addEventListener('input', updateTotalByPeople);

            // Validasi budget
            const budgetInputs = document.querySelectorAll('input[name*="budget"]');
            budgetInputs.forEach(input => {
                input.addEventListener('change', function() {
                    const value = parseFloat(this.value) || 0;
                    
                    // Validasi minimal 0
                    if (value < 0) {
                        this.value = 0;
                        alert('Budget tidak boleh kurang dari 0');
                        return;
                    }

                    // Validasi max budget tidak boleh kurang dari min budget
                    if (this.name.includes('max_budget')) {
                        const minBudgetInput = this.closest('.urutan-item-controls').querySelector('input[name*="min_budget"]');
                        const minBudget = parseFloat(minBudgetInput.value) || 0;
                        
                        if (value > 0 && value < minBudget) {
                            this.value = minBudget;
                            alert('Budget maksimal tidak boleh kurang dari budget minimal');
                        }
                    }
                });
            });

            // Memperbarui total awal
            updateTotalByPeople();

            // Initialize drag and drop sorting
            if (document.getElementById('urutanContainer')) {
                new Sortable(document.getElementById('urutanContainer'), {
                    handle: '.urutan-item-handle',
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                    dragClass: 'sortable-drag'
                });
            }

            // Toggle edit urutan form
            const toggleEditBtn = document.getElementById('toggleEditUrutan');
            const cancelEditBtn = document.getElementById('cancelEditUrutan');
            const editForm = document.getElementById('formEditUrutan');
            const regularItinerary = document.getElementById('regularItinerary');
            
            if (toggleEditBtn) {
                toggleEditBtn.addEventListener('click', function() {
                    editForm.classList.add('show');
                    regularItinerary.style.display = 'none';
                    toggleEditBtn.style.display = 'none';
                });
            }
            
            if (cancelEditBtn) {
                cancelEditBtn.addEventListener('click', function() {
                    editForm.classList.remove('show');
                    regularItinerary.style.display = 'block';
                    toggleEditBtn.style.display = 'inline-block';
                });
            }

            // Sidebar toggle for mobile
            const sidebar = document.querySelector('.sidebar');
            const sidebarOverlay = document.querySelector('.sidebar-overlay');
            const menuToggle = document.querySelector('.menu-toggle');

            if (menuToggle) {
                menuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                    sidebarOverlay.style.display = sidebar.classList.contains('show') ? 'block' : 'none';
                });
            }

            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('show');
                    this.style.display = 'none';
                });
            }

            // Auto-close sidebar on large screens
            function handleResize() {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('show');
                    if (sidebarOverlay) sidebarOverlay.style.display = 'none';
                }
            }

            window.addEventListener('resize', handleResize);
            handleResize();

            // Fungsi untuk membuka modal tambah item
            window.openTambahModal = function(itemType, itemId, itemName) {
                const modal = document.getElementById('modalTambahItem');
                document.getElementById('modal_item_type').value = itemType;
                document.getElementById('modal_item_id').value = itemId;
                document.getElementById('modal_nama_item').value = itemName;
                
                // Set tanggal default ke preferensi atau hari ini
                const preferensiTanggal = document.getElementById('tanggal_mulai').value;
                document.getElementById('tambah_tanggal').value = preferensiTanggal || new Date().toISOString().split('T')[0];
                
                modal.style.display = 'block';
            }

            // Fungsi untuk membuka modal edit item
            window.openEditModal = function(itemId) {
                const modal = document.getElementById('modalEditItem');
                
                // Ambil data item dari daftar trip
                const items = <?php echo json_encode($items_trip); ?>;
                const item = items.find(i => i.id == itemId);
                
                if (item) {
                    document.getElementById('edit_id').value = item.id;
                    document.getElementById('edit_tanggal').value = item.tanggal_kunjungan;
                    document.getElementById('edit_waktu').value = item.waktu_kunjungan.split(' ')[1] || '12:00';
                    document.getElementById('edit_min_budget').value = item.min_budget || '';
                    document.getElementById('edit_max_budget').value = item.max_budget || '';
                    document.getElementById('edit_keterangan').value = item.keterangan || '';
                    
                    modal.style.display = 'block';
                }
            }

            // Tombol edit di item trip
            document.querySelectorAll('.btn-edit-item').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const itemId = this.getAttribute('data-id');
                    openEditModal(itemId);
                });
            });
            
            // Tombol close modal
            document.querySelectorAll('.close-modal, .close-modal-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.modal').forEach(modal => {
                        modal.style.display = 'none';
                    });
                });
            });
            
            // Close modal ketika klik di luar
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.style.display = 'none';
                    }
                });
            });
            
            // Validasi form sebelum submit
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const minBudget = this.querySelector('input[name="min_budget"]');
                    const maxBudget = this.querySelector('input[name="max_budget"]');
                    
                    if (minBudget && maxBudget && minBudget.value && maxBudget.value) {
                        if (parseFloat(minBudget.value) > parseFloat(maxBudget.value)) {
                            e.preventDefault();
                            alert('Budget minimal tidak boleh lebih besar dari budget maksimal');
                            minBudget.focus();
                        }
                    }
                });
            });

            // Fungsi untuk ekspor ke PDF
            document.getElementById("exportPdf").addEventListener("click", function() {
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF();
                
                // Judul PDF
                doc.setFont("helvetica", "normal");
                doc.setFontSize(16);
                doc.text("Itinerary Trip Saya - Rencana-IN", 10, 10);
                
                // Informasi Preferensi
                doc.setFontSize(12);
                doc.text(`Lokasi: ${document.getElementById('lokasi_tujuan').value || '-'}`, 10, 20);
                doc.text(`Tanggal Mulai: ${document.getElementById('tanggal_mulai').value || '-'}`, 10, 30);
                doc.text(`Durasi: ${document.getElementById('durasi').value || '-'} hari`, 10, 40);
                
                // Estimasi Biaya
                doc.setFontSize(14);
                doc.text("Estimasi Biaya:", 10, 50);
                doc.setFontSize(12);
                doc.text(`Total: Rp ${document.getElementById('total-biaya').textContent}`, 15, 60);
                doc.text(`Untuk ${document.getElementById('orang').value} orang`, 15, 70);
                
                // Breakdown Biaya
                doc.text("Rincian Biaya:", 10, 80);
                doc.text(`- Wisata: Rp ${document.querySelector('.category-amount[data-category="wisata"]').textContent}`, 15, 90);
                doc.text(`- Kuliner: Rp ${document.querySelector('.category-amount[data-category="kuliner"]').textContent}`, 15, 100);
                doc.text(`- Transportasi: Rp ${document.querySelector('.category-amount[data-category="transportasi"]').textContent}`, 15, 110);
                doc.text(`- Penginapan: Rp ${document.querySelector('.category-amount[data-category="penginapan"]').textContent}`, 15, 120);
                
                // Itinerary
                doc.addPage();
                doc.setFontSize(16);
                doc.text("Rencana Perjalanan", 10, 10);
                
                let yPosition = 20;
                const itineraryDays = document.querySelectorAll('.itinerary-day');
                
                itineraryDays.forEach(day => {
                    const dayHeader = day.querySelector('.itinerary-day-header').textContent;
                    doc.setFontSize(14);
                    doc.text(dayHeader, 10, yPosition);
                    yPosition += 10;
                    
                    const items = day.querySelectorAll('.itinerary-item');
                    items.forEach(item => {
                        const time = item.querySelector('.itinerary-time').textContent;
                        const title = item.querySelector('.itinerary-item-title').textContent;
                        const meta = item.querySelector('.itinerary-item-meta').textContent;
                        
                        doc.setFontSize(12);
                        doc.text(`${time} - ${title}`, 15, yPosition);
                        yPosition += 7;
                        
                        if (meta.trim() !== '') {
                            doc.setFontSize(10);
                            doc.text(`   ${meta}`, 15, yPosition);
                            yPosition += 7;
                        }
                        
                        yPosition += 5;
                        
                        if (yPosition > 280) {
                            doc.addPage();
                            yPosition = 10;
                        }
                    });
                    
                    yPosition += 10;
                });
                
                // Export PDF
                doc.save('itinerary_trip_saya.pdf');
            });

            // Fungsi untuk ekspor ke Excel
            document.getElementById("exportExcel").addEventListener("click", function() {
                // Prepare data for Excel export
                const data = [
                    ["ITINERARY TRIP SAYA - RENCANA-IN"],
                    [""],
                    ["Informasi Trip"],
                    ["Lokasi Tujuan", document.getElementById('lokasi_tujuan').value || '-'],
                    ["Tanggal Mulai", document.getElementById('tanggal_mulai').value || '-'],
                    ["Durasi", `${document.getElementById('durasi').value || '-'} hari`],
                    [""],
                    ["Estimasi Biaya"],
                    ["Total", `Rp ${document.getElementById('total-biaya').textContent}`],
                    ["Jumlah Orang", document.getElementById('orang').value],
                    ["Rincian Biaya"],
                    ["Wisata", `Rp ${document.querySelector('.category-amount[data-category="wisata"]').textContent}`],
                    ["Kuliner", `Rp ${document.querySelector('.category-amount[data-category="kuliner"]').textContent}`],
                    ["Transportasi", `Rp ${document.querySelector('.category-amount[data-category="transportasi"]').textContent}`],
                    ["Penginapan", `Rp ${document.querySelector('.category-amount[data-category="penginapan"]').textContent}`],
                    [""],
                    ["Rencana Perjalanan"]
                ];

                // Add itinerary items
                const itineraryDays = document.querySelectorAll('.itinerary-day');
                itineraryDays.forEach(day => {
                    const dayHeader = day.querySelector('.itinerary-day-header').textContent;
                    data.push([dayHeader]);
                    
                    const items = day.querySelectorAll('.itinerary-item');
                    items.forEach(item => {
                        const time = item.querySelector('.itinerary-time').textContent;
                        const title = item.querySelector('.itinerary-item-title').textContent;
                        const meta = item.querySelector('.itinerary-item-meta').textContent;
                        
                        data.push([time, title]);
                        if (meta.trim() !== '') {
                            data.push(["", meta]);
                        }
                        data.push([""]);
                    });
                });

                // Create worksheet
                const ws = XLSX.utils.aoa_to_sheet(data);
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, "Itinerary");

                // Export Excel
                XLSX.writeFile(wb, 'itinerary_trip_saya.xlsx');
            });

            // Validasi sebelum simpan itinerary (wajib wisata dan transportasi)
            function validateBeforeSave() {
                const items = <?php echo json_encode($items_trip); ?>;
                let hasWisata = false;
                let hasTransportasi = false;

                items.forEach(item => {
                    if (item.item_type === 'wisata') hasWisata = true;
                    if (item.item_type === 'transportasi') hasTransportasi = true;
                });

                if (!hasWisata) {
                    alert('Anda wajib memilih setidaknya satu destinasi wisata!');
                    return false;
                }

                if (!hasTransportasi) {
                    alert('Anda wajib memilih setidaknya satu layanan transportasi!');
                    return false;
                }

                return true;
            }
            
            // Toggle metode pembayaran
            const metodePembayaran = document.querySelectorAll('input[name="metode"]');
            const qrisSection = document.getElementById('qrisSection');
            const transferSection = document.getElementById('transferSection');
            const buktiTransfer = document.getElementById('bukti_transfer');

            metodePembayaran.forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.value === 'qris') {
                        qrisSection.style.display = 'block';
                        transferSection.style.display = 'none';
                        document.getElementById('bukti_pembayaran').required = true;
                        buktiTransfer.required = false;
                        buktiTransfer.disabled = true;
                    } else {
                        qrisSection.style.display = 'none';
                        transferSection.style.display = 'block';
                        document.getElementById('bukti_pembayaran').required = false;
                        buktiTransfer.required = true;
                        buktiTransfer.disabled = false;
                    }
                });
            });

            // Modal untuk lihat bukti pembayaran
            const lihatBuktiBtn = document.getElementById('lihatBuktiPembayaran');
            if (lihatBuktiBtn) {
                lihatBuktiBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Create modal
                    const modal = document.createElement('div');
                    modal.className = 'modal';
                    modal.innerHTML = `
                        <div class="modal-content">
                            <span class="close-modal">&times;</span>
                            <h3>Bukti Pembayaran</h3>
                            <img src="../uploads/pembayaran/<?= isset($pembayaran['bukti_pembayaran']) ? $pembayaran['bukti_pembayaran'] : '' ?>" class="modal-image">
                        </div>
                    `;
                    
                    document.body.appendChild(modal);
                    modal.style.display = 'block';
                    
                    // Close modal
                    const closeBtn = modal.querySelector('.close-modal');
                    closeBtn.addEventListener('click', function() {
                        modal.style.display = 'none';
                        document.body.removeChild(modal);
                    });
                    
                    // Close when clicking outside
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            modal.style.display = 'none';
                            document.body.removeChild(modal);
                        }
                    });
                });
            }

            // Validasi form pembayaran
            document.getElementById('formPembayaran').addEventListener('submit', function(e) {
                const fileInput = this.querySelector('input[type="file"]:not([disabled])');
                if (fileInput && fileInput.files.length === 0) {
                    e.preventDefault();
                    alert('Silakan unggah bukti pembayaran terlebih dahulu');
                    fileInput.focus();
                }
            });
        });
    </script>
</body>
</html>

<?php
session_start();
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

// Check if connection file exists
if (!file_exists('../koneksi.php')) {
    die("File koneksi.php tidak ditemukan!");
}

require '../koneksi.php'; // Load database connection

// Verify connection
if (!isset($conn)) {
    die("Koneksi database gagal: \$conn tidak didefinisikan di koneksi.php");
}

$koneksi = $conn; // Define $koneksi as alias for $conn

// User data
$user_id = $_SESSION['user']['id'];
$namaPengguna = $_SESSION['user']['nama_lengkap'];
$level = $_SESSION['user']['level'];
$inisial = strtoupper(substr($namaPengguna, 0, 1));
$current_page = basename($_SERVER['PHP_SELF']);

// Get unique cities for location autocomplete
$cities_query = "SELECT DISTINCT lokasi FROM wisata WHERE status = 'approved'";
$cities_result = $koneksi->query($cities_query);
if (!$cities_result) {
    die("Error getting cities: " . $koneksi->error);
}
$cities = [];
while ($row = $cities_result->fetch_assoc()) {
    $cities[] = $row['lokasi'];
}

// Query parameters
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$kategori_filter = isset($_GET['kategori']) ? $_GET['kategori'] : '';
$budget_filter = isset($_GET['budget']) ? (int)$_GET['budget'] : 0;
$lokasi_filter = isset($_GET['lokasi']) ? trim($_GET['lokasi']) : '';
$promo_filter = isset($_GET['promo']) ? $_GET['promo'] : '';
$recommendation_filter = isset($_GET['recommendation']) ? $_GET['recommendation'] : '';
$participants = isset($_GET['participants']) ? max(1, (int)$_GET['participants']) : 1;
$city_filter = isset($_GET['city']) ? trim($_GET['city']) : '';

// Base SQL query
$sql = "SELECT w.* 
        FROM wisata w
        WHERE w.status = 'approved'";

// Initialize parameters
$params = [];
$types = '';

// Add filters to query
if (!empty($search_query)) {
    $sql .= " AND w.nama_wisata LIKE ?";
    $types .= 's';
    $params[] = "%$search_query%";
}

if (!empty($kategori_filter)) {
    $sql .= " AND w.kategori = ?";
    $types .= 's';
    $params[] = $kategori_filter;
}

if (!empty($city_filter)) {
    $sql .= " AND w.lokasi LIKE ?";
    $types .= 's';
    $params[] = "%$city_filter%";
}

if ($budget_filter > 0) {
    $sql .= " AND w.harga_tiket <= ?";
    $types .= 'd';
    $params[] = $budget_filter;
}

if (!empty($lokasi_filter)) {
    $sql .= " AND w.lokasi LIKE ?";
    $types .= 's';
    $params[] = "%$lokasi_filter%";
}

// Filter promosi
if ($promo_filter === 'yes') {
    $sql .= " AND w.is_promosi = 1";
}

// Filter rekomendasi
if ($recommendation_filter === 'yes') {
    $sql .= " AND w.is_rekomendasi = 1";
}

// Order results
$sql .= " ORDER BY w.nama_wisata ASC";

// Prepare and execute query
$stmt = $koneksi->prepare($sql);
if ($stmt === false) {
    die("Error preparing statement: " . $koneksi->error);
}

// Bind parameters if any
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

// Execute query
if (!$stmt->execute()) {
    die("Error executing statement: " . $stmt->error);
}

$result = $stmt->get_result();

// Proses tambah ke trip
if (isset($_POST['add_to_trip'])) {
    // Pastikan user sudah login
    if (!isset($_SESSION['user']['id'])) {
        $_SESSION['error_message'] = "Anda harus login untuk menambahkan ke trip";
    } else {
        $item_id = $_POST['item_id'];
        $biaya = $_POST['biaya'];
        $tanggal_kunjungan = $_POST['tanggal_kunjungan'];
        $waktu_kunjungan = $_POST['waktu_kunjungan'];
        $jumlah_orang = $_POST['jumlah_orang'];
        $user_id = $_SESSION['user']['id'];
        
        // Calculate total cost
        $total_biaya = $biaya * $jumlah_orang;

        // Insert ke tabel trip_saya
        $insert_query = "INSERT INTO trip_saya (user_id, item_type, item_id, tanggal_kunjungan, waktu_kunjungan, biaya, jumlah_orang, keterangan) 
                        VALUES (?, 'wisata', ?, ?, ?, ?, ?, ?)";
        
        // Prepare the statement for inserting the trip
        $insert_stmt = $koneksi->prepare($insert_query);
        if ($insert_stmt === false) {
            die('MySQL prepare error: ' . $koneksi->error);
        }

        $keterangan = "Wisata - $jumlah_orang orang";
        $insert_stmt->bind_param('isssdis', $user_id, $item_id, $tanggal_kunjungan, $waktu_kunjungan, $total_biaya, $jumlah_orang, $keterangan);
        
        if ($insert_stmt->execute()) {
            $_SESSION['success_message'] = "Wisata berhasil ditambahkan ke trip Anda!";
        } else {
            $_SESSION['error_message'] = "Gagal menambahkan wisata ke trip: " . $insert_stmt->error;
        }
    }
    header("Location: wisata.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wisata - Rencana-IN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/leaflet.css" />
    <style>
        :root {
            --primary: #4CAF50;
            --primary-dark: #3d8b40;
            --primary-light: rgba(76, 175, 80, 0.1);
            --secondary: #6c757d;
            --light: #f8f9fa;
            --dark: #343a40;
            --white: #ffffff;
            --gray-light: #e9ecef;
            --sidebar-width: 250px;
            --header-height: 60px;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --promo-color: #ff6b6b;
            --recommend-color: #4ecdc4;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
        }

        /* SIDEBAR STYLING */
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--white);
            position: fixed;
            top: 0;
            left: 0;
            box-shadow: var(--box-shadow);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        .sidebar-brand {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .sidebar-brand img {
            width: 36px;
            height: 36px;
            margin-right: 10px;
            object-fit: contain;
        }

        .sidebar-brand h2 {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            color: var(--primary);
            white-space: nowrap;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 0;
        }

        .nav-item {
            position: relative;
            margin: 0.25rem 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .nav-link:hover, .nav-link.active {
            color: var(--primary);
            background-color: var(--primary-light);
        }

        .nav-link.active {
            font-weight: 500;
            border-left: 3px solid var(--primary);
        }

        .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        /* HEADER DI BAGIAN ATAS */
        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        .header {
            height: var(--header-height);
            background-color: var(--white);
            box-shadow: 0 0.15rem 1.75rem 0 rgba(33, 40, 50, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--dark);
            cursor: pointer;
            margin-right: 1rem;
        }

        .search-bar {
            position: relative;
            width: 300px;
            flex-grow: 1;
            max-width: 400px;
            margin: 0 1rem;
        }

        .search-bar input {
            width: 100%;
            padding: 0.375rem 0.75rem 0.375rem 2.5rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
            background-color: var(--light);
            font-size: 0.875rem;
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
            font-size: 0.875rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            margin-left: auto;
        }

        .user-menu .dropdown-toggle {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .user-menu .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            margin-right: 0.5rem;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        /* OVERLAY UNTUK MOBILE SIDEBAR */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        .sidebar-overlay.show {
            display: block;
        }

        /* Wisata Content */
        .wisata-container {
            padding: 2rem;
        }

        /* Location Detection Section */
        .location-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .location-title {
            font-size: 1.2rem;
            margin-bottom: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
        }

        .location-title i {
            margin-right: 0.8rem;
            color: var(--primary);
        }

        .location-form {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .location-input {
            flex: 1;
            position: relative;
        }

        .location-input input {
            width: 100%;
            padding: 0.7rem 0.7rem 0.7rem 2.5rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
        }

        .location-input i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
        }

        .location-map {
            height: 200px;
            margin-top: 1rem;
            border-radius: var(--border-radius);
            overflow: hidden;
            display: none;
        }

        /* Category Filter */
        .filter-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .filter-title {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            color: var(--dark);
            display: flex;
            align-items: center;
        }

        .filter-title i {
            margin-right: 0.8rem;
            color: var(--primary);
        }

        .filter-row {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.6rem;
            font-weight: 500;
            color: var(--dark);
        }

        .filter-group select, 
        .filter-group input {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            background-color: var(--white);
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .filter-group select:focus, 
        .filter-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-secondary {
            background-color: var(--white);
            color: var(--dark);
            border: 1px solid var(--gray-light);
        }

        .btn-secondary:hover {
            background-color: var(--gray-light);
        }

        .btn i {
            margin-right: 0.5rem;
        }

        /* Category Filter Buttons */
        .category-filter {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .category-btn {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            background-color: var(--gray-light);
            color: var(--dark);
            transition: all 0.3s;
            display: flex;
            align-items: center;
        }

        .category-btn.active {
            background-color: var(--primary);
            color: white;
        }

        .category-btn.promo {
            background-color: var(--promo-color);
            color: white;
        }

        .category-btn.rekomendasi {
            background-color: var(--recommend-color);
            color: white;
        }

        /* Wisata Grid */
        .wisata-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .wisata-card {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            transition: all 0.3s;
        }

        .wisata-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }

        .wisata-img-container {
            height: 200px;
            overflow: hidden;
            position: relative;
        }

        .wisata-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }

        .wisata-card:hover .wisata-img {
            transform: scale(1.05);
        }

        .wisata-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background-color: var(--primary);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .wisata-badge.promo {
            background-color: var(--promo-color);
        }

        .wisata-badge.rekomendasi {
            background-color: var(--recommend-color);
        }

        .wisata-body {
            padding: 1.5rem;
        }

        .wisata-title {
            font-size: 1.2rem;
            margin-bottom: 0.8rem;
            color: var(--dark);
            font-weight: 600;
        }

        .wisata-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.2rem;
        }

        .wisata-meta-item {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            color: var(--secondary);
        }

        .wisata-meta-item i {
            margin-right: 0.5rem;
            color: var(--primary);
        }

        .wisata-price {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 1.5rem;
        }

        .wisata-actions {
            display: flex;
            gap: 0.8rem;
        }

        .wisata-actions .btn {
            flex: 1;
            padding: 0.7rem;
            font-size: 0.9rem;
        }

        .no-results {
            text-align: center;
            padding: 3rem;
            color: var(--secondary);
            font-size: 1.1rem;
            grid-column: 1 / -1;
        }

        .no-results i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--gray-light);
        }

        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
        }

        .alert-success {
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--primary-dark);
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .alert-error {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }

        .alert i {
            margin-right: 10px;
        }

        /* Date time group */
        .date-time-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-bottom: 1rem;
        }

        .date-time-item {
            flex: 1;
            min-width: 120px;
        }

        .date-time-item label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: var(--secondary);
        }

        .date-time-item input {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
        }

        /* Autocomplete dropdown */
        .autocomplete-items {
            position: absolute;
            border: 1px solid var(--gray-light);
            border-bottom: none;
            border-top: none;
            z-index: 99;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            max-height: 200px;
            overflow-y: auto;
        }

        .autocomplete-items div {
            padding: 10px;
            cursor: pointer;
            background-color: #fff;
            border-bottom: 1px solid var(--gray-light);
        }

        .autocomplete-items div:hover {
            background-color: var(--primary-light);
        }

        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .wisata-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .wisata-container {
                padding-top: calc(var(--header-height) + 1rem);
            }
        }

        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
                gap: 1rem;
            }

            .filter-group {
                min-width: 100%;
            }

            .wisata-actions {
                flex-direction: column;
            }
            
            .search-bar {
                display: none;
            }

            .location-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .category-filter {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
  <!-- Overlay untuk sidebar saat tampil di mobile -->
    <div class="sidebar-overlay"></div>

    <!-- Sidebar navigasi utama -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="../asset/logo.png" alt="Rencana-IN Logo">
            <h2>Rencana-IN</h2>
        </div>
        <nav class="sidebar-nav">
            <ul style="list-style: none; padding: 0; margin: 0;">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="wisata.php" class="nav-link <?= $current_page == 'wisata.php' ? 'active' : '' ?>">
                        <i class="fas fa-umbrella-beach"></i>
                        <span>Wisata</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="transportasi.php" class="nav-link <?= $current_page == 'transportasi.php' ? 'active' : '' ?>">
                        <i class="fas fa-bus"></i>
                        <span>Transportasi</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="kuliner.php" class="nav-link <?= $current_page == 'kuliner.php' ? 'active' : '' ?>">
                        <i class="fas fa-utensils"></i>
                        <span>Kuliner</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="penginapan.php" class="nav-link <?= $current_page == 'penginapan.php' ? 'active' : '' ?>">
                        <i class="fas fa-hotel"></i>
                        <span>Penginapan</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="trip_saya.php" class="nav-link <?= $current_page == 'trip_saya.php' ? 'active' : '' ?>">
                        <i class="fas fa-map-marked-alt"></i>
                        <span>Trip Saya</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main">
        <header class="header">
            <button class="menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Cari wisata...">
            </div>
            <div class="user-menu">
                <a href="#" class="dropdown-toggle">
                    <div class="avatar">
                        <?= htmlspecialchars($inisial) ?>
                    </div>
                    <span><?= htmlspecialchars($namaPengguna) ?></span>
                    <i class="fas fa-caret-down ml-2"></i>
                </a>
            </div>
        </header>

        <div class="wisata-container">
            <!-- Notification Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $_SESSION['success_message'] ?>
                    <a href="trip_saya.php" class="btn btn-primary" style="margin-left: 1rem; padding: 0.3rem 0.8rem; font-size: 0.8rem;">
                        Lihat Trip
                    </a>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error_message'] ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Location Detection Section -->
            <div class="location-section">
                <h3 class="location-title"><i class="fas fa-map-marker-alt"></i> Lokasi Saat Ini</h3>
                <div class="location-form">
                    <div class="location-input">
                        <i class="fas fa-search-location"></i>
                        <input type="text" id="lokasi-sekarang" placeholder="Masukkan lokasi Anda atau gunakan deteksi otomatis" value="<?= htmlspecialchars($city_filter) ?>">
                        <div id="autocomplete-container" class="autocomplete-items"></div>
                    </div>
                    <button type="button" id="deteksi-lokasi" class="btn btn-primary">
                        <i class="fas fa-location-arrow"></i> Deteksi Otomatis
                    </button>
                </div>
                <div id="peta-lokasi" class="location-map"></div>
            </div>

            <!-- Category Filter -->
            <div class="category-filter">
                <a href="wisata.php" class="category-btn <?= empty($promo_filter) && empty($recommendation_filter) ? 'active' : '' ?>">
                    <i class="fas fa-list"></i> Semua
                </a>
                <a href="wisata.php?promo=yes" class="category-btn promo <?= $promo_filter === 'yes' ? 'active' : '' ?>">
                    <i class="fas fa-tag"></i> Promosi
                </a>
                <a href="wisata.php?recommendation=yes" class="category-btn rekomendasi <?= $recommendation_filter === 'yes' ? 'active' : '' ?>">
                    <i class="fas fa-star"></i> Rekomendasi
                </a>
            </div>
            
            <!-- Search and Filter Section -->
            <div class="filter-section">
                <h3 class="filter-title"><i class="fas fa-sliders-h"></i> Filter Wisata</h3>
                <form method="GET" action="wisata.php">
                    <input type="hidden" name="promo" value="<?= $promo_filter ?>">
                    <input type="hidden" name="recommendation" value="<?= $recommendation_filter ?>">
                    
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="search"><i class="fas fa-search"></i> Cari Wisata</label>
                            <input type="text" id="search" name="search" placeholder="Nama wisata..." value="<?= htmlspecialchars($search_query) ?>">
                        </div>
                        <div class="filter-group">
                            <label for="kategori"><i class="fas fa-tags"></i> Kategori</label>
                            <select id="kategori" name="kategori">
                                <option value="">Semua Kategori</option>
                                <option value="alam" <?= $kategori_filter == 'alam' ? 'selected' : '' ?>>Alam</option>
                                <option value="budaya" <?= $kategori_filter == 'budaya' ? 'selected' : '' ?>>Budaya</option>
                                <option value="sejarah" <?= $kategori_filter == 'sejarah' ? 'selected' : '' ?>>Sejarah</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="city"><i class="fas fa-city"></i> Kota</label>
                            <input type="text" id="city" name="city" placeholder="Pilih kota" value="<?= htmlspecialchars($city_filter) ?>">
                        </div>
                        <div class="filter-group">
                            <label for="budget"><i class="fas fa-money-bill-wave"></i> Budget Maksimum (Rp)</label>
                            <input type="number" id="budget" name="budget" placeholder="Maksimum budget" value="<?= $budget_filter > 0 ? $budget_filter : '' ?>">
                        </div>
                        <div class="filter-group">
                            <label for="participants"><i class="fas fa-users"></i> Jumlah Orang</label>
                            <input type="number" id="participants" name="participants" min="1" value="<?= $participants ?>">
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Terapkan Filter
                        </button>
                        <a href="wisata.php" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset Filter
                        </a>
                    </div>
                </form>
            </div>

            <!-- Wisata List -->
            <div class="wisata-grid">
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <div class="wisata-card">
                            <div class="wisata-img-container">
                                <img src="../uploads/wisata/<?= htmlspecialchars($row['gambar']) ?>" 
                                     alt="<?= htmlspecialchars($row['nama_wisata']) ?>" 
                                     class="wisata-img">
                                <?php if ($row['is_promosi'] == 1): ?>
                                    <span class="wisata-badge promo">Promosi</span>
                                <?php elseif ($row['is_rekomendasi'] == 1): ?>
                                    <span class="wisata-badge rekomendasi">Rekomendasi</span>
                                <?php endif; ?>
                            </div>
                            <div class="wisata-body">
                                <h3 class="wisata-title"><?= htmlspecialchars($row['nama_wisata']) ?></h3>
                                
                                <div class="wisata-meta">
                                    <div class="wisata-meta-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?= htmlspecialchars($row['lokasi']) ?>
                                    </div>
                                    <div class="wisata-meta-item">
                                        <i class="fas fa-tag"></i>
                                        <?= ucfirst($row['kategori']) ?>
                                    </div>
                                </div>
                                
                                <div class="wisata-price">
                                    Rp <?= number_format($row['harga_tiket'], 0, ',', '.') ?>
                                </div>
                                
                                <?php if (isset($_SESSION['user']['id'])): ?>
                                    <form method="POST" action="wisata.php?<?= http_build_query($_GET) ?>">
                                        <input type="hidden" name="item_id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="biaya" value="<?= $row['harga_tiket'] ?>">
                                        
                                        <div class="date-time-group">
                                            <div class="date-time-item">
                                                <label for="tanggal_<?= $row['id'] ?>">Tanggal</label>
                                                <input type="date" id="tanggal_<?= $row['id'] ?>" name="tanggal_kunjungan" value="<?= date('Y-m-d') ?>" required>
                                            </div>
                                            <div class="date-time-item">
                                                <label for="waktu_<?= $row['id'] ?>">Waktu</label>
                                                <input type="time" id="waktu_<?= $row['id'] ?>" name="waktu_kunjungan" value="08:00" required>
                                            </div>
                                            <div class="date-time-item">
                                                <label for="jumlah_<?= $row['id'] ?>">Jumlah Orang</label>
                                                <input type="number" id="jumlah_<?= $row['id'] ?>" name="jumlah_orang" min="1" value="<?= $participants ?>" required>
                                            </div>
                                        </div>
                                        
                                        <div class="wisata-actions">
                                            <button type="submit" name="add_to_trip" class="btn btn-primary">
                                                <i class="fas fa-plus"></i> Tambah ke Trip
                                            </button>
                                            <a href="detail_wisata.php?id=<?= $row['id'] ?>" class="btn btn-secondary">
                                                <i class="fas fa-info-circle"></i> Detail
                                            </a>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="alert alert-error" style="margin-top: 1rem; padding: 0.8rem; text-align: center;">
                                        <i class="fas fa-exclamation-triangle"></i> Anda harus login untuk menambahkan ke trip
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-results">
                        <i class="fas fa-map-marked-alt"></i>
                        <h3>Tidak ada wisata yang ditemukan</h3>
                        <p>Coba gunakan filter yang berbeda atau cari dengan kata kunci lain</p>
                        <a href="wisata.php" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-undo"></i> Reset Filter
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/leaflet.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.querySelector('.sidebar');
            const sidebarOverlay = document.querySelector('.sidebar-overlay');
            const menuToggle = document.querySelector('.menu-toggle');

            // Tampilkan dan sembunyikan sidebar saat tombol ditekan
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
                sidebarOverlay.classList.toggle('show');
            });

            // Klik overlay untuk menutup sidebar
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');
            });

            // Tangani resize jendela untuk menutup sidebar di layar besar
            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                }
            });

            // Autocomplete untuk lokasi
            const lokasiInput = document.getElementById('lokasi-sekarang');
            const autocompleteContainer = document.getElementById('autocomplete-container');
            const daftarKota = <?= json_encode($cities) ?>;
            
            let peta;
            let marker;

            // Fungsi untuk menampilkan autocomplete
            function showAutocomplete(str) {
                autocompleteContainer.innerHTML = '';
                
                if (str.length === 0) {
                    autocompleteContainer.style.display = 'none';
                    return;
                }
                
                const filtered = daftarKota.filter(kota => 
                    kota.toLowerCase().includes(str.toLowerCase())
                );
                
                if (filtered.length > 0) {
                    filtered.forEach(kota => {
                        const item = document.createElement('div');
                        item.innerHTML = `<strong>${kota.substr(0, str.length)}</strong>${kota.substr(str.length)}`;
                        item.addEventListener('click', function() {
                            lokasiInput.value = kota;
                            autocompleteContainer.style.display = 'none';
                        });
                        autocompleteContainer.appendChild(item);
                    });
                    autocompleteContainer.style.display = 'block';
                } else {
                    autocompleteContainer.style.display = 'none';
                }
            }
            
            // Event listener untuk input lokasi
            lokasiInput.addEventListener('input', function() {
                showAutocomplete(this.value);
            });
            
            // Tutup autocomplete saat klik di luar
            document.addEventListener('click', function(e) {
                if (e.target !== lokasiInput) {
                    autocompleteContainer.style.display = 'none';
                }
            });
            
            // Deteksi lokasi otomatis
            document.getElementById('deteksi-lokasi').addEventListener('click', function() {
                const petaLokasi = document.getElementById('peta-lokasi');
                
                if (navigator.geolocation) {
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mendeteksi...';
                    this.disabled = true;
                    
                    navigator.geolocation.getCurrentPosition(
                        function(position) {
                            const lat = position.coords.latitude;
                            const lng = position.coords.longitude;
                            
                            // Inisialisasi peta jika belum ada
                            if (!peta) {
                                petaLokasi.style.display = 'block';
                                peta = L.map('peta-lokasi').setView([lat, lng], 13);
                                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                                }).addTo(peta);
                            } else {
                                peta.setView([lat, lng], 13);
                            }
                            
                            // Hapus marker lama jika ada
                            if (marker) {
                                peta.removeLayer(marker);
                            }
                            
                            // Tambahkan marker baru
                            marker = L.marker([lat, lng]).addTo(peta)
                                .bindPopup('Lokasi Anda saat ini').openPopup();
                            
                            // Reverse geocoding untuk mendapatkan nama lokasi
                            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                                .then(response => response.json())
                                .then(data => {
                                    let lokasiName = data.address.city || data.address.town || data.address.village || '';
                                    if (data.address.state) {
                                        lokasiName += lokasiName ? ', ' + data.address.state : data.address.state;
                                    }
                                    
                                    if (lokasiName) {
                                        lokasiInput.value = lokasiName;
                                    }
                                    
                                    document.getElementById('deteksi-lokasi').innerHTML = '<i class="fas fa-location-arrow"></i> Deteksi Otomatis';
                                    document.getElementById('deteksi-lokasi').disabled = false;
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    document.getElementById('deteksi-lokasi').innerHTML = '<i class="fas fa-location-arrow"></i> Deteksi Otomatis';
                                    document.getElementById('deteksi-lokasi').disabled = false;
                                });
                        },
                        function(error) {
                            console.error('Error getting location:', error);
                            alert('Gagal mendapatkan lokasi. Pastikan Anda mengizinkan akses lokasi.');
                            document.getElementById('deteksi-lokasi').innerHTML = '<i class="fas fa-location-arrow"></i> Deteksi Otomatis';
                            document.getElementById('deteksi-lokasi').disabled = false;
                        }
                    );
                } else {
                    alert('Browser Anda tidak mendukung geolocation.');
                }
            });
            
            // Sinkronkan input tanggal dengan hari ini jika kosong
            const today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(input => {
                if (!input.value) {
                    input.value = today;
                }
            });
        });
    </script>
</body>
</html>