<?php
require_once 'config.php';

if (isLoggedIn()) redirect('/index.php');

$error = '';

if (isPost()) {
    // El login permite entrar con email o con nombre de usuario.
    $login    = trim($_POST['login']    ?? '');
    $password = $_POST['password']      ?? '';

    if (!$login || !$password) {
        $error = 'Completa todos los campos.';
    } else {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? OR username = ? LIMIT 1");
            $stmt->execute([$login, $login]);
            $user = $stmt->fetch();

            // password_verify compara la contraseña escrita con el hash guardado.
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['id_usuario'] = $user['id_usuario'];
                $_SESSION['username']   = $user['username'];
                $_SESSION['nombre']     = $user['nombre'];
                $_SESSION['id_rol']     = $user['id_rol'];
                $_SESSION['avatar_url'] = $user['avatar_url'] ?? '';
                redirect('/index.php');
            } else {
                $error = 'Usuario o contraseña incorrectos.';
            }
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Iniciar sesión';
include 'partials/header.php';
?>

<div class="container">
  <div class="form-card">
    <h1>Bienvenido</h1>
    <p>Inicia sesión en tu cuenta RustyX</p>

    <?php if ($error): ?>
      <?php flashMessage('error', $error); ?>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label>Email o nombre de usuario</label>
        <input type="text" name="login"
               value="<?= h($_POST['login'] ?? '') ?>"
               required autofocus>
      </div>
      <div class="form-group">
        <label>Contraseña</label>
        <input type="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem">
        Iniciar sesión
      </button>
    </form>

    <div class="form-divider">¿No tienes cuenta?</div>
    <a href="<?= BASE_PATH ?>/registro.php" class="btn btn-ghost"
       style="width:100%;justify-content:center">Registrarse gratis</a>
  </div>
</div>

<?php include 'partials/footer.php'; ?>
