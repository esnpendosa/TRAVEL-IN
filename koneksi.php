<?php
// Konfigurasi database
$host = 'localhost';      // biasanya localhost
$user = 'u102554178_ojek';           // ganti sesuai username database kamu
$pass = 'Mohas@d121203';               // isi dengan password database kamu, jika ada
$db   = 'u102554178_ojek';      // nama database yang sudah dibuat

// Membuat koneksi
$conn = new mysqli($host, $user, $pass, $db);

// Cek koneksi
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
} else {
    // echo "Koneksi berhasil"; // bisa diaktifkan untuk testing
}
?>
