<?php
session_start();
require '../koneksi.php';
require '../vendor/autoload.php'; // pastikan PHPMailer sudah di-install via Composer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user']) || $_SESSION['user']['level'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

if (isset($_GET['id'], $_GET['action'])) {
    $id = (int) $_GET['id'];
    $action = $_GET['action'];

    if ($action === 'approve' || $action === 'reject') {
        $status = ($action === 'approve') ? 'approved' : 'rejected';

        // Ambil informasi transportasi dan email mitra
        $query = $koneksi->prepare("SELECT t.nama_layanan, u.email FROM transportasi t 
                                    JOIN user u ON t.created_by = u.id WHERE t.id = ?");
        $query->bind_param("i", $id);
        $query->execute();
        $query->bind_result($nama_transportasi, $email_mitra);
        $query->fetch();
        $query->close();

        // Update status di tabel
        $stmt = $koneksi->prepare("UPDATE transportasi SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $id);

        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Transportasi berhasil " . ucfirst($status) . ".";

            // Kirim email notifikasi ke mitra
            $subject = "Status Pengajuan Transportasi: " . ucfirst($status);
            $message = "Pengajuan transportasi <b>$nama_transportasi</b> telah <b>$status</b> oleh admin.";

            sendEmailNotification($email_mitra, $subject, $message);
        } else {
            $_SESSION['error_message'] = "Gagal memperbarui status transportasi: " . $stmt->error;
        }

        $stmt->close();
    } else {
        $_SESSION['error_message'] = "Aksi tidak valid.";
    }
} else {
    $_SESSION['error_message'] = "ID transportasi atau aksi tidak ditemukan.";
}

header('Location: transportasi.php');
exit;

// Fungsi untuk kirim email notifikasi
function sendEmailNotification($to, $subject, $body) {
    $mail = new PHPMailer(true);

    try {
        // Konfigurasi SMTP (gunakan sesuai SMTP provider kamu, contoh Gmail di bawah)
        $mail->SMTPDebug = 0; // Enable verbose debug output
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; 
        $mail->SMTPAuth   = true;
        $mail->Username   = 'm.aqiudin18@gmail.com'; // Ganti dengan email kamu
        $mail->Password   = 'dvzo beks vhpu giua';   // Gunakan App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Enable TLS encryption
        $mail->Port       = 465;

        // Pengirim & Penerima
        $mail->setFrom('m.aqiudin18@gmail.com', 'RENCANA - IN');
        $mail->addAddress($to);

        // Konten
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
    } catch (Exception $e) {
        error_log("Gagal mengirim email: " . $mail->ErrorInfo);
    }
}
