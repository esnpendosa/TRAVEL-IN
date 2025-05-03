<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - Travel In</title>
  <link href="https://cdn.jsdelivr.net/npm/@coreui/coreui@5.3.1/dist/css/coreui.min.css" rel="stylesheet" integrity="sha384-PDUiPu3vDllMfrUHnurV430Qg8chPZTNhY8RUpq89lq22R3PzypXQifBpcpE1eoB" crossorigin="anonymous">
  <style>
    body, html {
      height: 100%;
      margin: 0;
    }
    .login-container {
      height: 100vh;
      display: flex;
    }
    .login-image {
      background: url('asset/login.jpg') center center/cover no-repeat;
      width: 50%;
    }
    .login-form {
      width: 50%;
      padding: 3rem;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
    }
    .btn-success {
      background-color: #28a745 !important;
      border-color: #28a745 !important;
    }
    .form-control {
      margin-bottom: 1rem;
    }
  </style>
</head>
<body>

<div class="login-container">
  <div class="login-image d-none d-md-block"></div>

  <div class="login-form">
    <img src="asset/logo-travel.png" alt="Logo" style="width: 200px;">
    <p class="text-center mb-4">THE WORLD IS YOURS TO EXPLORE</p>

    <?php if (isset($_GET['error'])): ?>
      <div class="alert alert-danger" role="alert">
        Login gagal! Username atau password salah.
      </div>
    <?php endif; ?>

    <form action="logproses.php" method="POST" style="width: 100%; max-width: 600px;">
      <input type="text" name="username" class="form-control" placeholder="Email" required>
      <input type="password" name="password" class="form-control" placeholder="Password" required>
      <div class="form-check mb-3">
        <input type="checkbox" class="form-check-input" id="rememberMe">
        <label class="form-check-label" for="rememberMe">Remember me</label>
        <a href="#" class="float-end small">Forgot Your Password?</a>
      </div>
      <button type="submit" class="btn btn-success w-100 mb-2">LOGIN</button>
      <a href="register.php" class="btn btn-outline-success w-100">REGISTER AS AGENT</a>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@coreui/coreui@5.3.1/dist/js/coreui.bundle.min.js" integrity="sha384-8QmUFX1sl4cMveCP2+H1tyZlShMi1LeZCJJxTZeXDxOwQexlDdRLQ3O9L78gwBbe" crossorigin="anonymous"></script>
</body>
</html>
