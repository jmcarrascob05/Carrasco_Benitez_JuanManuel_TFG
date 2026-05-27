<?php
require_once '../config.php';
requireAdmin();
$pageTitle = 'Admin · Dashboard';
$adminPage = 'dashboard';

try {
    $pdo = getDB();

    // KPIs: una sola consulta con subconsultas para obtener los totales principales.
    $stats = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM usuarios)     AS total_usuarios,
            (SELECT COUNT(*) FROM videojuegos)  AS total_juegos,
            (SELECT COUNT(*) FROM valoraciones) AS total_valoraciones,
            (SELECT COUNT(*) FROM comentarios)  AS total_comentarios,
            (SELECT COUNT(*) FROM lista)        AS total_listas,
            (SELECT ROUND(AVG(puntuacion),2) FROM valoraciones) AS media_global
    ")->fetch();

    // Rankings del dashboard: sirven para ver actividad sin entrar tabla por tabla.
    // Top 5 juegos por puntuación
    $topJuegos = $pdo->query("
        SELECT v.titulo,
               COALESCE(AVG(val.puntuacion), 0) AS media_real,
               COUNT(val.id_valoracion) AS num_val
        FROM videojuegos v
        LEFT JOIN valoraciones val ON v.id_juego = val.id_juego
        GROUP BY v.id_juego, v.titulo
        ORDER BY media_real DESC, num_val DESC
        LIMIT 5
    ")->fetchAll();

    // Top 5 más comentados
    $topComentados = $pdo->query("
        SELECT v.titulo, COUNT(c.id_comentario) AS num_com
        FROM videojuegos v
        LEFT JOIN comentarios c ON v.id_juego = c.id_juego
        GROUP BY v.id_juego, v.titulo
        ORDER BY num_com DESC
        LIMIT 5
    ")->fetchAll();

    // Usuarios más activos
    $topUsuarios = $pdo->query("
        SELECT u.username,
               COUNT(DISTINCT val.id_valoracion) AS vals,
               COUNT(DISTINCT co.id_comentario)  AS coms,
               (COUNT(DISTINCT val.id_valoracion) + COUNT(DISTINCT co.id_comentario)) AS total_actividad
        FROM usuarios u
        LEFT JOIN valoraciones val ON u.id_usuario = val.id_usuario
        LEFT JOIN comentarios   co ON u.id_usuario = co.id_usuario
        GROUP BY u.id_usuario, u.username
        ORDER BY total_actividad DESC
        LIMIT 5
    ")->fetchAll();

    // Distribuciones: cuentan cuántos juegos hay por género y plataforma.
    // Distribución géneros
    $distGeneros = $pdo->query("
        SELECT g.nombre, COUNT(vg.id_juego) AS total
        FROM generos g
        LEFT JOIN videojuego_genero vg ON g.id_genero = vg.id_genero
        GROUP BY g.id_genero, g.nombre
        ORDER BY total DESC
    ")->fetchAll();

    // Distribución plataformas
    $distPlataformas = $pdo->query("
        SELECT p.nombre, COUNT(vp.id_juego) AS total
        FROM plataformas p
        LEFT JOIN videojuego_plataforma vp ON p.id_plataforma = vp.id_plataforma
        GROUP BY p.id_plataforma, p.nombre
        ORDER BY total DESC
    ")->fetchAll();

    // Últimos comentarios para moderación rápida desde el panel.
    $ultimosComentarios = $pdo->query("
        SELECT c.id_comentario, c.texto, c.fecha, c.id_juego,
               u.username, v.titulo
        FROM comentarios c
        JOIN usuarios u    ON c.id_usuario = u.id_usuario
        JOIN videojuegos v ON c.id_juego   = v.id_juego
        ORDER BY c.fecha DESC
        LIMIT 10
    ")->fetchAll();

    // Últimas valoraciones
    $ultimasVal = $pdo->query("
        SELECT val.puntuacion, val.fecha, u.username, v.titulo, v.id_juego
        FROM valoraciones val
        JOIN usuarios u    ON val.id_usuario = u.id_usuario
        JOIN videojuegos v ON val.id_juego   = v.id_juego
        ORDER BY val.fecha DESC
        LIMIT 10
    ")->fetchAll();

    // Admin log: puede no existir en instalaciones antiguas, por eso va en try/catch.
    $adminLog = [];
    try {
        $adminLog = $pdo->query("
            SELECT l.accion, l.detalle, l.fecha, u.username
            FROM admin_log l
            JOIN usuarios u ON l.id_usuario = u.id_usuario
            ORDER BY l.fecha DESC
            LIMIT 30
        ")->fetchAll();
    } catch (Exception $e) {
        // tabla puede no existir aún
    }

    // Registros últimos 7 días para dibujar una mini gráfica con barras.
    $registrosRecientes = $pdo->query("
        SELECT DATE(fecha_registro) AS dia, COUNT(*) AS total
        FROM usuarios
        WHERE fecha_registro >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(fecha_registro)
        ORDER BY dia ASC
    ")->fetchAll();

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

include '../partials/header.php';
?>

<div class="container page-content">
  <div class="section-header">
    <h2 class="section-title">📊 <span>Dashboard</span></h2>
  </div>

  <?php include '../partials/admin-nav.php'; ?>

  <!-- KPIs: tarjetas resumen del estado general de la aplicación -->
  <div class="stats-grid" style="grid-template-columns:repeat(6,1fr);margin-bottom:2rem">
    <?php
    $kpis = [
        ['Usuarios',     $stats['total_usuarios'],     '👤'],
        ['Juegos',       $stats['total_juegos'],       '🎮'],
        ['Valoraciones', $stats['total_valoraciones'], '⭐'],
        ['Comentarios',  $stats['total_comentarios'],  '💬'],
        ['Listas',       $stats['total_listas'],       '📋'],
        ['Media global', $stats['media_global'] ? number_format($stats['media_global'],1).'/10' : '—', '📈'],
    ];
    foreach ($kpis as [$label, $valor, $icon]):
    ?>
    <div class="stat-card">
      <div class="stat-icon"><?= $icon ?></div>
      <div class="stat-value"><?= $valor ?? 0 ?></div>
      <div class="stat-label"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Exportar CSV -->
  <div class="rating-section" style="margin-bottom:2rem">
    <h3 style="margin-bottom:.75rem">⬇ Exportar datos CSV</h3>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap">
      <a href="<?= BASE_PATH ?>/admin/exportar.php?tipo=valoraciones" class="btn btn-ghost btn-sm">📊 Valoraciones</a>
      <a href="<?= BASE_PATH ?>/admin/exportar.php?tipo=usuarios"     class="btn btn-ghost btn-sm">👤 Usuarios</a>
      <a href="<?= BASE_PATH ?>/admin/exportar.php?tipo=comentarios"  class="btn btn-ghost btn-sm">💬 Comentarios</a>
      <a href="<?= BASE_PATH ?>/admin/exportar.php?tipo=juegos"       class="btn btn-ghost btn-sm">🎮 Juegos</a>
    </div>
  </div>

  <!-- Nuevos usuarios últimos 7 días -->
  <?php if (!empty($registrosRecientes)): ?>
  <div class="rating-section" style="margin-bottom:2rem">
    <h3 style="margin-bottom:.75rem">📅 Nuevos usuarios (últimos 7 días)</h3>
    <div style="display:flex;gap:.5rem;align-items:flex-end;height:60px">
      <?php
      $maxReg = max(array_column($registrosRecientes, 'total') ?: [1]);
      foreach ($registrosRecientes as $r):
        $pct = round($r['total'] / $maxReg * 100);
        $dia = date('D', strtotime($r['dia']));
      ?>
        <div style="display:flex;flex-direction:column;align-items:center;gap:.2rem;flex:1">
          <span style="font-size:.7rem;color:var(--cyan);font-family:var(--font-mono)"><?= $r['total'] ?></span>
          <div style="background:var(--grad-brand);border-radius:3px 3px 0 0;width:100%;height:<?= max(4, $pct*0.5) ?>px"></div>
          <span style="font-size:.65rem;color:var(--muted)"><?= $dia ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:2rem">

    <!-- Top juegos por puntuación -->
    <div>
      <h3 style="font-size:1rem;font-weight:700;margin-bottom:.75rem">🏆 Top por puntuación</h3>
      <div class="table-wrapper">
        <table class="data-table">
          <thead><tr><th>#</th><th>Juego</th><th>Punt.</th><th>Votos</th></tr></thead>
          <tbody>
            <?php foreach ($topJuegos as $i => $j):
              $pm = (float)$j['media_real'];
              $sc = scoreColor($pm);
            ?>
            <tr>
              <td style="color:var(--muted)"><?= $i+1 ?></td>
              <td><?= h($j['titulo']) ?></td>
              <td style="color:<?= $sc ?>;font-family:var(--font-mono);font-weight:700">
                <?= $pm > 0 ? number_format($pm,1) : '—' ?>
              </td>
              <td style="color:var(--muted)"><?= $j['num_val'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Top más comentados -->
    <div>
      <h3 style="font-size:1rem;font-weight:700;margin-bottom:.75rem">💬 Más comentados</h3>
      <div class="table-wrapper">
        <table class="data-table">
          <thead><tr><th>#</th><th>Juego</th><th>Comentarios</th></tr></thead>
          <tbody>
            <?php foreach ($topComentados as $i => $j): ?>
            <tr>
              <td style="color:var(--muted)"><?= $i+1 ?></td>
              <td><?= h($j['titulo']) ?></td>
              <td style="font-family:var(--font-mono)"><?= $j['num_com'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Distribución géneros -->
    <div>
      <h3 style="font-size:1rem;font-weight:700;margin-bottom:.75rem">🎭 Juegos por género</h3>
      <div class="table-wrapper">
        <table class="data-table">
          <thead><tr><th>Género</th><th>Juegos</th><th style="width:40%">Barra</th></tr></thead>
          <tbody>
            <?php
            $maxGen = max(array_column($distGeneros, 'total') ?: [1]);
            foreach ($distGeneros as $g):
              $pct = $maxGen > 0 ? round($g['total'] / $maxGen * 100) : 0;
            ?>
            <tr>
              <td><?= h($g['nombre']) ?></td>
              <td style="font-family:var(--font-mono)"><?= $g['total'] ?></td>
              <td>
                <div style="background:var(--border);border-radius:4px;height:8px">
                  <div style="background:var(--grad-brand);height:8px;border-radius:4px;width:<?= $pct ?>%;transition:width .3s"></div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Usuarios más activos -->
    <div>
      <h3 style="font-size:1rem;font-weight:700;margin-bottom:.75rem">⚡ Usuarios más activos</h3>
      <div class="table-wrapper">
        <table class="data-table">
          <thead><tr><th>Usuario</th><th>Val.</th><th>Com.</th><th>Total</th></tr></thead>
          <tbody>
            <?php foreach ($topUsuarios as $u): ?>
            <tr>
              <td style="color:var(--cyan)">@<?= h($u['username']) ?></td>
              <td style="font-family:var(--font-mono)"><?= $u['vals'] ?></td>
              <td style="font-family:var(--font-mono)"><?= $u['coms'] ?></td>
              <td style="font-family:var(--font-mono);font-weight:700"><?= $u['total_actividad'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Moderación: últimos comentarios -->
  <div style="margin-bottom:2rem">
    <h3 style="font-size:1rem;font-weight:700;margin-bottom:.75rem">🛡️ Últimos comentarios</h3>
    <div class="table-wrapper">
      <table class="data-table">
        <thead><tr><th>Usuario</th><th>Juego</th><th>Comentario</th><th>Fecha</th><th>Acción</th></tr></thead>
        <tbody>
          <?php foreach ($ultimosComentarios as $cm):
            $dt = new DateTime($cm['fecha'], new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Europe/Madrid'));
          ?>
          <tr>
            <td style="color:var(--cyan)">@<?= h($cm['username']) ?></td>
            <td><a href="<?= BASE_PATH ?>/juego.php?id=<?= $cm['id_juego'] ?>"><?= h($cm['titulo']) ?></a></td>
            <td style="font-size:.8rem;color:var(--muted);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= h(shortText($cm['texto'], 80)) ?>
            </td>
            <td style="font-size:.8rem;color:var(--muted);white-space:nowrap"><?= $dt->format('d/m/Y H:i') ?></td>
            <td>
              <form method="POST" action="<?= BASE_PATH ?>/admin/mod_comentario.php">
                <input type="hidden" name="id_comentario" value="<?= $cm['id_comentario'] ?>">
                <input type="hidden" name="id_juego"      value="<?= $cm['id_juego'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Borrar</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Últimas valoraciones -->
  <div style="margin-bottom:2rem">
    <h3 style="font-size:1rem;font-weight:700;margin-bottom:.75rem">⭐ Últimas valoraciones</h3>
    <div class="table-wrapper">
      <table class="data-table">
        <thead><tr><th>Usuario</th><th>Juego</th><th>Puntuación</th><th>Fecha</th><th>Acción</th></tr></thead>
        <tbody>
          <?php foreach ($ultimasVal as $v): ?>
          <tr>
            <td style="color:var(--cyan)">@<?= h($v['username']) ?></td>
            <td><a href="<?= BASE_PATH ?>/juego.php?id=<?= $v['id_juego'] ?>"><?= h($v['titulo']) ?></a></td>
            <?php
              $pv = (int)$v['puntuacion'];
              $vc = scoreColor($pv);
            ?>
            <td style="color:<?= $vc ?>;font-family:var(--font-mono);font-weight:700"><?= $pv ?>/10</td>
            <td style="font-size:.8rem;color:var(--muted)"><?= dateEs($v['fecha']) ?></td>
            <td>
              <form method="POST" action="<?= BASE_PATH ?>/admin/mod_valoracion.php">
                <input type="hidden" name="id_juego"  value="<?= $v['id_juego'] ?>">
                <input type="hidden" name="username"  value="<?= h($v['username']) ?>">
                <button type="submit" class="btn btn-danger btn-sm">Borrar</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Log de acciones admin -->
  <div>
    <h3 style="font-size:1rem;font-weight:700;margin-bottom:.75rem">📋 Log de acciones admin</h3>
    <?php if (empty($adminLog)): ?>
      <div class="empty-state" style="padding:1.5rem">
        <div class="icon">📋</div>
        <h3>Sin acciones registradas</h3>
        <p>Las acciones de borrado aparecerán aquí</p>
      </div>
    <?php else: ?>
      <div class="table-wrapper">
        <div style="max-height:320px;overflow-y:auto">
          <?php foreach ($adminLog as $log):
            // adminLog() guarda la fecha ya en hora española.
            $dt = new DateTime($log['fecha']);
            $iconos = [
                'crear_juego'=>'➕',
                'eliminar_juego'=>'🎮',
                'borrar_comentario'=>'💬',
                'borrar_valoracion'=>'⭐',
                'cambiar_rol'=>'👤',
                'exportar_csv'=>'⬇'
            ];
            $icono  = $iconos[$log['accion']] ?? '⚙️';
          ?>
            <div class="log-entry">
              <span class="log-time"><?= $dt->format('d/m H:i') ?></span>
              <span class="log-action">
                <?= $icono ?>
                <span class="log-user">@<?= h($log['username']) ?></span>
                · <span style="color:var(--muted)"><?= h($log['accion']) ?></span>
                <?php if ($log['detalle']): ?>
                  · <span style="color:#A0A8CC;font-size:.8rem"><?= h(shortText($log['detalle'], 100)) ?></span>
                <?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php include '../partials/footer.php'; ?>
