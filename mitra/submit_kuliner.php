<?php
session_start();
require '../koneksi.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['level'] !== 'mitra') {
    header('Location: ../index.php');
    exit;
}

// Fungsi bantu validasi ekstensi gambar
function isAllowedImage($filename) {
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, $allowed);
}

// Validasi input
$nama_tempat     = trim($_POST['nama_tempat'] ?? '');
$kategori          = trim($_POST['kategori'] ?? '');
$menu_khas       = trim($_POST['menu_khas'] ?? '');
$lokasi        = trim($_POST['lokasi'] ?? '');
$harga     = trim($_POST['harga'] ?? '');
$jam_operasional = trim($_POST['jam_operasional'] ?? '');
$deskripsi = trim($_POST['deskripsi'] ?? '');
$created_by      = $_SESSION['user']['id'];

// Validasi wajib
if (empty($nama_tempat) || empty($kategori) || empty($menu_khas) || empty($lokasi) || 
    empty($harga) || empty($jam_operasional) || empty($deskripsi) || !isset($_FILES['gambar'])) {
    $_SESSION['error_message'] = "Semua data harus diisi.";
    header('Location: kuliner.php');
    exit;
}

$gambar = $_FILES['gambar'];
$gambar_size = $gambar['size'];
$max_size = 10 * 1024 * 1024; // 10MB

// Validasi ukuran gambar
if ($gambar_size > $max_size) {
    $_SESSION['error_message'] = "Ukuran file gambar maksimal 10MB.";
    header('Location: kuliner.php');
    exit;
}

// Validasi ekstensi gambar
if (!isAllowedImage($gambar['name'])) {
    $_SESSION['error_message'] = "Format gambar tidak valid. Hanya JPG, JPEG, PNG, dan WEBP.";
    header('Location: kuliner.php');
    exit;
}

// Simpan gambar ke direktori tujuan
$gambar_name = time() . '_' . basename($gambar['name']);
$upload_dir = '../asset/kuliner/';
$target_file = $upload_dir . $gambar_name;

if (!move_uploaded_file($gambar['tmp_name'], $target_file)) {
    $_SESSION['error_message'] = "Upload gambar gagal. Periksa ukuran atau format gambar.";
    header('Location: kuliner.php');
    exit;
}

// Simpan ke database
$stmt = $koneksi->prepare("INSERT INTO kuliner 
    (nama_tempat, kategori, lokasi, harga, deskripsi, jam_operasional, menu_khas, gambar, created_by, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");

if (!$stmt) {
    $_SESSION['error_message'] = "Gagal menyiapkan statement: " . $koneksi->error;
    header('Location: kuliner.php');
    exit;
}

$stmt->bind_param("sssissssi", 
    $nama_tempat, $kategori, $lokasi, $harga, $deskripsi, $jam_operasional, $menu_khas, $gambar_name, $created_by);

if ($stmt->execute()) {
    $_SESSION['success_message'] = "Pengajuan kuliner berhasil dikirim. Menunggu persetujuan admin.";
} else {
    $_SESSION['error_message'] = "Gagal menyimpan data ke database: " . $stmt->error;
}

$stmt->close();
header('Location: kuliner.php');
exit;
