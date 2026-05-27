<?php
require_once 'config.php';
$pageTitle = 'Catálogo';

// Leemos los filtros de la URL. Si no vienen, usamos valores por defecto.
$filtroBuscar     = trim($_GET['buscar']      ?? '');
$filtroGenero     = (int)($_GET['genero']     ?? 0);
$filtroPlataforma = (int)($_GET['plataforma'] ?? 0);
$filtroPrecioMax  = isset($_GET['precio_max']) && $_GET['precio_max']!=='' ? (float)$_GET['precio_max'] : null;
$filtroOrden      = $_GET['orden']  ?? 'puntuacion';
$filtroEstado     = $_GET['estado'] ?? '';
$pagina           = max(1,(int)($_GET['pagina'] ?? 1));
$porPagina        = 20;

try {
    $pdo    = getDB();
    $params = [];
    $where  = [];

    // Base común de la consulta: une juegos con géneros y plataformas.
    // Luego se le añaden filtros según lo que el usuario haya elegido.
    $sqlBase = "
        FROM videojuegos v
        LEFT JOIN videojuego_genero     vg  ON v.id_juego=vg.id_juego
        LEFT JOIN generos               g   ON vg.id_genero=g.id_genero
        LEFT JOIN videojuego_plataforma vp  ON v.id_juego=vp.id_juego
        LEFT JOIN plataformas           p   ON vp.id_plataforma=p.id_plataforma
    ";

    // Cada filtro añade una condición SQL y su parámetro.
    // Usar parámetros evita inyección SQL.
    if ($filtroBuscar !== '') {
        $where[] = "(v.titulo LIKE ? OR v.desarrollador LIKE ?)";
        $like = '%'.$filtroBuscar.'%';
        $params[] = $like; $params[] = $like;
    }
    if ($filtroGenero) {
        $where[] = "v.id_juego IN (SELECT id_juego FROM videojuego_genero WHERE id_genero=?)";
        $params[] = $filtroGenero;
    }
    if ($filtroPlataforma) {
        $where[] = "v.id_juego IN (SELECT id_juego FROM videojuego_plataforma WHERE id_plataforma=?)";
        $params[] = $filtroPlataforma;
    }
    if ($filtroPrecioMax !== null) {
        $where[] = "v.precio <= ?"; $params[] = $filtroPrecioMax;
    }
    if ($filtroEstado !== '') {
        $where[] = "v.estado = ?"; $params[] = $filtroEstado;
    }

    $whereSQL = $where ? "WHERE ".implode(" AND ",$where) : "";

    // Primero contamos resultados para calcular la paginación.
    $stmtTotal = $pdo->prepare("SELECT COUNT(DISTINCT v.id_juego) $sqlBase $whereSQL");
    $stmtTotal->execute($params);
    $total     = (int)$stmtTotal->fetchColumn();
    $totalPags = max(1,(int)ceil($total/$porPagina));
    $pagina    = min($pagina,$totalPags);
    $offset    = ($pagina-1)*$porPagina;

    // El ORDER BY se controla con opciones cerradas, no con texto libre.
    $orden = match($filtroOrden) {
        'titulo'   => "v.titulo ASC",
        'precio'   => "v.precio ASC",
        'reciente' => "v.fecha_lanzamiento DESC",
        default    => "media_real DESC"
    };

    // Consulta final: trae solo los juegos de la página actual.
    $sql = "SELECT DISTINCT v.id_juego, v.titulo, v.descripcion, v.desarrollador,
                   v.precio,
                   (SELECT COALESCE(AVG(puntuacion), 0)
                    FROM valoraciones
                    WHERE id_juego = v.id_juego) AS media_real,
                   v.fecha_lanzamiento, v.estado, v.imagen_url
            $sqlBase $whereSQL
            GROUP BY v.id_juego, v.titulo, v.descripcion, v.desarrollador,
                     v.precio, v.fecha_lanzamiento, v.estado, v.imagen_url
            ORDER BY $orden
            LIMIT $porPagina OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $juegos = $stmt->fetchAll();

    // Datos necesarios para rellenar los desplegables de filtros.
    $generos     = $pdo->query("SELECT * FROM generos   ORDER BY nombre")->fetchAll();
    $plataformas = $pdo->query("SELECT * FROM plataformas ORDER BY nombre")->fetchAll();

} catch (Exception $e) {
    $juegos = $generos = $plataformas = [];
    $total = $totalPags = 0; $pagina = 1;
    $error = "Error: ".$e->getMessage();
}

