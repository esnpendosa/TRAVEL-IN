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
$nama_layanan     = trim($_POST['nama_layanan'] ?? '');
$jenis          = trim($_POST['jenis'] ?? '');
$kapasitas       = intval(trim($_POST['kapasitas'] ?? ''));
$rute        = trim($_POST['rute'] ?? '');
$harga     = trim($_POST['harga'] ?? '');
$kontak = trim($_POST['kontak'] ?? '');
$created_by      = $_SESSION['user']['id'];
$deskripsi     = trim($_POST['nama_layanan'] ?? '');

// Ambil lokasi dari rute
$lokasi = explode('-', $rute)[0];
$lokasi = trim($lokasi);

// Validasi wajib
if (empty($nama_layanan) || empty($jenis) || empty($kapasitas) || empty($rute) || 
    empty($harga) || empty($kontak) || empty($deskripsi) || !isset($_FILES['gambar'])) {
    $_SESSION['error_message'] = "Semua data harus diisi.";
    header('Location: transportasi.php');
    exit;
}

$gambar = $_FILES['gambar'];
$gambar_size = $gambar['size'];
$max_size = 10 * 1024 * 1024; // 10MB

// Validasi ukuran gambar
if ($gambar_size > $max_size) {
    $_SESSION['error_message'] = "Ukuran file gambar maksimal 10MB.";
    header('Location: transportasi.php');
    exit;
}

// Validasi ekstensi gambar
if (!isAllowedImage($gambar['name'])) {
    $_SESSION['error_message'] = "Format gambar tidak valid. Hanya JPG, JPEG, PNG, dan WEBP.";
    header('Location: transportasi.php');
    exit;
}

// Simpan gambar ke direktori tujuan
$gambar_name = time() . '_' . basename($gambar['name']);
$upload_dir = '../asset/transportasi/';
$target_file = $upload_dir . $gambar_name;

if (!move_uploaded_file($gambar['tmp_name'], $target_file)) {
    $_SESSION['error_message'] = "Upload gambar gagal. Periksa ukuran atau format gambar.";
    header('Location: transportasi.php');
    exit;
}

// Simpan ke database
$stmt = $koneksi->prepare("INSERT INTO transportasi 
    (nama_layanan, jenis, kapasitas, rute, lokasi, harga, kontak, gambar, created_by, status, deskripsi)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");

if (!$stmt) {
    $_SESSION['error_message'] = "Gagal menyiapkan statement: " . $koneksi->error;
    header('Location: transportasi.php');
    exit;
}

$stmt->bind_param("ssissdssis", 
        $nama_layanan, $jenis, $kapasitas, $rute, $lokasi, $harga, $kontak, $gambar_name, 
        $created_by, $deskripsi);

if ($stmt->execute()) {
    $_SESSION['success_message'] = "Pengajuan transportasi berhasil dikirim. Menunggu persetujuan admin.";
} else {
    $_SESSION['error_message'] = "Gagal menyimpan data ke database: " . $stmt->error;
}

$stmt->close();
header('Location: transportasi.php');
exit;
