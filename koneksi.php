<?php
// Konfigurasi database
$host = 'localhost';      // biasanya localhost
$user = 'root';           // ganti sesuai username database kamu
$pass = '';               // isi dengan password database kamu, jika ada
$db   = 'travel_in';      // nama database yang sudah dibuat

// Membuat koneksi
$conn = new mysqli($host, $user, $pass, $db);

// Cek koneksi
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
} else {
    // echo "Koneksi berhasil"; // bisa diaktifkan untuk testing
}
?>
