<?php
// onat_generators/BaseGenerator.php — Base común para los generadores de modelos ONAT.

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

abstract class BaseGenerator
{
    /** Código del modelo (DJ-08, DJ-Utilidades, ...). */
    public const MODELO = '';
    /** mensual | trimestral | anual */
    public const PERIODO_TIPO = '';
    /** Mapeo lógica → celda Excel para la plantilla oficial. */
    protected const CELL_MAP = [];

    protected PDO $pdo;
    protected int $idEmpresa;
    protected array $fiscalRegime;
    protected array $config;

    public function __construct(PDO $pdo, int $idEmpresa, array $fiscalRegime, array $config)
    {
        $this->pdo = $pdo;
        $this->idEmpresa = $idEmpresa;
        $this->fiscalRegime = $fiscalRegime;
        $this->config = $config;
    }

    /**
     * Calcula los valores específicos del modelo. Debe devolver un array
     * con claves alineadas a CELL_MAP + meta:
     *   [
     *     'periodo_inicio' => 'YYYY-MM-DD',
     *     'periodo_fin'    => 'YYYY-MM-DD',
     *     'monto_total'    => float,
     *     'datos'          => [ clave => valor, ... ]
     *   ]
     */
    abstract public function calcular(int $anio, int $mes): array;

    /** Año del modelo (versión de plantilla a usar). */
    public function anioModelo(int $anio): int
    {
        $disponible = is_dir(__DIR__ . '/../onat_modelos/' . $anio);
        return $disponible ? $anio : intval(date('Y'));
    }

    public function generar(int $anio, int $mes): array
    {
        $datos = $this->calcular($anio, $mes);
        $anioModelo = $this->anioModelo($anio);

        $modelo = static::MODELO;
        $periodoTipo = static::PERIODO_TIPO;

        // Versionado: cada regeneración aumenta version y archiva la copia anterior por hash.
        $versionAnterior = 0;
        try {
            $stmt = $this->pdo->prepare("SELECT MAX(version) FROM onat_declaraciones
                                         WHERE id_empresa = ? AND modelo = ?
                                           AND periodo_inicio = ? AND periodo_fin = ?");
            $stmt->execute([$this->idEmpresa, $modelo, $datos['periodo_inicio'], $datos['periodo_fin']]);
            $versionAnterior = intval($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {}
        $version = $versionAnterior + 1;

        [$xlsxPath, $pdfPath] = $this->renderArchivos($datos, $anio, $mes, $anioModelo, $version);

        $hashXlsx = ($xlsxPath && is_file($xlsxPath)) ? hash_file('sha256', $xlsxPath) : null;
        $hashPdf  = ($pdfPath  && is_file($pdfPath))  ? hash_file('sha256', $pdfPath)  : null;

        $usuario = $_SESSION['admin_user'] ?? ($_SESSION['user_email'] ?? 'sistema');

        $sql = "INSERT INTO onat_declaraciones
                (id_empresa, modelo, periodo_tipo, periodo_inicio, periodo_fin, anio_modelo,
                 archivo_xlsx, archivo_pdf, monto_total, estado, hash_xlsx, hash_pdf, version, usuario_genera)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'generada', ?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $this->idEmpresa, $modelo, $periodoTipo,
            $datos['periodo_inicio'], $datos['periodo_fin'],
            $anioModelo,
            $xlsxPath ? str_replace(__DIR__ . '/../', '', $xlsxPath) : null,
            $pdfPath ? str_replace(__DIR__ . '/../', '', $pdfPath) : null,
            $datos['monto_total'] ?? null,
            $hashXlsx, $hashPdf, $version, $usuario,
        ]);
        $declId = (int)$this->pdo->lastInsertId();

        // Versionado inalterable: cada archivo se registra en onat_archivos_versiones con hash.
        $this->registrarVersion($declId, $version, 'xlsx', $xlsxPath, $hashXlsx, $usuario);
        $this->registrarVersion($declId, $version, 'pdf',  $pdfPath,  $hashPdf,  $usuario);

        return [
            'id'             => $declId,
            'modelo'         => $modelo,
            'periodo_tipo'   => $periodoTipo,
            'periodo_inicio' => $datos['periodo_inicio'],
            'periodo_fin'    => $datos['periodo_fin'],
            'monto_total'    => $datos['monto_total'] ?? null,
            'archivo_xlsx'   => $xlsxPath,
            'archivo_pdf'    => $pdfPath,
            'hash_xlsx'      => $hashXlsx,
            'hash_pdf'       => $hashPdf,
            'version'        => $version,
            'datos'          => $datos['datos'] ?? [],
        ];
    }

    protected function registrarVersion(int $declId, int $version, string $tipo, ?string $path, ?string $hash, string $usuario): void
    {
        if (!$path || !is_file($path) || !$hash) return;
        try {
            $rel = str_replace(__DIR__ . '/../', '', $path);
            $stmt = $this->pdo->prepare("INSERT INTO onat_archivos_versiones
                (id_declaracion, version, tipo, path, hash_sha256, tamano, usuario)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$declId, $version, $tipo, $rel, $hash, filesize($path), $usuario]);
        } catch (Throwable $e) {}
    }

