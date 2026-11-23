<?php
// app/Inventario.php
// Reglas del conteo: marbetes, conteos, cajas PT, capturas MP, comparación
// de 1er vs 2do conteo y acciones del supervisor.
declare(strict_types=1);

/** Error de negocio: el mensaje se muestra al usuario y se dice en voz alta. */
final class InvError extends RuntimeException
{
    public string $voz;
    public array $extra;

    public function __construct(string $msg, string $voz = '', array $extra = [])
    {
        parent::__construct($msg);
        $this->voz = $voz !== '' ? $voz : $msg;
        $this->extra = $extra;
    }
}

final class Inv
{
    /**
     * Usuario que opera la pantalla (supervisor o admin). Los conteos quedan a nombre
     * del CONTADOR elegido, y en conteos.id_supervisor se guarda quién lo operó.
     */
    public static ?array $op = null;

    private static function opId(array $u): int
    {
        return (int)(self::$op['id_usuario'] ?? $u['id_usuario']);
    }

    private static function opRol(array $u): string
    {
        return (string)(self::$op['rol'] ?? $u['rol']);
    }

    // ================================================================== //
    //  QR de caja de PT  (formato JOB*PARTE*PIEZAS*IDCAJA)              //
    //  Formato: JOB*PARTE*PIEZAS*IDCAJA                                  //
    // ================================================================== //
    public static function parseQrPT(string $raw): array
    {
        $qr = strtoupper(trim(str_replace(["\r", "\n", "\t"], '', $raw)));
        if ($qr === '' || strpos($qr, '*') === false) {
            throw new InvError('Código inválido.', 'Código inválido.');
        }
        $p = explode('*', $qr);
        if (count($p) < 4) {
            throw new InvError('Formato inválido del código escaneado.', 'Código inválido.');
        }
        $job   = trim($p[0]);
        $parte = trim($p[1]);
        // Acepta separador de miles: "1,920" / "1.920" / "1 920"
        $piezas = (int)preg_replace('/[.,\s]/', '', trim($p[2]));
        $idCaja = trim($p[3]);

        if ($job === '' || $parte === '') {
            throw new InvError('El QR no trae job o número de parte.', 'Código inválido.');
        }
        if ($piezas <= 0) {
            throw new InvError('Número de piezas inválido.', 'Cantidad inválida.');
        }
        if (!preg_match('/^\d+$/', $idCaja)) {
            throw new InvError('ID de caja inválido (debe ser numérico).', 'Código inválido.');
        }
        return ['qr' => $qr, 'job' => $job, 'parte' => $parte, 'piezas' => $piezas, 'id_caja' => $idCaja];
    }

    /** Convierte "MB-000123", "MB000123" o "123" en 123. Null si no es marbete. */
    public static function idDeMarbete(string $codigo): ?int
    {
        $c = strtoupper(trim($codigo));
        $pref = preg_quote(rtrim(strtoupper((string)cfg('prefijo_marbete', 'MB-')), '-'), '/');
        if (preg_match('/^(?:' . $pref . '-?)?0*(\d{1,12})$/', $c, $m)) {
            return (int)$m[1] ?: null;
        }
        return null;
    }

    public static function esCodigoMarbete(string $codigo): bool
    {
        $pref = preg_quote(rtrim(strtoupper((string)cfg('prefijo_marbete', 'MB-')), '-'), '/');
        return (bool)preg_match('/^' . $pref . '-?\d+$/', strtoupper(trim($codigo)));
    }

    // ================================================================== //
    //  Consultas                                                          //
    // ================================================================== //
    public static function conteoAbiertoDe(int $idUsuario): ?array
    {
        $st = db()->prepare(
            "SELECT c.*, m.tipo, m.ubicacion, m.id_inventario, m.estado AS estado_marbete
               FROM conteos c JOIN marbetes m ON m.id_marbete = c.id_marbete
              WHERE c.id_usuario = ? AND c.estado = 'ABIERTO'
              ORDER BY c.id_conteo DESC LIMIT 1"
        );
        $st->execute([$idUsuario]);
        return $st->fetch() ?: null;
    }

    public static function lineas(int $idConteo): array
    {
        $st = db()->prepare(
            "SELECT d.*, p.descripcion
               FROM conteo_detalle d LEFT JOIN partes p ON p.parte = d.parte
              WHERE d.id_conteo = ? ORDER BY d.id_detalle DESC"
        );
        $st->execute([$idConteo]);
        return $st->fetchAll();
    }

