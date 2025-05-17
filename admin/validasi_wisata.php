<?php
require '../koneksi.php';
require '../vendor/autoload.php'; // jika pakai Composer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_start();

if (!isset($_SESSION['user']) || $_SESSION['user']['level'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

if (isset($_GET['id'], $_GET['action'])) {
    $id = (int) $_GET['id'];
    $action = $_GET['action'];

    if ($action === 'approve' || $action === 'reject') {
        $status = ($action === 'approve') ? 'approved' : 'rejected';

        // Ambil data wisata termasuk id mitra
        $query = $koneksi->prepare("SELECT w.nama_wisata, u.email FROM wisata w 
                                    JOIN user u ON w.created_by = u.id WHERE w.id = ?");
        $query->bind_param("i", $id);
        $query->execute();
        $query->bind_result($nama_wisata, $email_mitra);
        $query->fetch();
        $query->close();

        // Update status
        $stmt = $koneksi->prepare("UPDATE wisata SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $id);

        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Wisata berhasil " . ucfirst($status) . ".";

            // Kirim Email
            $subject = "Status Pengajuan Wisata: " . ucfirst($status);
            $message = "Pengajuan wisata <b>$nama_wisata</b> telah <b>$status</b> oleh admin.";

            sendEmailNotification($email_mitra, $subject, $message);
        } else {
            $_SESSION['error_message'] = "Gagal memperbarui status wisata: " . $stmt->error;
        }

        $stmt->close();
    } else {
        $_SESSION['error_message'] = "Aksi tidak valid.";
    }
} else {
    $_SESSION['error_message'] = "ID wisata atau aksi tidak ditemukan.";
}

header('Location: wisata.php');
exit;

// Fungsi untuk mengirim email
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

        // Konten Email
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
    } catch (Exception $e) {
        // Log error atau tampilkan pesan jika perlu
        error_log("Email gagal dikirim: " . $mail->ErrorInfo);
    }
}

