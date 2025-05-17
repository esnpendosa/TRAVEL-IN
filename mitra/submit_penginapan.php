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
$nama_penginapan     = trim($_POST['nama_penginapan'] ?? '');
$kategori        = trim($_POST['kategori'] ?? '');
$lokasi          = trim($_POST['lokasi'] ?? '');
$harga_per_malam     = trim($_POST['harga_per_malam'] ?? '');
$fasilitas = trim($_POST['fasilitas'] ?? '');
$deskripsi       = trim($_POST['deskripsi'] ?? '');
$created_by      = $_SESSION['user']['id'];

// Validasi wajib
if (empty($nama_penginapan) || empty($kategori) || empty($lokasi) || empty($harga_per_malam) || empty($fasilitas) || empty($deskripsi) || !isset($_FILES['gambar'])) {
    $_SESSION['error_message'] = "Semua data harus diisi.";
    header('Location: penginapan.php');
    exit;
}

// Fungsi untuk menangani upload gambar
function handleImageUpload($fileInputName) {
    if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] == 0) {
        $gambar = $_FILES[$fileInputName];
        $gambar_size = $gambar['size'];
        $max_size = 10 * 1024 * 1024; // 10MB

        // Validasi ukuran gambar
        if ($gambar_size > $max_size) {
            $_SESSION['error_message'] = "Ukuran file gambar maksimal 10MB.";
            header('Location: penginapan.php');
            exit;
        }

        // Validasi ekstensi gambar
        if (!isAllowedImage($gambar['name'])) {
            $_SESSION['error_message'] = "Format gambar tidak valid. Hanya JPG, JPEG, PNG, dan WEBP.";
            header('Location: penginapan.php');
            exit;
        }

        // Simpan gambar ke direktori tujuan
        $gambar_name = time() . '_' . basename($gambar['name']);
        $upload_dir = '../asset/penginapan/';
        $target_file = $upload_dir . $gambar_name;

        if (!move_uploaded_file($gambar['tmp_name'], $target_file)) {
            $_SESSION['error_message'] = "Upload gambar gagal. Periksa ukuran atau format gambar.";
            header('Location: penginapan.php');
            exit;
        }

        return $gambar_name;
    }
    return null; // Jika tidak ada gambar
}

// Ambil gambar utama
$gambar_name = handleImageUpload('gambar');

// Ambil gambar 2, gambar 3, gambar 4
$gambar2_name = handleImageUpload('gambar2');
$gambar3_name = handleImageUpload('gambar3');
$gambar4_name = handleImageUpload('gambar4');

// Jika gambar2, gambar3, gambar4 kosong, set null
if ($gambar2_name === null) $gambar2_name = null;
if ($gambar3_name === null) $gambar3_name = null;
if ($gambar4_name === null) $gambar4_name = null;

// Simpan ke database
$stmt = $koneksi->prepare("INSERT INTO penginapan 
    (nama_penginapan, kategori, lokasi, harga_per_malam, fasilitas, gambar, gambar2, gambar3, gambar4, deskripsi, created_by, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");

if (!$stmt) {
    $_SESSION['error_message'] = "Gagal menyiapkan statement: " . $koneksi->error;
    header('Location: penginapan.php');
    exit;
}

// Bind parameter untuk query
$stmt->bind_param("sssissssssi", 
    $nama_penginapan, $kategori, $lokasi, $harga_per_malam, $fasilitas, 
    $gambar_name, 
    $gambar2_name, 
    $gambar3_name, 
    $gambar4_name, 
    $deskripsi, $created_by);

// Eksekusi query
if ($stmt->execute()) {
    $_SESSION['success_message'] = "Pengajuan penginapan berhasil dikirim. Menunggu persetujuan admin.";
} else {
    $_SESSION['error_message'] = "Gagal menyimpan data ke database: " . $stmt->error;
}

$stmt->close();
header('Location: penginapan.php');
exit;


