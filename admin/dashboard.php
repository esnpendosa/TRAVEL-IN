<?php
session_start();
require '../koneksi.php';

if (!isset($_SESSION['user']) && $_SESSION['user']['level'] !== 'admin') {
    header('Location: ../index.php');
}

// Ambil nama pengguna dari session jika tersedia
$namaPengguna = isset($_SESSION['user']['nama_lengkap']) ? $_SESSION['user']['nama_lengkap'] : 'Pengguna';
$inisial = strtoupper(substr($namaPengguna, 0, 1));

// Ambil jumlah wisata yang masih pending validasi
$queryNotif = "SELECT COUNT(*) AS total FROM wisata WHERE status = 'pending'";
$resultNotif = mysqli_query($koneksi, $queryNotif);
$dataNotif = mysqli_fetch_assoc($resultNotif);
$jumlahPengajuanBaru = $dataNotif['total'];

// Ambil jumlah transportasi yang masih pending validasi
$queryNotif1 = "SELECT COUNT(*) AS total FROM transportasi WHERE status = 'pending'";
$resultNotif1 = mysqli_query($koneksi, $queryNotif1);
$dataNotif1 = mysqli_fetch_assoc($resultNotif1);
$jumlahPengajuanBaru1 = $dataNotif1['total'];

// Ambil jumlah kuliner yang masih pending validasi
$queryNotif2 = "SELECT COUNT(*) AS total FROM kuliner WHERE status = 'pending'";
$resultNotif2 = mysqli_query($koneksi, $queryNotif2);
$dataNotif2 = mysqli_fetch_assoc($resultNotif2);
$jumlahPengajuanBaru2 = $dataNotif2['total'];

// Ambil jumlah penginapan yang masih pending validasi
$queryNotif3 = "SELECT COUNT(*) AS total FROM penginapan WHERE status = 'pending'";
$resultNotif3 = mysqli_query($koneksi, $queryNotif3);
$dataNotif3 = mysqli_fetch_assoc($resultNotif3);
$jumlahPengajuanBaru3 = $dataNotif3['total'];



$current_page = basename($_SERVER['PHP_SELF']);

// Get statistics for dashboard
if ($current_page == 'dashboard.php') {
    $stats = [
        'wisata' => getFeaturedCount($koneksi, 'wisata'),
        'transportasi' => getFeaturedCount($koneksi, 'transportasi'),
        'kuliner' => getFeaturedCount($koneksi, 'kuliner'),
        'penginapan' => getFeaturedCount($koneksi, 'penginapan')
    ];
}

function getFeaturedCount($conn, $table) {
    $result = $conn->query("SELECT COUNT(*) as total FROM $table WHERE status='approved'");
    return $result->fetch_assoc()['total'];
}

// Total pengguna
$total_users = $koneksi->query("SELECT COUNT(*) as total FROM user")->fetch_assoc()['total'];

// Total pengguna per level
$jumlah_admin = $koneksi->query("SELECT COUNT(*) as total FROM user WHERE level = 'admin'")->fetch_assoc()['total'];
$jumlah_mitra = $koneksi->query("SELECT COUNT(*) as total FROM user WHERE level = 'mitra'")->fetch_assoc()['total'];
$jumlah_customer = $koneksi->query("SELECT COUNT(*) as total FROM user WHERE level = 'user'")->fetch_assoc()['total'];

// Fungsi ambil statistik dari tabel tertentu
function getStats($koneksi, $table) {
    return [
        'pending'   => $koneksi->query("SELECT COUNT(*) as total FROM $table WHERE status = 'pending'")->fetch_assoc()['total'],
        'approved'  => $koneksi->query("SELECT COUNT(*) as total FROM $table WHERE status = 'approved'")->fetch_assoc()['total'],
        'rejected'  => $koneksi->query("SELECT COUNT(*) as total FROM $table WHERE status = 'rejected'")->fetch_assoc()['total'],
    ];
}

// Ambil statistik semua jenis pengajuan
$stats_wisata        = getStats($koneksi, 'wisata');
$stats_transportasi  = getStats($koneksi, 'transportasi');
$stats_kuliner       = getStats($koneksi, 'kuliner');
$stats_penginapan    = getStats($koneksi, 'penginapan');

?>



