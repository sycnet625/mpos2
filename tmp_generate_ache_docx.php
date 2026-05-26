<?php
$finalOutput = __DIR__ . '/ache_plus_resumen_comercial.docx';
$output = '/tmp/ache_plus_resumen_comercial_' . getmypid() . '.docx';

function x($text) {
    return htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function p($text, $style = 'Normal') {
    $styleXml = $style !== 'Normal' ? '<w:pPr><w:pStyle w:val="' . x($style) . '"/></w:pPr>' : '';
    return '<w:p>' . $styleXml . '<w:r><w:t xml:space="preserve">' . x($text) . '</w:t></w:r></w:p>';
}

function bullet($text) {
    return '<w:p><w:pPr><w:pStyle w:val="Bullet"/></w:pPr><w:r><w:t xml:space="preserve">' . x($text) . '</w:t></w:r></w:p>';
}

$paragraphs = [];
$paragraphs[] = p('🚀 Aché+ ERP Ligero', 'Title');
$paragraphs[] = p('Resumen comercial y funcional para dueños de negocios en Cuba', 'Subtitle');
$paragraphs[] = p('📌 Resumen Comercial', 'Heading1');
$paragraphs[] = p('Aché+ es un ERP ligero pensado para negocios cubanos que necesitan vender, controlar inventario, atender clientes y organizar operaciones sin depender de sistemas grandes, caros o complicados.');
$paragraphs[] = p('Está orientado a bodegas, cafeterías, restaurantes, tiendas, negocios de delivery, emprendimientos familiares, puntos de venta y operaciones con varias sucursales.');
$paragraphs[] = p('Su propuesta principal es simple: centralizar ventas, inventario, caja, pedidos, reservas, tienda online y WhatsApp en una sola herramienta práctica para el día a día.');
$paragraphs[] = p('⚙️ Características Funcionales', 'Heading1');
$features = [
    '🧾 Punto de venta para vender rápido desde caja.',
    '📦 Control de productos, precios, categorías y existencias.',
    '🏬 Inventario por almacén, sucursal y empresa.',
    '📊 Kardex de movimientos: ventas, entradas, ajustes, mermas y devoluciones.',
    '🛒 Gestión de compras y entrada de mercancía.',
    '💵 Control de caja: apertura, cierre, diferencias y sesiones de cajeros.',
    '🖨️ Tickets, facturas y comprobantes de venta.',
    '📅 Reservas y pedidos programados.',
    '🍽️ Gestión de mesas, cocina y comandas para negocios gastronómicos.',
    '🌐 Tienda online para que los clientes compren desde el catálogo.',
    '💬 Integración con WhatsApp para atención, respuestas automáticas y campañas.',
    '📣 Campañas promocionales a grupos o clientes.',
    '👥 Base de clientes, teléfonos, direcciones y preferencias.',
    '🕘 Historial de ventas y pedidos por cliente.',
    '📈 Reportes comerciales para saber qué se vende, cuánto entra y qué falta.',
    '🛵 Manejo de mensajería y delivery.',
    '🏢 Multi-sucursal, multi-almacén y multiempresa.',
    '📱 Modo PWA: se puede usar como app desde el navegador.',
    '🔄 Herramientas para sincronización y trabajo en entornos con conectividad limitada.',
];
foreach ($features as $feature) $paragraphs[] = bullet($feature);
$paragraphs[] = p('🇨🇺 Por Qué Un Dueño De Negocio Cubano Debería Comprar Aché+', 'Heading1');
$paragraphs[] = p('Porque está hecho para la realidad cubana: negocios con internet inestable, ventas por WhatsApp, precios que cambian rápido, inventario sensible, clientes que piden por chat, entregas por mensajero y necesidad de controlar caja todos los días.');
$paragraphs[] = p('Aché+ ayuda a evitar pérdidas por descontrol de inventario, errores de caja, productos mal cobrados, ventas no registradas o pedidos olvidados. También permite vender más porque combina POS, tienda online y WhatsApp en el mismo flujo.');
$paragraphs[] = p('Con Aché+, el dueño puede saber:', 'Heading2');
$owner = [
    '✅ Qué productos tiene disponibles.',
    '✅ Cuánto vendió hoy.',
    '✅ Qué cajero trabajó.',
    '✅ Qué productos se mueven más.',
    '✅ Qué pedidos están pendientes.',
    '✅ Qué clientes compran más.',
    '✅ Qué mercancía debe reponer.',
    '✅ Qué campañas puede enviar por WhatsApp.',
    '✅ Cómo va el negocio aunque no esté físicamente allí.',
];
foreach ($owner as $item) $paragraphs[] = bullet($item);
$paragraphs[] = p('💼 Mensaje Comercial', 'Heading1');
$paragraphs[] = p('Aché+ no es solo un punto de venta. Es una herramienta para ordenar el negocio, vender más y perder menos.');
$paragraphs[] = p('Para un emprendedor cubano, significa menos libreta, menos cálculo manual, menos confusión en caja y más control real sobre lo que entra, sale y se vende.');

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>' . implode('', $paragraphs) . '
    <w:sectPr>
      <w:pgSz w:w="12240" w:h="15840"/>
      <w:pgMar w:top="1080" w:right="1080" w:bottom="1080" w:left="1080" w:header="720" w:footer="720" w:gutter="0"/>
    </w:sectPr>
  </w:body>
</w:document>';

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Segoe UI Emoji" w:hAnsi="Segoe UI Emoji" w:eastAsia="Segoe UI Emoji" w:cs="Segoe UI Emoji"/><w:sz w:val="22"/><w:color w:val="1F2937"/></w:rPr></w:rPrDefault></w:docDefaults>
  <w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:pPr><w:spacing w:after="160" w:line="276" w:lineRule="auto"/></w:pPr></w:style>
  <w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:pPr><w:spacing w:after="120"/></w:pPr><w:rPr><w:b/><w:color w:val="0F766E"/><w:sz w:val="42"/></w:rPr></w:style>
  <w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:pPr><w:spacing w:after="360"/></w:pPr><w:rPr><w:color w:val="475569"/><w:sz w:val="24"/></w:rPr></w:style>
  <w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:pPr><w:spacing w:before="360" w:after="160"/></w:pPr><w:rPr><w:b/><w:color w:val="0F766E"/><w:sz w:val="30"/></w:rPr></w:style>
  <w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:pPr><w:spacing w:before="220" w:after="120"/></w:pPr><w:rPr><w:b/><w:color w:val="334155"/><w:sz w:val="24"/></w:rPr></w:style>
  <w:style w:type="paragraph" w:styleId="Bullet"><w:name w:val="Bullet"/><w:pPr><w:ind w:left="360" w:hanging="0"/><w:spacing w:after="80"/></w:pPr><w:rPr><w:sz w:val="22"/></w:rPr></w:style>
</w:styles>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>';

$docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

$core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>Aché+ ERP Ligero - Resumen Comercial</dc:title>
  <dc:creator>PalWeb</dc:creator>
  <cp:lastModifiedBy>PalWeb</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified>
</cp:coreProperties>';

$app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>PalWeb</Application>
</Properties>';

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "No se pudo crear $output\n");
    exit(1);
}
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rels);
$zip->addFromString('word/document.xml', $documentXml);
$zip->addFromString('word/styles.xml', $stylesXml);
$zip->addFromString('word/_rels/document.xml.rels', $docRels);
$zip->addFromString('docProps/core.xml', $core);
$zip->addFromString('docProps/app.xml', $app);
$zip->close();
if (!@copy($output, $finalOutput)) {
    fwrite(STDERR, "No se pudo copiar $output a $finalOutput\n");
    exit(1);
}
@chmod($finalOutput, 0664);
@unlink($output);

echo $finalOutput . PHP_EOL;
