<?php

declare(strict_types=1);

/** Evita que una celda de texto sea interpretada como fórmula por una hoja de cálculo. */
function si_csv_celda_segura(mixed $valor, string $tipo = 'texto'): string
{
    if ($valor === null) {
        return '';
    }

    if (in_array($tipo, ['numero', 'moneda', 'moneda_base', 'cantidad', 'entero'], true)) {
        return is_numeric($valor) ? (string) $valor : '';
    }

    $texto = str_replace(["\r\n", "\r"], "\n", (string) $valor);
    if ($texto !== '' && in_array($texto[0], ['=', '+', '-', '@'], true)) {
        return "'" . $texto;
    }

    return $texto;
}
