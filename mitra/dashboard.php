<?php
session_start();
require '../koneksi.php';

if (!isset($_SESSION['user']) && $_SESSION['user']['level'] !== 'mitra') {
    header('Location: ../index.php');
}

// Ambil nama pengguna dari session jika tersedia
$namaPengguna = isset($_SESSION['user']['nama_lengkap']) ? $_SESSION['user']['nama_lengkap'] : 'Pengguna';
$inisial = strtoupper(substr($namaPengguna, 0, 1));



$user_id = $_SESSION['user']['id'];
$current_page = basename($_SERVER['PHP_SELF']);

// Get statistics for dashboard
if ($current_page == 'dashboard.php') {
    $stats = [
        'wisata' => getCount($koneksi, 'wisata', $user_id),
        'transportasi' => getCount($koneksi, 'transportasi', $user_id),
        'kuliner' => getCount($koneksi, 'kuliner', $user_id),
        'penginapan' => getCount($koneksi, 'penginapan', $user_id)
    ];
}

// Helper functions
function getCount($conn, $table, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM $table WHERE created_by = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

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

