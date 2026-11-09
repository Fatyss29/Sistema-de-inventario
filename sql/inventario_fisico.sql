-- =====================================================================
--  INVENTARIO FÍSICO — INSTALACIÓN COMPLETA DESDE CERO
--  Base de datos: inventario_fisico   (MySQL 5.7+ / MariaDB 10.3+ de XAMPP)
--
--  Incluye todo: usuarios y roles, inventarios, marbetes, conteos,
--  catálogo de partes con COSTO, costos congelados por inventario,
--  existencias de Epicor, EQUIPOS (supervisor → contadores),
--  ASIGNACIÓN de 2do conteo (admin → supervisor → contador), bitácoras.
--  (Ya NO hace falta correr ninguna migracion_*.sql)
--
--  ⚠ ATENCIÓN: BORRA la base inventario_fisico completa si ya existe
--    (marbetes, conteos, usuarios… todo) y la crea en blanco.
--
--  Cómo usarlo:
--    phpMyAdmin → pestaña "Importar" (sin seleccionar ninguna base) → este archivo.
--    o en consola:  C:\xampp\mysql\bin\mysql -u root < inventario_fisico.sql
--
--  Usuario inicial:  No. empleado = 1   Contraseña = Admin123  (cámbiala al entrar)
-- =====================================================================

