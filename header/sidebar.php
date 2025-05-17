<?php
$current_page = basename($_SERVER['PHP_SELF']); // Mengambil nama file halaman saat ini

?>

<?php if( $_SESSION['user']['level'] == 'admin' ) : ?>
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
                <a href="../admin/dashboard.php" class="nav-link <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../admin/wisata.php" class="nav-link <?php echo ($current_page == 'wisata.php') ? 'active' : ''; ?>">
                    <i class="fas fa-umbrella-beach"></i>
                    <span>Wisata</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../admin/transportasi.php" class="nav-link <?php echo ($current_page == 'transportasi.php') ? 'active' : ''; ?>">
                    <i class="fas fa-bus"></i>
                    <span>Transportasi</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../admin/kuliner.php" class="nav-link <?php echo ($current_page == 'kuliner.php') ? 'active' : ''; ?>">
                    <i class="fas fa-utensils"></i>
                    <span>Kuliner</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../admin/penginapan.php" class="nav-link <?php echo ($current_page == 'penginapan.php') ? 'active' : ''; ?>">
                    <i class="fas fa-hotel"></i>
                    <span>Penginapan</span>
                </a>
            </li>
            <!-- <li class="nav-item">
                <a href="#" class="nav-link">
                    <i class="fas fa-map-marked-alt"></i>
                    <span>Trip Saya</span>
                </a>
            </li> -->
            <li class="nav-item">
                <a href="#" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../logout.php" class="nav-link btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>
</div>
<?php elseif( $_SESSION['user']['level'] == 'mitra' ) : ?>
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
                <a href="../mitra/dashboard.php" class="nav-link <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../mitra/wisata.php" class="nav-link <?php echo ($current_page == 'wisata.php' || $current_page == 'sukses.php') ? 'active' : ''; ?>">
                    <i class="fas fa-umbrella-beach"></i>
                    <span>Wisata</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../mitra/transportasi.php" class="nav-link <?php echo ($current_page == 'transportasi.php') ? 'active' : ''; ?>">
                    <i class="fas fa-bus"></i>
                    <span>Transportasi</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../mitra/kuliner.php" class="nav-link <?php echo ($current_page == 'kuliner.php') ? 'active' : ''; ?>">
                    <i class="fas fa-utensils"></i>
                    <span>Kuliner</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../mitra/penginapan.php" class="nav-link <?php echo ($current_page == 'penginapan.php') ? 'active' : ''; ?>">
                    <i class="fas fa-hotel"></i>
                    <span>Penginapan</span>
                </a>
            </li>
            <!-- <li class="nav-item">
                <a href="#" class="nav-link">
                    <i class="fas fa-map-marked-alt"></i>
                    <span>Trip Saya</span>
                </a>
            </li> -->
            <li class="nav-item">
                <a href="#" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../logout.php" class="nav-link btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>
</div>
<?php endif; ?>
