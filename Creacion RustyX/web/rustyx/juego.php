<?php
require_once 'config.php';
date_default_timezone_set('Europe/Madrid');

// ID del juego recibido por URL. Sin ID válido volvemos al catálogo.
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect('/index.php');

$ordenCom = in_array($_GET['orden_com'] ?? '', ['asc','desc']) ? $_GET['orden_com'] : 'desc';

try {
    $pdo = getDB();

    // Cargamos el juego con sus géneros, plataformas y número de valoraciones.
    $stmt = $pdo->prepare("
        SELECT v.*,
               GROUP_CONCAT(DISTINCT g.nombre ORDER BY g.nombre SEPARATOR ', ') AS generos,
               GROUP_CONCAT(DISTINCT p.nombre ORDER BY p.nombre SEPARATOR ', ') AS plataformas,
               (SELECT COALESCE(AVG(puntuacion), 0)
                FROM valoraciones
                WHERE id_juego = v.id_juego) AS media_real,
               (SELECT COUNT(*)
                FROM valoraciones
                WHERE id_juego = v.id_juego) AS num_valoraciones
        FROM videojuegos v
        LEFT JOIN videojuego_genero     vg  ON v.id_juego=vg.id_juego
        LEFT JOIN generos               g   ON vg.id_genero=g.id_genero
        LEFT JOIN videojuego_plataforma vpl ON v.id_juego=vpl.id_juego
        LEFT JOIN plataformas           p   ON vpl.id_plataforma=p.id_plataforma
        WHERE v.id_juego=?
        GROUP BY v.id_juego
    ");
    $stmt->execute([$id]);
    $juego = $stmt->fetch();
    if (!$juego) redirect('/index.php');

    // El orden de comentarios solo acepta asc/desc, definido arriba con lista blanca.
    $orderSQL = $ordenCom === 'asc' ? 'ASC' : 'DESC';
    $stmtC = $pdo->prepare("
        SELECT c.*, u.username, u.avatar_url, r.nombre_rol
        FROM comentarios c
        JOIN usuarios u ON c.id_usuario=u.id_usuario
        JOIN roles    r ON u.id_rol=r.id_rol
        WHERE c.id_juego=?
        ORDER BY c.fecha $orderSQL
        LIMIT 100
    ");
    $stmtC->execute([$id]);
    $comentarios = $stmtC->fetchAll();

    $miValoracion = null;
    $misListas    = [];

    // Si hay sesión, cargamos los datos personales del usuario para este juego.
    if (isLoggedIn()) {
        $stmtV = $pdo->prepare("SELECT puntuacion FROM valoraciones WHERE id_usuario=? AND id_juego=?");
        $stmtV->execute([$_SESSION['id_usuario'], $id]);
        $miValoracion = $stmtV->fetchColumn();

        $stmtL = $pdo->prepare("
            SELECT l.id_lista, l.nombre_lista,
                   lv.estado AS estado_lista,
                   (SELECT COUNT(*) FROM lista_videojuego lv2
                    WHERE lv2.id_lista=l.id_lista AND lv2.id_videojuego=?) AS tiene
            FROM lista l
            LEFT JOIN lista_videojuego lv ON l.id_lista=lv.id_lista AND lv.id_videojuego=?
            WHERE l.id_usuario=?
        ");
        $stmtL->execute([$id,$id,$_SESSION['id_usuario']]);
        $misListas = $stmtL->fetchAll();
    }

} catch (Exception $e) {
    die("Error: ".$e->getMessage());
}

// ── POST ─────────────────────────────────────
// Todas las acciones privadas de esta página entran por el mismo formulario:
// valorar, comentar, editar/borrar comentario o añadir a listas.
if (isPost() && isLoggedIn()) {
    $accion = postAction();
    $uid    = $_SESSION['id_usuario'];

    if ($accion === 'valorar') {
        $punt = (int)$_POST['puntuacion'];
        if ($punt >= 1 && $punt <= 10) {
            $pdo->prepare("INSERT INTO valoraciones (id_usuario,id_juego,puntuacion,fecha)
                VALUES (?,?,?,CURDATE())
                ON DUPLICATE KEY UPDATE puntuacion=VALUES(puntuacion),fecha=CURDATE()")
                ->execute([$uid,$id,$punt]);
            if (function_exists('updateGameScore')) {
                updateGameScore($pdo, $id);
            }
        }
        redirect("/juego.php?id=$id&ok=valorado");
    }

    if ($accion === 'quitar_valoracion') {
        $pdo->prepare("DELETE FROM valoraciones WHERE id_usuario=? AND id_juego=?")->execute([$uid,$id]);
        if (function_exists('updateGameScore')) {
            updateGameScore($pdo, $id);
        }
        redirect("/juego.php?id=$id&ok=valoracion_quitada");
    }

    if ($accion === 'comentar') {
        $texto = trim($_POST['texto'] ?? '');
        if (strlen($texto) < 3) redirect("/juego.php?id=$id&err=comentario_vacio");
        // Cooldown: evita que un usuario publique comentarios muy seguidos.
        $stmtCd = $pdo->prepare("SELECT fecha FROM comentarios WHERE id_usuario=? ORDER BY fecha DESC LIMIT 1");
        $stmtCd->execute([$uid]);
        $lastCom = $stmtCd->fetchColumn();
        if ($lastCom) {
            $diff = time() - (new DateTime($lastCom, new DateTimeZone('UTC')))->getTimestamp();
            if ($diff < 300) {
                $r = 300-$diff;
                redirect("/juego.php?id=$id&err=cooldown&espera=".urlencode(floor($r/60)."m ".($r%60)."s"));
            }
        }
        if (strlen($texto) <= 2000) {
            $pdo->prepare("INSERT INTO comentarios (id_usuario,id_juego,texto,fecha) VALUES (?,?,?,NOW())")
                ->execute([$uid,$id,$texto]);
        }
        redirect("/juego.php?id=$id&ok=comentado");
    }

    if ($accion === 'editar_comentario') {
        $idCom = (int)$_POST['id_comentario'];
        $texto = trim($_POST['texto'] ?? '');
        if (strlen($texto) >= 3) {
            $where = isAdmin() ? "id_comentario=?" : "id_comentario=? AND id_usuario=$uid";
            $pdo->prepare("UPDATE comentarios SET texto=?,editado=1 WHERE $where")->execute([$texto,$idCom]);
        }
        redirect("/juego.php?id=$id&ok=editado");
    }

    if ($accion === 'borrar_comentario') {
        $idCom = (int)$_POST['id_comentario'];
        if (isAdmin()) {
            $stmtLog = $pdo->prepare("
                SELECT c.texto, u.username
                FROM comentarios c
                JOIN usuarios u ON c.id_usuario = u.id_usuario
                WHERE c.id_comentario = ?
            ");
            $stmtLog->execute([$idCom]);
            $comLog = $stmtLog->fetch();
        }
        $where = isAdmin() ? "id_comentario=?" : "id_comentario=? AND id_usuario=$uid";
        $pdo->prepare("DELETE FROM comentarios WHERE $where")->execute([$idCom]);
        if (isAdmin() && !empty($comLog)) {
            $detalle = "Comentario de @".($comLog['username']??'?').": \"".substr($comLog['texto']??'',0,80)."\"";
            adminLog($pdo, 'borrar_comentario', $detalle);
        }
        redirect("/juego.php?id=$id&ok=borrado");
    }

    if ($accion === 'lista') {
        $idLista = (int)$_POST['id_lista'];
        $op      = $_POST['op'] ?? 'add';
        $estado  = $_POST['estado_lista'] ?? 'pendiente';
        if ($op === 'add') {
            $pdo->prepare("INSERT IGNORE INTO lista_videojuego (id_lista,id_videojuego,estado) VALUES (?,?,?)")
                ->execute([$idLista,$id,$estado]);
        } elseif ($op === 'estado') {
            $pdo->prepare("UPDATE lista_videojuego SET estado=? WHERE id_lista=? AND id_videojuego=?")
                ->execute([$estado,$idLista,$id]);
        } else {
            $pdo->prepare("DELETE FROM lista_videojuego WHERE id_lista=? AND id_videojuego=?")->execute([$idLista,$id]);
        }
        redirect("/juego.php?id=$id&ok=lista");
    }
}

function youtubeId(?string $url): string {
    // Acepta tanto una URL de YouTube como el ID directamente.
    if (!$url) return '';
    if (strlen($url)===11 && preg_match('/^[a-zA-Z0-9_-]{11}$/',$url)) return $url;
    if (preg_match('/(?:v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/',$url,$m)) return $m[1];
    return '';
}
$ytId = youtubeId($juego['youtube_url'] ?? '');

$estadoLabels = ['disponible'=>'✅ Disponible','proximo'=>'🕐 Próximo','descontinuado'=>'🚫 Descontinuado'];
$estadoColors = ['disponible'=>'var(--success)','proximo'=>'var(--cyan)','descontinuado'=>'var(--muted)'];
$pm = (float)($juego['media_real'] ?? $juego['puntuacion_media']);
$scoreColor = scoreColor($pm);

$pageTitle = $juego['titulo'];
include 'partials/header.php';
?>

<div class="container page-content">

  <?php if (isset($_GET['ok'])): ?>
    <div class="alert alert-success">
      <?= match($_GET['ok']) {
          'valorado'           => '✓ Valoración guardada',
          'valoracion_quitada' => '✓ Valoración eliminada',
          'comentado'          => '✓ Comentario publicado',
          'editado'            => '✓ Comentario editado',
          'borrado'            => '✓ Comentario eliminado',
          'lista'              => '✓ Lista actualizada',
          default              => '✓ Hecho'
      } ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_GET['err'])): ?>
    <div class="alert alert-error">
      <?php if ($_GET['err']==='comentario_vacio'): ?>
        ⚠ El comentario no puede estar vacío (mínimo 3 caracteres).
      <?php elseif ($_GET['err']==='cooldown'): ?>
        ⏳ Debes esperar <?= h($_GET['espera']??'5m') ?> antes de publicar otro comentario.
      <?php else: ?> ⚠ Ha ocurrido un error. <?php endif; ?>
    </div>
  <?php endif; ?>

  <a href="<?= BASE_PATH ?>/index.php"
     style="color:var(--muted);font-size:.875rem;display:inline-flex;align-items:center;gap:.4rem;margin-bottom:1.5rem">
    ← Volver al catálogo
  </a>

  <!-- Header -->
  <div class="game-detail-header">
    <div class="game-detail-cover">
      <?php if (!empty($juego['imagen_url'])): ?>
        <img src="<?= h($juego['imagen_url']) ?>" alt="<?= h($juego['titulo']) ?>">
      <?php else: ?>🎮<?php endif; ?>
    </div>

    <div class="game-detail-info">
      <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.4rem">
        <h1 style="margin:0"><?= h($juego['titulo']) ?></h1>
        <span style="font-size:.8rem;color:<?= $estadoColors[$juego['estado']??'disponible'] ?>">
          <?= $estadoLabels[$juego['estado']??'disponible'] ?>
        </span>
      </div>
      <p class="developer">por <?= h($juego['desarrollador']??'Desconocido') ?></p>

      <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;flex-wrap:wrap">
        <div class="score-display" style="border-color:<?= $scoreColor ?>30">
          <span class="score-num" style="color:<?= $scoreColor ?>">
            <?= $pm>0 ? number_format($pm,1) : '—' ?>
          </span>
          <span class="score-max">/ 10</span>
        </div>
        <span style="color:var(--muted);font-size:.8rem"><?= $juego['num_valoraciones'] ?> valoraciones</span>
      </div>

      <p class="description"><?= nl2br(h($juego['descripcion']??'')) ?></p>
      <p class="price"><?= money($juego['precio']) ?></p>

      <div class="game-card-tags" style="margin-bottom:1rem">
        <?php foreach (explode(', ',$juego['generos']??'') as $g): ?>
          <?php if(trim($g)): ?><span class="tag tag-genre"><?= h(trim($g)) ?></span><?php endif; ?>
        <?php endforeach; ?>
        <?php foreach (explode(', ',$juego['plataformas']??'') as $p): ?>
          <?php if(trim($p)): ?><span class="tag tag-platform"><?= h(trim($p)) ?></span><?php endif; ?>
        <?php endforeach; ?>
      </div>
      <p style="font-size:.8rem;color:var(--muted)">
        Lanzamiento: <?= $juego['fecha_lanzamiento'] ? date('d/m/Y',strtotime($juego['fecha_lanzamiento'])) : '—' ?>
      </p>
    </div>
  </div>

  <!-- Trailer -->
  <?php if ($ytId): ?>
  <div style="margin-bottom:2rem">
    <h3 style="font-size:1rem;font-weight:700;margin-bottom:.75rem">🎬 Tráiler</h3>
    <div class="youtube-embed">
      <iframe src="https://www.youtube-nocookie.com/embed/<?= h($ytId) ?>"
              title="Tráiler <?= h($juego['titulo']) ?>"
              frameborder="0" allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture"
              allowfullscreen loading="lazy"></iframe>
    </div>
  </div>
  <?php endif; ?>

  <!-- Área privada -->
  <?php if (isLoggedIn()): ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:2rem">

      <!-- Valorar -->
      <div class="rating-section">
        <h3>Tu valoración
          <?php if ($miValoracion): ?>
            <span style="color:<?= $scoreColor ?>;font-family:var(--font-mono)"><?= $miValoracion ?>/10</span>
          <?php endif; ?>
        </h3>
        <form method="POST">
          <input type="hidden" name="accion" value="valorar">
          <div class="star-radio-group">
            <?php for ($i=1;$i<=10;$i++): ?>
              <label class="star-radio-label">
                <input type="radio" name="puntuacion" value="<?= $i ?>"
                       <?= checked($miValoracion, $i) ?> required>
                <span class="star-radio-star <?= ($miValoracion??0)>=$i?'active':'' ?>">★</span>
              </label>
            <?php endfor; ?>
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.6rem">
            <?= $miValoracion?'Actualizar':'Valorar' ?>
          </button>
        </form>
        <?php if ($miValoracion): ?>
          <form method="POST" style="margin-top:.4rem">
            <input type="hidden" name="accion" value="quitar_valoracion">
            <button type="submit" class="btn btn-ghost btn-sm"
                    style="font-size:.75rem;color:var(--muted)">✕ Quitar mi valoración</button>
          </form>
        <?php endif; ?>
      </div>

      <!-- Listas -->
      <div class="rating-section">
        <h3>Mis listas</h3>
        <?php if (empty($misListas)): ?>
          <p style="color:var(--muted);font-size:.85rem">No tienes listas. <a href="<?= BASE_PATH ?>/mis-listas.php">Crear una</a></p>
        <?php else: ?>
          <?php foreach ($misListas as $lista): ?>
            <div style="margin-bottom:.5rem">
              <?php if ($lista['tiene']): ?>
                <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap">
                  <form method="POST" style="display:flex;align-items:center;gap:.3rem">
                    <input type="hidden" name="accion"   value="lista">
                    <input type="hidden" name="id_lista" value="<?= $lista['id_lista'] ?>">
                    <input type="hidden" name="op"       value="estado">
                    <span style="font-size:.8rem;color:var(--cyan)">✓ <?= h($lista['nombre_lista']) ?></span>
                    <select name="estado_lista" style="font-size:.75rem;padding:.15rem .3rem">
                      <?php foreach(['pendiente','jugando','completado','abandonado'] as $est): ?>
                        <option value="<?= $est ?>" <?= selected($lista['estado_lista'], $est) ?>><?= ucfirst($est) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:.15rem .4rem">✓</button>
                  </form>
                  <form method="POST">
                    <input type="hidden" name="accion"   value="lista">
                    <input type="hidden" name="id_lista" value="<?= $lista['id_lista'] ?>">
                    <input type="hidden" name="op"       value="remove">
                    <button type="submit" class="btn btn-ghost btn-sm"
                            style="color:var(--danger);border-color:var(--danger);padding:.15rem .4rem">✕</button>
                  </form>
                </div>
              <?php else: ?>
                <form method="POST" style="display:flex;align-items:center;gap:.3rem">
                  <input type="hidden" name="accion"   value="lista">
                  <input type="hidden" name="id_lista" value="<?= $lista['id_lista'] ?>">
                  <input type="hidden" name="op"       value="add">
                  <select name="estado_lista" style="font-size:.75rem;padding:.15rem .3rem">
                    <?php foreach(['pendiente','jugando','completado','abandonado'] as $est): ?>
                      <option value="<?= $est ?>"><?= ucfirst($est) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-ghost btn-sm">+ <?= h($lista['nombre_lista']) ?></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Nuevo comentario -->
    <div class="rating-section" style="margin-bottom:2rem">
      <h3>Escribir comentario</h3>
      <form method="POST">
        <input type="hidden" name="accion" value="comentar">
        <div class="form-group" style="position:relative">
          <textarea name="texto" id="commentText" placeholder="Comparte tu opinión..."
                    maxlength="2000" style="padding-bottom:1.8rem"
                    oninput="document.getElementById('cc').textContent=this.value.length+' / 2000'"></textarea>
          <span id="cc" style="position:absolute;bottom:.4rem;right:.6rem;font-size:.72rem;
                               color:var(--muted);pointer-events:none">0 / 2000</span>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Publicar</button>
      </form>
    </div>

  <?php else: ?>
    <div class="alert alert-info" style="margin-bottom:2rem">
      <a href="<?= BASE_PATH ?>/login.php">Inicia sesión</a> para valorar, comentar y añadir a listas.
    </div>
  <?php endif; ?>

  <!-- Comentarios -->
  <div class="section-header" style="margin-bottom:1rem">
    <h2 class="section-title">Comentarios <span>(<?= count($comentarios) ?>)</span></h2>
    <!-- Ordenar comentarios -->
    <div style="display:flex;align-items:center;gap:.5rem">
      <span style="font-size:.8rem;color:var(--muted)">Orden:</span>
      <a href="?id=<?= $id ?>&orden_com=desc"
         class="btn btn-ghost btn-sm <?= $ordenCom==='desc'?'':'opacity:.5' ?>"
         style="<?= $ordenCom==='desc'?'border-color:var(--violet);color:var(--white)':'' ?>">
        Más recientes
      </a>
      <a href="?id=<?= $id ?>&orden_com=asc"
         class="btn btn-ghost btn-sm"
         style="<?= $ordenCom==='asc'?'border-color:var(--violet);color:var(--white)':'' ?>">
        Más antiguos
      </a>
    </div>
  </div>

  <?php if (empty($comentarios)): ?>
    <div class="empty-state"><div class="icon">💬</div><h3>Sin comentarios aún</h3></div>
  <?php else: ?>
    <div class="comments-list">
      <?php foreach ($comentarios as $c):
        $esAutor = isLoggedIn() && $_SESSION['id_usuario']==$c['id_usuario'];
        $editando = isset($_GET['editar_com']) && (int)$_GET['editar_com']===$c['id_comentario'] && ($esAutor||isAdmin());
        $rolColors = ['admin'=>'var(--magenta)','tester'=>'var(--cyan)','desarrollador'=>'var(--success)'];
        $rolColor  = $rolColors[$c['nombre_rol']] ?? null;
        $dt = new DateTime($c['fecha'], new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Madrid'));
      ?>
        <div class="comment-item">
          <div class="comment-header">
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
              <?php if ($c['avatar_url'] && file_exists(AVATAR_DIR.$c['avatar_url'])): ?>
                <img src="<?= h(avatarUrl($c['avatar_url'])) ?>"
                     style="width:24px;height:24px;border-radius:50%;object-fit:cover">
              <?php else: ?>
                <div style="width:24px;height:24px;border-radius:50%;background:var(--grad-brand);
                            display:flex;align-items:center;justify-content:center;
                            font-size:.7rem;font-weight:900;color:#fff;flex-shrink:0">
                  <?= strtoupper(substr($c['username'],0,1)) ?>
                </div>
              <?php endif; ?>
              <span class="comment-author">@<?= h($c['username']) ?></span>
              <?php if ($rolColor): ?>
                <span style="font-size:.68rem;padding:.1rem .4rem;border-radius:3px;
                             background:rgba(255,255,255,.06);color:<?= $rolColor ?>;
                             border:1px solid <?= $rolColor ?>;text-transform:uppercase;
                             letter-spacing:.05em;font-weight:700">
                  <?= h($c['nombre_rol']) ?>
                </span>
              <?php endif; ?>
              <?php if ($c['editado']): ?>
                <span style="font-size:.7rem;color:var(--muted)">(editado)</span>
              <?php endif; ?>
            </div>
            <span class="comment-date"><?= $dt->format('d/m/Y H:i') ?></span>
          </div>

          <?php if ($editando): ?>
            <form method="POST" style="margin-top:.5rem">
              <input type="hidden" name="accion"        value="editar_comentario">
              <input type="hidden" name="id_comentario" value="<?= $c['id_comentario'] ?>">
              <div class="form-group" style="margin-bottom:.5rem">
                <textarea name="texto" maxlength="2000" rows="3"><?= h($c['texto']) ?></textarea>
              </div>
              <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary btn-sm">Guardar</button>
                <a href="<?= BASE_PATH ?>/juego.php?id=<?= $id ?>" class="btn btn-ghost btn-sm">Cancelar</a>
              </div>
            </form>
          <?php else: ?>
            <p class="comment-text"><?= nl2br(h($c['texto'])) ?></p>
            <?php if ($esAutor || isAdmin()): ?>
              <div style="display:flex;gap:.5rem;margin-top:.4rem">
                <?php if ($esAutor): ?>
                  <a href="<?= BASE_PATH ?>/juego.php?id=<?= $id ?>&orden_com=<?= $ordenCom ?>&editar_com=<?= $c['id_comentario'] ?>"
                     class="btn btn-ghost btn-sm" style="font-size:.75rem">Editar</a>
                <?php endif; ?>
                <form method="POST">
                  <input type="hidden" name="accion"        value="borrar_comentario">
                  <input type="hidden" name="id_comentario" value="<?= $c['id_comentario'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm"
                          style="font-size:.75rem;color:var(--danger);border-color:var(--danger)">Borrar</button>
                </form>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php include 'partials/footer.php'; ?>
