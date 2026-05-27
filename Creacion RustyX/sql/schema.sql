-- =============================================
-- RustyX - Esquema de Base de Datos MariaDB
-- =============================================

CREATE DATABASE IF NOT EXISTS rustyxdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rustyxdb;

SET sql_mode = '';

-- Roles
CREATE TABLE IF NOT EXISTS roles (
    id_rol INT AUTO_INCREMENT PRIMARY KEY,
    nombre_rol VARCHAR(40) NOT NULL
);
INSERT INTO roles (nombre_rol) VALUES ('admin'), ('usuario'), ('tester'), ('desarrollador')
    ON DUPLICATE KEY UPDATE nombre_rol = nombre_rol;

-- Usuarios
CREATE TABLE IF NOT EXISTS usuarios (
    id_usuario    INT AUTO_INCREMENT PRIMARY KEY,
    nombre        VARCHAR(50)  NOT NULL,
    apellidos     VARCHAR(80)  NOT NULL,
    email         VARCHAR(80)  NOT NULL UNIQUE,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL,
    fecha_registro DATE NOT NULL,
    id_rol        INT NOT NULL DEFAULT 2,
    avatar_url    VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (id_rol) REFERENCES roles(id_rol)
);

-- Plataformas
CREATE TABLE IF NOT EXISTS plataformas (
    id_plataforma INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL
);
INSERT INTO plataformas (nombre) VALUES ('PC'),('PS5'),('Xbox Series X'),('Nintendo Switch'),('PS4'),('Xbox One'),('Mobile')
    ON DUPLICATE KEY UPDATE nombre = nombre;

-- Géneros
CREATE TABLE IF NOT EXISTS generos (
    id_genero INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL
);
INSERT INTO generos (nombre) VALUES ('Acción'),('Aventura'),('RPG'),('Shooter'),('Estrategia'),('Deportes'),('Simulación'),('Terror'),('Plataformas'),('Puzzle')
    ON DUPLICATE KEY UPDATE nombre = nombre;

-- Videojuegos
CREATE TABLE IF NOT EXISTS videojuegos (
    id_juego          INT AUTO_INCREMENT PRIMARY KEY,
    titulo            VARCHAR(100) NOT NULL,
    descripcion       TEXT,
    fecha_lanzamiento DATE,
    desarrollador     VARCHAR(80),
    precio            DECIMAL(5,2) DEFAULT 0.00,
    puntuacion_media  DECIMAL(3,2) DEFAULT 0.00,
    imagen_url        VARCHAR(255) DEFAULT NULL,
    youtube_url       VARCHAR(255) DEFAULT NULL,  -- trailer YouTube (embed ID o URL)
    estado            ENUM('disponible','proximo','descontinuado') DEFAULT 'disponible'
);

-- Tabla intermedia: videojuego_plataforma
CREATE TABLE IF NOT EXISTS videojuego_plataforma (
    id_juego      INT NOT NULL,
    id_plataforma INT NOT NULL,
    PRIMARY KEY (id_juego, id_plataforma),
    FOREIGN KEY (id_juego)      REFERENCES videojuegos(id_juego) ON DELETE CASCADE,
    FOREIGN KEY (id_plataforma) REFERENCES plataformas(id_plataforma)
);

-- Tabla intermedia: videojuego_genero
CREATE TABLE IF NOT EXISTS videojuego_genero (
    id_juego  INT NOT NULL,
    id_genero INT NOT NULL,
    PRIMARY KEY (id_juego, id_genero),
    FOREIGN KEY (id_juego)  REFERENCES videojuegos(id_juego) ON DELETE CASCADE,
    FOREIGN KEY (id_genero) REFERENCES generos(id_genero)
);

-- Valoraciones
CREATE TABLE IF NOT EXISTS valoraciones (
    id_valoracion INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario    INT NOT NULL,
    id_juego      INT NOT NULL,
    puntuacion    TINYINT NOT NULL CHECK (puntuacion BETWEEN 1 AND 10),
    fecha         DATE NOT NULL,
    UNIQUE KEY unique_valoracion (id_usuario, id_juego),
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    FOREIGN KEY (id_juego)   REFERENCES videojuegos(id_juego) ON DELETE CASCADE
);