    protected function renderArchivos(array $datos, int $anio, int $mes, int $anioModelo, int $version = 1): array
    {
        $modelo = static::MODELO;
        $periodoSlug = $this->periodoSlug($anio, $mes);
        $outDir = __DIR__ . '/../onat_archivos/' . $this->idEmpresa . '/' . $anio . '/' . $periodoSlug;
        if (!is_dir($outDir)) @mkdir($outDir, 0775, true);

        $plantilla = __DIR__ . '/../onat_modelos/' . $anioModelo . '/' . $modelo . '.xlsx';
        if (is_file($plantilla)) {
            $spreadsheet = IOFactory::load($plantilla);
            $sheet = $spreadsheet->getActiveSheet();
            foreach (static::CELL_MAP as $clave => $coord) {
                if (array_key_exists($clave, $datos['datos'] ?? [])) {
                    $sheet->setCellValue($coord, $datos['datos'][$clave]);
                }
            }
        } else {
            $spreadsheet = $this->buildFallbackSpreadsheet($datos);
        }

        // Sufijo de versión: v1 sobreescribe el principal; v2+ se preservan como copias inalterables.
        $sufijo = $version > 1 ? ('-v' . $version) : '';
        $xlsxPath = $outDir . '/' . $modelo . $sufijo . '.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($xlsxPath);

        $pdfPath = null;
        try {
            $pdfPath = $outDir . '/' . $modelo . $sufijo . '.pdf';
            $this->renderPdfFromSpreadsheet($spreadsheet, $pdfPath, $datos);
        } catch (Throwable $e) {
            $pdfPath = null;
        }

        return [$xlsxPath, $pdfPath];
    }

