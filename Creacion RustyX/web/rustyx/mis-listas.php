<?php
require_once 'config.php';
requireLogin('/login.php?redirect=/mis-listas.php');

$uid = $_SESSION['id_usuario'];
$error = '';
$ok = '';

try {
    $pdo = getDB();

    // POST: acciones sobre listas propias del usuario.
    if (isPost()) {
        $accion = postAction();

        // Crear una lista nueva asociada al usuario conectado.
        if ($accion === 'crear') {
            $nombre = trim($_POST['nombre_lista'] ?? '');
            if (strlen($nombre) >= 2 && strlen($nombre) <= 100) {
                $stmt = $pdo->prepare("INSERT INTO lista (id_usuario, nombre_lista) VALUES (?, ?)");
                $stmt->execute([$uid, $nombre]);
                $ok = 'Lista creada correctamente.';
            } else {
                $error = 'El nombre debe tener entre 2 y 100 caracteres.';
            }
        }

        // El DELETE filtra también por id_usuario para no borrar listas ajenas.
        if ($accion === 'eliminar') {
            $idLista = (int)$_POST['id_lista'];
            $stmt = $pdo->prepare("DELETE FROM lista WHERE id_lista = ? AND id_usuario = ?");
            $stmt->execute([$idLista, $uid]);
            $ok = 'Lista eliminada.';
        }

        // Cambia el estado de un juego dentro de una lista: pendiente, jugando, etc.
        if ($accion === 'cambiar_estado') {
            $idLista  = (int)$_POST['id_lista'];
            $idJuego  = (int)$_POST['id_videojuego'];
            $estado   = $_POST['estado'] ?? 'pendiente';
            $allowed  = ['pendiente','jugando','completado','abandonado'];
            if (in_array($estado, $allowed)) {
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM lista WHERE id_lista = ? AND id_usuario = ?");
                $stmtCheck->execute([$idLista, $uid]);
                if ($stmtCheck->fetchColumn()) {
                    $pdo->prepare("UPDATE lista_videojuego SET estado=? WHERE id_lista=? AND id_videojuego=?")
                        ->execute([$estado, $idLista, $idJuego]);
                    $ok = 'Estado actualizado.';
                }
            }
        }

        // Quita un juego de una lista, comprobando antes que la lista es del usuario.
        if ($accion === 'quitar_juego') {
            $idLista = (int)$_POST['id_lista'];
            $idJuego = (int)$_POST['id_videojuego'];
            // Verificar que la lista pertenece al usuario
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM lista WHERE id_lista = ? AND id_usuario = ?");
            $stmtCheck->execute([$idLista, $uid]);
            if ($stmtCheck->fetchColumn()) {
                $stmt = $pdo->prepare("DELETE FROM lista_videojuego WHERE id_lista = ? AND id_videojuego = ?");
                $stmt->execute([$idLista, $idJuego]);
                $ok = 'Juego eliminado de la lista.';
            }
        }
    }

    // Cargar listas con conteo de juegos para mostrar el panel lateral.
    $stmt = $pdo->prepare("
        SELECT l.*, COUNT(lv.id_videojuego) AS num_juegos
        FROM lista l
        LEFT JOIN lista_videojuego lv ON l.id_lista = lv.id_lista
        WHERE l.id_usuario = ?
        GROUP BY l.id_lista
        ORDER BY l.id_lista DESC
    ");
    $stmt->execute([$uid]);
    $listas = $stmt->fetchAll();

    // Si llega ?lista=ID, cargamos el contenido de esa lista.
    $listaActiva = null;
    $juegosLista = [];
    $idListaVer = (int)($_GET['lista'] ?? 0);

    if ($idListaVer) {
        $stmtL = $pdo->prepare("SELECT * FROM lista WHERE id_lista = ? AND id_usuario = ?");
        $stmtL->execute([$idListaVer, $uid]);
        $listaActiva = $stmtL->fetch();

        if ($listaActiva) {
            $stmtJ = $pdo->prepare("
                SELECT v.id_juego, v.titulo, v.desarrollador, v.precio, v.puntuacion_media,
                       (SELECT COALESCE(AVG(puntuacion), 0)
                        FROM valoraciones
                        WHERE id_juego = v.id_juego) AS media_real,
                       lv.fecha_agregado, lv.estado AS estado_lista
                FROM lista_videojuego lv
                JOIN videojuegos v ON lv.id_videojuego = v.id_juego
                WHERE lv.id_lista = ?
                ORDER BY lv.fecha_agregado DESC
            ");
            $stmtJ->execute([$idListaVer]);
            $juegosLista = $stmtJ->fetchAll();
        }
    }

} catch (Exception $e) {
    $error = 'Error al cargar las listas.';
    $listas = [];
}

$pageTitle = 'Mis Listas';
include 'partials/header.php';
?>

<div class="container page-content">
  <div class="section-header">
    <h2 class="section-title">Mis <span>Listas</span></h2>
  </div>

  <?php if ($error) flashMessage('error', $error); ?>
  <?php if ($ok) flashMessage('success', $ok); ?>

  <!-- Crear nueva lista -->
  <div class="rating-section" style="margin-bottom:1.5rem">
    <h3>Nueva lista</h3>
    <form method="POST" style="display:flex;gap:.75rem;align-items:flex-end">
      <input type="hidden" name="accion" value="crear">
      <div class="form-group" style="margin:0;flex:1">
        <input type="text" name="nombre_lista" placeholder="Nombre de la lista..." maxlength="100" required>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Crear</button>
    </form>
  </div>

  <?php if (empty($listas)): ?>
    <div class="empty-state">
      <div class="icon">📋</div>
      <h3>Sin listas aún</h3>
      <p>Crea tu primera lista para organizar tus videojuegos</p>
    </div>
  <?php else: ?>

    <div style="display:grid;grid-template-columns:280px 1fr;gap:1.5rem">
      <!-- Sidebar de listas -->
      <div>
        <p style="font-size:.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;font-weight:700;margin-bottom:.75rem">Tus listas (<?= count($listas) ?>)</p>
        <div style="display:flex;flex-direction:column;gap:.5rem">
          <?php foreach ($listas as $lista): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;background:var(--bg-card);border:1px solid <?= $idListaVer == $lista['id_lista'] ? 'var(--violet)' : 'var(--border)' ?>;border-radius:var(--radius-sm);padding:.6rem .9rem">
              <a href="/mis-listas.php?lista=<?= $lista['id_lista'] ?>" style="flex:1;color:<?= $idListaVer == $lista['id_lista'] ? 'var(--white)' : 'var(--muted)' ?>;font-size:.875rem;font-weight:600">
                <?= h($lista['nombre_lista']) ?>
                <span style="color:var(--muted);font-size:.75rem;font-weight:400"> (<?= $lista['num_juegos'] ?>)</span>
              </a>
              <form method="POST" onsubmit="return confirm('¿Eliminar esta lista?')">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id_lista" value="<?= $lista['id_lista'] ?>">
                <button type="submit" style="background:none;border:none;cursor:pointer;color:var(--muted);font-size:1rem;padding:0 .25rem" title="Eliminar">✕</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Contenido de lista activa -->
      <div>
        <?php if ($listaActiva): ?>
          <div class="section-header">
            <h3 style="font-size:1.1rem;font-weight:800"><?= h($listaActiva['nombre_lista']) ?></h3>
            <span style="color:var(--muted);font-size:.85rem"><?= count($juegosLista) ?> juego<?= count($juegosLista) != 1 ? 's' : '' ?></span>
          </div>
          <?php if (empty($juegosLista)): ?>
            <div class="empty-state">
              <div class="icon">🎮</div>
              <h3>Lista vacía</h3>
              <p>Añade juegos desde la <a href="/index.php">página de cada juego</a></p>
            </div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Juego</th>
                    <th>Desarrollador</th>
                    <th>Precio</th>
                    <th>Puntuación</th>
                    <th>Añadido</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($juegosLista as $j): ?>
                    <tr>
                      <td><a href="<?= BASE_PATH ?>/juego.php?id=<?= $j['id_juego'] ?>"><?= h($j['titulo']) ?></a></td>
                      <td><?= h($j['desarrollador'] ?? '—') ?></td>
                      <td><?= money($j['precio']) ?></td>
                      <td style="color:var(--cyan);font-family:var(--font-mono)"><?= $j['media_real'] > 0 ? number_format($j['media_real'],1) : '—' ?></td>
                      <td>
                        <?php
                          $estColores = ['pendiente'=>'var(--muted)','jugando'=>'var(--cyan)','completado'=>'var(--success)','abandonado'=>'var(--danger)'];
                          $estIcons   = ['pendiente'=>'⏳','jugando'=>'🎮','completado'=>'✅','abandonado'=>'🚫'];
                          $est = $j['estado_lista'] ?? 'pendiente';
                        ?>
                        <form method="POST" style="display:flex;align-items:center;gap:.3rem">
                          <input type="hidden" name="accion"       value="cambiar_estado">
                          <input type="hidden" name="id_lista"     value="<?= $listaActiva['id_lista'] ?>">
                          <input type="hidden" name="id_videojuego" value="<?= $j['id_juego'] ?>">
                          <select name="estado" style="font-size:.78rem;padding:.2rem .35rem;color:<?= $estColores[$est] ?>">
                            <?php foreach(['pendiente','jugando','completado','abandonado'] as $e): ?>
                              <option value="<?= $e ?>" <?= selected($est, $e) ?>><?= $estIcons[$e] ?> <?= ucfirst($e) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <button type="submit" class="btn btn-ghost btn-sm" style="padding:.2rem .4rem">✓</button>
                        </form>
                      </td>
                      <td style="color:var(--muted);font-size:.8rem"><?= date('d/m/Y', strtotime($j['fecha_agregado'])) ?></td>
                      <td>
                        <form method="POST">
                          <input type="hidden" name="accion"        value="quitar_juego">
                          <input type="hidden" name="id_lista"      value="<?= $listaActiva['id_lista'] ?>">
                          <input type="hidden" name="id_videojuego" value="<?= $j['id_juego'] ?>">
                          <button type="submit" class="btn btn-ghost btn-sm">Quitar</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="empty-state">
            <div class="icon">👈</div>
            <h3>Selecciona una lista</h3>
            <p>Haz clic en una de tus listas para ver su contenido</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php endif; ?>
</div>

<?php include 'partials/footer.php'; ?>