-- Comentarios
CREATE TABLE IF NOT EXISTS comentarios (
    id_comentario INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario    INT NOT NULL,
    id_juego      INT NOT NULL,
    texto         TEXT NOT NULL,
    fecha         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    editado       TINYINT(1) NOT NULL DEFAULT 0,  -- flag si fue editado
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    FOREIGN KEY (id_juego)   REFERENCES videojuegos(id_juego) ON DELETE CASCADE
);

-- Listas
CREATE TABLE IF NOT EXISTS lista (
    id_lista     INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario   INT NOT NULL,
    nombre_lista VARCHAR(100) NOT NULL,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
);

-- Tabla intermedia: lista_videojuego
CREATE TABLE IF NOT EXISTS lista_videojuego (
    id_lista      INT NOT NULL,
    id_videojuego INT NOT NULL,
    fecha_agregado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    estado        ENUM('pendiente','jugando','completado','abandonado') DEFAULT 'pendiente',
    PRIMARY KEY (id_lista, id_videojuego),
    FOREIGN KEY (id_lista)      REFERENCES lista(id_lista) ON DELETE CASCADE,
    FOREIGN KEY (id_videojuego) REFERENCES videojuegos(id_juego) ON DELETE CASCADE
);

-- Log de acciones administrativas
CREATE TABLE IF NOT EXISTS admin_log (
    id_log      INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario  INT NOT NULL,
    accion      VARCHAR(100) NOT NULL,
    detalle     TEXT,
    fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
);

-- Triggers puntuacion_media
DELIMITER //
CREATE TRIGGER IF NOT EXISTS after_valoracion_insert
AFTER INSERT ON valoraciones FOR EACH ROW
BEGIN
    UPDATE videojuegos
    SET puntuacion_media = (SELECT AVG(puntuacion) FROM valoraciones WHERE id_juego = NEW.id_juego)
    WHERE id_juego = NEW.id_juego;
END//
CREATE TRIGGER IF NOT EXISTS after_valoracion_update
AFTER UPDATE ON valoraciones FOR EACH ROW
BEGIN
    UPDATE videojuegos
    SET puntuacion_media = (SELECT AVG(puntuacion) FROM valoraciones WHERE id_juego = NEW.id_juego)
    WHERE id_juego = NEW.id_juego;
END//
DELIMITER ;

-- Admin por defecto (contraseña: Admin1234!)
-- Regenerar hash: php -r "echo password_hash('Admin1234!', PASSWORD_BCRYPT, ['cost'=>12]);"
INSERT IGNORE INTO usuarios (nombre, apellidos, email, username, password, fecha_registro, id_rol)
VALUES ('Admin','RustyX','admin@rustyx.com','admin',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    CURDATE(), 1);

-- Datos de ejemplo
INSERT IGNORE INTO videojuegos (id_juego, titulo, descripcion, fecha_lanzamiento, desarrollador, precio, youtube_url, estado) VALUES
(1,'The Witcher 3','RPG de mundo abierto épico ambientado en un universo de fantasía oscura.','2015-05-19','CD Projekt Red',29.99,'c0i88t09agM','disponible'),
(2,'Elden Ring','Action RPG desarrollado por FromSoftware y George R.R. Martin.','2022-02-25','FromSoftware',59.99,'E3Huy2cdih0','disponible'),
(3,'Cyberpunk 2077','RPG de acción en primera persona en un mundo futurista.','2020-12-10','CD Projekt Red',39.99,'8X2kIfS6fb8','disponible'),
(4,'Hollow Knight','Metroidvania de acción y aventura en un reino subterráneo.','2017-02-24','Team Cherry',14.99,'UAO2JGkloqY','disponible'),
(5,'God of War','Aventura de acción con Kratos y su hijo Atreus en la mitología nórdica.','2018-04-20','Santa Monica Studio',39.99,'K0u_kAWLJOA','disponible');

INSERT IGNORE INTO videojuego_genero (id_juego, id_genero) VALUES
(1,3),(1,2),(2,3),(2,1),(3,3),(3,4),(4,1),(4,9),(5,1),(5,2);

INSERT IGNORE INTO videojuego_plataforma (id_juego, id_plataforma) VALUES
(1,1),(1,5),(1,6),(2,1),(2,2),(2,3),(3,1),(3,2),(3,3),(4,1),(4,4),(5,2),(5,5);