    protected function buildFallbackSpreadsheet(array $datos): Spreadsheet
    {
        $modelo = static::MODELO;
        $sp = new Spreadsheet();
        $sh = $sp->getActiveSheet();
        $sh->setTitle(substr($modelo, 0, 31));

        $sh->setCellValue('A1', 'ONAT — ' . $modelo);
        $sh->setCellValue('A2', 'Empresa ID: ' . $this->idEmpresa . ' · ' . ($this->config['marca_empresa_nombre'] ?? ''));
        $sh->setCellValue('A3', 'NIT: ' . ($this->fiscalRegime['nit'] ?? ''));
        $sh->setCellValue('A4', 'Tipo de actor: ' . ($this->fiscalRegime['tipo_actor_economico'] ?? ''));
        $sh->setCellValue('A5', 'Período: ' . $datos['periodo_inicio'] . ' → ' . $datos['periodo_fin']);
        $sh->mergeCells('A1:D1');
        $sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sh->getStyle('A1:A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $row = 7;
        $sh->setCellValue('A' . $row, 'Concepto');
        $sh->setCellValue('B' . $row, 'Valor');
        $sh->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
        $sh->getStyle("A{$row}:B{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $row++;
        foreach (($datos['datos'] ?? []) as $k => $v) {
            $sh->setCellValue('A' . $row, $k);
            $sh->setCellValue('B' . $row, is_numeric($v) ? floatval($v) : (string)$v);
            $row++;
        }

        $sh->setCellValue('A' . ($row + 1), 'TOTAL A PAGAR');
        $sh->setCellValue('B' . ($row + 1), $datos['monto_total'] ?? 0);
        $sh->getStyle('A' . ($row + 1) . ':B' . ($row + 1))->getFont()->setBold(true);
        $sh->getStyle("A7:B" . ($row + 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach (['A','B','C','D'] as $col) $sh->getColumnDimension($col)->setAutoSize(true);

        return $sp;
    }

    protected function renderPdfFromSpreadsheet(Spreadsheet $sp, string $pdfPath, array $datos): void
    {
        $hasMpdf = class_exists('Mpdf\\Mpdf');
        if ($hasMpdf) {
            $htmlWriter = IOFactory::createWriter($sp, 'Html');
            $html = $htmlWriter->generateHtmlAll();
            $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4']);
            $mpdf->WriteHTML($html);
            $mpdf->Output($pdfPath, \Mpdf\Output\Destination::FILE);
            return;
        }
        // Fallback: si no hay mPDF instalado, intentar libreoffice headless para convertir el .xlsx existente.
        $xlsx = preg_replace('/\.pdf$/', '.xlsx', $pdfPath);
        if (is_file($xlsx)) {
            $cmd = 'libreoffice --headless --convert-to pdf --outdir ' . escapeshellarg(dirname($pdfPath)) . ' ' . escapeshellarg($xlsx) . ' 2>&1';
            @exec($cmd, $out, $rc);
            if ($rc !== 0) {
                throw new RuntimeException('No se pudo generar PDF (instalá mPDF: composer require mpdf/mpdf, o libreoffice).');
            }
            return;
        }
        throw new RuntimeException('No hay mPDF ni LibreOffice disponibles para PDF.');
    }

    protected function periodoSlug(int $anio, int $mes): string
    {
        switch (static::PERIODO_TIPO) {
            case 'mensual':
                return sprintf('%04d-%02d', $anio, $mes);
            case 'trimestral':
                $trimestre = (int)ceil($mes / 3);
                return sprintf('%04d-T%d', $anio, $trimestre);
            case 'anual':
            default:
                return sprintf('%04d', $anio);
        }
    }

    /**
     * Helper: total de ventas brutas en un rango de fechas para esta empresa.
     */
    protected function ventasBrutasRango(string $desde, string $hasta): float
    {
        require_once __DIR__ . '/../accounting_helpers.php';
        $sql = "SELECT SUM(v.total)
                FROM ventas_cabecera v
                LEFT JOIN caja_sesiones s ON v.id_caja = s.id
                WHERE v.id_empresa = ?
                  AND IFNULL(s.fecha_contable, DATE(v.fecha)) BETWEEN ? AND ?
                  AND " . ventas_reales_where_clause('v');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->idEmpresa, $desde, $hasta]);
        return floatval($stmt->fetchColumn() ?: 0);
    }

    protected function costoVentasRango(string $desde, string $hasta): float
    {
        require_once __DIR__ . '/../accounting_helpers.php';
        $sql = "SELECT SUM(d.cantidad * p.costo)
                FROM ventas_detalle d
                JOIN productos p ON d.id_producto = p.codigo
                JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
                LEFT JOIN caja_sesiones s ON v.id_caja = s.id
                WHERE v.id_empresa = ?
                  AND IFNULL(s.fecha_contable, DATE(v.fecha)) BETWEEN ? AND ?
                  AND " . ventas_reales_where_clause('v');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->idEmpresa, $desde, $hasta]);
        return floatval($stmt->fetchColumn() ?: 0);
    }

    protected function gastosOperativosRango(string $desde, string $hasta): float
    {
        try {
            $sql = "SELECT SUM(monto) FROM contabilidad_diario
                    WHERE fecha BETWEEN ? AND ?
                      AND (asiento_tipo IN ('GASTO','OPERATIVO','SALARIO') OR cuenta_debito LIKE '6%')";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$desde, $hasta]);
            return floatval($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}
