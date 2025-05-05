<?php
session_start();
require_once 'koneksi.php';

// Proses Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password']) && !isset($_POST['nama_lengkap'])) {
  if (empty($_POST['username']) || empty($_POST['password'])) {
    header('Location: index.php?error=Harap isi semua kolom');
    exit;
  }

  $username = trim($_POST['username']);
  $password = $_POST['password'];

  $sql = "SELECT * FROM user WHERE username = ?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $username);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
    if (md5($password) === $user['password']) {
      $_SESSION['user'] = $user;
      switch($user['level']) {
        case 'admin': header('Location: admin/dashboard.php'); break;
        case 'mitra': header('Location: mitra/dashboard.php'); break;
        default: header('Location: user/dashboard.php');
      }
      exit;
    }
  }
  header('Location: index.php?error=Username atau password salah');
  exit;
}

// Proses Registrasi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nama_lengkap'])) {
  $username = trim($_POST['username']);
  $password = md5($_POST['password']);
  $nama_lengkap = trim($_POST['nama_lengkap']);
  $email = trim($_POST['email']);
  $level = $_POST['level'];

  if (!$username || !$password || !$nama_lengkap || !$email || !$level) {
    header('Location: index.php?error=Semua kolom harus diisi');
    exit;
  }

  $check = $conn->prepare("SELECT id FROM user WHERE username = ?");
  $check->bind_param("s", $username);
  $check->execute();
  $checkResult = $check->get_result();

  if ($checkResult->num_rows > 0) {
    header('Location: index.php?error=Username sudah digunakan');
    exit;
  }

  $insert = $conn->prepare("INSERT INTO user (username, password, nama_lengkap, email, level) VALUES (?, ?, ?, ?, ?)");
  $insert->bind_param("sssss", $username, $password, $nama_lengkap, $email, $level);

  if ($insert->execute()) {
    header('Location: index.php?error=Pendaftaran berhasil, silakan login');
    exit;
  } else {
    header('Location: index.php?error=Terjadi kesalahan, coba lagi');
    exit;
  }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rencana In - Masuk & Daftar</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* Deklarasi variabel warna utama untuk konsistensi tema */
    :root {
      --primary: #4CAF50;
      --primary-dark: #388E3C;
      --secondary: #FFC107;
      --light: #F5F5F5;
      --dark: #212121;
      --gray: #757575;
      --error: #F44336;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: 'Poppins', sans-serif;
    }

    body {
      background-color: var(--light);
      color: var(--dark);
      line-height: 1.6;
    }

    .container {
      display: flex;
      min-height: 100vh;
    }

    /* Bagian gambar dan teks promosi sebelah kiri */
    .hero-section {
      flex: 1;
      background: linear-gradient(rgba(0, 0, 0, 0.5), rgba(0, 0, 0, 0.5)), 
                  url('asset/login.jpg') center/cover no-repeat;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      color: white;
      padding: 2rem;
      text-align: center;
    }

    .hero-content {
      max-width: 500px;
    }

    .hero-section h1 {
      font-size: 2.5rem;
      margin-bottom: 1rem;
    }

    .hero-section p {
      font-size: 1.1rem;
      opacity: 0.9;
    }

    /* Form login dan register */
    .form-section {
      width: 450px;
      padding: 3rem 2rem;
      display: flex;
      flex-direction: column;
      justify-content: center;
      background-color: white;
      box-shadow: -5px 0 15px rgba(0, 0, 0, 0.1);
    }

    .logo {
      width: 180px;
      margin: 0 auto 2rem;
      display: block;
    }

    .form-container {
      width: 100%;
    }

    .form-title {
      text-align: center;
      margin-bottom: 2rem;
      color: var(--primary);
      font-weight: 600;
    }

    /* Pesan kesalahan */
    .alert {
      padding: 0.75rem 1rem;
      margin-bottom: 1.5rem;
      border-radius: 4px;
      font-size: 0.9rem;
      display: none;
    }

    .alert-error {
      background-color: #FFEBEE;
      color: var(--error);
      border-left: 4px solid var(--error);
    }

    .form-group {
      margin-bottom: 1.5rem;
    }

    .form-group label {
      display: block;
      margin-bottom: 0.5rem;
      font-weight: 500;
      color: var(--dark);
    }

    .form-control {
      width: 100%;
      padding: 0.75rem 1rem;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 1rem;
      transition: border 0.3s;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2);
    }

    .btn {
      display: block;
      width: 100%;
      padding: 0.75rem;
      border: none;
      border-radius: 4px;
      font-size: 1rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.3s;
    }

    .btn-primary {
      background-color: var(--primary);
      color: white;
    }

    .btn-primary:hover {
      background-color: var(--primary-dark);
    }

    .form-footer {
      text-align: center;
      margin-top: 1.5rem;
      font-size: 0.9rem;
      color: var(--gray);
    }

    .form-footer a {
      color: var(--primary);
      text-decoration: none;
      font-weight: 500;
    }

    .form-footer a:hover {
      text-decoration: underline;
    }

    .form-toggle {
      display: none;
      animation: fadeIn 0.5s;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .active-form {
      display: block;
    }

    @media (max-width: 992px) {
      .container {
        flex-direction: column;
      }
      .hero-section {
        display: none;
      }
      .form-section {
        width: 100%;
        max-width: 500px;
        margin: 0 auto;
        box-shadow: none;
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="hero-section">
      <div class="hero-content">
        <h1>Selamat Datang di Rencana - In</h1>
        <p>Temukan pengalaman wisata menakjubkan dan pesan petualangan berikutnya bersama kami. Gabung dan jelajahi dunia.</p>
      </div>
    </div>

    <div class="form-section">
      <img src="asset/logo-travel.png" alt="Logo Travel In" class="logo">
      <div class="form-container">
        <?php if (isset($_GET['error'])): ?>
          <div class="alert alert-error" id="alert-box">
            <?php echo htmlspecialchars($_GET['error']); ?>
          </div>
          <script>document.getElementById('alert-box').style.display = 'block';</script>
        <?php endif; ?>

        <!-- Form Login -->
        <div id="login-form" class="form-toggle active-form">
          <h2 class="form-title">Masuk</h2>
          <form action="index.php" method="POST">
            <div class="form-group">
              <label for="login-username">Username</label>
              <input type="text" id="login-username" name="username" class="form-control" required>
            </div>
            <div class="form-group">
              <label for="login-password">Kata Sandi</label>
              <input type="password" id="login-password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Masuk</button>
          </form>
          <div class="form-footer">
            Belum punya akun? <a href="#" onclick="toggleForm('register')">Daftar di sini</a>
          </div>
        </div>

        <!-- Form Registrasi -->
        <div id="register-form" class="form-toggle">
          <h2 class="form-title">Buat Akun</h2>
          <form action="index.php" method="POST">
            <div class="form-group">
              <label for="reg-username">Username</label>
              <input type="text" id="reg-username" name="username" class="form-control" required>
            </div>
            <div class="form-group">
              <label for="reg-password">Kata Sandi</label>
              <input type="password" id="reg-password" name="password" class="form-control" required>
            </div>
            <div class="form-group">
              <label for="reg-nama">Nama Lengkap</label>
              <input type="text" id="reg-nama" name="nama_lengkap" class="form-control" required>
            </div>
            <div class="form-group">
              <label for="reg-email">Email</label>
              <input type="email" id="reg-email" name="email" class="form-control" required>
            </div>
            <div class="form-group">
              <label for="reg-level">Jenis Akun</label>
              <select id="reg-level" name="level" class="form-control" required>
                <option value="user">User</option>
                <option value="mitra">Agen Travel</option>
              </select>
            </div>
            <button type="submit" class="btn btn-primary">Daftar</button>
          </form>
          <div class="form-footer">
            Sudah punya akun? <a href="#" onclick="toggleForm('login')">Masuk di sini</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Script untuk mengganti tampilan antara form login dan register -->
  <script>
    function toggleForm(formType) {
      document.querySelectorAll('.form-toggle').forEach(form => {
        form.classList.remove('active-form');
      });
      document.getElementById(formType + '-form').classList.add('active-form');
      const alertBox = document.getElementById('alert-box');
      if (alertBox) alertBox.style.display = 'none';
    }
  </script>
</body>
</html>
