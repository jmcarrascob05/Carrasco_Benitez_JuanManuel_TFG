<?php
// =============================================
// RustyX - Configuración central
// =============================================

// Base de datos
define('DB_HOST',    '192.168.30.10');
define('DB_NAME',    'rustyxdb');
define('DB_USER',    'rustyxuser');
define('DB_PASS',    'rustyxpass');
define('DB_CHARSET', 'utf8mb4');

// BASE_PATH vacío porque nginx apunta root a /var/www/rustyx directamente
define('BASE_PATH', '');

// Avatares
define('AVATAR_DIR', __DIR__ . '/assets/avatars/');
define('AVATAR_URL', '/assets/avatars/');

// Portadas de juegos subidas desde el panel admin
define('GAME_DIR', __DIR__ . '/assets/games/');
define('GAME_URL', '/assets/games/');

// ── Sesión ──────────────────────────────────
ini_set('session.save_path', '/tmp');
ini_set('session.cookie_path', '/');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Base de datos ────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}

// ── Helpers ──────────────────────────────────
// Estas funciones pequeñas evitan repetir código en todas las páginas.
// Por ejemplo: comprobar login, escapar HTML, formatear precios o redirigir.
function isLoggedIn(): bool {
    return isset($_SESSION['id_usuario']);
}

function isAdmin(): bool {
    return isset($_SESSION['id_rol']) && $_SESSION['id_rol'] == 1;
}

function currentUser(): array {
    return $_SESSION ?? [];
}

function requireLogin(string $redirectTo = '/login.php'): void {
    if (!isLoggedIn()) redirect($redirectTo);
}

function requireAdmin(): void {
    if (!isAdmin()) redirect('/index.php');
}

function redirect(string $path): void {
    if (str_starts_with($path, 'http')) {
        header("Location: $path");
    } else {
        header("Location: " . BASE_PATH . $path);
    }
    exit;
}

function h(mixed $value): string {
    // Se usa siempre que mostramos texto que viene de usuario o BD.
    // Así evitamos que se pueda inyectar HTML o JavaScript en la página.
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function isPost(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function postAction(): string {
    return $_POST['accion'] ?? '';
}

function selected(mixed $a, mixed $b): string {
    // Devuelve "selected" para dejar marcada una opción de un <select>.
    return $a == $b ? 'selected' : '';
}

function checked(mixed $a, mixed $b): string {
    // Igual que selected(), pero para inputs tipo radio o checkbox.
    return $a == $b ? 'checked' : '';
}

function money(mixed $price): string {
    $price = (float)$price;
    return $price > 0 ? number_format($price, 2) . '€' : 'Gratis';
}

function dateEs(?string $date, string $format = 'd/m/Y'): string {
    return $date ? date($format, strtotime($date)) : '—';
}

function shortText(?string $text, int $max = 120): string {
    $text = (string)$text;
    return strlen($text) > $max ? substr($text, 0, $max) . '...' : $text;
}

function scoreColor(float $score): string {
    if ($score >= 7) return 'var(--success)';
    if ($score >= 5) return '#FFD600';
    return $score > 0 ? 'var(--danger)' : 'var(--muted)';
}

function flashMessage(string $type, string $text): void {
    echo '<div class="alert alert-' . h($type) . '">' . h($text) . '</div>';
}

function fetchColumnList(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function updateGameScore(PDO $pdo, int $idJuego): void {
    // Recalcula la media desde PHP. Esto evita depender de triggers SQL,
    // que en algunos hostings gratuitos pueden no importarse correctamente.
    $pdo->prepare("
        UPDATE videojuegos
        SET puntuacion_media = COALESCE(
            (SELECT AVG(puntuacion) FROM valoraciones WHERE id_juego = ?),
            0
        )
        WHERE id_juego = ?
    ")->execute([$idJuego, $idJuego]);
}

function adminLog(PDO $pdo, string $accion, string $detalle = ''): void {
    // El log no debe romper la acción principal si falla.
    if (!isAdmin()) return;
    try {
        $fechaMadrid = (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO admin_log (id_usuario, accion, detalle, fecha) VALUES (?, ?, ?, ?)")
            ->execute([$_SESSION['id_usuario'], $accion, $detalle, $fechaMadrid]);
    } catch (Exception $e) {
        // Ignoramos errores para que el panel siga funcionando aunque no exista admin_log.
    }
}

/**
 * Devuelve la URL pública del avatar o una cadena vacía si no existe.
 * Acepta tanto el filename suelto como la URL completa (para compatibilidad).
 */
function avatarUrl(?string $avatar_url): string {
    if (!$avatar_url) return '';
    // Si ya es una URL completa, extraer solo el filename
    $filename = basename($avatar_url);
    $fullPath = AVATAR_DIR . $filename;
    if (file_exists($fullPath)) {
        return AVATAR_URL . rawurlencode($filename) . '?v=' . filemtime($fullPath);
    }
    return '';
}

// Crear directorio de avatares si no existe
if (!is_dir(AVATAR_DIR)) {
    @mkdir(AVATAR_DIR, 0755, true);
}

// Crear directorio de portadas si no existe
if (!is_dir(GAME_DIR)) {
    @mkdir(GAME_DIR, 0755, true);
}
