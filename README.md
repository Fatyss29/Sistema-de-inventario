# Sistema de inventario

Sistema de control de inventario de fin de año con conexión a ERP (Epicor Kinetic).

**Stack:** PHP 8 · MySQL/MariaDB · Bootstrap 5 · JavaScript · Epicor Kinetic REST API

> 🚧 En desarrollo.

## Objetivo

- Capturar conteos de Producto Terminado (QR de caja) y Materia Prima (parte + lote).
- Manejar 1er y 2do conteo a ciegas por equipos de supervisores.
- Comparar el inventario real contra el ERP en cantidad y en dinero.

## Seguridad

Las credenciales nunca se versionan: `.gitignore` excluye los archivos de configuración reales
y un hook de pre-commit bloquea commits con contraseñas o claves. Actívalo una vez por clon:

```bash
git config core.hooksPath .githooks
```
