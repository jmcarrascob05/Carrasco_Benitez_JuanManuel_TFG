<?php // Navegación común del panel de administración. $adminPage marca la pestaña activa. ?>
<div class="admin-nav">
  <a href="<?= BASE_PATH ?>/admin/index.php" class="<?= $adminPage === 'juegos' ? 'active' : '' ?>">Videojuegos</a>
  <a href="<?= BASE_PATH ?>/admin/usuarios.php" class="<?= $adminPage === 'usuarios' ? 'active' : '' ?>">Usuarios</a>
  <a href="<?= BASE_PATH ?>/admin/dashboard.php" class="<?= $adminPage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
  <a href="<?= BASE_PATH ?>/index.php">Sitio</a>
</div>
