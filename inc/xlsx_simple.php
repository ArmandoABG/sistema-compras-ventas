<?php

declare(strict_types=1);

/**
 * Generador XLSX mínimo, sin Composer ni extensiones PHP adicionales.
 * Crea un ZIP válido en modo STORE (sin compresión) para máxima compatibilidad.
 */
function si_xlsx_descargar(string $nombreArchivo, array $hojas): void
{
    if ($hojas === []) {
        throw new RuntimeException('No hay hojas para exportar.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'si_xlsx_');
    if ($tmp === false) {
        throw new RuntimeException('No fue posible crear el archivo temporal XLSX.');
    }

    $zip = new SiZipStore($tmp);
    $sheetRels = [];
    $sheetNodes = [];
    $contentOverrides = [];
    $indice = 1;

    foreach ($hojas as $hoja) {
        $titulo = si_xlsx_nombre_hoja((string) ($hoja['nombre'] ?? ('Hoja ' . $indice)));
        $columnas = is_array($hoja['columnas'] ?? null) ? $hoja['columnas'] : [];
        $filas = is_array($hoja['filas'] ?? null) ? $hoja['filas'] : [];

        $zip->add('xl/worksheets/sheet' . $indice . '.xml', si_xlsx_worksheet_xml($columnas, $filas));
        $sheetNodes[] = '<sheet name="' . si_xlsx_xml($titulo) . '" sheetId="' . $indice . '" r:id="rId' . $indice . '"/>';
        $sheetRels[] = '<Relationship Id="rId' . $indice . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $indice . '.xml"/>';
        $contentOverrides[] = '<Override PartName="/xl/worksheets/sheet' . $indice . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $indice++;
    }

    $stylesRelId = $indice;
    $sheetRels[] = '<Relationship Id="rId' . $stylesRelId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $zip->add('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . implode('', $sheetNodes) . '</sheets></workbook>');

    $zip->add('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', $sheetRels) . '</Relationships>');

    $zip->add('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . implode('', $contentOverrides)
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>');

    $zip->add('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>');

    $zip->add('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF17472F"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border/><border><left style="thin"><color rgb="FFDCE5DF"/></left><right style="thin"><color rgb="FFDCE5DF"/></right><top style="thin"><color rgb="FFDCE5DF"/></top><bottom style="thin"><color rgb="FFDCE5DF"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');

    $ahora = gmdate('Y-m-d\TH:i:s\Z');
    $zip->add('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:creator>Sistema Integral</dc:creator><cp:lastModifiedBy>Sistema Integral</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $ahora . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $ahora . '</dcterms:modified></cp:coreProperties>');

    $zip->add('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Sistema Integral</Application></Properties>');

    $zip->close();

    $nombreArchivo = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nombreArchivo) ?: 'exportacion.xlsx';
    if (!str_ends_with(strtolower($nombreArchivo), '.xlsx')) {
        $nombreArchivo .= '.xlsx';
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Content-Length: ' . (string) filesize($tmp));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

final class SiZipStore
{
    private $handle;
    private array $central = [];
    private int $offset = 0;

    public function __construct(string $ruta)
    {
        $this->handle = fopen($ruta, 'wb');
        if ($this->handle === false) {
            throw new RuntimeException('No fue posible crear el contenedor XLSX.');
        }
    }

    public function add(string $nombre, string $contenido): void
    {
        $nombre = str_replace('\\', '/', $nombre);
        $crc = (int) sprintf('%u', crc32($contenido));
        $tam = strlen($contenido);
        [$hora, $fecha] = $this->dosDateTime();
        $nombreLen = strlen($nombre);

        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $hora, $fecha, $crc, $tam, $tam, $nombreLen, 0);
        fwrite($this->handle, $local . $nombre . $contenido);

        $central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $hora, $fecha, $crc, $tam, $tam, $nombreLen, 0, 0, 0, 0, 0, $this->offset) . $nombre;
        $this->central[] = $central;
        $this->offset += strlen($local) + $nombreLen + $tam;
    }

    public function close(): void
    {
        $inicioCentral = $this->offset;
        $central = implode('', $this->central);
        fwrite($this->handle, $central);
        $tamCentral = strlen($central);
        $cantidad = count($this->central);
        fwrite($this->handle, pack('VvvvvVVv', 0x06054b50, 0, 0, $cantidad, $cantidad, $tamCentral, $inicioCentral, 0));
        fclose($this->handle);
    }

    private function dosDateTime(): array
    {
        $d = getdate();
        $hora = (($d['hours'] & 0x1F) << 11) | (($d['minutes'] & 0x3F) << 5) | (($d['seconds'] >> 1) & 0x1F);
        $anio = max(1980, (int) $d['year']);
        $fecha = (($anio - 1980) << 9) | (($d['mon'] & 0x0F) << 5) | ($d['mday'] & 0x1F);
        return [$hora, $fecha];
    }
}

function si_xlsx_worksheet_xml(array $columnas, array $filas): string
{
    $anchos = [];
    foreach ($columnas as $i => $columna) {
        $titulo = (string) ($columna['titulo'] ?? '');
        $ancho = (float) ($columna['ancho'] ?? max(10, min(32, strlen($titulo) + 3)));
        $anchos[$i] = max(8, min(45, $ancho));
    }
    foreach ($filas as $fila) {
        foreach ($columnas as $i => $columna) {
            $valor = $fila[(string) ($columna['campo'] ?? '')] ?? '';
            if (is_scalar($valor) || $valor === null) {
                $anchos[$i] = max($anchos[$i], min(45, strlen((string) ($valor ?? '')) + 2));
            }
        }
    }

    $cols = '';
    foreach ($anchos as $i => $ancho) {
        $n = $i + 1;
        $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . number_format($ancho, 2, '.', '') . '" customWidth="1"/>';
    }

    $rowsXml = '';
    $headerCells = '';
    foreach ($columnas as $i => $columna) {
        $headerCells .= si_xlsx_celda(si_xlsx_columna($i + 1) . '1', (string) ($columna['titulo'] ?? ''), 'texto', 1);
    }
    $rowsXml .= '<row r="1" ht="22" customHeight="1">' . $headerCells . '</row>';

    $rowNum = 1;
    foreach ($filas as $fila) {
        $rowNum++;
        $cells = '';
        foreach ($columnas as $i => $columna) {
            $tipo = (string) ($columna['tipo'] ?? 'texto');
            $cells .= si_xlsx_celda(si_xlsx_columna($i + 1) . $rowNum, $fila[(string) ($columna['campo'] ?? '')] ?? '', $tipo, $tipo === 'numero' ? 2 : 3);
        }
        $rowsXml .= '<row r="' . $rowNum . '">' . $cells . '</row>';
    }

    $ultimaCol = si_xlsx_columna(max(1, count($columnas)));
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:' . $ultimaCol . max(1, $rowNum) . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . $cols . '</cols><sheetData>' . $rowsXml . '</sheetData><autoFilter ref="A1:' . $ultimaCol . max(1, $rowNum) . '"/></worksheet>';
}

function si_xlsx_celda(string $ref, mixed $valor, string $tipo, int $estilo): string
{
    if ($tipo === 'numero' && $valor !== null && $valor !== '' && is_numeric($valor)) {
        return '<c r="' . $ref . '" s="' . $estilo . '"><v>' . si_xlsx_xml((string) $valor) . '</v></c>';
    }
    return '<c r="' . $ref . '" t="inlineStr" s="' . $estilo . '"><is><t xml:space="preserve">' . si_xlsx_xml($valor === null ? '' : (string) $valor) . '</t></is></c>';
}

function si_xlsx_columna(int $numero): string
{
    $resultado = '';
    while ($numero > 0) {
        $numero--;
        $resultado = chr(65 + ($numero % 26)) . $resultado;
        $numero = intdiv($numero, 26);
    }
    return $resultado;
}

function si_xlsx_xml(string $valor): string
{
    $valor = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~', '', $valor) ?? '';
    return htmlspecialchars($valor, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function si_xlsx_nombre_hoja(string $nombre): string
{
    $nombre = strtr(trim($nombre), ['\\' => ' ', '/' => ' ', '?' => ' ', '*' => ' ', '[' => ' ', ']' => ' ', ':' => ' ']);
    $nombre = trim(preg_replace('/\s+/', ' ', $nombre) ?? '') ?: 'Hoja';
    return substr($nombre, 0, 31);
}
