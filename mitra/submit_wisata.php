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
$nama_wisata     = trim($_POST['nama_wisata'] ?? '');
$lokasi          = trim($_POST['lokasi'] ?? '');
$deskripsi       = trim($_POST['deskripsi'] ?? '');
$kategori        = trim($_POST['kategori'] ?? '');
$jam_operasional = trim($_POST['jam_operasional'] ?? '');
$harga_tiket     = trim($_POST['harga_tiket'] ?? '');
$created_by      = $_SESSION['user']['id'];

// Validasi wajib
if (empty($nama_wisata) || empty($lokasi) || empty($deskripsi) || empty($kategori) || 
    empty($jam_operasional) || empty($harga_tiket) || !isset($_FILES['gambar'])) {
    $_SESSION['error_message'] = "Semua data harus diisi.";
    header('Location: wisata.php');
    exit;
}

$gambar = $_FILES['gambar'];
$gambar_size = $gambar['size'];
$max_size = 10 * 1024 * 1024; // 10MB

// Validasi ukuran gambar
if ($gambar_size > $max_size) {
    $_SESSION['error_message'] = "Ukuran file gambar maksimal 10MB.";
    header('Location: wisata.php');
    exit;
}

// Validasi ekstensi gambar
if (!isAllowedImage($gambar['name'])) {
    $_SESSION['error_message'] = "Format gambar tidak valid. Hanya JPG, JPEG, PNG, dan WEBP.";
    header('Location: wisata.php');
    exit;
}

// Simpan gambar ke direktori tujuan
$gambar_name = time() . '_' . basename($gambar['name']);
$upload_dir = '../asset/wisata/';
$target_file = $upload_dir . $gambar_name;

if (!move_uploaded_file($gambar['tmp_name'], $target_file)) {
    $_SESSION['error_message'] = "Upload gambar gagal. Periksa ukuran atau format gambar.";
    header('Location: wisata.php');
    exit;
}

// Simpan ke database
$stmt = $koneksi->prepare("INSERT INTO wisata 
    (nama_wisata, kategori, lokasi, harga_tiket, jam_operasional, deskripsi, gambar, created_by, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");

if (!$stmt) {
    $_SESSION['error_message'] = "Gagal menyiapkan statement: " . $koneksi->error;
    header('Location: wisata.php');
    exit;
}

$stmt->bind_param("sssisssi", 
    $nama_wisata, $kategori, $lokasi, $harga_tiket, $jam_operasional, $deskripsi, $gambar_name, $created_by);

if ($stmt->execute()) {
    $_SESSION['success_message'] = "Pengajuan wisata berhasil dikirim. Menunggu persetujuan admin.";
} else {
    $_SESSION['error_message'] = "Gagal menyimpan data ke database: " . $stmt->error;
}

$stmt->close();
header('Location: wisata.php');
exit;
