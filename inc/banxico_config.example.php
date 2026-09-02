<?php

/**
 * Copia este archivo como banxico_config.local.php y coloca el token SIE
 * emitido por Banco de México. El archivo local está excluido de Git.
 */
return [
    'activo' => true,
    'token' => 'PEGA_AQUI_TU_TOKEN_SIE_BANXICO',
    'serie_fix' => 'SF43718',
    'timeout_segundos' => 8,
    'dias_habiles_alerta' => 2,
];
