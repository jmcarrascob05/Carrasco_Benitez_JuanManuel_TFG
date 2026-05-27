<?php
require_once 'config.php';
if (isLoggedIn()) redirect('/index.php');

$errores = [];
$campos  = [];

if (isPost()) {
    // Guardamos los campos en un array para poder reutilizarlos en el formulario.
    // Así, si hay un error, el usuario no pierde lo que ya había escrito.
    foreach (['nombre', 'apellidos', 'email', 'username'] as $campo) {
        $campos[$campo] = trim($_POST[$campo] ?? '');
    }

    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    // Validaciones básicas antes de tocar la base de datos.
    if (strlen($campos['nombre']) < 2 || strlen($campos['nombre']) > 50) {
        $errores['nombre'] = 'El nombre debe tener entre 2 y 50 caracteres.';
    }
    if (strlen($campos['apellidos']) < 2) {
        $errores['apellidos'] = 'Los apellidos deben tener mínimo 2 caracteres.';
    }
    if (!filter_var($campos['email'], FILTER_VALIDATE_EMAIL)) {
        $errores['email'] = 'Formato de email no válido.';
    }
    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $campos['username'])) {
        $errores['username'] = 'Debe tener 3-50 caracteres: letras, números o _.';
    }
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errores['password'] = 'Mínimo 8 caracteres, 1 mayúscula y 1 número.';
    }
    if ($password !== $password2) {
        $errores['password2'] = 'Las contraseñas no coinciden.';
    }

    // Si no hay errores de formato, comprobamos duplicados en BD.
    if (empty($errores)) {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT email, username FROM usuarios WHERE email = ? OR username = ?");
            $stmt->execute([$campos['email'], $campos['username']]);
            $existente = $stmt->fetch();
            if ($existente) {
                if ($existente['email'] === $campos['email'])
                    $errores['email'] = 'Este email ya está registrado.';
                else
                    $errores['username'] = 'Este nombre de usuario ya está en uso.';
            }
        } catch (Exception $e) {
            $errores['general'] = 'Error de conexión: ' . $e->getMessage();
        }
    }

    // Registrar si todo OK. La contraseña se guarda con hash, nunca en texto plano.
    if (empty($errores)) {
        try {
            $pdo  = $pdo ?? getDB();
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            try {
                $stmt = $pdo->prepare("INSERT INTO usuarios (nombre,apellidos,email,username,password,fecha_registro,id_rol,avatar_url) VALUES (?,?,?,?,?,?,2,NULL)");
                $stmt->execute([$campos['nombre'],$campos['apellidos'],$campos['email'],$campos['username'],$hash,date('Y-m-d')]);
            } catch (Exception $e) {
                $stmt = $pdo->prepare("INSERT INTO usuarios (nombre,apellidos,email,username,password,fecha_registro,id_rol) VALUES (?,?,?,?,?,?,2)");
                $stmt->execute([$campos['nombre'],$campos['apellidos'],$campos['email'],$campos['username'],$hash,date('Y-m-d')]);
            }
            $uid = $pdo->lastInsertId();
            $_SESSION['id_usuario'] = $uid;
            $_SESSION['username']   = $campos['username'];
            $_SESSION['nombre']     = $campos['nombre'];
            $_SESSION['id_rol']     = 2;
            $_SESSION['avatar_url'] = '';
            redirect('/index.php');
        } catch (Exception $e) {
            $errores['general'] = 'Error al crear la cuenta: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Registro';
include 'partials/header.php';
?>

<div class="container">
  <div class="form-card" style="max-width:540px">
    <h1>Crear cuenta</h1>
    <p>Únete a la comunidad RustyX</p>

    <?php if (!empty($errores['general'])): ?>
      <?php flashMessage('error', $errores['general']); ?>
    <?php endif; ?>

    <form method="POST" novalidate>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <!-- Nombre -->
        <div class="form-group">
          <label>Nombre</label>
          <input type="text" name="nombre"
                 value="<?= h($campos['nombre'] ?? '') ?>"
                 class="<?= isset($errores['nombre']) ? 'input-error' : '' ?>"
                 maxlength="50" required>
          <?php if (isset($errores['nombre'])): ?>
            <span class="field-error">⚠ <?= h($errores['nombre']) ?></span>
          <?php else: ?>
            <span class="field-hint">Entre 2 y 50 caracteres</span>
          <?php endif; ?>
        </div>

        <!-- Apellidos -->
        <div class="form-group">
          <label>Apellidos</label>
          <input type="text" name="apellidos"
                 value="<?= h($campos['apellidos'] ?? '') ?>"
                 class="<?= isset($errores['apellidos']) ? 'input-error' : '' ?>"
                 maxlength="80" required>
          <?php if (isset($errores['apellidos'])): ?>
            <span class="field-error">⚠ <?= h($errores['apellidos']) ?></span>
          <?php else: ?>
            <span class="field-hint">Mínimo 2 caracteres</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Email -->
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email"
               value="<?= h($campos['email'] ?? '') ?>"
               class="<?= isset($errores['email']) ? 'input-error' : '' ?>"
               maxlength="80" required>
        <?php if (isset($errores['email'])): ?>
          <span class="field-error">⚠ <?= h($errores['email']) ?></span>
        <?php else: ?>
          <span class="field-hint">Formato: usuario@dominio.com</span>
        <?php endif; ?>
      </div>

      <!-- Username -->
      <div class="form-group">
        <label>Nombre de usuario</label>
        <input type="text" name="username"
               value="<?= h($campos['username'] ?? '') ?>"
               class="<?= isset($errores['username']) ? 'input-error' : '' ?>"
               maxlength="50" required>
        <?php if (isset($errores['username'])): ?>
          <span class="field-error">⚠ <?= h($errores['username']) ?></span>
        <?php else: ?>
          <span class="field-hint">3–50 caracteres · solo letras, números y _</span>
        <?php endif; ?>
      </div>

      <!-- Contraseña -->
      <div class="form-group">
        <label>Contraseña</label>
        <input type="password" name="password"
               class="<?= isset($errores['password']) ? 'input-error' : '' ?>"
               minlength="8" required>
        <?php if (isset($errores['password'])): ?>
          <span class="field-error">⚠ <?= h($errores['password']) ?></span>
        <?php else: ?>
          <span class="field-hint">Mínimo 8 caracteres · 1 mayúscula · 1 número</span>
        <?php endif; ?>
      </div>

      <!-- Repetir contraseña -->
      <div class="form-group">
        <label>Repetir contraseña</label>
        <input type="password" name="password2"
               class="<?= isset($errores['password2']) ? 'input-error' : '' ?>"
               minlength="8" required>
        <?php if (isset($errores['password2'])): ?>
          <span class="field-error">⚠ <?= h($errores['password2']) ?></span>
        <?php else: ?>
          <span class="field-hint">Debe coincidir con la contraseña</span>
        <?php endif; ?>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem">
        Crear cuenta
      </button>
    </form>

    <div class="form-divider">¿Ya tienes cuenta?</div>
    <a href="<?= BASE_PATH ?>/login.php" class="btn btn-ghost"
       style="width:100%;justify-content:center">Iniciar sesión</a>
  </div>
</div>

<?php include 'partials/footer.php'; ?>
