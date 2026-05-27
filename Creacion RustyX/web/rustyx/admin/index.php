<?php
require_once '../config.php';
requireAdmin();

$pageTitle = 'Admin · Videojuegos';
$adminPage = 'juegos';
$ok = $error = '';

try {
    $pdo = getDB();

    if (isPost()) {
        $accion = postAction();

        // Crear y editar comparten casi los mismos campos del formulario.
        if ($accion === 'crear' || $accion === 'editar') {
            $titulo        = trim($_POST['titulo']        ?? '');
            $descripcion   = trim($_POST['descripcion']   ?? '');
            $fecha         = $_POST['fecha_lanzamiento']  ?: null;
            $desarrollador = trim($_POST['desarrollador'] ?? '');
            $precio        = (float)($_POST['precio']     ?? 0);
            $youtube       = trim($_POST['youtube_url']   ?? '');
            $estado        = $_POST['estado']             ?? 'disponible';
            $generos       = array_map('intval', $_POST['generos']    ?? []);
            $plataformas   = array_map('intval', $_POST['plataformas'] ?? []);

            if (!$titulo) { $error = 'El título es obligatorio.'; }
            else {
                // Gestionar subida de imagen de portada.
                $imagenUrl = $_POST['imagen_url_actual'] ?? null; // mantener la existente por defecto
                if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
                    $file    = $_FILES['imagen'];
                    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
                    $maxSize = 5 * 1024 * 1024; // 5MB
                    if (!in_array($file['type'], $allowed)) {
                        $error = 'Solo se permiten imágenes JPG, PNG, WebP o GIF.';
                    } elseif ($file['size'] > $maxSize) {
                        $error = 'La imagen no puede superar 5MB.';
                    } else {
                        $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        $filename  = 'game_' . time() . '_' . mt_rand(100,999) . '.' . $ext;
                        if (!is_dir(GAME_DIR)) @mkdir(GAME_DIR, 0775, true);
                        if (move_uploaded_file($file['tmp_name'], GAME_DIR . $filename)) {
                            $imagenUrl = GAME_URL . $filename;
                            // Borrar imagen anterior si existe
                            $anterior = $_POST['imagen_url_actual'] ?? '';
                            if ($anterior && str_starts_with($anterior, GAME_URL) && file_exists(GAME_DIR . basename($anterior))) {
                                @unlink(GAME_DIR . basename($anterior));
                            }
                        } else {
                            $error = 'No se pudo guardar la imagen. Verifica permisos en assets/games.';
                        }
                    }
                }

                if (!$error) {
                // Guardamos primero el juego y después actualizamos sus relaciones.
                if ($accion === 'crear') {
                    $stmt = $pdo->prepare("INSERT INTO videojuegos
                        (titulo,descripcion,fecha_lanzamiento,desarrollador,precio,youtube_url,estado,imagen_url)
                        VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->execute([$titulo,$descripcion,$fecha,$desarrollador,$precio,$youtube,$estado,$imagenUrl]);
                    $idJuego = $pdo->lastInsertId();
                    $ok = 'Juego creado.';
                } else {
                    $idJuego = (int)$_POST['id_juego'];
                    $pdo->prepare("UPDATE videojuegos SET
                        titulo=?,descripcion=?,fecha_lanzamiento=?,desarrollador=?,
                        precio=?,youtube_url=?,estado=?,imagen_url=? WHERE id_juego=?")
                        ->execute([$titulo,$descripcion,$fecha,$desarrollador,$precio,$youtube,$estado,$imagenUrl,$idJuego]);
                    $ok = 'Juego actualizado.';
                }
                // Las relaciones many-to-many se rehacen para reflejar los checkboxes.
                $pdo->prepare("DELETE FROM videojuego_genero    WHERE id_juego=?")->execute([$idJuego]);
                $pdo->prepare("DELETE FROM videojuego_plataforma WHERE id_juego=?")->execute([$idJuego]);
                $stmtG = $pdo->prepare("INSERT IGNORE INTO videojuego_genero    (id_juego,id_genero)    VALUES (?,?)");
                $stmtP = $pdo->prepare("INSERT IGNORE INTO videojuego_plataforma(id_juego,id_plataforma) VALUES (?,?)");
                foreach ($generos    as $gid) $stmtG->execute([$idJuego,$gid]);
                foreach ($plataformas as $pid) $stmtP->execute([$idJuego,$pid]);
                } // end if(!$error)
            }
        }

        // Eliminar juego desde la tabla de administración.
        if ($accion === 'eliminar') {
            $pdo->prepare("DELETE FROM videojuegos WHERE id_juego=?")->execute([(int)$_POST['id_juego']]);
            $ok = 'Juego eliminado.';
        }
    }

    // Listado completo para la tabla del panel.
    $juegos = $pdo->query("
        SELECT v.*,
               (SELECT COALESCE(AVG(puntuacion), 0)
                FROM valoraciones
                WHERE id_juego = v.id_juego) AS media_real,
               GROUP_CONCAT(DISTINCT g.nombre SEPARATOR ', ') AS generos,
               GROUP_CONCAT(DISTINCT p.nombre SEPARATOR ', ') AS plataformas
        FROM videojuegos v
        LEFT JOIN videojuego_genero vg  ON v.id_juego = vg.id_juego
        LEFT JOIN generos g             ON vg.id_genero = g.id_genero
        LEFT JOIN videojuego_plataforma vp ON v.id_juego = vp.id_juego
        LEFT JOIN plataformas p         ON vp.id_plataforma = p.id_plataforma
        GROUP BY v.id_juego ORDER BY v.id_juego DESC
    ")->fetchAll();

    $generosList    = $pdo->query("SELECT * FROM generos   ORDER BY nombre")->fetchAll();
    $plataformasList = $pdo->query("SELECT * FROM plataformas ORDER BY nombre")->fetchAll();

} catch (Exception $e) {
    $error = $e->getMessage(); $juegos = [];
}

$editJuego = null;
if (isset($_GET['editar'])) {
    // Si llega ?editar=ID, cargamos el juego para rellenar el formulario.
    $stmt = $pdo->prepare("SELECT * FROM videojuegos WHERE id_juego=?");
    $stmt->execute([(int)$_GET['editar']]);
    $editJuego = $stmt->fetch();
    if ($editJuego) {
        $editJuego['generos_ids'] = fetchColumnList(
            $pdo,
            "SELECT id_genero FROM videojuego_genero WHERE id_juego=?",
            [$editJuego['id_juego']]
        );
        $editJuego['plataformas_ids'] = fetchColumnList(
            $pdo,
            "SELECT id_plataforma FROM videojuego_plataforma WHERE id_juego=?",
            [$editJuego['id_juego']]
        );
    }
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

  <!-- Formulario -->
  <div class="rating-section" style="margin-bottom:1.5rem">
    <h3><?= $editJuego ? 'Editar juego' : 'Añadir nuevo juego' ?></h3>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="accion" value="<?= $editJuego ? 'editar' : 'crear' ?>">
      <?php if ($editJuego): ?>
        <input type="hidden" name="id_juego" value="<?= $editJuego['id_juego'] ?>">
      <?php endif; ?>

      <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:1rem">
        <div class="form-group">
          <label>Título *</label>
          <input type="text" name="titulo" value="<?= h($editJuego['titulo'] ?? '') ?>" required maxlength="100">
        </div>
        <div class="form-group">
          <label>Desarrollador</label>
          <input type="text" name="desarrollador" value="<?= h($editJuego['desarrollador'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Estado</label>
          <select name="estado">
            <?php foreach (['disponible','proximo','descontinuado'] as $est): ?>
              <option value="<?= $est ?>" <?= selected($editJuego['estado'] ?? 'disponible', $est) ?>>
                <?= ucfirst($est) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
        <div class="form-group">
          <label>Fecha lanzamiento</label>
          <input type="date" name="fecha_lanzamiento" value="<?= $editJuego['fecha_lanzamiento'] ?? '' ?>">
        </div>
        <div class="form-group">
          <label>Precio (€)</label>
          <input type="number" name="precio" value="<?= $editJuego['precio'] ?? '0' ?>" step="0.01" min="0">
        </div>
        <div class="form-group">
          <label>YouTube (ID o URL del tráiler)</label>
          <input type="text" name="youtube_url" value="<?= h($editJuego['youtube_url'] ?? '') ?>"
                 placeholder="ej: dQw4w9WgXcQ">
        </div>
      </div>

      <!-- Imagen portada -->
      <div class="form-group">
        <label>Imagen de portada</label>
        <?php if (!empty($editJuego['imagen_url'])): ?>
          <div style="margin-bottom:.5rem;display:flex;align-items:center;gap:.75rem">
            <img src="<?= h($editJuego['imagen_url']) ?>"
                 style="height:80px;width:auto;border-radius:var(--radius-sm);border:1px solid var(--border);object-fit:cover">
            <span style="font-size:.8rem;color:var(--muted)">Imagen actual — sube una nueva para reemplazarla</span>
          </div>
          <input type="hidden" name="imagen_url_actual" value="<?= h($editJuego['imagen_url']) ?>">
        <?php else: ?>
          <input type="hidden" name="imagen_url_actual" value="">
        <?php endif; ?>
        <input type="file" name="imagen" accept="image/jpeg,image/png,image/webp,image/gif"
               style="font-size:.85rem;padding:.3rem .5rem">
        <p style="font-size:.75rem;color:var(--muted);margin-top:.3rem">JPG, PNG, WebP o GIF · máx. 5MB</p>
      </div>

      <div class="form-group">
        <label>Descripción</label>
        <textarea name="descripcion" rows="3"><?= h($editJuego['descripcion'] ?? '') ?></textarea>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
        <div>
          <label style="display:block;font-size:.8rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem">Géneros</label>
          <div style="display:flex;flex-wrap:wrap;gap:.5rem">
            <?php foreach ($generosList as $g): ?>
              <label style="cursor:pointer;font-size:.85rem;display:flex;align-items:center;gap:.25rem">
                <input type="checkbox" name="generos[]" value="<?= $g['id_genero'] ?>"
                       <?= in_array($g['id_genero'], $editJuego['generos_ids'] ?? []) ? 'checked' : '' ?>
                       style="width:auto">
                <?= h($g['nombre']) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <label style="display:block;font-size:.8rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem">Plataformas</label>
          <div style="display:flex;flex-wrap:wrap;gap:.5rem">
            <?php foreach ($plataformasList as $p): ?>
              <label style="cursor:pointer;font-size:.85rem;display:flex;align-items:center;gap:.25rem">
                <input type="checkbox" name="plataformas[]" value="<?= $p['id_plataforma'] ?>"
                       <?= in_array($p['id_plataforma'], $editJuego['plataformas_ids'] ?? []) ? 'checked' : '' ?>
                       style="width:auto">
                <?= h($p['nombre']) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary"><?= $editJuego ? 'Guardar cambios' : 'Añadir juego' ?></button>
        <?php if ($editJuego): ?>
          <a href="<?= BASE_PATH ?>/admin/index.php" class="btn btn-ghost">Cancelar</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Tabla de juegos -->
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr><th>ID</th><th>Título</th><th>Desarrollador</th><th>Precio</th><th>Punt.</th><th>Estado</th><th>Géneros</th><th>Plataformas</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach ($juegos as $j): ?>
          <tr>
            <td style="font-family:var(--font-mono);color:var(--muted)"><?= $j['id_juego'] ?></td>
            <td><a href="<?= BASE_PATH ?>/juego.php?id=<?= $j['id_juego'] ?>"><?= h($j['titulo']) ?></a></td>
            <td><?= h($j['desarrollador'] ?? '—') ?></td>
            <td><?= money($j['precio']) ?></td>
            <td style="color:var(--cyan);font-family:var(--font-mono)"><?= $j['media_real'] > 0 ? number_format($j['media_real'],1) : '—' ?></td>
            <td><span class="tag"><?= $j['estado'] ?? 'disponible' ?></span></td>
            <td style="font-size:.8rem;color:var(--muted)"><?= h($j['generos'] ?? '—') ?></td>
            <td style="font-size:.8rem;color:var(--muted)"><?= h($j['plataformas'] ?? '—') ?></td>
            <td style="display:flex;gap:.4rem">
              <a href="<?= BASE_PATH ?>/admin/index.php?editar=<?= $j['id_juego'] ?>" class="btn btn-ghost btn-sm">Editar</a>
              <form method="POST" onsubmit="return confirm('¿Eliminar este juego?')">
                <input type="hidden" name="accion"   value="eliminar">
                <input type="hidden" name="id_juego" value="<?= $j['id_juego'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include '../partials/footer.php'; ?>
