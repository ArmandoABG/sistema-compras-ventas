<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Alertas compartidas
|--------------------------------------------------------------------------
| Por ahora se deja preparado para mensajes simples.
| Más adelante podemos conectar SweetAlert2 o el sistema visual definitivo.
|--------------------------------------------------------------------------
*/

function si_flash_guardar(
    string $tipo,
    string $mensaje
): void {
    $_SESSION['si_flash'] = [
        'tipo' => $tipo,
        'mensaje' => $mensaje,
    ];
}

function si_flash_obtener(): ?array
{
    if (empty($_SESSION['si_flash'])) {
        return null;
    }

    $flash = $_SESSION['si_flash'];
    unset($_SESSION['si_flash']);

    return is_array($flash) ? $flash : null;
}
