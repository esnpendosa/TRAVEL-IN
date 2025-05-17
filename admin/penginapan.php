<?php
session_start();
require '../koneksi.php';

if (!isset($_SESSION['user']) && $_SESSION['user']['level'] !== 'admin') {
    header('Location: ../index.php');
}

// Ambil nama pengguna dari session jika tersedia
$namaPengguna = isset($_SESSION['user']['nama_lengkap']) ? $_SESSION['user']['nama_lengkap'] : 'Pengguna';
$inisial = strtoupper(substr($namaPengguna, 0, 1));

$query = "SELECT p.id, p.nama_penginapan, p.kategori, p.lokasi, p.harga_per_malam, p.fasilitas, p.gambar, p.gambar2, p.gambar3, p.gambar4, p.deskripsi, p.status, u.nama_lengkap AS mitra
          FROM penginapan p
          JOIN user u ON p.created_by = u.id
          WHERE p.status = 'pending'";
$result = $koneksi->query($query);

?>



<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validasi Penginapan - Rencana-IN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
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

        .alert {
            padding: 1rem;
            margin: 1.5rem 2rem 1rem;
            border-radius: 6px;
            font-size: 0.95rem;
            border-left: 5px solid;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background-color: rgba(76, 175, 80, 0.15);
            color: #2e7d32;
            border-color: #4CAF50;
        }

        .alert-error {
            background-color: rgba(220, 53, 69, 0.15);
            color: #c62828;
            border-color: #dc3545;
        }

        .table-container {
            background-color: #ffffff;
            margin: 2rem;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 0.125rem 0.75rem rgba(0, 0, 0, 0.1);
            overflow-x: auto;
        }

        /* Tombol umum */
        .btn {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            font-size: 0.875rem;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease;
            border: none;
            font-weight: 500;
            text-align: center;
            margin-bottom: 10px;
        }

        /* Tombol Approve */
        .btn-success {
            background-color: var(--primary);
            color: white;
        }

        .btn-success:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        /* Tombol Reject */
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
            transform: translateY(-1px);
        }

        /* Tabel Styling */
        .styled-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            margin-top: 1rem;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
        }

        .styled-table thead {
            background-color: var(--primary);
            color: white;
        }

        .styled-table thead th {
            padding: 0.75rem 1rem;
            text-align: left;
        }

        .styled-table tbody tr {
            border-bottom: 1px solid #f0f0f0;
        }

        .styled-table tbody tr:last-child {
            border-bottom: none;
        }

        .styled-table tbody td {
            padding: 0.75rem 1rem;
            color: #333;
        }

        .styled-table tbody tr:hover {
            background-color: #f9f9f9;
        }

        /* Badge Status */
        .badge-warning {
            background-color: #ffc107;
            color: #fff;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            border-radius: 5px;
            display: inline-block;
        }

        /* Styling untuk search input */
        .dataTables_wrapper .dataTables_filter {
            text-align: right;
            margin-bottom: 1rem;
        }

        .dataTables_wrapper .dataTables_filter input {
            padding: 6px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background-color: #f1f1f1;
            transition: border-color 0.3s ease;
        }

        .dataTables_wrapper .dataTables_filter input:focus {
            outline: none;
            border-color: var(--primary);
            background-color: white;
        }

        /* Styling untuk select jumlah data */
        .dataTables_wrapper .dataTables_length select {
            padding: 6px 10px;
            border-radius: 4px;
            border: 1px solid #ccc;
            background-color: #f1f1f1;
            margin-left: 5px;
        }

        /* Styling untuk info jumlah data */
        .dataTables_wrapper .dataTables_info {
            margin-top: 0.75rem;
            color: #666;
            font-size: 0.875rem;
        }

        /* Styling untuk pagination */
        .dataTables_wrapper .dataTables_paginate {
            margin-top: 1rem;
            text-align: right;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            background-color: white;
            color: var(--primary);
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 6px 10px;
            margin: 0 2px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background-color: var(--primary);
            color: white !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background-color: var(--primary);
            color: white !important;
            border: 1px solid var(--primary-dark);
            font-weight: bold;
        }

        /* Responsive wrap */
        @media (max-width: 768px) {
            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter,
            .dataTables_wrapper .dataTables_info,
            .dataTables_wrapper .dataTables_paginate {
                text-align: center;
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

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= $_SESSION['success_message'] ?>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error_message'] ?>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="table-container">
            <table id="tabel-penginapan" class="styled-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Gambar</th>
                        <th>Nama Penginapan</th>
                        <th>Nama Mitra</th>
                        <th>Kategori</th>
                        <th>Lokasi</th>
                        <th>Harga Per Malam</th>
                        <th>Fasilitas</th>
                        <th>Deskripsi</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <img src="../asset/penginapan/<?= htmlspecialchars($row["gambar"]); ?>" alt="Gambar Penginapan" width="75">
                            </td>
                            <td><?= htmlspecialchars($row['nama_penginapan']) ?></td>
                            <td><?= htmlspecialchars($row['mitra']) ?></td>
                            <td><?= htmlspecialchars($row['kategori']) ?></td>
                            <td><?= htmlspecialchars($row['lokasi']) ?></td>
                            <td style="white-space: nowrap;">Rp <?= number_format($row['harga_per_malam'], 0, ',', '.') ?></td>
                            <td><?= htmlspecialchars($row['fasilitas']) ?></td>
                            <td><?= htmlspecialchars($row['deskripsi']) ?></td>
                            <td>
                                <span class="badge badge-warning"><?= htmlspecialchars($row['status']) ?></span>
                            </td>
                            <td style="white-space: nowrap;">
                                <a href="validasi_penginapan.php?id=<?= $row['id'] ?>&action=approve" class="btn btn-success btn-approve">Approve</a>
                                <a href="validasi_penginapan.php?id=<?= $row['id'] ?>&action=reject" class="btn btn-danger btn-reject">Reject</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

    </div>


    <!-- jQuery dan DataTables JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
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

          // SweetAlert konfirmasi untuk tombol "Approve"
        document.querySelectorAll('.btn-approve').forEach(button => {
            button.addEventListener('click', function (e) {
                e.preventDefault(); // Mencegah pengalihan langsung
                const href = this.getAttribute('href'); // Ambil href dari tombol

                Swal.fire({
                    title: 'Konfirmasi Approve',
                    text: 'Apakah Anda yakin ingin menyetujui penginapan ini?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Ya, Setujui',
                    cancelButtonText: 'Batal'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Arahkan ke link jika dikonfirmasi
                        window.location.href = href;
                    }
                });
            });
        });

        // SweetAlert konfirmasi untuk tombol "Reject"
        document.querySelectorAll('.btn-reject').forEach(button => {
            button.addEventListener('click', function (e) {
                e.preventDefault(); // Mencegah pengalihan langsung
                const href = this.getAttribute('href'); // Ambil href dari tombol

                Swal.fire({
                    title: 'Konfirmasi Reject',
                    text: 'Apakah Anda yakin ingin menolak penginapan ini?',
                    icon: 'error',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Ya, Tolak',
                    cancelButtonText: 'Batal'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Arahkan ke link jika dikonfirmasi
                        window.location.href = href;
                    }
                });
            });
        });

    </script>

    <script>
        $(document).ready(function () {
            $('#tabel-penginapan').DataTable({
                pageLength: 10,
                order: [[0, 'desc']], // Kolom pertama (index 0) diurutkan secara menurun
                lengthMenu: [5, 10, 20, 50],
                language: {
                    search: "Cari:",
                    lengthMenu: "Tampilkan _MENU_ data per halaman",
                    info: "Menampilkan _START_ - _END_ dari _TOTAL_ data",
                    infoEmpty: "Tidak ada data tersedia",
                    infoFiltered: "(disaring dari _MAX_ total data)",
                    zeroRecords: "Tidak ditemukan hasil",
                    paginate: {
                        first: "Pertama",
                        last: "Terakhir",
                        next: "Berikutnya",
                        previous: "Sebelumnya"
                    }
                }
            });
        });
    </script>
</body>
</html>

