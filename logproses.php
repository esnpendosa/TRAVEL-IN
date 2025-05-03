<?php
session_start();
require_once 'koneksi.php';

// Ambil input
$username = $_POST['username'];
$password = $_POST['password'];

// Cek user di database
$sql = "SELECT * FROM user WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();

    // Jika password pakai MD5
    if (md5($password) === $user['password']) {
        $_SESSION['user'] = $user;
        header('Location: header/sidebar.html');
        exit;
    } else {
        // Password salah
        header('Location: index.php?error=1');
        exit;
    }
} else {
    // Username tidak ditemukan
    header('Location: index.php?error=1');
    exit;
}
?>
