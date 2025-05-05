<?php
session_start();
require '../koneksi.php';

// Ambil nama pengguna dari session jika tersedia
$namaPengguna = isset($_SESSION['user']['nama_lengkap']) ? $_SESSION['user']['nama_lengkap'] : 'Pengguna';
$inisial = strtoupper(substr($namaPengguna, 0, 1));
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
                    <a href="#" class="nav-link active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="fas fa-umbrella-beach"></i>
                        <span>Wisata</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="fas fa-bus"></i>
                        <span>Transportasi</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="fas fa-utensils"></i>
                        <span>Kuliner</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="fas fa-hotel"></i>
                        <span>Penginapan</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="fas fa-map-marked-alt"></i>
                        <span>Trip Saya</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

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
</body>
</html>
