<?php
require_once 'config.php';
requireLogin('/login.php');

$uid = $_SESSION['id_usuario'];
$ok = $error = '';

try {
    $pdo = getDB();

    if (isPost()) {
        $accion = postAction();

        // Subida de avatar: validamos error, tamaño, tipo MIME real y permisos.
        if ($accion === 'avatar' && isset($_FILES['avatar'])) {
            $file    = $_FILES['avatar'];
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize = 2 * 1024 * 1024;

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $codes = [
                    UPLOAD_ERR_INI_SIZE   => 'El archivo supera el límite del servidor.',
                    UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el límite del formulario.',
                    UPLOAD_ERR_PARTIAL    => 'La subida fue interrumpida.',
                    UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Falta el directorio temporal.',
                    UPLOAD_ERR_CANT_WRITE => 'Error al escribir en disco.',
                ];
                $error = $codes[$file['error']] ?? 'Error al subir (código ' . $file['error'] . ').';
            } elseif ($file['size'] === 0) {
                $error = 'El archivo está vacío.';
            } else {
                $finfo    = finfo_open(FILEINFO_MIME_TYPE);
                $mimeReal = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mimeReal, $allowed)) {
                    $error = 'Solo JPG, PNG, GIF o WebP. Detectado: ' . $mimeReal;
                } elseif ($file['size'] > $maxSize) {
                    $error = 'La imagen no puede superar 2 MB.';
                } elseif (!is_dir(AVATAR_DIR) && !mkdir(AVATAR_DIR, 0755, true)) {
                    $error = 'No se pudo crear el directorio de avatares.';
                } elseif (!is_writable(AVATAR_DIR)) {
                    $error = 'Sin permisos de escritura en: ' . AVATAR_DIR;
                } else {
                    $extMap   = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                    $filename = 'avatar_' . $uid . '_' . time() . '.' . $extMap[$mimeReal];
                    $dest     = AVATAR_DIR . $filename;

                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        // Borramos el avatar anterior para no acumular imágenes viejas.
                        $old = $_SESSION['avatar_url'] ?? '';
                        if ($old && file_exists(AVATAR_DIR . basename($old))) {
                            unlink(AVATAR_DIR . basename($old));
                        }
                        $pdo->prepare("UPDATE usuarios SET avatar_url = ? WHERE id_usuario = ?")
                            ->execute([$filename, $uid]);
                        $_SESSION['avatar_url'] = $filename;
                        $ok = 'Foto de perfil actualizada.';
                    } else {
                        $error = 'No se pudo guardar la imagen en: ' . $dest;
                    }
                }
            }
        }

        // El usuario puede volver al avatar por defecto eliminando su foto.
        if ($accion === 'borrar_avatar') {
            $old = $_SESSION['avatar_url'] ?? '';
            if ($old && file_exists(AVATAR_DIR . basename($old))) {
                unlink(AVATAR_DIR . basename($old));
            }
            $pdo->prepare("UPDATE usuarios SET avatar_url = NULL WHERE id_usuario = ?")->execute([$uid]);
            $_SESSION['avatar_url'] = '';
            $ok = 'Foto de perfil eliminada.';
        }
    }

    // Datos principales del usuario y su rol.
    $stmt = $pdo->prepare("SELECT u.*, r.nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id_rol WHERE u.id_usuario = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    // Sincronizar avatar en sesión para que la barra superior se actualice.
    $_SESSION['avatar_url'] = $user['avatar_url'] ?? '';



    // Estadísticas rápidas que se muestran en las tarjetas del perfil.
    $stats = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM valoraciones WHERE id_usuario = ?) AS total_valoraciones,
            (SELECT COUNT(*) FROM comentarios  WHERE id_usuario = ?) AS total_comentarios,
            (SELECT COUNT(*) FROM lista        WHERE id_usuario = ?) AS total_listas,
            (SELECT AVG(puntuacion) FROM valoraciones WHERE id_usuario = ?) AS media_puntuacion
    ");
    $stats->execute([$uid, $uid, $uid, $uid]);
    $st = $stats->fetch();

    // Últimas valoraciones
    $stmtV = $pdo->prepare("SELECT v.puntuacion, v.fecha, j.titulo, j.id_juego FROM valoraciones v JOIN videojuegos j ON v.id_juego = j.id_juego WHERE v.id_usuario = ? ORDER BY v.fecha DESC LIMIT 10");
    $stmtV->execute([$uid]);
    $valoraciones = $stmtV->fetchAll();

    // Últimos comentarios
    $stmtC = $pdo->prepare("SELECT c.texto, c.fecha, j.titulo, j.id_juego FROM comentarios c JOIN videojuegos j ON c.id_juego = j.id_juego WHERE c.id_usuario = ? ORDER BY c.fecha DESC LIMIT 10");
    $stmtC->execute([$uid]);
    $comentarios = $stmtC->fetchAll();

} catch (Exception $e) {
    die("Error al cargar el perfil: " . $e->getMessage());
}