    public static function marbete(int $id): ?array
    {
        $st = db()->prepare(
            "SELECT m.*, u.nombre AS creado_por_nombre, v.nombre AS validado_por_nombre
               FROM marbetes m
               JOIN usuarios u ON u.id_usuario = m.created_by
          LEFT JOIN usuarios v ON v.id_usuario = m.validado_por
              WHERE m.id_marbete = ?"
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function conteosDe(int $idMarbete): array
    {
        $st = db()->prepare(
            "SELECT c.*, u.nombre AS usuario_nombre, u.num_empleado
               FROM conteos c JOIN usuarios u ON u.id_usuario = c.id_usuario
              WHERE c.id_marbete = ? ORDER BY c.num_conteo, c.id_conteo"
        );
        $st->execute([$idMarbete]);
        return $st->fetchAll();
    }

    /** [ 'PARTE|LOTE' => ['parte','lote','cantidad','lineas','uom'] ] */
    public static function resumen(int $idConteo): array
    {
        $st = db()->prepare(
            "SELECT parte, lote, SUM(cantidad) AS cantidad, COUNT(*) AS lineas, MAX(uom) AS uom
               FROM conteo_detalle WHERE id_conteo = ? GROUP BY parte, lote ORDER BY parte, lote"
        );
        $st->execute([$idConteo]);
        $r = [];
        foreach ($st->fetchAll() as $row) {
            $r[$row['parte'] . '|' . $row['lote']] = $row;
        }
        return $r;
    }

    public static function resumenesIguales(array $a, array $b): bool
    {
        $tol = (float)cfg('reglas.tolerancia', 0);
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            $qa = (float)($a[$k]['cantidad'] ?? 0);
            $qb = (float)($b[$k]['cantidad'] ?? 0);
            if (abs($qa - $qb) > $tol + 0.00001) return false;
        }
        return true;
    }

    private static function totales(int $idConteo): void
    {
        db()->prepare(
            "UPDATE conteos c SET
               total_lineas   = (SELECT COUNT(*) FROM conteo_detalle d WHERE d.id_conteo = c.id_conteo),
               total_cantidad = (SELECT COALESCE(SUM(cantidad),0) FROM conteo_detalle d WHERE d.id_conteo = c.id_conteo)
             WHERE c.id_conteo = ?"
        )->execute([$idConteo]);
    }

    public static function evento(int $idMarbete, int $idUsuario, string $accion, string $comentario = ''): void
    {
        db()->prepare("INSERT INTO marbete_eventos (id_marbete, id_usuario, accion, comentario) VALUES (?,?,?,?)")
            ->execute([$idMarbete, $idUsuario, $accion, $comentario !== '' ? $comentario : null]);
    }

    private static function puedeContar(array $u, string $tipo): void
    {
        if ($u['rol'] !== 'CONTADOR') return;
        if ($u['tipo_contador'] !== 'AMBOS' && $u['tipo_contador'] !== $tipo) {
            throw new InvError("{$u['nombre']} no está habilitado para contar {$tipo}.");
        }
    }

    private static function yaTieneAbierto(array $u): void
    {
        if ($ab = self::conteoAbiertoDe((int)$u['id_usuario'])) {
            throw new InvError($u['nombre'] . ' ya tiene abierto el marbete ' . folio($ab['id_marbete']) . '. Ciérralo primero.', 'Ya hay una tarima abierta.');
        }
    }

    private static function inventarioAbiertoObligatorio(): array
    {
        $inv = inventario_abierto();
        if (!$inv) throw new InvError('No hay un inventario abierto. Pide al administrador que abra uno.');
        return $inv;
    }

    // ================================================================== //
    //  Abrir conteos                                                      //
    // ================================================================== //

    /** Nuevo marbete = primer conteo (CCOPEN en PT / botón "Nuevo marbete" en MP). */
    public static function abrirNuevo(array $u, string $tipo, string $ubicacion = ''): array
    {
        $tipo = $tipo === 'MP' ? 'MP' : 'PT';
        self::puedeContar($u, $tipo);
        $inv = self::inventarioAbiertoObligatorio();
        self::yaTieneAbierto($u);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO marbetes (id_inventario, tipo, ubicacion, estado, created_by) VALUES (?,?,?, 'EN_CONTEO', ?)")
                ->execute([$inv['id_inventario'], $tipo, mb_substr(trim($ubicacion), 0, 100) ?: null, self::opId($u)]);
            $idM = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO conteos (id_marbete, num_conteo, id_usuario, id_supervisor, ip) VALUES (?,1,?,?,?)")
                ->execute([$idM, $u['id_usuario'], self::opId($u), ip()]);
            self::evento($idM, self::opId($u), 'ABRE_CONTEO_1', 'Contador: ' . $u['nombre']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::conteoAbiertoDe((int)$u['id_usuario']);
    }

    /** Segundo conteo o reconteo: el contador escanea el QR del marbete. */
    public static function abrirMarbete(array $u, string $codigo, string $tipo): array
    {
        $tipo = $tipo === 'MP' ? 'MP' : 'PT';
        self::puedeContar($u, $tipo);
        $inv = self::inventarioAbiertoObligatorio();
        self::yaTieneAbierto($u);
        $id = self::idDeMarbete($codigo);
        $m = $id ? self::marbete($id) : null;
        if (!$m) throw new InvError('Marbete no encontrado.', 'Marbete no encontrado.');
        if ((int)$m['id_inventario'] !== (int)$inv['id_inventario']) {
            throw new InvError('Ese marbete es de otro inventario (' . folio($id) . ').', 'Marbete de otro inventario.');
        }
        if ($m['tipo'] !== $tipo) {
            throw new InvError("El marbete " . folio($id) . " es de {$m['tipo']}; recuéntalo en la pantalla de {$m['tipo']}.");
        }

        switch ($m['estado']) {
            case 'CANCELADO':
                throw new InvError('El marbete ' . folio($id) . ' está cancelado.', 'Marbete cancelado.');
            case 'EN_CONTEO':
                throw new InvError('Alguien está contando el marbete ' . folio($id) . ' en este momento.', 'Marbete en conteo.');
            case 'VALIDADO':
                throw new InvError('El administrador ya eligió el conteo del marbete ' . folio($id) . '. Solo se recuenta si él lo pide.', 'Marbete ya cerrado.');
            case 'COINCIDE':
            case 'DIFERENCIA':
                throw new InvError('El marbete ' . folio($id) . ' ya tiene sus dos conteos. Solo se recuenta si el administrador lo pide.', 'Ya tiene dos conteos.');
        }

        // Asignaciones del 2do conteo / reconteo:
        //   admin → asigna el marbete a un SUPERVISOR
        //   supervisor → lo asigna a un CONTADOR de su equipo
        if (self::opRol($u) === 'SUPERVISOR') {
            if ((int)$m['sup_reconteo'] !== self::opId($u) || self::asignacionSupTerminada($m)) {
                throw new InvError('El marbete ' . folio($id) . ' no está asignado a tu equipo para el siguiente conteo. Lo asigna el administrador.', 'Marbete no asignado a tu equipo.');
            }
            if (!$m['asignado_a'] || self::asignacionTerminada($m)) {
                throw new InvError('Primero asigna el marbete ' . folio($id) . ' a un contador en "2do conteo".', 'Asigna primero un contador.');
            }
        }
        if ($m['asignado_a'] && !self::asignacionTerminada($m) && (int)$m['asignado_a'] !== (int)$u['id_usuario']) {
            $st = db()->prepare("SELECT nombre FROM usuarios WHERE id_usuario = ?");
            $st->execute([$m['asignado_a']]);
            throw new InvError('El marbete ' . folio($id) . ' está asignado a ' . $st->fetchColumn() . ' para el siguiente conteo.', 'Este marbete está asignado a otra persona.');
        }

        $previos = array_values(array_filter(self::conteosDe($id), fn($c) => $c['estado'] === 'CERRADO'));
        $ultimo  = end($previos) ?: null;
        if ($ultimo && cfg('reglas.reconteo_otro_usuario', true) && (int)$ultimo['id_usuario'] === (int)$u['id_usuario']) {
            throw new InvError('El conteo anterior de este marbete lo hizo ' . $u['nombre'] . '. El siguiente conteo lo debe hacer otra persona.', 'Debe contar otra persona.');
        }
        $num = $ultimo ? (int)$ultimo['num_conteo'] + 1 : 1;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO conteos (id_marbete, num_conteo, id_usuario, id_supervisor, ip) VALUES (?,?,?,?,?)")
                ->execute([$id, $num, $u['id_usuario'], self::opId($u), ip()]);
            $pdo->prepare("UPDATE marbetes SET estado = 'EN_CONTEO' WHERE id_marbete = ?")->execute([$id]);
            self::evento($id, self::opId($u), 'ABRE_CONTEO_' . $num, 'Contador: ' . $u['nombre']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::conteoAbiertoDe((int)$u['id_usuario']);
    }

    // ================================================================== //
    //  Asignaciones de 2do conteo / reconteo                              //
    // ================================================================== //

    /** ¿Ya se hizo el conteo para el que se asignó? (hay más conteos cerrados que al asignarlo) */
    public static function asignacionTerminada(array $m): bool
    {
        if (!$m['asignado_a']) return false;
        $st = db()->prepare("SELECT COUNT(*) FROM conteos WHERE id_marbete = ? AND estado = 'CERRADO'");
        $st->execute([$m['id_marbete']]);
        return (int)$st->fetchColumn() > (int)$m['asignado_conteo'];
    }

    /** ¿Ya se hizo el conteo para el que el admin asignó el marbete al supervisor? */
    public static function asignacionSupTerminada(array $m): bool
    {
        if (!$m['sup_reconteo']) return false;
        $st = db()->prepare("SELECT COUNT(*) FROM conteos WHERE id_marbete = ? AND estado = 'CERRADO'");
        $st->execute([$m['id_marbete']]);
        return (int)$st->fetchColumn() > (int)$m['sup_asignado_conteo'];
    }

    /** Supervisor que operó el último conteo cerrado del marbete (su equipo lo contó). */
    public static function supervisorUltimoConteo(int $idMarbete): ?int
    {
        $st = db()->prepare("SELECT id_supervisor FROM conteos WHERE id_marbete = ? AND estado = 'CERRADO' ORDER BY num_conteo DESC LIMIT 1");
        $st->execute([$idMarbete]);
        $v = $st->fetchColumn();
        return $v ? (int)$v : null;
    }

    /**
     * ADMIN: asigna marbetes a un SUPERVISOR para su siguiente conteo (2do / reconteo).
     * No se puede asignar al mismo supervisor cuyo equipo hizo el conteo anterior.
     */
    public static function asignarSupervisor(array $adm, array $ids, int $idSup): array
    {
        $st = db()->prepare("SELECT * FROM usuarios WHERE id_usuario = ? AND activo = 1 AND rol = 'SUPERVISOR'");
        $st->execute([$idSup]);
        $sup = $st->fetch();
        if (!$sup) throw new InvError('Elige un supervisor activo.');
        $ok = 0;
        $rech = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $m = self::marbete($id);
            if (!$m) continue;
            $f = folio($id);
            if (!in_array($m['estado'], ['CONTADO', 'RECONTEO'], true)) {
                $rech[$f] = 'está en "' . (ESTADOS_MARBETE[$m['estado']][0] ?? $m['estado']) . '" (solo se asignan los de 1 conteo o con reconteo pedido)';
                continue;
            }
            if (self::supervisorUltimoConteo($id) === $idSup) {
                $rech[$f] = "el conteo anterior lo hizo el equipo de {$sup['nombre']}";
                continue;
            }
            $cerr = count(array_filter(self::conteosDe($id), fn($x) => $x['estado'] === 'CERRADO'));
            db()->prepare(
                "UPDATE marbetes SET sup_reconteo = ?, sup_asignado_por = ?, sup_asignado_at = NOW(), sup_asignado_conteo = ?,
                        asignado_a = NULL, asignado_por = NULL, asignado_at = NULL, asignado_conteo = NULL
                  WHERE id_marbete = ?"
            )->execute([$idSup, $adm['id_usuario'], $cerr, $id]);
            self::evento($id, (int)$adm['id_usuario'], 'ASIGNA_SUPERVISOR', 'Equipo de ' . $sup['nombre'] . ' para el conteo ' . ($cerr + 1));
            $ok++;
        }
        return [$ok, $rech];
    }

    public static function quitarAsignacionSupervisor(array $adm, array $ids): int
    {
        $n = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $m = self::marbete($id);
            if (!$m || !$m['sup_reconteo']) continue;
            db()->prepare(
                "UPDATE marbetes SET sup_reconteo = NULL, sup_asignado_por = NULL, sup_asignado_at = NULL, sup_asignado_conteo = NULL,
                        asignado_a = NULL, asignado_por = NULL, asignado_at = NULL, asignado_conteo = NULL WHERE id_marbete = ?"
            )->execute([$id]);
            self::evento($id, (int)$adm['id_usuario'], 'QUITA_ASIGNACION_SUP');
            $n++;
        }
        return $n;
    }

    /**
     * SUPERVISOR: asigna a un CONTADOR de su equipo los marbetes que el admin le asignó.
     * Regresa [asignados, [folio => motivo de rechazo]].
     */
    public static function asignar(array $sup, array $ids, int $idUsuario): array
    {
        $st = db()->prepare("SELECT * FROM usuarios WHERE id_usuario = ? AND activo = 1 AND rol = 'CONTADOR'");
        $st->execute([$idUsuario]);
        $c = $st->fetch();
        if (!$c) throw new InvError('Elige un contador activo.');
        $esSup = $sup['rol'] === 'SUPERVISOR';
        if ($esSup && (int)$c['id_supervisor'] !== (int)$sup['id_usuario']) {
            throw new InvError("{$c['nombre']} no es de tu equipo.");
        }
        $ok = 0;
        $rech = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $m = self::marbete($id);
            if (!$m) continue;
            $f = folio($id);
            if ($esSup && ((int)$m['sup_reconteo'] !== (int)$sup['id_usuario'] || self::asignacionSupTerminada($m))) {
                $rech[$f] = 'no está asignado a tu equipo';
                continue;
            }
            if (!in_array($m['estado'], ['CONTADO', 'RECONTEO'], true)) {
                $rech[$f] = 'ya no está pendiente de conteo';
                continue;
            }
            if ($c['tipo_contador'] !== 'AMBOS' && $c['tipo_contador'] !== $m['tipo']) {
                $rech[$f] = "es de {$m['tipo']} y {$c['nombre']} solo cuenta {$c['tipo_contador']}";
                continue;
            }
            $cerr = array_values(array_filter(self::conteosDe($id), fn($x) => $x['estado'] === 'CERRADO'));
            $ult = end($cerr) ?: null;
            if ($ult && cfg('reglas.reconteo_otro_usuario', true) && (int)$ult['id_usuario'] === $idUsuario) {
                $rech[$f] = "el conteo anterior lo hizo {$c['nombre']}";
                continue;
            }
            db()->prepare("UPDATE marbetes SET asignado_a = ?, asignado_por = ?, asignado_at = NOW(), asignado_conteo = ? WHERE id_marbete = ?")
                ->execute([$idUsuario, $sup['id_usuario'], count($cerr), $id]);
            self::evento($id, (int)$sup['id_usuario'], 'ASIGNA', 'Asignado a ' . $c['nombre'] . ' para el conteo ' . (count($cerr) + 1));
            $ok++;
        }
        return [$ok, $rech];
    }

