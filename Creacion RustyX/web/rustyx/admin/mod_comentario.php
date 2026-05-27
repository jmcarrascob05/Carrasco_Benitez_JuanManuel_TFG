<?php
require_once '../config.php';
requireAdmin();
$idCom   = (int)($_POST['id_comentario'] ?? 0);
$idJuego = (int)($_POST['id_juego']      ?? 0);
if ($idCom) {
    $pdo = getDB();
    // Guardamos datos del comentario antes de borrarlo para escribir el log.
    $stmt = $pdo->prepare("SELECT c.texto, u.username FROM comentarios c JOIN usuarios u ON c.id_usuario=u.id_usuario WHERE c.id_comentario=?");
    $stmt->execute([$idCom]);
    $com = $stmt->fetch();
    $pdo->prepare("DELETE FROM comentarios WHERE id_comentario=?")->execute([$idCom]);
    $detalle = "Comentario de @".($com['username']??'?').": \"".substr($com['texto']??'',0,80)."\"";
    adminLog($pdo, 'borrar_comentario', $detalle);
}
redirect($idJuego ? "/juego.php?id=$idJuego&ok=borrado" : '/admin/dashboard.php');
