<?php
/**
 * SlidesPdf — tFPDF configurado para os slides no padrão AutoriaSCS (V15):
 * página 960×540 pt (16:9), Comfortaa Regular/Bold (TTF em app/slides/fonts),
 * texto centralizado e itálico sintético (a Comfortaa não tem itálico).
 */
if (!defined('FPDF_FONTPATH')) define('FPDF_FONTPATH', __DIR__ . '/lib/tfpdf/font/');
if (!defined('_SYSTEM_TTFONTS')) define('_SYSTEM_TTFONTS', __DIR__ . '/slides/fonts/');
require_once __DIR__ . '/lib/tfpdf/tfpdf.php';
require_once __DIR__ . '/lib/tfpdf/font/unifont/ttfonts.php';

class SlidesPdf extends tFPDF {
  public function __construct() {
    parent::__construct('L', 'pt', [SLIDES_W, SLIDES_H]);
    $this->SetAutoPageBreak(false);
    $this->SetMargins(0, 0, 0);
    $this->SetCompression(true);
    $this->AddFont('Comfortaa', '', 'Comfortaa-Regular.ttf', true);
    $this->AddFont('Comfortaa', 'B', 'Comfortaa-Bold.ttf', true);
    $this->SetFont('Comfortaa', '', 16);
  }

  /** Marcador: círculo preenchido (nível 0) ou vazado (nível 1+), desenhado com curvas de Bézier. */
  public function Marcador(float $cx, float $cy, float $r, bool $cheio = true): void {
    $k = $this->k; $m = 0.5523 * $r;
    $x = $cx * $k; $y = ($this->h - $cy) * $k; $r *= $k; $m *= $k;
    $s = sprintf('q 0 g 0 G 0.8 w %.2F %.2F m ', $x + $r, $y);
    $s .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x + $r, $y + $m, $x + $m, $y + $r, $x, $y + $r);
    $s .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x - $m, $y + $r, $x - $r, $y + $m, $x - $r, $y);
    $s .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x - $r, $y - $m, $x - $m, $y - $r, $x, $y - $r);
    $s .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x + $m, $y - $r, $x + $r, $y - $m, $x + $r, $y);
    $s .= ($cheio ? 'f' : 'S') . ' Q';
    $this->_out($s);
  }

  /** Texto centralizado na largura $w a partir de $x; $obliquo = itálico sintético (inclinação 12°). */
  public function TextoCentral(float $x, float $y, float $w, string $txt, bool $obliquo = false): void {
    $tw = $this->GetStringWidth($txt);
    $tx = $x + ($w - $tw) / 2;
    if ($obliquo) $this->TextoObliquo($tx, $y, $txt); else $this->Text($tx, $y, $txt);
  }

  /** Igual a Text(), mas com matriz inclinada (itálico sintético). */
  public function TextoObliquo(float $x, float $y, string $txt): void {
    if ($this->unifontSubset) {
      $txt2 = '(' . $this->_escape($this->UTF8ToUTF16BE($txt, false)) . ')';
      foreach ($this->UTF8StringToArray($txt) as $uni) $this->CurrentFont['subset'][$uni] = $uni;
    } else {
      $txt2 = '(' . $this->_escape($txt) . ')';
    }
    $s = sprintf('BT 1 0 %.3F 1 %.2F %.2F Tm %s Tj ET', 0.21, $x * $this->k, ($this->h - $y) * $this->k, $txt2);
    if ($this->ColorFlag) $s = 'q ' . $this->TextColor . ' ' . $s . ' Q';
    $this->_out($s);
  }
}