// Base de enlaces para la paginación manteniendo los filtros actuales.
$queryParams = $_GET; unset($queryParams['pagina']);
$queryBase   = $queryParams ? '?'.http_build_query($queryParams).'&' : '?';

include 'partials/header.php';
?>

<div class="container page-content">

  <?php if (!isLoggedIn()): ?>
  <div class="hero">
    <h1>El catálogo de <span class="grad">videojuegos</span><br>que estabas buscando</h1>
    <p>Descubre, valora y crea tus listas personalizadas. Únete a la comunidad RustyX.</p>
    <a href="<?= BASE_PATH ?>/registro.php" class="btn btn-primary">Crear cuenta gratis</a>
  </div>
  <?php endif; ?>

  <?php if (isset($error)): ?>
    <?php flashMessage('error', $error); ?>
  <?php endif; ?>

  <!-- Filtros servidor: al enviar el formulario se recarga el catálogo con GET -->
  <form method="GET" action="<?= BASE_PATH ?>/index.php">
    <div class="filters-bar">
      <div class="filter-group">
        <label>🔍</label>
        <input type="text" name="buscar" value="<?= h($filtroBuscar) ?>"
               placeholder="Buscar juego o desarrollador..." style="width:200px">
      </div>
      <div class="filter-group">
        <label>Género</label>
        <select name="genero">
          <option value="0">Todos</option>
          <?php foreach ($generos as $g): ?>
            <option value="<?= $g['id_genero'] ?>" <?= selected($filtroGenero, $g['id_genero']) ?>>
              <?= h($g['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>Plataforma</label>
        <select name="plataforma">
          <option value="0">Todas</option>
          <?php foreach ($plataformas as $p): ?>
            <option value="<?= $p['id_plataforma'] ?>" <?= selected($filtroPlataforma, $p['id_plataforma']) ?>>
              <?= h($p['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>Estado</label>
        <select name="estado">
          <option value="">Todos</option>
          <option value="disponible" <?= selected($filtroEstado, 'disponible') ?>>Disponible</option>
          <option value="proximo" <?= selected($filtroEstado, 'proximo') ?>>Próximo</option>
          <option value="descontinuado" <?= selected($filtroEstado, 'descontinuado') ?>>Descontinuado</option>
        </select>
      </div>
      <div class="filter-group">
        <label>Precio máx.</label>
        <input type="number" name="precio_max" min="0" max="200" step="5"
               value="<?= $filtroPrecioMax!==null?$filtroPrecioMax:'' ?>"
               placeholder="Sin límite" style="width:100px">
      </div>
      <div class="filter-group">
        <label>Ordenar</label>
        <select name="orden">
          <option value="puntuacion" <?= selected($filtroOrden, 'puntuacion') ?>>Mejor puntuación</option>
          <option value="reciente" <?= selected($filtroOrden, 'reciente') ?>>Más recientes</option>
          <option value="precio" <?= selected($filtroOrden, 'precio') ?>>Precio: menor</option>
          <option value="titulo" <?= selected($filtroOrden, 'titulo') ?>>Título A-Z</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
      <a href="<?= BASE_PATH ?>/index.php" class="btn btn-ghost btn-sm">Limpiar</a>
    </div>
  </form>

  <!-- Filtro rápido local: solo oculta tarjetas ya cargadas, no consulta la BD -->
  <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;flex-wrap:wrap">
    <input type="text" id="quickSearch" placeholder="⚡ Filtro rápido por título..."
           style="flex:1;min-width:200px;max-width:350px"
           oninput="filtrarLocal(this.value)">
    <div class="view-toggle">
      <button class="view-btn active" id="btnGrid" title="Vista grid"   onclick="setView('grid')">⊞</button>
      <button class="view-btn"        id="btnList" title="Vista lista"   onclick="setView('list')">☰</button>
    </div>
  </div>

  <!-- Cabecera resultados -->
  <div class="section-header">
    <h2 class="section-title"><span>Catálogo</span> de videojuegos</h2>
    <span style="color:var(--muted);font-size:.85rem">
      <?= $total ?> juego<?= $total!=1?'s':'' ?>
      <?php if ($totalPags>1): ?> · Página <?= $pagina ?> de <?= $totalPags ?><?php endif; ?>
    </span>
  </div>

  <?php if (empty($juegos)): ?>
    <div class="empty-state">
      <div class="icon">🎮</div><h3>Sin resultados</h3>
      <p>Prueba con otros filtros o <a href="<?= BASE_PATH ?>/index.php">ver todos</a></p>
    </div>
  <?php else: ?>

    <!-- Skeleton (visible solo mientras carga) -->
    <div id="skeletonGrid" class="games-grid" style="display:none">
      <?php for($s=0;$s<8;$s++): ?>
        <div class="skeleton-card">
          <div class="skeleton skeleton-img"></div>
          <div style="padding:.75rem">
            <div class="skeleton skeleton-line"></div>
            <div class="skeleton skeleton-line short"></div>
            <div class="skeleton skeleton-line xshort"></div>
          </div>
        </div>
      <?php endfor; ?>
    </div>

    <div class="games-grid" id="gamesContainer">
      <?php foreach ($juegos as $juego):
        $pm = (float)$juego['media_real'];
        $sc = scoreColor($pm);
      ?>
        <a href="<?= BASE_PATH ?>/juego.php?id=<?= $juego['id_juego'] ?>"
           class="game-card"
           style="text-decoration:none;color:inherit"
           data-titulo="<?= h(strtolower($juego['titulo'])) ?>"
           data-dev="<?= h(strtolower($juego['desarrollador']??'')) ?>">
          <div class="game-card-img">
            <?php if (!empty($juego['imagen_url'])): ?>
              <img src="<?= h($juego['imagen_url']) ?>"
                   alt="<?= h($juego['titulo']) ?>">
            <?php else: ?>🎮<?php endif; ?>
            <?php if ($pm>0): ?>
              <div class="score-badge" style="color:<?= $sc ?>;border-color:<?= $sc ?>40">
                <?= number_format($pm,1) ?>
              </div>
            <?php endif; ?>
            <?php if (($juego['estado']??'disponible')!=='disponible'): ?>
              <div class="estado-badge estado-<?= $juego['estado'] ?>">
                <?= $juego['estado']==='proximo'?'Próximo':'Descont.' ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="game-card-body">
            <div class="game-card-title"><?= h($juego['titulo']) ?></div>
            <div class="game-card-meta">
              <span><?= h($juego['desarrollador']??'—') ?></span>
              <span style="color:var(--magenta);font-weight:700">
                <?= money($juego['precio']) ?>
              </span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <script>
    // Filtro rápido local
    function filtrarLocal(q) {
      q = q.toLowerCase();
      document.querySelectorAll('#gamesContainer .game-card').forEach(function(el) {
        var ok = el.dataset.titulo.includes(q) || el.dataset.dev.includes(q);
        el.style.display = ok ? '' : 'none';
      });
    }
    // Toggle vista grid/lista
    function setView(v) {
      var cont = document.getElementById('gamesContainer');
      var bg   = document.getElementById('btnGrid');
      var bl   = document.getElementById('btnList');
      if (v === 'list') {
        cont.className = 'games-list-view'; bg.classList.remove('active'); bl.classList.add('active');
      } else {
        cont.className = 'games-grid'; bl.classList.remove('active'); bg.classList.add('active');
      }
      try { localStorage.setItem('rustyx_view', v); } catch(e) {}
    }
    try { var sv = localStorage.getItem('rustyx_view'); if (sv) setView(sv); } catch(e) {}
    </script>

    <!-- Paginación -->
    <?php if ($totalPags>1): ?>
    <div class="pagination">
      <?php if ($pagina>1): ?>
        <a href="<?= $queryBase ?>pagina=<?= $pagina-1 ?>" class="btn btn-ghost btn-sm">← Anterior</a>
      <?php endif; ?>
      <?php
      $rango = 2; $ini = max(1,$pagina-$rango); $fin = min($totalPags,$pagina+$rango);
      if ($ini>1): ?><a href="<?= $queryBase ?>pagina=1" class="page-btn">1</a><?php
        if ($ini>2): ?><span class="page-dots">…</span><?php endif;
      endif;
      for ($i=$ini;$i<=$fin;$i++): ?>
        <a href="<?= $queryBase ?>pagina=<?= $i ?>"
           class="page-btn <?= $i===$pagina?'active':'' ?>"><?= $i ?></a>
      <?php endfor;
      if ($fin<$totalPags):
        if ($fin<$totalPags-1): ?><span class="page-dots">…</span><?php endif; ?>
        <a href="<?= $queryBase ?>pagina=<?= $totalPags ?>" class="page-btn"><?= $totalPags ?></a>
      <?php endif; ?>
      <?php if ($pagina<$totalPags): ?>
        <a href="<?= $queryBase ?>pagina=<?= $pagina+1 ?>" class="btn btn-ghost btn-sm">Siguiente →</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  <?php endif; ?>
</div>

<?php include 'partials/footer.php'; ?>