DROP DATABASE IF EXISTS inventario_fisico;
CREATE DATABASE IF NOT EXISTS inventario_fisico
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE inventario_fisico;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
--  Usuarios y roles
--    CONTADOR   → persona que cuenta. NO entra al sistema: su supervisor lo
--                 elige en la pantalla de conteo. tipo_contador: PT, MP o AMBOS.
--                 id_supervisor = supervisor que lo tiene a cargo.
--    SUPERVISOR → opera las pantallas de conteo con la gente de su equipo,
--                 ve solo lo que contó su gente y reparte el 2do conteo.
--    ADMIN      → asigna equipos, reparte el 2do conteo entre supervisores,
--                 ve si coinciden los conteos y decide cuál vale; comparativo
--                 vs Epicor, usuarios, inventarios y sincronización.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
  id_usuario     INT AUTO_INCREMENT PRIMARY KEY,
  nombre         VARCHAR(120) NOT NULL,
  num_empleado   VARCHAR(20)  NOT NULL,
  contrasena     VARCHAR(255) NOT NULL,              -- password_hash() de PHP
  rol            ENUM('CONTADOR','SUPERVISOR','ADMIN') NOT NULL DEFAULT 'CONTADOR',
  tipo_contador  ENUM('PT','MP','AMBOS') NOT NULL DEFAULT 'AMBOS',
  id_supervisor  INT          NULL,               -- (contadores) supervisor que lo tiene a cargo
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  ultimo_acceso  DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usuarios_empleado (num_empleado),
  INDEX idx_usuarios_rol (rol),
  INDEX idx_usuarios_supervisor (id_supervisor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Inventarios (cada evento de inventario físico: "Inventario Dic 2026")
--  Solo puede haber uno ABIERTO a la vez; ahí cuentan los contadores.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventarios (
  id_inventario     INT AUTO_INCREMENT PRIMARY KEY,
  nombre            VARCHAR(120) NOT NULL,
  descripcion       VARCHAR(255) NULL,
  almacenes         VARCHAR(255) NULL,   -- almacenes de Epicor a comparar, separados por coma (vacío = todos)
  estado            ENUM('ABIERTO','CERRADO') NOT NULL DEFAULT 'ABIERTO',
  fecha_foto_epicor DATETIME     NULL,   -- cuándo se tomó la "foto" de existencias de Epicor
  creado_por        INT          NOT NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cerrado_at        DATETIME     NULL,
  CONSTRAINT fk_inv_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_inv_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Catálogo de partes ACTIVAS de Epicor (se sincroniza por BAQ)
--  Sirve para validar el QR de PT y para el buscador de MP.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS partes (
  parte        VARCHAR(50)  NOT NULL,
  descripcion  VARCHAR(255) NOT NULL DEFAULT '',
  clase        VARCHAR(10)  NOT NULL DEFAULT '',   -- Part.ClassID
  tipo         VARCHAR(5)   NOT NULL DEFAULT '',   -- Part.TypeCode (M = fabricada / P = comprada)
  uom          VARCHAR(10)  NOT NULL DEFAULT '',   -- Part.IUM (KG, PZ, ...)
  lleva_lote   TINYINT(1)   NOT NULL DEFAULT 0,    -- Part.TrackLots
  costo        DECIMAL(18,5) NOT NULL DEFAULT 0,   -- costo unitario de Epicor (PartCost según método de costeo)
  activo       TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (parte),
  INDEX idx_partes_activo (activo),
  INDEX idx_partes_clase (clase)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lotes que existen en Epicor por parte (para que no escriban 0 en vez de O)
CREATE TABLE IF NOT EXISTS lotes (
  parte       VARCHAR(50) NOT NULL,
  lote        VARCHAR(50) NOT NULL,
  updated_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (parte, lote),
  INDEX idx_lotes_lote (lote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Costo unitario congelado al tomar la foto de Epicor de cada inventario
-- (así el comparativo en $ no cambia si después se mueven los costos en Epicor)
CREATE TABLE IF NOT EXISTS inventario_costos (
  id_inventario  INT           NOT NULL,
  parte          VARCHAR(50)   NOT NULL,
  costo          DECIMAL(18,5) NOT NULL DEFAULT 0,
  PRIMARY KEY (id_inventario, parte),
  CONSTRAINT fk_ic_inv FOREIGN KEY (id_inventario) REFERENCES inventarios(id_inventario)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Foto" de existencias de Epicor para cada inventario (PartBin)
CREATE TABLE IF NOT EXISTS epicor_existencias (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_inventario  INT           NOT NULL,
  parte          VARCHAR(50)   NOT NULL,
  almacen        VARCHAR(20)   NOT NULL DEFAULT '',
  ubicacion      VARCHAR(30)   NOT NULL DEFAULT '',  -- BinNum
  lote           VARCHAR(50)   NOT NULL DEFAULT '',
  cantidad       DECIMAL(18,4) NOT NULL DEFAULT 0,
  uom            VARCHAR(10)   NOT NULL DEFAULT '',
  CONSTRAINT fk_exi_inv FOREIGN KEY (id_inventario) REFERENCES inventarios(id_inventario)
    ON UPDATE CASCADE ON DELETE CASCADE,
  INDEX idx_exi_inv_parte (id_inventario, parte, lote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Marbetes: lo que se pega en la tarima / material contado.
--  Cada marbete tiene uno o más conteos (1er, 2do, reconteos).
--  Folio impreso = id_marbete con ceros (MB-000123). El QR trae ese folio.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS marbetes (
  id_marbete       BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_inventario    INT          NOT NULL,
  tipo             ENUM('PT','MP') NOT NULL,
  ubicacion        VARCHAR(100) NULL,           -- texto libre: rack, pasillo, área
  estado           ENUM('EN_CONTEO','CONTADO','COINCIDE','DIFERENCIA','RECONTEO','VALIDADO','CANCELADO')
                   NOT NULL DEFAULT 'EN_CONTEO',
  conteo_vigente   TINYINT      NULL,           -- número de conteo que cuenta como "real"
  conteo_final     TINYINT      NULL,           -- conteo elegido por supervisor (o el que coincidió)
  reconteo_pendiente TINYINT(1) NOT NULL DEFAULT 0, -- el supervisor pidió contar otra vez
  sup_reconteo     INT          NULL,           -- supervisor al que el ADMIN asignó el siguiente conteo
  sup_asignado_por INT          NULL,
  sup_asignado_at  DATETIME     NULL,
  sup_asignado_conteo TINYINT   NULL,           -- conteos cerrados que tenía al asignarlo al supervisor
  asignado_a       INT          NULL,           -- contador (del equipo de sup_reconteo) que hará el siguiente conteo
  asignado_por     INT          NULL,
  asignado_at      DATETIME     NULL,
  asignado_conteo  TINYINT      NULL,           -- conteos cerrados que tenía al asignarlo (para saber si ya lo hizo)
  comentario       TEXT         NULL,           -- justificación del supervisor
  validado_por     INT          NULL,
  validado_at      DATETIME     NULL,
  created_by       INT          NOT NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_mb_inv FOREIGN KEY (id_inventario) REFERENCES inventarios(id_inventario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_mb_creado FOREIGN KEY (created_by) REFERENCES usuarios(id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_mb_valida FOREIGN KEY (validado_por) REFERENCES usuarios(id_usuario)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_mb_inv_estado (id_inventario, estado),
  INDEX idx_mb_asignado (id_inventario, asignado_a),
  INDEX idx_mb_sup_reconteo (id_inventario, sup_reconteo),
  INDEX idx_mb_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conteos (
  id_conteo      BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_marbete     BIGINT       NOT NULL,
  num_conteo     TINYINT      NOT NULL,         -- 1 = primer conteo, 2 = segundo, 3+ = reconteos
  id_usuario     INT          NOT NULL,         -- quién contó (contador)
  id_supervisor  INT          NULL,             -- supervisor que operó el conteo (su equipo)
  estado         ENUM('ABIERTO','CERRADO','CANCELADO') NOT NULL DEFAULT 'ABIERTO',
  inicio         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fin            DATETIME     NULL,
  total_lineas   INT          NOT NULL DEFAULT 0,  -- cajas (PT) o renglones (MP)
  total_cantidad DECIMAL(18,4) NOT NULL DEFAULT 0,
  ip             VARCHAR(45)  NULL,
  CONSTRAINT fk_ct_marbete FOREIGN KEY (id_marbete) REFERENCES marbetes(id_marbete)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ct_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_ct_marbete (id_marbete, num_conteo),
  INDEX idx_ct_usuario_fecha (id_usuario, inicio),
  INDEX idx_ct_supervisor (id_supervisor, inicio),
  INDEX idx_ct_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Renglones del conteo: una caja escaneada (PT) o una captura manual (MP)
CREATE TABLE IF NOT EXISTS conteo_detalle (
  id_detalle        BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_conteo         BIGINT        NOT NULL,
  parte             VARCHAR(50)   NOT NULL,
  lote              VARCHAR(50)   NOT NULL DEFAULT '',  -- PT: el JOB  ·  MP: lote de la etiqueta
  cantidad          DECIMAL(18,4) NOT NULL,
  uom               VARCHAR(10)   NOT NULL DEFAULT '',
  qr                VARCHAR(255)  NULL,                 -- QR completo de la caja (solo PT)
  id_caja           BIGINT        NULL,                 -- ID de caja del QR (solo PT)
  lote_fuera_epicor TINYINT(1)    NOT NULL DEFAULT 0,   -- MP: lote escrito a mano que no existe en Epicor
  id_usuario        INT           NOT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cd_conteo FOREIGN KEY (id_conteo) REFERENCES conteos(id_conteo)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_cd_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  UNIQUE KEY uq_cd_conteo_qr (id_conteo, qr),           -- misma caja 2 veces en el mismo conteo = duplicado
  INDEX idx_cd_qr (qr),
  INDEX idx_cd_parte_lote (parte, lote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historial de cada marbete (quién lo abrió, cerró, pidió reconteo, justificó...)
CREATE TABLE IF NOT EXISTS marbete_eventos (
  id_evento   BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_marbete  BIGINT       NOT NULL,
  id_usuario  INT          NOT NULL,
  accion      VARCHAR(40)  NOT NULL,
  comentario  TEXT         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ev_marbete FOREIGN KEY (id_marbete) REFERENCES marbetes(id_marbete)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ev_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_ev_marbete (id_marbete)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bitácora general (accesos, usuarios, sincronizaciones, cambios de inventario)
CREATE TABLE IF NOT EXISTS bitacora (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_usuario  INT          NULL,
  accion      VARCHAR(60)  NOT NULL,
  detalle     TEXT         NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bit_fecha (created_at),
  INDEX idx_bit_usuario (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registro de cada sincronización con Epicor
CREATE TABLE IF NOT EXISTS sync_epicor_log (
  id_sync     INT AUTO_INCREMENT PRIMARY KEY,
  tipo        VARCHAR(20) NOT NULL,                  -- PARTES | LOTES | EXISTENCIAS | CSV
  origen      ENUM('AUTOMATICO','MANUAL') NOT NULL DEFAULT 'MANUAL',
  id_usuario  INT      NULL,
  inicio      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fin         DATETIME NULL,
  estado      ENUM('EN_PROCESO','OK','ERROR') NOT NULL DEFAULT 'EN_PROCESO',
  registros   INT      NOT NULL DEFAULT 0,
  detalle     TEXT     NULL,
  INDEX idx_sync_inicio (inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
--  Usuario administrador inicial
--  No. empleado: 1   Contraseña: Admin123   (cámbiala al entrar)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO usuarios (id_usuario, nombre, num_empleado, contrasena, rol, tipo_contador)
VALUES (1, 'Administrador', '1', '$2y$10$OisGHXa9bgsLrTpi0sAzw.EsPejLLosAczYIasWxZhnZ3VC.0Nn3O', 'ADMIN', 'AMBOS');
