<?php
/**
 * SimpleXLSX.php — Minimal xlsx writer, no dependencies.
 * Requires: PHP ZipArchive extension (enabled in php.ini: extension=zip)
 */

class SimpleXLSXSheet {
    public string $name;
    private array $rows      = [];
    private array $colWidths = [];

    public function __construct(string $name) { $this->name = $name; }

    public function writeRow(array $values, array $rowStyle = [], array $cellStyles = []): void {
        $this->rows[] = ['v'=>$values, 's'=>$rowStyle, 'cs'=>$cellStyles];
    }
    public function writeBlank(int $n = 1): void {
        for ($i = 0; $i < $n; $i++) $this->rows[] = ['v'=>[], 's'=>[], 'cs'=>[]];
    }
    public function setColWidths(array $widths): void { $this->colWidths = $widths; }
    public function getRows(): array      { return $this->rows; }
    public function getColWidths(): array { return $this->colWidths; }
}

class SimpleXLSX {
    private array $sheets   = [];
    private array $sharedSt = [];   // escaped_value => index
    private array $xfMap    = [];   // fingerprint => xf index
    private array $fontMap  = [];   // font fingerprint => font index
    private array $fillMap  = [];   // bg hex => fill index

    public function addSheet(string $name): SimpleXLSXSheet {
        $s = new SimpleXLSXSheet($name);
        $this->sheets[] = $s;
        return $s;
    }

    // ── STYLE FINGERPRINT ─────────────────────────────────────────────────────
    // Exclude 'merge' and 'height' — layout props, not cell style props
    private function xfFingerprint(array $s): string {
        return json_encode([
            'b'  => (bool)($s['bold']   ?? false),
            'i'  => (bool)($s['italic'] ?? false),
            'fg' => strtoupper($s['color']  ?? ''),
            'bg' => strtoupper($s['bg']     ?? ''),
            'ha' => $s['halign'] ?? '',
        ]);
    }

    private function getXfIdx(array $s): int {
        $fp = $this->xfFingerprint($s);
        if (!isset($this->xfMap[$fp])) {
            $this->xfMap[$fp] = count($this->xfMap) + 1; // 0 = default
        }
        return $this->xfMap[$fp];
    }

    private function strIdx(string $val): int {
        if (!isset($this->sharedSt[$val])) $this->sharedSt[$val] = count($this->sharedSt);
        return $this->sharedSt[$val];
    }

    private function colLetter(int $n): string {
        $l = '';
        while ($n > 0) { $r = ($n-1)%26; $l = chr(65+$r).$l; $n = (int)(($n-1)/26); }
        return $l;
    }