    public static function quitarAsignacion(array $sup, array $ids): int
    {
        $n = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $m = self::marbete($id);
            if (!$m || !$m['asignado_a']) continue;
            db()->prepare("UPDATE marbetes SET asignado_a = NULL, asignado_por = NULL, asignado_at = NULL, asignado_conteo = NULL WHERE id_marbete = ?")->execute([$id]);
            self::evento($id, (int)$sup['id_usuario'], 'QUITA_ASIGNACION');
            $n++;
        }
        return $n;
    }

    /**
     * Marbetes asignados (opcionalmente de un contador) con su estado:
     * terminado = ya se cerró el conteo asignado (verde); si no, pendiente (rojo).
     */
    public static function asignados(int $idInv, ?int $idUsuario = null, ?int $idSup = null): array
    {
        $sql = "SELECT m.*, u.nombre AS asignado_nombre, u.num_empleado AS asignado_num,
                       (SELECT COUNT(*) FROM conteos c WHERE c.id_marbete = m.id_marbete AND c.estado = 'CERRADO') AS cerrados,
                       (SELECT GROUP_CONCAT(DISTINCT d.parte ORDER BY d.parte SEPARATOR ', ')
                          FROM conteo_detalle d JOIN conteos c1 ON c1.id_conteo = d.id_conteo
                         WHERE c1.id_marbete = m.id_marbete AND c1.estado = 'CERRADO') AS partes,
                       (SELECT GROUP_CONCAT(DISTINCT d.lote ORDER BY d.lote SEPARATOR ', ')
                          FROM conteo_detalle d JOIN conteos c1 ON c1.id_conteo = d.id_conteo
                         WHERE c1.id_marbete = m.id_marbete AND c1.estado = 'CERRADO' AND d.lote <> '') AS lotes,
                       (SELECT uc.nombre FROM conteos c2 JOIN usuarios uc ON uc.id_usuario = c2.id_usuario
                         WHERE c2.id_marbete = m.id_marbete AND c2.estado = 'CERRADO' ORDER BY c2.num_conteo DESC LIMIT 1) AS ultimo_nombre,
                       (SELECT MAX(c3.fin) FROM conteos c3 WHERE c3.id_marbete = m.id_marbete AND c3.estado = 'CERRADO') AS ultimo_fin
                  FROM marbetes m JOIN usuarios u ON u.id_usuario = m.asignado_a
                 WHERE m.id_inventario = ? AND m.asignado_a IS NOT NULL" . ($idUsuario ? ' AND m.asignado_a = ?' : '') . ($idSup ? ' AND m.sup_reconteo = ?' : '') . "
                 ORDER BY u.nombre, m.ubicacion, m.id_marbete";
        $st = db()->prepare($sql);
        $st->execute(array_values(array_filter([$idInv, $idUsuario, $idSup])));
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['terminado'] = (int)$r['cerrados'] > (int)$r['asignado_conteo'];
            $r['cancelado'] = $r['estado'] === 'CANCELADO';
        }
        return $rows;
    }

    private static function conteoAbiertoTipo(array $u, string $tipo): array
    {
        $c = self::conteoAbiertoDe((int)$u['id_usuario']);
        if (!$c) throw new InvError('Primero abre una tarima o marbete.', 'Primero abre una tarima.');
        if ($c['tipo'] !== $tipo) throw new InvError("{$u['nombre']} tiene abierto un marbete de {$c['tipo']}. Ciérralo primero.");
        return $c;
    }

    // ================================================================== //
    //  PT: escanear caja                                                  //
    // ================================================================== //
    public static function agregarCajaPT(array $u, string $raw): array
    {
        $c   = self::conteoAbiertoTipo($u, 'PT');
        $qr  = self::parseQrPT($raw);
        $pdo = db();

        // Parte debe existir en Epicor (partes activas)
        $parte = null;
        $st = $pdo->prepare("SELECT parte, uom, activo FROM partes WHERE parte = ?");
        $st->execute([$qr['parte']]);
        $parte = $st->fetch() ?: null;
        if (cfg('reglas.pt_validar_parte_en_catalogo', true)) {
            if (!$parte) throw new InvError("La parte {$qr['parte']} no existe en el catálogo de Epicor.", 'Parte no encontrada.');
            if (!(int)$parte['activo']) throw new InvError("La parte {$qr['parte']} está inactiva en Epicor.", 'Parte inactiva.');
        }

        // Una parte / un job por tarima: lo define la primera caja
        $st = $pdo->prepare("SELECT parte, lote FROM conteo_detalle WHERE id_conteo = ? ORDER BY id_detalle LIMIT 1");
        $st->execute([$c['id_conteo']]);
        if ($primera = $st->fetch()) {
            if (cfg('reglas.pt_una_parte_por_tarima', true) && $primera['parte'] !== $qr['parte']) {
                throw new InvError(
                    "La pieza de esta caja ({$qr['parte']}) es diferente a la de la tarima ({$primera['parte']}).",
                    'La pieza de esta caja es diferente a la de la tarima. Favor de retirarla.'
                );
            }
            if (cfg('reglas.pt_un_job_por_tarima', false) && cfg('reglas.pt_lote_es_job', true) && $primera['lote'] !== $qr['job']) {
                throw new InvError(
                    "El Job de esta caja ({$qr['job']}) es diferente al de la tarima ({$primera['lote']}).",
                    'El Job de esta caja es diferente al de la tarima. Favor de retirarla.'
                );
            }
        }

        // Duplicado en este mismo conteo (QR completo)
        $st = $pdo->prepare("SELECT 1 FROM conteo_detalle WHERE id_conteo = ? AND qr = ?");
        $st->execute([$c['id_conteo'], $qr['qr']]);
        if ($st->fetch()) {
            throw new InvError('Este código ya fue escaneado (coincide todo el QR).', 'Este código ya fue escaneado.');
        }

        // ¿La caja ya se contó en OTRO marbete de este inventario?
        $st = $pdo->prepare(
            "SELECT m.id_marbete FROM conteo_detalle d
               JOIN conteos c  ON c.id_conteo = d.id_conteo AND c.estado <> 'CANCELADO'
               JOIN marbetes m ON m.id_marbete = c.id_marbete AND m.estado <> 'CANCELADO'
              WHERE d.qr = ? AND m.id_inventario = ? AND m.id_marbete <> ? LIMIT 1"
        );
        $st->execute([$qr['qr'], $c['id_inventario'], $c['id_marbete']]);
        if ($otro = $st->fetchColumn()) {
            throw new InvError(
                'Esta caja ya se contó en el marbete ' . folio($otro) . '.',
                'Esta caja ya se contó en otra tarima.',
                ['marbete' => folio($otro)]
            );
        }

        $lote = cfg('reglas.pt_lote_es_job', true) ? $qr['job'] : '';
        $pdo->prepare(
            "INSERT INTO conteo_detalle (id_conteo, parte, lote, cantidad, uom, qr, id_caja, id_usuario)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $c['id_conteo'], $qr['parte'], $lote, $qr['piezas'],
            $parte['uom'] ?? 'PZ', $qr['qr'], $qr['id_caja'], $u['id_usuario'],
        ]);
        self::totales((int)$c['id_conteo']);
        return $qr;
    }

    // ================================================================== //
    //  MP: captura manual                                                 //
    // ================================================================== //
    public static function numero($v): float
    {
        $s = str_replace(' ', '', trim((string)$v));
        if (strpos($s, '.') !== false) $s = str_replace(',', '', $s);          // 1,250.5
        elseif (preg_match('/^-?\d{1,3}(,\d{3})+$/', $s)) $s = str_replace(',', '', $s); // 1,500
        else $s = str_replace(',', '.', $s);                                     // 12,5
        if ($s === '' || !is_numeric($s)) throw new InvError('Cantidad inválida.');
        return (float)$s;
    }

    public static function agregarLineaMP(array $u, string $parte, string $lote, $cantidad, bool $loteFuera = false): void
    {
        $c = self::conteoAbiertoTipo($u, 'MP');
        $parte = trim($parte);
        $lote  = strtoupper(trim($lote));
        $cant  = self::numero($cantidad);
        if ($cant <= 0) throw new InvError('La cantidad debe ser mayor a 0.');
        if ($cant > 99999999) throw new InvError('Cantidad demasiado grande, revísala.');

        $st = db()->prepare("SELECT * FROM partes WHERE parte = ?");
        $st->execute([$parte]);
        $p = $st->fetch();
        if (!$p) throw new InvError('Selecciona la parte de la lista (no existe en Epicor).');
        if (!(int)$p['activo']) throw new InvError("La parte {$parte} está inactiva en Epicor.");

        $flag = 0;
        if ($lote === '') {
            if ((int)$p['lleva_lote']) throw new InvError('Esta parte lleva lote. Búscalo y selecciónalo.');
        } else {
            $st = db()->prepare(
                "SELECT 1 FROM lotes WHERE parte = ? AND lote = ?
                 UNION SELECT 1 FROM epicor_existencias WHERE id_inventario = ? AND parte = ? AND lote = ? LIMIT 1"
            );
            $st->execute([$p['parte'], $lote, $c['id_inventario'], $p['parte'], $lote]);
            if (!$st->fetch()) {
                if (!$loteFuera || !cfg('reglas.mp_permitir_lote_fuera_epicor', true)) {
                    throw new InvError("El lote {$lote} no existe en Epicor para la parte {$p['parte']}. Revisa O vs 0, I vs 1.");
                }
                $flag = 1;
            }
        }

        db()->prepare(
            "INSERT INTO conteo_detalle (id_conteo, parte, lote, cantidad, uom, lote_fuera_epicor, id_usuario)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$c['id_conteo'], $p['parte'], $lote, $cant, $p['uom'], $flag, $u['id_usuario']]);
        self::totales((int)$c['id_conteo']);
    }

    // ================================================================== //
    //  Borrar / cerrar / cancelar                                         //
    // ================================================================== //
    public static function borrarLinea(array $u, ?int $idDetalle): array
    {
        $c = self::conteoAbiertoDe((int)$u['id_usuario']);
        if (!$c) throw new InvError('No hay un conteo abierto.');
        if ($idDetalle === null) {
            $st = db()->prepare("SELECT id_detalle FROM conteo_detalle WHERE id_conteo = ? ORDER BY id_detalle DESC LIMIT 1");
            $st->execute([$c['id_conteo']]);
            $idDetalle = (int)$st->fetchColumn();
            if (!$idDetalle) throw new InvError('No hay nada que borrar.');
        }
        $st = db()->prepare("SELECT * FROM conteo_detalle WHERE id_detalle = ? AND id_conteo = ?");
        $st->execute([$idDetalle, $c['id_conteo']]);
        $d = $st->fetch();
        if (!$d) throw new InvError('Ese renglón no es de tu conteo abierto.');
        db()->prepare("DELETE FROM conteo_detalle WHERE id_detalle = ?")->execute([$idDetalle]);
        self::totales((int)$c['id_conteo']);
        self::evento((int)$c['id_marbete'], self::opId($u), 'BORRA_RENGLON',
            "{$d['parte']} lote {$d['lote']} cant " . qty($d['cantidad']) . ($d['qr'] ? " QR {$d['qr']}" : ''));
        return $d;
    }

    public static function cerrarConteo(array $u): array
    {
        $c = self::conteoAbiertoDe((int)$u['id_usuario']);
        if (!$c) throw new InvError('No hay ninguna tarima abierta.', 'No hay ninguna tarima abierta.');
        self::totales((int)$c['id_conteo']);
        $st = db()->prepare("SELECT COUNT(*) FROM conteo_detalle WHERE id_conteo = ?");
        $st->execute([$c['id_conteo']]);
        if ((int)$st->fetchColumn() === 0) {
            throw new InvError('No hay nada contado. Si te equivocaste de marbete usa "Cancelar".', 'No hay cajas en la tarima.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE conteos SET estado = 'CERRADO', fin = NOW() WHERE id_conteo = ?")->execute([$c['id_conteo']]);
            $pdo->prepare("UPDATE marbetes SET reconteo_pendiente = 0 WHERE id_marbete = ?")->execute([$c['id_marbete']]);
            self::evento((int)$c['id_marbete'], self::opId($u), 'CIERRA_CONTEO_' . $c['num_conteo'], 'Contador: ' . $u['nombre']);
            self::evaluar((int)$c['id_marbete']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $m = self::marbete((int)$c['id_marbete']);
        return ['id_marbete' => (int)$c['id_marbete'], 'id_conteo' => (int)$c['id_conteo'],
                'num_conteo' => (int)$c['num_conteo'], 'estado' => $m['estado']];
    }

    /** Cancela un conteo ABIERTO (el propio contador o un supervisor). */
    public static function cancelarConteo(array $u, ?int $idConteo = null, string $motivo = ''): void
    {
        if ($idConteo === null) {
            $c = self::conteoAbiertoDe((int)$u['id_usuario']);
            if (!$c) throw new InvError('No hay un conteo abierto.');
        } else {
            $st = db()->prepare("SELECT * FROM conteos WHERE id_conteo = ?");
            $st->execute([$idConteo]);
            $c = $st->fetch();
            if (!$c) throw new InvError('Conteo no encontrado.');
            if ($u['rol'] === 'SUPERVISOR' && (int)$c['id_supervisor'] !== (int)$u['id_usuario']) {
                throw new InvError('Ese conteo es de otro equipo.');
            }
            if (!in_array($u['rol'], ['SUPERVISOR', 'ADMIN'], true)) throw new InvError('Sin permiso.');
        }
        if ($c['estado'] !== 'ABIERTO') throw new InvError('Solo se pueden cancelar conteos abiertos.');

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE conteos SET estado = 'CANCELADO', fin = NOW() WHERE id_conteo = ?")->execute([$c['id_conteo']]);
            self::evento((int)$c['id_marbete'], self::opId($u), 'CANCELA_CONTEO_' . $c['num_conteo'], $motivo);
            self::evaluar((int)$c['id_marbete']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Recalcula el estado del marbete según sus conteos:
     *  - conteo abierto                 → EN_CONTEO
     *  - sin conteos cerrados           → CANCELADO
     *  - supervisor pidió reconteo      → RECONTEO
     *  - supervisor validó/justificó    → VALIDADO (cuenta el conteo que eligió)
     *  - 1 conteo                       → CONTADO
     *  - último conteo = algún anterior → COINCIDE
     *  - si no                          → DIFERENCIA (cuenta el último mientras el supervisor decide)
     */
    public static function evaluar(int $idMarbete): string
    {
        $m = self::marbete($idMarbete);
        if (!$m || $m['estado'] === 'CANCELADO') return 'CANCELADO';

        $conteos  = self::conteosDe($idMarbete);
        $cerrados = array_values(array_filter($conteos, fn($c) => $c['estado'] === 'CERRADO'));
        $abierto  = (bool)array_filter($conteos, fn($c) => $c['estado'] === 'ABIERTO');
        $ultimo   = end($cerrados) ?: null;

        $estado = 'CONTADO';
        $vigente = $ultimo ? (int)$ultimo['num_conteo'] : null;
        $final  = $m['conteo_final'] !== null ? (int)$m['conteo_final'] : null;

        if (!$cerrados) {
            $estado = $abierto ? 'EN_CONTEO' : 'CANCELADO';
            $final = null;
        } elseif ($abierto) {
            $estado = 'EN_CONTEO';
            if ($m['validado_por'] && $final) $vigente = $final;
        } elseif ((int)$m['reconteo_pendiente'] === 1) {
            $estado = 'RECONTEO';
        } elseif ($m['validado_por'] && $final) {
            $estado = 'VALIDADO';
            $vigente = $final;
        } elseif (count($cerrados) === 1) {
            $estado = 'CONTADO';
            $final = null;
        } else {
            $estado = 'DIFERENCIA';
            $final = null;
            $resUlt = self::resumen((int)$ultimo['id_conteo']);
            for ($i = count($cerrados) - 2; $i >= 0; $i--) {
                if (self::resumenesIguales($resUlt, self::resumen((int)$cerrados[$i]['id_conteo']))) {
                    $estado = 'COINCIDE';
                    $final = (int)$ultimo['num_conteo'];
                    break;
                }
            }
        }

        db()->prepare("UPDATE marbetes SET estado = ?, conteo_vigente = ?, conteo_final = ? WHERE id_marbete = ?")
            ->execute([$estado, $vigente, $final, $idMarbete]);
        return $estado;
    }

    // ================================================================== //
    //  Supervisor                                                         //
    // ================================================================== //
    public static function solicitarReconteo(array $u, int $idMarbete, string $comentario): void
    {
        $m = self::marbete($idMarbete);
        if (!$m) throw new InvError('Marbete no encontrado.');
        if ($m['estado'] === 'CANCELADO') throw new InvError('El marbete está cancelado.');
        if ($m['estado'] === 'EN_CONTEO') throw new InvError('El marbete se está contando en este momento.');
        db()->prepare("UPDATE marbetes SET reconteo_pendiente = 1, validado_por = NULL, validado_at = NULL, conteo_final = NULL WHERE id_marbete = ?")
            ->execute([$idMarbete]);
        self::evento($idMarbete, (int)$u['id_usuario'], 'SOLICITA_RECONTEO', $comentario);
        self::evaluar($idMarbete);
    }

    public static function validar(array $u, int $idMarbete, int $numConteo, string $comentario): void
    {
        $comentario = trim($comentario);
        $m = self::marbete($idMarbete);
        if (!$m) throw new InvError('Marbete no encontrado.');
        if (in_array($m['estado'], ['CANCELADO', 'EN_CONTEO'], true)) throw new InvError('No se puede validar un marbete ' . strtolower(ESTADOS_MARBETE[$m['estado']][0]) . '.');
        $ok = array_filter(self::conteosDe($idMarbete), fn($c) => $c['estado'] === 'CERRADO' && (int)$c['num_conteo'] === $numConteo);
        if (!$ok) throw new InvError('Elige un conteo cerrado de este marbete.');
        db()->prepare(
            "UPDATE marbetes SET conteo_final = ?, validado_por = ?, validado_at = NOW(), comentario = ?, reconteo_pendiente = 0
             WHERE id_marbete = ?"
        )->execute([$numConteo, $u['id_usuario'], $comentario !== '' ? $comentario : null, $idMarbete]);
        self::evento($idMarbete, (int)$u['id_usuario'], 'VALIDA_CONTEO_' . $numConteo, $comentario);
        self::evaluar($idMarbete);
    }

    public static function cancelarMarbete(array $u, int $idMarbete, string $comentario): void
    {
        $comentario = trim($comentario);
        if ($comentario === '') throw new InvError('Escribe el motivo de la cancelación.');
        $m = self::marbete($idMarbete);
        if (!$m) throw new InvError('Marbete no encontrado.');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE conteos SET estado = 'CANCELADO', fin = NOW() WHERE id_marbete = ? AND estado = 'ABIERTO'")->execute([$idMarbete]);
            $pdo->prepare("UPDATE marbetes SET estado = 'CANCELADO', comentario = ? WHERE id_marbete = ?")->execute([$comentario, $idMarbete]);
            self::evento($idMarbete, (int)$u['id_usuario'], 'CANCELA_MARBETE', $comentario);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function comentar(array $u, int $idMarbete, string $comentario): void
    {
        $comentario = trim($comentario);
        if ($comentario === '') throw new InvError('Escribe el comentario.');
        if (!self::marbete($idMarbete)) throw new InvError('Marbete no encontrado.');
        self::evento($idMarbete, (int)$u['id_usuario'], 'COMENTARIO', $comentario);
    }

    // ================================================================== //
    //  Comparativo real vs Epicor                                         //
    // ================================================================== //

    /** Cantidad real por parte|lote = conteo vigente de cada marbete no cancelado. */
    public static function real(int $idInv): array
    {
        $st = db()->prepare(
            "SELECT d.parte, d.lote, SUM(d.cantidad) AS cantidad, COUNT(*) AS lineas,
                    COUNT(DISTINCT m.id_marbete) AS marbetes,
                    COUNT(DISTINCT CASE WHEN m.estado IN ('DIFERENCIA','RECONTEO') THEN m.id_marbete END) AS pendientes,
                    GROUP_CONCAT(DISTINCT m.id_marbete ORDER BY m.id_marbete) AS ids_marbetes
               FROM marbetes m
               JOIN conteos c ON c.id_marbete = m.id_marbete AND c.estado = 'CERRADO' AND c.num_conteo = m.conteo_vigente
               JOIN conteo_detalle d ON d.id_conteo = c.id_conteo
              WHERE m.id_inventario = ? AND m.estado NOT IN ('CANCELADO')
              GROUP BY d.parte, d.lote"
        );
        $st->execute([$idInv]);
        return $st->fetchAll();
    }

    /**
     * Cantidad real que contó el EQUIPO de un supervisor: por cada marbete se toma
     * el último conteo cerrado que hizo su gente (no ve los conteos de otros equipos).
     */
    public static function realSupervisor(int $idInv, int $idSup): array
    {
        $st = db()->prepare(
            "SELECT d.parte, d.lote, SUM(d.cantidad) AS cantidad, COUNT(*) AS lineas,
                    COUNT(DISTINCT m.id_marbete) AS marbetes, 0 AS pendientes,
                    GROUP_CONCAT(DISTINCT m.id_marbete ORDER BY m.id_marbete) AS ids_marbetes
               FROM marbetes m
               JOIN conteos c ON c.id_marbete = m.id_marbete AND c.estado = 'CERRADO' AND c.id_supervisor = :s1
                AND c.num_conteo = (SELECT MAX(c2.num_conteo) FROM conteos c2
                                     WHERE c2.id_marbete = m.id_marbete AND c2.estado = 'CERRADO' AND c2.id_supervisor = :s2)
               JOIN conteo_detalle d ON d.id_conteo = c.id_conteo
              WHERE m.id_inventario = :inv AND m.estado <> 'CANCELADO'
              GROUP BY d.parte, d.lote"
        );
        $st->execute([':s1' => $idSup, ':s2' => $idSup, ':inv' => $idInv]);
        return $st->fetchAll();
    }

    /**
     * Avance por supervisor (admin) o por contador de un equipo (supervisor).
     * Por grupo: conteos cerrados, marbetes de 1er conteo, cajas/renglones y 2do conteo asignado/contado.
     */
    public static function avanceEquipos(int $idInv, ?int $idSup = null): array
    {
        $porSup = $idSup === null;
        $campo = $porSup ? 'c.id_supervisor' : 'c.id_usuario';
        $sql = "SELECT $campo AS id, COUNT(*) AS conteos, SUM(c.num_conteo = 1) AS primeros, SUM(c.num_conteo > 1) AS segundos,
                       SUM(c.total_lineas) AS lineas
                  FROM conteos c JOIN marbetes m ON m.id_marbete = c.id_marbete
                 WHERE m.id_inventario = ? AND c.estado = 'CERRADO'" . ($porSup ? '' : ' AND c.id_supervisor = ?') . "
                 GROUP BY $campo";
        $st = db()->prepare($sql);
        $st->execute($porSup ? [$idInv] : [$idInv, $idSup]);
        $res = [];
        foreach ($st->fetchAll() as $r) $res[(int)$r['id']] = $r + ['asig' => 0, 'asig_ok' => 0];

        // 2do conteo: asignados a cada supervisor (admin) o a cada contador del equipo (supervisor)
        $st = db()->prepare(
            "SELECT m.id_marbete, m.sup_reconteo, m.sup_asignado_conteo, m.asignado_a, m.asignado_conteo,
                    (SELECT COUNT(*) FROM conteos c WHERE c.id_marbete = m.id_marbete AND c.estado = 'CERRADO') AS cerrados
               FROM marbetes m WHERE m.id_inventario = ? AND m.estado <> 'CANCELADO' AND m.sup_reconteo IS NOT NULL"
            . ($porSup ? '' : ' AND m.sup_reconteo = ? AND m.asignado_a IS NOT NULL')
        );
        $st->execute($porSup ? [$idInv] : [$idInv, $idSup]);
        foreach ($st->fetchAll() as $m) {
            $k = $porSup ? (int)$m['sup_reconteo'] : (int)$m['asignado_a'];
            $hecho = $porSup ? (int)$m['cerrados'] > (int)$m['sup_asignado_conteo'] : (int)$m['cerrados'] > (int)$m['asignado_conteo'];
            $res[$k] = $res[$k] ?? ['id' => $k, 'conteos' => 0, 'primeros' => 0, 'segundos' => 0, 'lineas' => 0, 'asig' => 0, 'asig_ok' => 0];
            $res[$k]['asig']++;
            if ($hecho) $res[$k]['asig_ok']++;
        }
        if (!$res) return [];
        $in = implode(',', array_fill(0, count($res), '?'));
        $st = db()->prepare("SELECT id_usuario, nombre, num_empleado FROM usuarios WHERE id_usuario IN ($in)");
        $st->execute(array_keys($res));
        foreach ($st->fetchAll() as $u) {
            $res[(int)$u['id_usuario']]['nombre'] = $u['nombre'];
            $res[(int)$u['id_usuario']]['num_empleado'] = $u['num_empleado'];
        }
        uasort($res, fn($a, $b) => strcmp($a['nombre'] ?? '', $b['nombre'] ?? ''));
        return $res;
    }

    /** Contadores activos del equipo de un supervisor (o todos si $idSup es null). */
    public static function equipo(?int $idSup, string $tipo = ''): array
    {
        $sql = "SELECT u.id_usuario, u.nombre, u.num_empleado, u.tipo_contador, u.id_supervisor, s.nombre AS supervisor
                  FROM usuarios u LEFT JOIN usuarios s ON s.id_usuario = u.id_supervisor
                 WHERE u.activo = 1 AND u.rol = 'CONTADOR'";
        $p = [];
        if ($idSup !== null) { $sql .= ' AND u.id_supervisor = ?'; $p[] = $idSup; }
        if ($tipo !== '') { $sql .= " AND u.tipo_contador IN ('AMBOS', ?)"; $p[] = $tipo; }
        $st = db()->prepare($sql . ' ORDER BY u.nombre');
        $st->execute($p);
        return $st->fetchAll();
    }

    public static function epicor(int $idInv): array
    {
        $st = db()->prepare(
            "SELECT parte, lote, SUM(cantidad) AS cantidad,
                    GROUP_CONCAT(DISTINCT CONCAT(almacen, '/', ubicacion) ORDER BY almacen, ubicacion SEPARATOR ', ') AS ubicaciones
               FROM epicor_existencias WHERE id_inventario = ? GROUP BY parte, lote"
        );
        $st->execute([$idInv]);
        return $st->fetchAll();
    }

    /**
     * Une real y Epicor por parte, con desglose por lote.
     * Regresa lista de partes: [parte, descripcion, clase, tipo, uom, epicor, real, dif, marbetes, pendientes, lotes => [...]]
     */
    public static function comparativo(int $idInv): array
    {
        $partes = [];
        $nuevo = fn() => ['epicor' => 0.0, 'real' => 0.0, 'marbetes' => 0, 'pendientes' => 0, 'lotes' => [], 'ids' => [], 'ubicaciones' => ''];

        foreach (self::epicor($idInv) as $r) {
            $p = &$partes[$r['parte']];
            $p = $p ?? $nuevo();
            $p['epicor'] += (float)$r['cantidad'];
            $p['lotes'][$r['lote']]['epicor'] = (float)$r['cantidad'];
            $p['lotes'][$r['lote']]['ubicaciones'] = $r['ubicaciones'];
            unset($p);
        }
        foreach (self::real($idInv) as $r) {
            $p = &$partes[$r['parte']];
            $p = $p ?? $nuevo();
            $p['real'] += (float)$r['cantidad'];
            $p['pendientes'] += (int)$r['pendientes'];
            foreach (explode(',', (string)$r['ids_marbetes']) as $idm) if ($idm !== '') $p['ids'][$idm] = true;
            $p['lotes'][$r['lote']]['real'] = (float)$r['cantidad'];
            $p['lotes'][$r['lote']]['marbetes'] = $r['ids_marbetes'];
            unset($p);
        }

        if (!$partes) return [];
        // Datos del catálogo
        $in = implode(',', array_fill(0, count($partes), '?'));
        // Costo: el congelado al tomar la foto de Epicor; si no hay, el actual del catálogo
        $st = db()->prepare(
            "SELECT p.parte, p.descripcion, p.clase, p.tipo, p.uom, COALESCE(ic.costo, p.costo) AS costo
               FROM partes p LEFT JOIN inventario_costos ic ON ic.id_inventario = ? AND ic.parte = p.parte
              WHERE p.parte IN ($in)"
        );
        $st->execute(array_merge([$idInv], array_map('strval', array_keys($partes))));
        $cat = [];
        foreach ($st->fetchAll() as $c) $cat[$c['parte']] = $c;

        $out = [];
        foreach ($partes as $parte => $p) {
            $costo = (float)($cat[$parte]['costo'] ?? 0);
            $lotes = [];
            foreach ($p['lotes'] as $lote => $l) {
                $e = (float)($l['epicor'] ?? 0);
                $r = (float)($l['real'] ?? 0);
                $lotes[] = ['lote' => (string)$lote, 'epicor' => $e, 'real' => $r, 'dif' => $r - $e,
                            'valor_epicor' => $e * $costo, 'valor_real' => $r * $costo, 'dif_valor' => ($r - $e) * $costo,
                            'ubicaciones' => $l['ubicaciones'] ?? '', 'marbetes' => $l['marbetes'] ?? ''];
            }
            usort($lotes, fn($a, $b) => strcmp($a['lote'], $b['lote']));
            $out[] = [
                'parte'       => (string)$parte,
                'descripcion' => $cat[$parte]['descripcion'] ?? '(no está en catálogo)',
                'clase'       => $cat[$parte]['clase'] ?? '',
                'tipo'        => $cat[$parte]['tipo'] ?? '',
                'uom'         => $cat[$parte]['uom'] ?? '',
                'epicor'      => $p['epicor'],
                'real'        => $p['real'],
                'dif'         => $p['real'] - $p['epicor'],
                'costo'        => $costo,
                'valor_epicor' => $p['epicor'] * $costo,
                'valor_real'   => $p['real'] * $costo,
                'dif_valor'    => ($p['real'] - $p['epicor']) * $costo,
                'marbetes'    => count($p['ids']),
                'pendientes'  => $p['pendientes'],
                'lotes'       => $lotes,
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['parte'], $b['parte']));
        return $out;
    }

    /** 'falta' (real < epicor) | 'sobra' (real > epicor) | 'ok' */
    public static function clasifica(float $real, float $epicor): string
    {
        $tol = (float)cfg('reglas.tolerancia', 0) + 0.00001;
        if ($real < $epicor - $tol) return 'falta';
        if ($real > $epicor + $tol) return 'sobra';
        return 'ok';
    }

    public static function avance(int $idInv): array
    {
        $st = db()->prepare("SELECT estado, tipo, COUNT(*) n FROM marbetes WHERE id_inventario = ? GROUP BY estado, tipo");
        $st->execute([$idInv]);
        $r = ['total' => 0, 'PT' => 0, 'MP' => 0, 'por_estado' => array_fill_keys(array_keys(ESTADOS_MARBETE), 0)];
        foreach ($st->fetchAll() as $row) {
            $r['por_estado'][$row['estado']] += (int)$row['n'];
            if ($row['estado'] !== 'CANCELADO') {
                $r['total'] += (int)$row['n'];
                $r[$row['tipo']] += (int)$row['n'];
            }
        }
        $st = db()->prepare(
            "SELECT COUNT(*) FROM marbetes m WHERE m.id_inventario = ? AND m.estado <> 'CANCELADO'
               AND (SELECT COUNT(*) FROM conteos c WHERE c.id_marbete = m.id_marbete AND c.estado = 'CERRADO') >= 2"
        );
        $st->execute([$idInv]);
        $r['con_2_conteos'] = (int)$st->fetchColumn();
        return $r;
    }
}