<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Rencana-IN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ROOT VARIABEL UNTUK KONSISTENSI WARNA DAN UKURAN */
        :root {
            --primary: #4CAF50; /* Hijau utama */
            --primary-dark: #3d8b40; /* Versi gelap hijau */
            --secondary: #6c757d; /* Abu-abu sekunder */
            --light: #f8f9fa; /* Background terang */
            --dark: #343a40; /* Warna teks gelap */
            --white: #ffffff; /* Putih */
            --sidebar-width: 250px;
            --header-height: 60px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: #495057;
            overflow-x: hidden;
        }

        /* SIDEBAR STYLING */
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

        /* Dashboard Specific Styles */
        .content {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
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
        }
    </style>
</head>
<body>
    <?php include '../header/sidebar.php'; ?>

    <!-- Header Atas -->
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
                        <?php echo $inisial; ?>
                    </div>
                    <span><?php echo htmlspecialchars($namaPengguna); ?></span>
                    <i class="fas fa-caret-down ml-2"></i>
                </a>
            </div>
        </header>
        <!-- Notifikasi pengajuan baru -->
        <div style="padding: 1.5rem 2rem;">
            <?php if ($jumlahPengajuanBaru > 0): ?>
                <div style="background-color: rgba(255, 193, 7, 0.1); border-left: 5px solid #ffc107; padding: 1rem; border-radius: 5px; color: #856404; font-size: 0.95rem; margin-bottom: 10px;">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Pengajuan Baru!</strong> Terdapat <strong><?= $jumlahPengajuanBaru ?></strong> wisata yang menunggu validasi.
                    <a href="wisata.php" style="margin-left: 1rem; color: var(--primary); font-weight: bold; text-decoration: none;">
                        Lihat sekarang →
                    </a>
                </div>
            <?php else: ?>
                <div style="background-color: #e9f7ef; border-left: 5px solid var(--primary); padding: 1rem; border-radius: 5px; color: var(--primary-dark); font-size: 0.95rem; margin-bottom: 10px;">
                    <i class="fas fa-check-circle"></i>
                    Tidak ada pengajuan wisata baru saat ini.
                </div>
            <?php endif; ?>
            <?php if ($jumlahPengajuanBaru1 > 0): ?>
                <div style="background-color: rgba(255, 193, 7, 0.1); border-left: 5px solid #ffc107; padding: 1rem; border-radius: 5px; color: #856404; font-size: 0.95rem; margin-bottom: 10px;">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Pengajuan Baru!</strong> Terdapat <strong><?= $jumlahPengajuanBaru1 ?></strong> transportasi yang menunggu validasi.
                    <a href="transportasi.php" style="margin-left: 1rem; color: var(--primary); font-weight: bold; text-decoration: none;">
                        Lihat sekarang →
                    </a>
                </div>
            <?php else: ?>
                <div style="background-color: #e9f7ef; border-left: 5px solid var(--primary); padding: 1rem; border-radius: 5px; color: var(--primary-dark); font-size: 0.95rem; margin-bottom: 10px;">
                    <i class="fas fa-check-circle"></i>
                    Tidak ada pengajuan transportasi baru saat ini.
                </div>
            <?php endif; ?>
            <?php if ($jumlahPengajuanBaru2 > 0): ?>
                <div style="background-color: rgba(255, 193, 7, 0.1); border-left: 5px solid #ffc107; padding: 1rem; border-radius: 5px; color: #856404; font-size: 0.95rem; margin-bottom: 10px;">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Pengajuan Baru!</strong> Terdapat <strong><?= $jumlahPengajuanBaru2 ?></strong> kuliner yang menunggu validasi.
                    <a href="kuliner.php" style="margin-left: 1rem; color: var(--primary); font-weight: bold; text-decoration: none;">
                        Lihat sekarang →
                    </a>
                </div>
            <?php else: ?>
                <div style="background-color: #e9f7ef; border-left: 5px solid var(--primary); padding: 1rem; border-radius: 5px; color: var(--primary-dark); font-size: 0.95rem; margin-bottom: 10px;">
                    <i class="fas fa-check-circle"></i>
                    Tidak ada pengajuan kuliner baru saat ini.
                </div>
            <?php endif; ?>
            <?php if ($jumlahPengajuanBaru3 > 0): ?>
                <div style="background-color: rgba(255, 193, 7, 0.1); border-left: 5px solid #ffc107; padding: 1rem; border-radius: 5px; color: #856404; font-size: 0.95rem; margin-bottom: 10px;">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Pengajuan Baru!</strong> Terdapat <strong><?= $jumlahPengajuanBaru3 ?></strong> penginapan yang menunggu validasi.
                    <a href="penginapan.php" style="margin-left: 1rem; color: var(--primary); font-weight: bold; text-decoration: none;">
                        Lihat sekarang →
                    </a>
                </div>
            <?php else: ?>
                <div style="background-color: #e9f7ef; border-left: 5px solid var(--primary); padding: 1rem; border-radius: 5px; color: var(--primary-dark); font-size: 0.95rem; margin-bottom: 10px;">
                    <i class="fas fa-check-circle"></i>
                    Tidak ada pengajuan penginapan baru saat ini.
                </div>
            <?php endif; ?>
        </div>


        <div class="content">
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><i class="fas fa-umbrella-beach"></i> Wisata</h3>
                    <p class="stat-value"><?= $stats['wisata'] ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-bus"></i> Transportasi</h3>
                    <p class="stat-value"><?= $stats['transportasi'] ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-utensils"></i> Kuliner</h3>
                    <p class="stat-value"><?= $stats['kuliner'] ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-hotel"></i> Penginapan</h3>
                    <p class="stat-value"><?= $stats['penginapan'] ?></p>
                </div>
            </div>
        </div>

        <div class="content">
            <h1>Statistik Pengguna</h1>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><i class="fas fa-users"></i> Total Semua Users</h3>
                    <p class="stat-value"><?= $total_users ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-user-shield"></i> Total Admin</h3>
                    <p class="stat-value"><?= $jumlah_admin ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-handshake"></i> Total Mitra</h3>
                    <p class="stat-value"><?= $jumlah_mitra ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-user"></i> Total Customer</h3>
                    <p class="stat-value"><?= $jumlah_customer ?></p>
                </div>
            </div>
        </div>

        <div class="content">
            <h1>Statistik Pengajuan Mitra</h1>
            <h3>Wisata</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><i class="fas fa-users"></i> Pending</h3>
                    <p class="stat-value"><?= $stats_wisata['pending'] ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-user-shield"></i> Approved</h3>
                    <p class="stat-value"><?= $stats_wisata['approved'] ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-handshake"></i> Rejected</h3>
                    <p class="stat-value"><?= $stats_wisata['rejected'] ?></p>
                </div>
            </div>
            <h3>Transportasi</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><i class="fas fa-users"></i> Pending</h3>
                    <p class="stat-value"><?= $stats_transportasi['pending'] ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-user-shield"></i> Approved</h3>
                    <p class="stat-value"><?= $stats_transportasi['approved'] ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-handshake"></i> Rejected</h3>
                    <p class="stat-value"><?= $stats_transportasi['rejected'] ?></p>
                </div>
            </div>
            <h3>Kuliner</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><i class="fas fa-users"></i> Pending</h3>
                    <p class="stat-value"><?= $stats_kuliner['pending'] ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-user-shield"></i> Approved</h3>
                    <p class="stat-value"><?= $stats_kuliner['approved'] ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-handshake"></i> Rejected</h3>
                    <p class="stat-value"><?= $stats_kuliner['rejected'] ?></p>
                </div>
            </div>
            <h3>Penginapan</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><i class="fas fa-users"></i> Pending</h3>
                    <p class="stat-value"><?= $stats_penginapan['pending'] ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-user-shield"></i> Approved</h3>
                    <p class="stat-value"><?= $stats_penginapan['approved'] ?></p>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-handshake"></i> Rejected</h3>
                    <p class="stat-value"><?= $stats_penginapan['rejected'] ?></p>
                </div>
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
        });
    </script>
    <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
        // SweetAlert untuk button logout
          // Ambil semua elemen dengan class btn-logout
          document.querySelectorAll('.btn-logout').forEach(button => {
              button.addEventListener('click', function (e) {
                  e.preventDefault(); // Mencegah tautan langsung
                  const href = this.getAttribute('href'); // Ambil tautan href

                  Swal.fire({
                      title: 'Konfirmasi Logout',
                      text: "Apakah Anda yakin ingin logout?",
                      icon: 'warning',
                      showCancelButton: true,
                      confirmButtonColor: '#3085d6',
                      cancelButtonColor: '#d33',
                      confirmButtonText: 'Ya, Logout',
                      cancelButtonText: 'Batal'
                  }).then((result) => {
                      if (result.isConfirmed) {
                          // Arahkan ke tautan jika dikonfirmasi
                          window.location.href = href;
                      }
                  });
              });
          });
    </script>
</body>
</html>

