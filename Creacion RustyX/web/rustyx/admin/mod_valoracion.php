<?php
require_once '../config.php';
requireAdmin();
$idJuego  = (int)($_POST['id_juego']  ?? 0);
$username = trim($_POST['username']   ?? '');
if ($idJuego && $username) {
    $pdo  = getDB();
    // Buscamos el usuario por username porque el formulario del dashboard envía ese dato.
    $stmt = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE username=? LIMIT 1");
    $stmt->execute([$username]);
    $uid  = $stmt->fetchColumn();
    if ($uid) {
        $stmtV = $pdo->prepare("SELECT puntuacion FROM valoraciones WHERE id_juego=? AND id_usuario=?");
        $stmtV->execute([$idJuego,$uid]);
        $punt = $stmtV->fetchColumn();
        $pdo->prepare("DELETE FROM valoraciones WHERE id_juego=? AND id_usuario=?")->execute([$idJuego,$uid]);
        // Al borrar una valoración recalculamos la media del juego.
        updateGameScore($pdo, $idJuego);
        try {
            $titulo = $pdo->prepare("SELECT titulo FROM videojuegos WHERE id_juego=?");
            $titulo->execute([$idJuego]);
            $tit = $titulo->fetchColumn();
            adminLog($pdo, 'borrar_valoracion', "@$username · $tit · puntuación: $punt");
        } catch(Exception $e) {}
    }
}
redirect('/admin/dashboard.php');
