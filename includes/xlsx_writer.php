<?php
/**
 * Generador mínimo de archivos .xlsx (SpreadsheetML / OOXML) sin
 * dependencias externas (sin Composer ni PhpSpreadsheet): usa únicamente
 * la extensión ZipArchive, incluida por defecto en XAMPP y en la mayoría
 * de instalaciones estándar de PHP.
 *
 * Motivo del cambio: el manual de usuario de referencia especifica de
 * forma explícita (secciones 3.4 y 3.5) que los botones "Exportar Excel"
 * y "Exportar Control Excel" deben generar un archivo descargable en
 * formato .xlsx. Los endpoints anteriores (api/export_inventario.php y
 * api/export_control.php) generaban un CSV con extensión .csv, que Excel
 * puede abrir pero que no es un .xlsx real: la etiqueta del botón y el
 * formato entregado no coincidían. Este archivo centraliza la generación
 * del .xlsx real para que ambos endpoints solo tengan que construir sus
 * encabezados y filas (misma lógica de negocio y consultas SQL de siempre)
 * y delegar aquí la serialización del archivo.
 *
 * No sustituye csv como formato interno de nada más: es exclusivamente la
 * capa de presentación de los reportes de Inventario y Control.
 */

/** Convierte un índice de columna (1, 2, 3…) en su letra de Excel (A, B, C…, AA…). */
function xlsxColumnaLetra(int $indice): string
{
    $letra = '';
    while ($indice > 0) {
        $resto = ($indice - 1) % 26;
        $letra = chr(65 + $resto) . $letra;
        $indice = intdiv($indice - 1, 26);
    }
    return $letra;
}

/** Escapa un valor para uso seguro dentro de texto XML (inlineStr). */
function xlsxEscaparTexto(?string $valor): string
{
    $valor = $valor ?? '';
    // Elimina caracteres de control no permitidos por la especificación XML 1.0
    // (evita archivos corruptos si algún campo de texto libre los contuviera).
    $valor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $valor) ?? '';
    return htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Construye el XML de una fila de celdas tipo texto (inlineStr).
 *
 * @param string[] $valores
 */
function xlsxFilaXml(int $numeroFila, array $valores, bool $esEncabezado = false): string
{
    $celdas = '';
    $indiceColumna = 1;
    $estilo = $esEncabezado ? ' s="1"' : '';

    foreach ($valores as $valor) {
        $referencia = xlsxColumnaLetra($indiceColumna) . $numeroFila;
        $texto = xlsxEscaparTexto((string)$valor);
        $celdas .= "<c r=\"{$referencia}\" t=\"inlineStr\"{$estilo}><is><t xml:space=\"preserve\">{$texto}</t></is></c>";
        $indiceColumna++;
    }

    return "<row r=\"{$numeroFila}\">{$celdas}</row>";
}

const XLSX_CONTENT_TYPES = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML;

const XLSX_RELS_RAIZ = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;

const XLSX_WORKBOOK_RELS = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;

/** @param string $nombreHoja Nombre visible de la pestaña (máx. 31 caracteres, sin : \ / ? * [ ]). */
function xlsxWorkbookXml(string $nombreHoja): string
{
    $nombreHoja = htmlspecialchars(substr($nombreHoja, 0, 31), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . "<sheets><sheet name=\"{$nombreHoja}\" sheetId=\"1\" r:id=\"rId1\"/></sheets>"
        . '</workbook>';
}

const XLSX_STYLES = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="2">
<font><sz val="10"/><name val="Calibri"/></font>
<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
</fonts>
<fills count="3">
<fill><patternFill patternType="none"/></fill>
<fill><patternFill patternType="gray125"/></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FF7A1F3D"/><bgColor indexed="64"/></patternFill></fill>
</fills>
<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="2">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
</cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML;

/**
 * Genera el XML completo de la hoja (encabezados + filas de datos), con la
 * primera fila inmovilizada (freeze pane) para que el encabezado quede
 * siempre visible al desplazarse por reportes largos.
 *
 * @param string[]   $encabezados
 * @param string[][] $filas
 */
function xlsxSheetXml(array $encabezados, array $filas): string
{
    $filasXml = xlsxFilaXml(1, $encabezados, true);
    $numeroFila = 2;
    foreach ($filas as $fila) {
        $filasXml .= xlsxFilaXml($numeroFila, $fila);
        $numeroFila++;
    }

    $totalColumnas = max(1, count($encabezados));

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . "<cols><col min=\"1\" max=\"{$totalColumnas}\" width=\"22\" customWidth=\"1\"/></cols>"
        . "<sheetData>{$filasXml}</sheetData>"
        . '</worksheet>';
}

/**
 * Construye el .xlsx completo en un archivo temporal y lo entrega como
 * descarga al navegador con las cabeceras HTTP correctas, terminando la
 * ejecución (mismo contrato que fputcsv + exit que usaban los endpoints
 * antes de esta mejora).
 *
 * @param string     $nombreArchivoSinExtension Nombre de archivo sin ".xlsx".
 * @param string[]   $encabezados               Encabezados de columna, en orden.
 * @param string[][] $filas                     Cada elemento es un arreglo de valores en el mismo orden que $encabezados.
 * @param string     $nombreHoja                Nombre de la pestaña dentro del libro.
 */
function exportarXlsx(string $nombreArchivoSinExtension, array $encabezados, array $filas, string $nombreHoja = 'Datos'): void
{
    if (!class_exists('ZipArchive')) {
        // Salvaguarda: si el servidor no tuviera la extensión zip habilitada,
        // se informa con un mensaje claro en vez de generar un archivo corrupto.
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No fue posible generar el archivo Excel: la extensión PHP "zip" no está habilitada en el servidor. Contacta al área de Desarrollo de Sistemas.';
        exit;
    }

    $rutaTemporal = tempnam(sys_get_temp_dir(), 'musiteca_xlsx_');

    $zip = new ZipArchive();
    $zip->open($rutaTemporal, ZipArchive::OVERWRITE);
    $zip->addEmptyDir('_rels');
    $zip->addEmptyDir('xl');
    $zip->addEmptyDir('xl/_rels');
    $zip->addEmptyDir('xl/worksheets');
    $zip->addFromString('[Content_Types].xml', XLSX_CONTENT_TYPES);
    $zip->addFromString('_rels/.rels', XLSX_RELS_RAIZ);
    $zip->addFromString('xl/workbook.xml', xlsxWorkbookXml($nombreHoja));
    $zip->addFromString('xl/_rels/workbook.xml.rels', XLSX_WORKBOOK_RELS);
    $zip->addFromString('xl/styles.xml', XLSX_STYLES);
    $zip->addFromString('xl/worksheets/sheet1.xml', xlsxSheetXml($encabezados, $filas));
    $zip->close();

    $archivo = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombreArchivoSinExtension) . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Content-Length: ' . filesize($rutaTemporal));
    header('Cache-Control: max-age=0');

    readfile($rutaTemporal);
    unlink($rutaTemporal);
    exit;
}
