<?php
require_once '../config.php';
requireAdmin();

$tipo = $_GET['tipo'] ?? 'valoraciones';
$pdo  = getDB();

// Registramos la exportación para que quede constancia en el log de admin.
adminLog($pdo, 'exportar_csv', "tipo: $tipo");

// Estas cabeceras hacen que el navegador descargue un archivo CSV.
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="rustyx_'.$tipo.'_'.date('Ymd_His').'.csv"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 para Excel

// Según el tipo pedido, lanzamos una consulta distinta y escribimos sus filas.
if ($tipo === 'valoraciones') {
    fputcsv($out, ['ID','Usuario','Juego','Puntuación','Fecha']);
    $rows = $pdo->query("
        SELECT val.id_valoracion, u.username, v.titulo, val.puntuacion, val.fecha
        FROM valoraciones val
        JOIN usuarios u ON val.id_usuario=u.id_usuario
        JOIN videojuegos v ON val.id_juego=v.id_juego
        ORDER BY val.fecha DESC
    ")->fetchAll();
    foreach ($rows as $r) fputcsv($out, $r);

} elseif ($tipo === 'usuarios') {
    fputcsv($out, ['ID','Username','Nombre','Apellidos','Email','Rol','Fecha Registro','Valoraciones','Comentarios']);
    $rows = $pdo->query("
        SELECT u.id_usuario, u.username, u.nombre, u.apellidos, u.email, r.nombre_rol,
               u.fecha_registro,
               (SELECT COUNT(*) FROM valoraciones v WHERE v.id_usuario=u.id_usuario) AS vals,
               (SELECT COUNT(*) FROM comentarios  c WHERE c.id_usuario=u.id_usuario) AS coms
        FROM usuarios u JOIN roles r ON u.id_rol=r.id_rol
        ORDER BY u.fecha_registro DESC
    ")->fetchAll();
    foreach ($rows as $r) fputcsv($out, $r);

} elseif ($tipo === 'comentarios') {
    fputcsv($out, ['ID','Usuario','Juego','Comentario','Fecha','Editado']);
    $rows = $pdo->query("
        SELECT c.id_comentario, u.username, v.titulo,
               c.texto, c.fecha, c.editado
        FROM comentarios c
        JOIN usuarios u ON c.id_usuario=u.id_usuario
        JOIN videojuegos v ON c.id_juego=v.id_juego
        ORDER BY c.fecha DESC
    ")->fetchAll();
    foreach ($rows as $r) fputcsv($out, $r);

} elseif ($tipo === 'juegos') {
    fputcsv($out, ['ID','Título','Desarrollador','Precio','Puntuación Media','Estado','Fecha Lanzamiento','Valoraciones']);
    $rows = $pdo->query("
        SELECT v.id_juego, v.titulo, v.desarrollador, v.precio,
               COALESCE(AVG(val.puntuacion), 0) AS puntuacion_media,
               v.estado, v.fecha_lanzamiento,
               COUNT(val.id_valoracion) AS num_val
        FROM videojuegos v
        LEFT JOIN valoraciones val ON v.id_juego=val.id_juego
        GROUP BY v.id_juego, v.titulo, v.desarrollador, v.precio,
                 v.estado, v.fecha_lanzamiento
        ORDER BY v.id_juego
    ")->fetchAll();
    foreach ($rows as $r) fputcsv($out, $r);
}

fclose($out);
exit;