$avatarFile  = $_SESSION['avatar_url'] ?? '';
$tieneAvatar = $avatarFile && file_exists(AVATAR_DIR . basename($avatarFile));

$pageTitle = 'Mi Perfil';
include 'partials/header.php';
?>

<div class="container page-content">

  <?php if ($error) flashMessage('error', $error); ?>
  <?php if ($ok) flashMessage('success', $ok); ?>

  <div class="profile-header">
    <div class="avatar-upload-area">
      <?php if ($tieneAvatar): ?>
        <img src="<?= avatarUrl($avatarFile) ?>" alt="Avatar" class="avatar-img">
      <?php else: ?>
        <div class="avatar avatar-lg"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" style="margin-top:.5rem;text-align:center">
        <input type="hidden" name="accion" value="avatar">
        <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp"
               style="font-size:.78rem;padding:.3rem .5rem;margin-bottom:.4rem;display:block">
        <button type="submit" class="btn btn-ghost btn-sm">Subir foto</button>
      </form>

      <?php if ($tieneAvatar): ?>
        <form method="POST" style="margin-top:.25rem">
          <input type="hidden" name="accion" value="borrar_avatar">
          <button type="submit" class="btn btn-ghost btn-sm"
                  style="color:var(--danger);border-color:var(--danger)">Quitar foto</button>
        </form>
      <?php endif; ?>
    </div>

    <div>
      <h2><?= h($user['nombre'] . ' ' . $user['apellidos']) ?></h2>
      <p>@<?= h($user['username']) ?> · <?= h($user['email']) ?></p>
      <p style="margin-top:.4rem">
        <span class="tag tag-genre"><?= h($user['nombre_rol']) ?></span>

      </p>
    </div>
  </div>

  <div class="stats-grid">
    <?php
    $statsData = [
        ['Valoraciones', $st['total_valoraciones'], '⭐'],
        ['Comentarios',  $st['total_comentarios'],  '💬'],
        ['Listas',       $st['total_listas'],       '📋'],
        ['Media dada',   $st['media_puntuacion'] ? number_format($st['media_puntuacion'],1).'/10' : '—', '📊'],
    ];
    foreach ($statsData as [$label, $valor, $icon]):
    ?>
    <div class="stat-card">
      <div class="stat-icon"><?= $icon ?></div>
      <div class="stat-value"><?= $valor ?></div>
      <div class="stat-label"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">
    <div>
      <h3 style="font-size:1rem;font-weight:700;margin-bottom:1rem">Últimas valoraciones</h3>
      <?php if (empty($valoraciones)): ?>
        <div class="empty-state" style="padding:1.5rem"><div class="icon">⭐</div><h3>Sin valoraciones</h3></div>
      <?php else: ?>
        <div class="table-wrapper">
          <table class="data-table">
            <thead><tr><th>Juego</th><th>Punt.</th><th>Fecha</th></tr></thead>
            <tbody>
              <?php foreach ($valoraciones as $v): ?>
                <tr>
                  <td><a href="<?= BASE_PATH ?>/juego.php?id=<?= $v['id_juego'] ?>"><?= h($v['titulo']) ?></a></td>
                  <td style="color:var(--cyan);font-family:var(--font-mono);font-weight:700"><?= $v['puntuacion'] ?>/10</td>
                  <td style="color:var(--muted);font-size:.8rem"><?= date('d/m/Y', strtotime($v['fecha'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div>
      <h3 style="font-size:1rem;font-weight:700;margin-bottom:1rem">Últimos comentarios</h3>
      <?php if (empty($comentarios)): ?>
        <div class="empty-state" style="padding:1.5rem"><div class="icon">💬</div><h3>Sin comentarios</h3></div>
      <?php else: ?>
        <div class="comments-list">
          <?php foreach (array_slice($comentarios, 0, 5) as $c): ?>
            <div class="comment-item">
              <div class="comment-header">
                <a href="<?= BASE_PATH ?>/juego.php?id=<?= $c['id_juego'] ?>" style="font-size:.875rem;font-weight:700">
                  <?= h($c['titulo']) ?>
                </a>
                <span class="comment-date"><?= date('d/m/Y', strtotime($c['fecha'])) ?></span>
              </div>
              <p class="comment-text" style="font-size:.85rem">
                <?= h(shortText($c['texto'])) ?>
              </p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include 'partials/footer.php'; ?>