    // ── BUILD SHEET XML ───────────────────────────────────────────────────────
    private function buildSheetXml(SimpleXLSXSheet $sheet): string {
        $merges = []; // reset per sheet

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
             . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        $widths = $sheet->getColWidths();
        if (!empty($widths)) {
            $xml .= '<cols>';
            foreach ($widths as $i => $w) {
                $c = $i+1;
                $xml .= "<col min=\"$c\" max=\"$c\" width=\"$w\" customWidth=\"1\"/>";
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        $rowNum = 0;
        foreach ($sheet->getRows() as $rowData) {
            $rowNum++;
            $ht     = $rowData['s']['height'] ?? null;
            $htAttr = $ht ? " ht=\"$ht\" customHeight=\"1\"" : '';
            $xml   .= "<row r=\"$rowNum\"$htAttr>";

            foreach ($rowData['v'] as $ci => $val) {
                $colNum    = $ci + 1;
                $cellRef   = $this->colLetter($colNum).$rowNum;
                $cellStyle = array_merge($rowData['s'], $rowData['cs'][$ci] ?? []);

                // FIX: only the FIRST cell (ci===0) creates a merge record
                $colspan = (int)($cellStyle['merge'] ?? 0);
                if ($colspan > 1 && $ci === 0) {
                    $endL     = $this->colLetter($colNum + $colspan - 1);
                    $merges[] = "$cellRef:{$endL}{$rowNum}";
                }

                $xf = $this->getXfIdx($cellStyle);

                if (is_int($val) || is_float($val)) {
                    $xml .= "<c r=\"$cellRef\" s=\"$xf\"><v>$val</v></c>";
                } elseif ($val === '' || $val === null) {
                    $xml .= "<c r=\"$cellRef\" s=\"$xf\"/>";
                } else {
                    $esc = htmlspecialchars((string)$val, ENT_XML1, 'UTF-8');
                    $si  = $this->strIdx($esc);
                    $xml .= "<c r=\"$cellRef\" t=\"s\" s=\"$xf\"><v>$si</v></c>";
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if (!empty($merges)) {
            $xml .= '<mergeCells count="'.count($merges).'">';
            foreach ($merges as $m) $xml .= "<mergeCell ref=\"$m\"/>";
            $xml .= '</mergeCells>';
        }

        return $xml . '</worksheet>';
    }

    // ── BUILD STYLES XML ──────────────────────────────────────────────────────
    private function buildStylesXml(): string {
        // Parse all registered styles
        $stylesByIdx = [];
        foreach ($this->xfMap as $fp => $idx) $stylesByIdx[$idx] = json_decode($fp, true);
        ksort($stylesByIdx);

        // Collect unique fonts (bold × italic × color combinations)
        $fontDefs = []; // font fingerprint => font index
        // Index 0 = default font (no bold, no italic, no color)
        $fontDefs['000'] = 0;

        foreach ($stylesByIdx as $s) {
            $fk = ($s['b']?'1':'0').($s['i']?'1':'0').strtoupper($s['fg']??'');
            if (!isset($fontDefs[$fk])) $fontDefs[$fk] = count($fontDefs);
        }

        // Build font XML entries
        $fontsXml = '<fonts count="'.count($fontDefs).'">';
        foreach ($fontDefs as $fk => $fi) {
            $bold   = substr($fk,0,1)==='1';
            $italic = substr($fk,1,1)==='1';
            $color  = substr($fk,2);
            $fontsXml .= '<font>';
            if ($bold)   $fontsXml .= '<b/>';
            if ($italic) $fontsXml .= '<i/>';
            if ($color)  $fontsXml .= "<color rgb=\"FF{$color}\"/>";
            $fontsXml .= '<sz val="11"/><name val="Calibri"/></font>';
        }
        $fontsXml .= '</fonts>';

        // Collect unique fills (bg colors)
        $fillBgs = []; // bg hex => fill index (0,1 reserved by xlsx spec)
        foreach ($stylesByIdx as $s) {
            $bg = strtoupper($s['bg'] ?? '');
            if ($bg && !isset($fillBgs[$bg])) $fillBgs[$bg] = count($fillBgs) + 2;
        }

        $fillsXml = '<fills count="'.(count($fillBgs)+2).'">'
                  . '<fill><patternFill patternType="none"/></fill>'
                  . '<fill><patternFill patternType="gray125"/></fill>';
        foreach ($fillBgs as $bg => $fi) {
            $fillsXml .= "<fill><patternFill patternType=\"solid\">"
                       . "<fgColor rgb=\"FF{$bg}\"/></patternFill></fill>";
        }
        $fillsXml .= '</fills>';

        $bordersXml = '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>';

        // Build xf entries
        $xfDefs   = [];
        $xfDefs[] = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'; // index 0 default

        foreach ($stylesByIdx as $s) {
            $fk     = ($s['b']?'1':'0').($s['i']?'1':'0').strtoupper($s['fg']??'');
            $fontId = $fontDefs[$fk] ?? 0;
            $bg     = strtoupper($s['bg'] ?? '');
            $fillId = ($bg && isset($fillBgs[$bg])) ? $fillBgs[$bg] : 0;
            $ha     = $s['ha'] ?? '';
            $alignXml = '<alignment vertical="center"'.($ha?" horizontal=\"$ha\"":'').' wrapText="0"/>';

            $applyFont = $fontId > 0 ? ' applyFont="1"' : '';
            $applyFill = $fillId > 0 ? ' applyFill="1"' : '';
            $xfDefs[]  = "<xf numFmtId=\"0\" fontId=\"$fontId\" fillId=\"$fillId\""
                       . " borderId=\"0\" xfId=\"0\"$applyFont$applyFill applyAlignment=\"1\">"
                       . $alignXml.'</xf>';
        }

        $cellXfsXml = '<cellXfs count="'.count($xfDefs).'">'.implode('', $xfDefs).'</cellXfs>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . $fontsXml.$fillsXml.$bordersXml
             . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
             . $cellXfsXml
             . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
             . '</styleSheet>';
    }

    // ── SHARED STRINGS XML ────────────────────────────────────────────────────
    private function buildSharedStringsXml(): string {
        $count = count($this->sharedSt);
        $xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . "<sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\""
               . " count=\"$count\" uniqueCount=\"$count\">";
        $byIdx = array_flip($this->sharedSt);
        for ($i = 0; $i < $count; $i++) {
            $xml .= '<si><t xml:space="preserve">'.($byIdx[$i] ?? '').'</t></si>';
        }
        return $xml . '</sst>';
    }

    private function buildWorkbookXml(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
             . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
             . '<sheets>';
        foreach ($this->sheets as $i => $s) {
            $id   = $i + 1;
            $name = htmlspecialchars($s->name, ENT_XML1, 'UTF-8');
            $xml .= "<sheet name=\"$name\" sheetId=\"$id\" r:id=\"rId$id\"/>";
        }
        return $xml . '</sheets></workbook>';
    }

    private function buildWorkbookRels(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $s) {
            $id   = $i + 1;
            $xml .= "<Relationship Id=\"rId$id\""
                  . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                  . " Target=\"worksheets/sheet$id.xml\"/>";
        }
        $n    = count($this->sheets);
        $xml .= "<Relationship Id=\"rId".($n+1)."\""
              . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"'
              . ' Target="sharedStrings.xml"/>'
              . "<Relationship Id=\"rId".($n+2)."\""
              . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
              . ' Target="styles.xml"/>';
        return $xml . '</Relationships>';
    }

    // ── DOWNLOAD ──────────────────────────────────────────────────────────────
    public function download(string $filename): void {
        // Pre-pass: register all styles and shared strings before generating XML
        foreach ($this->sheets as $sheet) {
            foreach ($sheet->getRows() as $row) {
                foreach ($row['v'] as $ci => $val) {
                    $cs = array_merge($row['s'], $row['cs'][$ci] ?? []);
                    $this->getXfIdx($cs);
                    if (is_string($val) && $val !== '') {
                        $this->strIdx(htmlspecialchars($val, ENT_XML1, 'UTF-8'));
                    }
                }
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        // [Content_Types].xml
        $ct  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
             . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
             . '<Default Extension="xml"  ContentType="application/xml"/>'
             . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
             . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
             . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        foreach ($this->sheets as $i => $s) {
            $id  = $i + 1;
            $ct .= "<Override PartName=\"/xl/worksheets/sheet$id.xml\""
                 . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $ct .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $ct);

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1"'
          . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
          . ' Target="xl/workbook.xml"/></Relationships>');

        $zip->addFromString('xl/workbook.xml',            $this->buildWorkbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRels());
        $zip->addFromString('xl/styles.xml',              $this->buildStylesXml());
        $zip->addFromString('xl/sharedStrings.xml',       $this->buildSharedStringsXml());

        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString(
                "xl/worksheets/sheet".($i+1).".xml",
                $this->buildSheetXml($sheet)
            );
        }

        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header('Content-Length: '.filesize($tmp));
        header('Cache-Control: max-age=0');
        readfile($tmp);
        unlink($tmp);
    }
}