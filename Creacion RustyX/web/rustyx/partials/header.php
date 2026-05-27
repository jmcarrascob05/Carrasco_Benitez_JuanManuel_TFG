<?php
// partials/header.php
// Cabecera común: la incluyen todas las páginas para compartir menú, CSS y título.
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$bp = BASE_PATH;
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle ?? 'RustyX') ?> — RustyX</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="<?= $bp ?>/css/style.css">
</head>
<body>

<nav class="navbar">
  <a href="<?= $bp ?>/index.php" class="navbar-logo">
    <img src="<?= $bp ?>/assets/logo.png" alt="RustyX" onerror="this.style.display='none'">
    RUSTYX
  </a>

  <ul class="navbar-nav">
    <li><a href="<?= $bp ?>/index.php" class="<?= $currentPage === 'index' ? 'active' : '' ?>">Catálogo</a></li>
    <!-- Enlaces visibles solo para usuarios con sesión iniciada -->
    <?php if (isLoggedIn()): ?>
      <li><a href="<?= $bp ?>/mis-listas.php" class="<?= $currentPage === 'mis-listas' ? 'active' : '' ?>">Mis Listas</a></li>
      <li><a href="<?= $bp ?>/perfil.php" class="<?= $currentPage === 'perfil' ? 'active' : '' ?>">Perfil</a></li>
      <?php if (isAdmin()): ?>
        <!-- El panel admin solo aparece si el rol del usuario es administrador -->
        <li><a href="<?= $bp ?>/admin/index.php" class="<?= strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? 'active' : '' ?>">Admin</a></li>
      <?php endif; ?>
    <?php endif; ?>
  </ul>

  <div class="navbar-actions">
    <?php if (isLoggedIn()): ?>
      <a href="<?= $bp ?>/perfil.php" class="navbar-avatar-link">
        <?php
          // Si el usuario tiene avatar subido, se muestra; si no, se usa su inicial.
          $avatarFile = $user['avatar_url'] ?? '';
          $avatarPath = AVATAR_DIR . $avatarFile;
          if ($avatarFile && file_exists($avatarPath)):
        ?>
          <img src="<?= h(avatarUrl($avatarFile)) ?>"
               alt="Avatar" class="navbar-avatar-img">
        <?php else: ?>
          <div class="navbar-avatar-placeholder"><?= strtoupper(substr($user['username'] ?? 'U', 0, 1)) ?></div>
        <?php endif; ?>
        <span class="navbar-username"><?= h($user['username'] ?? '') ?></span>
      </a>
      <a href="<?= $bp ?>/logout.php" class="btn btn-ghost btn-sm">Salir</a>
    <?php else: ?>
      <a href="<?= $bp ?>/login.php" class="btn btn-outline btn-sm">Iniciar sesión</a>
      <a href="<?= $bp ?>/registro.php" class="btn btn-primary btn-sm">Registrarse</a>
    <?php endif; ?>
  </div>
</nav>
