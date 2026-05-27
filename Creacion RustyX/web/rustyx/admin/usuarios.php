<?php
require_once '../config.php';
requireAdmin();

$pageTitle = 'Admin · Usuarios';
$adminPage = 'usuarios';
$ok = $error = '';

try {
    $pdo = getDB();

    if (isPost()) {
        $accion = postAction();

        // Permite cambiar el rol de otro usuario, pero no el del propio admin.
        if ($accion === 'cambiar_rol') {
            $idUsuario = (int)$_POST['id_usuario'];
            $idRol     = (int)$_POST['id_rol'];
            if ($idUsuario !== (int)$_SESSION['id_usuario']) {
                $pdo->prepare("UPDATE usuarios SET id_rol = ? WHERE id_usuario = ?")->execute([$idRol, $idUsuario]);
                $ok = 'Rol actualizado.';
            } else {
                $error = 'No puedes cambiar tu propio rol.';
            }
        }

        // También evitamos que el admin se borre a sí mismo.
        if ($accion === 'eliminar') {
            $idUsuario = (int)$_POST['id_usuario'];
            if ($idUsuario !== (int)$_SESSION['id_usuario']) {
                $pdo->prepare("DELETE FROM usuarios WHERE id_usuario = ?")->execute([$idUsuario]);
                $ok = 'Usuario eliminado.';
            } else {
                $error = 'No puedes eliminarte a ti mismo.';
            }
        }
    }

    // Listado de usuarios con contadores para tener contexto en el panel.
    $usuarios = $pdo->query("
        SELECT u.*, r.nombre_rol,
               (SELECT COUNT(*) FROM valoraciones v WHERE v.id_usuario = u.id_usuario) AS num_val,
               (SELECT COUNT(*) FROM comentarios  c WHERE c.id_usuario = u.id_usuario) AS num_com
        FROM usuarios u JOIN roles r ON u.id_rol = r.id_rol
        ORDER BY u.fecha_registro DESC
    ")->fetchAll();

    $roles = $pdo->query("SELECT * FROM roles")->fetchAll();

} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
    $usuarios = [];
}

include '../partials/header.php';
?>

<div class="container page-content">
  <div class="section-header">
    <h2 class="section-title">Panel de <span>Administración</span></h2>
  </div>

  <?php include '../partials/admin-nav.php'; ?>

  <?php if ($error) flashMessage('error', $error); ?>
  <?php if ($ok) flashMessage('success', $ok); ?>

  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr><th>ID</th><th>Usuario</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Valoraciones</th><th>Comentarios</th><th>Registro</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach ($usuarios as $u): ?>
          <tr>
            <td style="font-family:var(--font-mono);color:var(--muted)"><?= $u['id_usuario'] ?></td>
            <td style="font-weight:700;color:var(--cyan)">@<?= h($u['username']) ?></td>
            <td><?= h($u['nombre'] . ' ' . $u['apellidos']) ?></td>
            <td style="font-size:.8rem"><?= h($u['email']) ?></td>
            <td>
              <form method="POST" style="display:flex;align-items:center;gap:.3rem">
                <input type="hidden" name="accion"     value="cambiar_rol">
                <input type="hidden" name="id_usuario" value="<?= $u['id_usuario'] ?>">
                <select name="id_rol" style="font-size:.8rem;padding:.2rem .4rem">
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= $r['id_rol'] ?>" <?= $r['id_rol'] == $u['id_rol'] ? 'selected' : '' ?>>
                      <?= h($r['nombre_rol']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if ($u['id_usuario'] != $_SESSION['id_usuario']): ?>
                  <button type="submit" class="btn btn-ghost btn-sm" style="padding:.2rem .5rem">✓</button>
                <?php else: ?>
                  <span style="color:var(--muted);font-size:.8rem">Tú</span>
                <?php endif; ?>
              </form>
            </td>
            <td style="text-align:center;font-family:var(--font-mono)"><?= $u['num_val'] ?></td>
            <td style="text-align:center;font-family:var(--font-mono)"><?= $u['num_com'] ?></td>
            <td style="font-size:.8rem;color:var(--muted)"><?= dateEs($u['fecha_registro']) ?></td>
            <td>
              <?php if ($u['id_usuario'] != $_SESSION['id_usuario']): ?>
                <form method="POST" onsubmit="return confirm('¿Eliminar usuario y todos sus datos?')">
                  <input type="hidden" name="accion"     value="eliminar">
                  <input type="hidden" name="id_usuario" value="<?= $u['id_usuario'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                </form>
              <?php else: ?>
                <span style="color:var(--muted);font-size:.8rem">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include '../partials/footer.php'; ?>
