<?php
/**
 * Slides no padrão AutoriaSCS (V15).
 *
 *  1) slides_ler_pptx()   — lê o PPTX do professor (ZipArchive + DOM): títulos (objeto de
 *                           título ou primeira linha), parágrafos, marcadores, imagens com
 *                           posição/tamanho originais, legendas, links; detecta vídeo/áudio,
 *                           animações, transições e tabelas.
 *  2) slides_analisar()   — aplica as regras e monta o PLANO: slides só com logos são
 *                           substituídos pela abertura/encerramento padrão; a capa do professor
 *                           pela capa padrão; o texto é medido com a Comfortaa (16 → 14 → 12 pt)
 *                           e, se ainda não couber, o slide é dividido em continuações.
 *                           Devolve o laudo: ERRO (impede), AVISO (apontado, mas gera), INFO.
 *  3) slides_gerar_pdf()  — PDF 16:9 (960×540 pt) com os fundos do modelo oficial (tFPDF).
 *  4) slides_gerar_pptx() — PPTX editável a partir de app/slides/modelo.pptx.
 *  5) slides_processar()  — orquestra: guarda o original, analisa, gera e registra o PDF como
 *                           entrega "Slide" do módulo (aguardando aprovação da TI).
 *
 * Regras (coordenação, out/2026): bloqueiam apenas vídeo/áudio embutido, link para vídeo e
 * mais de 30 slides. Imagens são mantidas no tamanho e posição entregues; só as imagens com
 * conteúdo (sem fundo transparente) precisam de legenda ("Figura N – Título. Fonte: AUTOR, ano").
 * Palavras: aviso acima de 80, aviso forte acima de 120. Fonte única Comfortaa; título negrito
 * #058285 (20/18 pt); texto #000000 (16/14/12 pt); legenda 10 pt.
 */
require_once __DIR__ . '/db.php';

const SLIDES_PT_EMU   = 12700;
const SLIDES_W        = 960;   // pt
const SLIDES_H        = 540;
const SLIDES_COR_TIT  = '058285';
const SLIDES_MAX_PAL_AVISO = 80;
const SLIDES_MAX_PAL_ERRO  = 120;
const SLIDES_MIN_SLIDES    = 6;
const SLIDES_MAX_SLIDES    = 30;
const SLIDES_IMG_MAX_BYTES = 2 * 1024 * 1024;
const SLIDES_IMG_MIN_PX    = 800;
const SLIDES_TAM_TEXTO     = [16, 14, 12];
const SLIDES_VIDEO_EXT     = ['mp4', 'mov', 'avi', 'wmv', 'm4v', 'mpg', 'mpeg', 'webm', 'mkv', 'mp3', 'wav', 'm4a', 'aac', 'ogg', 'wma', 'asf', 'swf'];
const SLIDES_VIDEO_URL_RE  = '~(youtube\.com|youtu\.be|vimeo\.com|dailymotion\.com|tiktok\.com|\.mp4(\?|$)|\.mov(\?|$)|\.webm(\?|$)|drive\.google\.com/file/d/[^/]+/(view|preview))~i';
const SLIDES_LEGENDA_RE    = '~^\s*(figura|imagem|foto|gr[áa]fico|quadro|tabela|infogr[áa]fico|esquema|fonte)\b~iu';

// caixas do modelo (pt) — medidas do modelo oficial (slideLayout15 / slideLayout2)
const SLIDES_BOX = [
  'capa_titulo' => ['x' => 43.2, 'y' => 230.0, 'w' => 665.0, 'h' => 112.0],
  'titulo'      => ['x' => 87.6, 'y' => 124.4, 'w' => 555.0, 'h' => 32.0],
  'texto'       => ['x' => 85.0, 'y' => 171.0, 'w' => 530.0, 'h' => 322.0],
];

function slides_dir_app(): string { return __DIR__ . '/slides'; }

/* ============================================================
 * 1) LEITURA DO PPTX
 * ============================================================ */
function slides_xpath(string $xml): ?DOMXPath {
  $doc = new DOMDocument();
  if (!@$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) return null;
  $xp = new DOMXPath($doc);
  $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
  $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
  $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
  $xp->registerNamespace('p14', 'http://schemas.microsoft.com/office/powerpoint/2010/main');
  return $xp;
}

function slides_rels(ZipArchive $z, string $relsPath, string $baseDir): array {
  $out = [];
  $xml = $z->getFromName($relsPath);
  if ($xml === false || !preg_match_all('~<Relationship\s[^>]*>~', $xml, $mm)) return $out;
  foreach ($mm[0] as $tag) {
    $id = preg_match('~\bId="([^"]+)"~', $tag, $a) ? $a[1] : null;
    if (!$id) continue;
    $type = preg_match('~\bType="([^"]+)"~', $tag, $b) ? $b[1] : '';
    $target = preg_match('~\bTarget="([^"]+)"~', $tag, $c) ? html_entity_decode($c[1]) : '';
    $externo = stripos($tag, 'TargetMode="External"') !== false;
    $path = $target;
    if (!$externo) {
      $parts = [];
      foreach (explode('/', $baseDir . '/' . $target) as $seg) { if ($seg === '..') array_pop($parts); elseif ($seg !== '.' && $seg !== '') $parts[] = $seg; }
      $path = implode('/', $parts);
    }
    $out[$id] = ['type' => $type, 'target' => $path, 'externo' => $externo];
  }
  return $out;
}

function slides_contar_palavras(string $t): int {
  $t = trim(preg_replace('/\s+/u', ' ', $t));
  return $t === '' ? 0 : count(preg_split('/\s+/u', $t));
}

/** md5 das imagens de logo do modelo oficial (layouts, master e logo do topo): ignoradas na leitura. */
function slides_logos_md5(): array {
  static $md5 = null;
  if ($md5 !== null) return $md5;
  $md5 = [];
  $z = new ZipArchive();
  if ($z->open(slides_dir_app() . '/modelo.pptx') !== true) return $md5;
  $refs = [];
  for ($i = 0; $i < $z->numFiles; $i++) {
    $n = $z->getNameIndex($i);
    if (preg_match('~^ppt/(slideLayouts|slideMasters)/_rels/.*\.rels$~', $n)) {
      foreach (slides_rels($z, $n, dirname(dirname($n))) as $r) if (str_contains($r['type'], '/image')) $refs[$r['target']] = true;
    }
  }
  // logo repetida em vários slides do modelo (AutoriaSCS no topo)
  $cont = [];
  for ($i = 0; $i < $z->numFiles; $i++) {
    $n = $z->getNameIndex($i);
    if (preg_match('~^ppt/slides/_rels/slide\d+\.xml\.rels$~', $n)) {
      foreach (slides_rels($z, $n, 'ppt/slides') as $r) if (str_contains($r['type'], '/image')) $cont[$r['target']] = ($cont[$r['target']] ?? 0) + 1;
    }
  }
  foreach ($cont as $t => $c) if ($c >= 3) $refs[$t] = true;
  foreach (array_keys($refs) as $t) { $b = $z->getFromName($t); if ($b !== false) $md5[md5($b)] = true; }
  $z->close();
  return $md5;
}

/** Imagem tem transparência (fundo transparente)? Amostra alguns pontos com GD. */
function slides_imagem_transparente(string $bytes, string $mime): bool {
  if ($mime === 'image/jpeg' || !function_exists('imagecreatefromstring')) return false;
  $im = @imagecreatefromstring($bytes);
  if (!$im) return false;
  $w = imagesx($im); $h = imagesy($im);
  $transp = 0; $tot = 0;
  foreach ([0, 0.5, 0.98] as $fy) foreach ([0, 0.5, 0.98] as $fx) {
    $c = imagecolorat($im, (int)min($w - 1, $fx * $w), (int)min($h - 1, $fy * $h));
    $tot++;
    if ((($c >> 24) & 0x7F) >= 64) $transp++;
  }
  // bordas (fundo transparente costuma estar nas bordas)
  for ($i = 1; $i < 8; $i++) {
    foreach ([[(int)($w * $i / 8), 0], [(int)($w * $i / 8), $h - 1], [0, (int)($h * $i / 8)], [$w - 1, (int)($h * $i / 8)]] as [$x, $y]) {
      $c = imagecolorat($im, $x, $y); $tot++; if ((($c >> 24) & 0x7F) >= 64) $transp++;
    }
  }
  imagedestroy($im);
  return $tot && $transp / $tot >= 0.25;
}

/** Converte para PNG/JPEG aceito no PDF quando o formato não é suportado (webp, gif, bmp). Retorna [bytes, mime] ou null. */
function slides_imagem_normalizar(string $bytes, string $mime): ?array {
  if (in_array($mime, ['image/jpeg', 'image/png'], true)) return [$bytes, $mime];
  if (!function_exists('imagecreatefromstring')) return null;
  $im = @imagecreatefromstring($bytes);
  if (!$im) return null;
  imagesavealpha($im, true);
  ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
  return $png ? [$png, 'image/png'] : null;
}

/**
 * Lê o PPTX. Retorna ['slides' => [...], 'fontes' => [], 'erro' => null|string].
 */
function slides_ler_pptx(string $path): array {
  $z = new ZipArchive();
  if ($z->open($path) !== true) return ['slides' => [], 'fontes' => [], 'erro' => 'O arquivo não é um PPTX válido (não foi possível abrir o pacote).'];
  $pres = $z->getFromName('ppt/presentation.xml');
  if ($pres === false) { $z->close(); return ['slides' => [], 'fontes' => [], 'erro' => 'O arquivo não é uma apresentação PowerPoint (.pptx).']; }
  $sldW = 12192000; $sldH = 6858000;
  if (preg_match('~<p:sldSz[^>]*cx="(\d+)"[^>]*cy="(\d+)"~', $pres, $sz) || preg_match('~<p:sldSz[^>]*cy="(\d+)"[^>]*cx="(\d+)"~', $pres, $sz2)) {
    if (!empty($sz)) { $sldW = (int)$sz[1]; $sldH = (int)$sz[2]; } else { $sldH = (int)$sz2[1]; $sldW = (int)$sz2[2]; }
  }
  $presRels = slides_rels($z, 'ppt/_rels/presentation.xml.rels', 'ppt');
  preg_match_all('~<p:sldId\s[^>]*r:id="([^"]+)"~', $pres, $mm);
  $ordem = [];
  foreach ($mm[1] as $rid) if (isset($presRels[$rid])) $ordem[] = $presRels[$rid]['target'];
  if (!$ordem) {
    for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); if (preg_match('~^ppt/slides/slide\d+\.xml$~', $n)) $ordem[] = $n; }
    usort($ordem, fn($a, $b) => (int)filter_var($a, FILTER_SANITIZE_NUMBER_INT) <=> (int)filter_var($b, FILTER_SANITIZE_NUMBER_INT));
  }
  $logos = slides_logos_md5();
  $fontes = []; $slides = [];

  foreach ($ordem as $n => $slidePath) {
    $xml = $z->getFromName($slidePath);
    if ($xml === false) continue;
    $rels = slides_rels($z, 'ppt/slides/_rels/' . basename($slidePath) . '.rels', 'ppt/slides');
    $xp = slides_xpath($xml);
    $s = ['n' => $n + 1, 'titulo' => '', 'paragrafos' => [], 'imagens' => [], 'legendas' => [], 'links' => [], 'videos' => [],
          'tem_animacao' => false, 'tem_transicao' => false, 'tabela' => false, 'palavras' => 0, 'logos_ignoradas' => 0];
    if (!$xp) { $slides[] = $s; continue; }

    foreach ($rels as $r) {
      $t = strtolower($r['type']); $tg = strtolower($r['target']);
      $ext = pathinfo(parse_url($tg, PHP_URL_PATH) ?: $tg, PATHINFO_EXTENSION);
      if (str_contains($t, '/video') || str_contains($t, '/audio') || str_contains($t, '/media') || in_array($ext, SLIDES_VIDEO_EXT, true)) $s['videos'][] = $r['externo'] ? $r['target'] : basename($r['target']);
    }
    foreach ($xp->query('//a:videoFile | //a:audioFile | //a:quickTimeFile | //p14:media | //*[local-name()="video"]') as $el) $s['videos'][] = $el->localName;
    $s['videos'] = array_values(array_unique($s['videos']));
    $s['tem_animacao']  = $xp->query('//p:timing')->length > 0;
    $s['tem_transicao'] = $xp->query('//*[local-name()="transition"]')->length > 0;
    foreach ($xp->query('//a:hlinkClick/@r:id') as $attr) { $r = $rels[$attr->nodeValue] ?? null; if ($r && $r['externo']) $s['links'][] = $r['target']; }
    $s['links'] = array_values(array_unique($s['links']));
    foreach ($xp->query('//a:latin/@typeface') as $attr) { $f = trim($attr->nodeValue); if ($f !== '' && $f[0] !== '+') $fontes[$f] = true; }

    // formas de texto
    $shapes = [];
    foreach ($xp->query('//p:sp') as $sp) {
      $ph = $xp->query('.//p:nvPr/p:ph', $sp)->item(0);
      $phType = $ph ? ($ph->getAttribute('type') ?: 'body') : '';
      $off = $xp->query('.//a:xfrm/a:off', $sp)->item(0);
      $y = $off ? (int)$off->getAttribute('y') : 0;
      $paras = [];
      foreach ($xp->query('./p:txBody/a:p', $sp) as $p) {
        $texto = ''; $negrito = true; $runs = 0;
        foreach ($xp->query('./a:r | ./a:br | ./a:fld', $p) as $run) {
          if ($run->localName === 'br') { $texto .= "\n"; continue; }
          $t = $xp->query('./a:t', $run)->item(0); $rpr = $xp->query('./a:rPr', $run)->item(0);
          $tx = $t ? $t->textContent : '';
          if (trim($tx) === '') { $texto .= $tx; continue; }
          $runs++; if (!$rpr || $rpr->getAttribute('b') !== '1') $negrito = false;
          $texto .= $tx;
        }
        $texto = preg_replace('/[ \t]+/u', ' ', $texto);
        if (trim($texto) === '') continue;
        $ppr = $xp->query('./a:pPr', $p)->item(0);
        $lvl = $ppr ? (int)$ppr->getAttribute('lvl') : 0;
        $bullet = $ppr ? ($xp->query('./a:buChar | ./a:buAutoNum', $ppr)->length > 0) : false;
        if (!$bullet && $ph && in_array($phType, ['body', 'obj'], true) && !($ppr && $xp->query('./a:buNone', $ppr)->length)) $bullet = true;
        foreach (preg_split('/\n/', $texto) as $linha) {
          if (trim($linha) === '') continue;
          $paras[] = ['texto' => trim($linha), 'nivel' => min(2, $lvl), 'bullet' => $bullet, 'negrito' => $runs > 0 && $negrito];
        }
      }
      if ($paras) $shapes[] = ['ph' => $phType, 'y' => $y, 'paras' => $paras];
    }
    foreach ($xp->query('//a:tbl') as $tbl) {
      $s['tabela'] = true;
      foreach ($xp->query('.//a:tr', $tbl) as $tr) {
        $cels = [];
        foreach ($xp->query('./a:tc', $tr) as $tc) { $tx = trim(preg_replace('/\s+/u', ' ', $tc->textContent)); if ($tx !== '') $cels[] = $tx; }
        if ($cels) $shapes[] = ['ph' => 'tabela', 'y' => PHP_INT_MAX, 'paras' => [['texto' => implode(' | ', $cels), 'nivel' => 0, 'bullet' => true, 'negrito' => false]]];
      }
    }
    usort($shapes, fn($a, $b) => $a['y'] <=> $b['y']);

    // título: objeto de título; senão, a primeira linha curta (sem marcador) do primeiro texto
    $idxTitulo = null;
    foreach ($shapes as $i => $sh) if (in_array($sh['ph'], ['title', 'ctrTitle'], true)) { $idxTitulo = $i; break; }
    if ($idxTitulo !== null) {
      $s['titulo'] = trim(implode(' ', array_map(fn($p) => $p['texto'], $shapes[$idxTitulo]['paras'])));
      unset($shapes[$idxTitulo]);
    } elseif ($shapes) {
      $k = array_key_first($shapes); $p0 = $shapes[$k]['paras'][0];
      if (!$p0['bullet'] && slides_contar_palavras($p0['texto']) <= 14 && !preg_match(SLIDES_LEGENDA_RE, $p0['texto'])) {
        $s['titulo'] = $p0['texto']; array_shift($shapes[$k]['paras']);
        if (!$shapes[$k]['paras']) unset($shapes[$k]);
      }
    }
    foreach ($shapes as $sh) foreach ($sh['paras'] as $p) {
      if (preg_match(SLIDES_LEGENDA_RE, $p['texto'])) { $s['legendas'][] = $p['texto']; continue; }
      $s['paragrafos'][] = $p;
    }

    // imagens com posição/tamanho originais (relativos ao slide)
    foreach ($xp->query('//p:pic') as $pic) {
      if ($xp->query('.//a:videoFile | .//a:audioFile', $pic)->length) continue;
      $blip = $xp->query('.//a:blip/@r:embed', $pic)->item(0);
      if (!$blip) continue;
      $r = $rels[$blip->nodeValue] ?? null;
      if (!$r || $r['externo']) continue;
      $bytes = $z->getFromName($r['target']);
      if ($bytes === false) continue;
      $ext = strtolower(pathinfo($r['target'], PATHINFO_EXTENSION));
      if (in_array($ext, SLIDES_VIDEO_EXT, true)) { $s['videos'][] = basename($r['target']); continue; }
      if (isset($logos[md5($bytes)])) { $s['logos_ignoradas']++; continue; }
      $off = $xp->query('./p:spPr/a:xfrm/a:off', $pic)->item(0); $ext2 = $xp->query('./p:spPr/a:xfrm/a:ext', $pic)->item(0);
      $info = @getimagesizefromstring($bytes);
      $mime = $info['mime'] ?? ''; $w = $info[0] ?? 0; $h = $info[1] ?? 0;
      $rx = $off ? (int)$off->getAttribute('x') / $sldW : 0.1; $ry = $off ? (int)$off->getAttribute('y') / $sldH : 0.3;
      $rw = $ext2 ? (int)$ext2->getAttribute('cx') / $sldW : 0.4; $rh = $ext2 ? (int)$ext2->getAttribute('cy') / $sldH : 0.4;
      $s['imagens'][] = ['bytes' => $bytes, 'ext' => $ext, 'mime' => $mime, 'w' => $w, 'h' => $h, 'nome' => basename($r['target']), 'tamanho' => strlen($bytes),
                         'rx' => $rx, 'ry' => $ry, 'rw' => $rw, 'rh' => $rh, 'transparente' => $mime ? slides_imagem_transparente($bytes, $mime) : false];
    }
    $s['palavras'] = slides_contar_palavras($s['titulo'] . ' ' . implode(' ', array_map(fn($p) => $p['texto'], $s['paragrafos'])));
    $slides[] = $s;
  }
  $z->close();
  return ['slides' => $slides, 'fontes' => array_keys($fontes), 'erro' => null];
}

/* ============================================================
 * 2) ANÁLISE + PLANO
 * ============================================================ */
function slides_pdf_medidor() {
  static $pdf = null;
  if ($pdf === null) { require_once __DIR__ . '/slides_pdf.php'; $pdf = new SlidesPdf(); }
  return $pdf;
}

function slides_quebrar($pdf, string $texto, float $w): array {
  $linhas = []; $atual = '';
  foreach (preg_split('/\s+/u', trim($texto)) as $pal) {
    $teste = $atual === '' ? $pal : $atual . ' ' . $pal;
    if ($pdf->GetStringWidth($teste) <= $w) { $atual = $teste; continue; }
    if ($atual !== '') $linhas[] = $atual;
    while ($pdf->GetStringWidth($pal) > $w && mb_strlen($pal) > 1) {
      $corte = mb_strlen($pal) - 1;
      while ($corte > 1 && $pdf->GetStringWidth(mb_substr($pal, 0, $corte)) > $w) $corte--;
      $linhas[] = mb_substr($pal, 0, $corte); $pal = mb_substr($pal, $corte);
    }
    $atual = $pal;
  }
  if ($atual !== '') $linhas[] = $atual;
  return $linhas ?: [''];
}

/** Altura (pt) dos parágrafos com tamanho $sz na largura $w. Retorna [altura total, linhas por parágrafo, altura por parágrafo]. */
function slides_medir_paragrafos($pdf, array $paras, float $w, float $sz): array {
  $lh = $sz * 1.3; $esp = $sz * 0.45; $h = 0; $out = []; $alt = [];
  foreach (array_values($paras) as $i => $p) {
    $indent = $p['bullet'] ? 16 + $p['nivel'] * 16 : 0;
    $pdf->SetFont('Comfortaa', $p['negrito'] ? 'B' : '', $sz);
    $ls = slides_quebrar($pdf, $p['texto'], $w - $indent);
    $out[$i] = $ls; $alt[$i] = count($ls) * $lh + ($i > 0 ? $esp : 0);
    $h += $alt[$i];
  }
  return [$h, $out, $alt];
}

/** Posição/tamanho (pt) de uma imagem, mantendo o tamanho entregue e dentro do slide. */
function slides_imagem_rect(array $img): array {
  $w = max(20, $img['rw'] * SLIDES_W); $h = max(20, $img['rh'] * SLIDES_H);
  $x = $img['rx'] * SLIDES_W; $y = $img['ry'] * SLIDES_H;
  if ($w > SLIDES_W - 20) { $h *= (SLIDES_W - 20) / $w; $w = SLIDES_W - 20; }
  if ($h > SLIDES_H - 20) { $w *= (SLIDES_H - 20) / $h; $h = SLIDES_H - 20; }
  $x = max(10, min($x, SLIDES_W - 10 - $w)); $y = max(10, min($y, SLIDES_H - 10 - $h));
  return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
}

/** Caixa de texto livre: a caixa padrão menos as imagens que a invadem (texto à esquerda/direita/acima). */
function slides_caixa_livre(array $imagens): array {
  $b = SLIDES_BOX['texto'];
  foreach ($imagens as $im) {
    $r = $im['rect'];
    $intersecta = $r['x'] < $b['x'] + $b['w'] && $r['x'] + $r['w'] > $b['x'] && $r['y'] < $b['y'] + $b['h'] && $r['y'] + $r['h'] > $b['y'];
    if (!$intersecta) continue;
    $cx = $r['x'] + $r['w'] / 2;
    if ($cx >= $b['x'] + $b['w'] / 2) { $novaW = $r['x'] - 10 - $b['x']; if ($novaW >= 150) { $b['w'] = $novaW; continue; } }
    else { $novoX = $r['x'] + $r['w'] + 10; $novaW = $b['x'] + $b['w'] - $novoX; if ($novaW >= 150) { $b['w'] = $novaW; $b['x'] = $novoX; continue; } }
    if ($r['y'] - $b['y'] >= 90) { $b['h'] = $r['y'] - 8 - $b['y']; continue; }
    if ($b['y'] + $b['h'] - ($r['y'] + $r['h']) >= 90) { $novoY = $r['y'] + $r['h'] + 8; $b['h'] = $b['y'] + $b['h'] - $novoY; $b['y'] = $novoY; }
  }
  return $b;
}

function slides_analisar(array $lido, array $curso, int $modulo, string $nomeModulo): array {
  $laudo = []; $plano = [];
  $add = function (string $nivel, ?int $slide, string $msg) use (&$laudo) { $laudo[] = ['nivel' => $nivel, 'slide' => $slide, 'msg' => $msg]; };
  if ($lido['erro']) { $add('ERRO', null, $lido['erro']); return ['laudo' => $laudo, 'aprovado' => false, 'plano' => [], 'resumo' => []]; }

  // slides só com logos/imagens decorativas e sem texto → abertura/encerramento padrão
  $slides = [];
  foreach ($lido['slides'] as $s) {
    $semTexto = $s['titulo'] === '' && !$s['paragrafos'] && !$s['legendas'];
    $soDecor = !array_filter($s['imagens'], fn($i) => !$i['transparente']);
    if ($semTexto && $soDecor && !$s['videos']) { $add('INFO', $s['n'], 'Slide só com logos: substituído pela abertura/encerramento padrão.'); continue; }
    $slides[] = $s;
  }
  // capa do professor (1º slide só com título / subtítulo curto, sem imagem de conteúdo) → capa padrão
  if ($slides) {
    $s0 = $slides[0];
    $semConteudoImg = !array_filter($s0['imagens'], fn($i) => !$i['transparente']);
    if ($semConteudoImg && !$s0['videos'] && !$s0['links'] && !$s0['legendas'] && $s0['titulo'] !== '' && count($s0['paragrafos']) <= 1
        && !($s0['paragrafos'][0]['bullet'] ?? false) && slides_contar_palavras($s0['paragrafos'][0]['texto'] ?? '') <= 20) {
      $add('INFO', $s0['n'], 'Capa do(a) professor(a) substituída pela capa padrão (nome do curso + módulo).');
      array_shift($slides);
    }
  }
  $n = count($slides);
  if ($n === 0) $add('ERRO', null, 'Nenhum slide com conteúdo foi encontrado no arquivo.');
  if ($n > SLIDES_MAX_SLIDES) $add('ERRO', null, "A apresentação tem {$n} slides de conteúdo; o máximo por módulo é " . SLIDES_MAX_SLIDES . '.');
  elseif ($n && $n < SLIDES_MIN_SLIDES) $add('AVISO', null, "A apresentação tem {$n} slide(s) de conteúdo; o recomendado é ao menos " . SLIDES_MIN_SLIDES . ' por módulo.');
  if ($lido['fontes']) $add('INFO', null, 'Formatação original substituída pelo padrão (fontes encontradas: ' . implode(', ', array_slice($lido['fontes'], 0, 6)) . ').');

  $pdf = slides_pdf_medidor();
  $numFig = 0; $divididos = 0;
  foreach ($slides as $k => $s) {
    $num = $s['n'];
    if ($s['videos']) $add('ERRO', $num, 'Vídeo ou áudio embutido (' . implode(', ', array_filter($s['videos'], fn($v) => str_contains($v, '.')) ?: ['mídia']) . '). A apresentação não pode conter vídeos: envie-os pela entrega de Vídeo do módulo.');
    foreach ($s['links'] as $l) { if (preg_match(SLIDES_VIDEO_URL_RE, $l)) $add('ERRO', $num, "Link para vídeo não permitido: {$l}"); else $add('INFO', $num, "Link mantido como texto: {$l}"); }
    if ($s['tem_animacao'])  $add('AVISO', $num, 'Animações removidas (o PDF não as reproduz).');
    if ($s['tem_transicao']) $add('AVISO', $num, 'Transição de slide removida.');
    if ($s['tabela'])        $add('AVISO', $num, 'Tabela convertida em linhas de texto (colunas separadas por " | ").');
    if ($s['titulo'] === '') $add('AVISO', $num, 'Slide sem título: inclua uma primeira linha curta como título.');
    elseif (slides_contar_palavras($s['titulo']) > 14) $add('AVISO', $num, 'Título longo (' . slides_contar_palavras($s['titulo']) . ' palavras); recomenda-se até 8.');
    if ($s['palavras'] > SLIDES_MAX_PAL_ERRO) $add('AVISO', $num, "{$s['palavras']} palavras no slide; o máximo recomendado é " . SLIDES_MAX_PAL_ERRO . '. O conteúdo foi dividido/ajustado para caber.');
    elseif ($s['palavras'] > SLIDES_MAX_PAL_AVISO) $add('AVISO', $num, "{$s['palavras']} palavras no slide; o ideal é até " . SLIDES_MAX_PAL_AVISO . '.');

    // imagens: mantidas no tamanho/posição entregues; legenda só para as com conteúdo
    $imgs = []; $legendas = $s['legendas']; $comConteudo = 0;
    foreach ($s['imagens'] as $img) {
      $norm = slides_imagem_normalizar($img['bytes'], $img['mime']);
      if (!$norm) { $add('AVISO', $num, "Imagem {$img['nome']} em formato não suportado ({$img['ext']}); foi omitida. Use JPG ou PNG."); continue; }
      [$img['bytes'], $img['mime']] = $norm;
      $img['rect'] = slides_imagem_rect($img);
      if ($img['tamanho'] > SLIDES_IMG_MAX_BYTES) $add('AVISO', $num, "Imagem {$img['nome']} com " . round($img['tamanho'] / 1048576, 1) . ' MB; recomenda-se até 2 MB.');
      if ($img['w'] && $img['w'] < SLIDES_IMG_MIN_PX && !$img['transparente']) $add('AVISO', $num, "Imagem {$img['nome']} com {$img['w']} px de largura; recomenda-se ao menos " . SLIDES_IMG_MIN_PX . ' px.');
      $img['legenda'] = '';
      if (!$img['transparente']) {
        $comConteudo++; $numFig++;
        if ($legendas) { $img['legenda'] = array_shift($legendas); if (!preg_match('~fonte~iu', $img['legenda'])) $add('AVISO', $num, 'A legenda da imagem não cita a fonte ("Fonte: AUTOR, ano").'); }
        else $add('AVISO', $num, 'Imagem com conteúdo sem legenda. Inclua "Figura N – Título. Fonte: AUTOR, ano." no mesmo slide.');
      }
      $imgs[] = $img;
    }
    foreach ($legendas as $lg) $s['paragrafos'][] = ['texto' => $lg, 'nivel' => 0, 'bullet' => false, 'negrito' => false]; // legendas sem imagem: ficam como texto

    // título: 20 pt; 18 pt se não couber em uma linha
    $pdf->SetFont('Comfortaa', 'B', 20); $tz = 20;
    if ($s['titulo'] !== '' && count(slides_quebrar($pdf, $s['titulo'], SLIDES_BOX['titulo']['w'])) > 1) { $tz = 18; $pdf->SetFont('Comfortaa', 'B', 18);
      if (count(slides_quebrar($pdf, $s['titulo'], SLIDES_BOX['titulo']['w'])) > 1) $add('AVISO', $num, 'Título em duas linhas; prefira um título mais curto.'); }

    // texto: cabe na caixa livre? 16 → 14 → 12; senão divide em continuações (mantendo 14 pt)
    $box = slides_caixa_livre($imgs);
    $paginas = [];
    if ($s['paragrafos']) {
      $escolhido = null;
      foreach (SLIDES_TAM_TEXTO as $sz) { [$h, $ls] = slides_medir_paragrafos($pdf, $s['paragrafos'], $box['w'], $sz); if ($h <= $box['h']) { $escolhido = $sz; $paginas[] = ['paragrafos' => $s['paragrafos'], 'linhas' => $ls, 'sz' => $sz, 'box' => $box]; break; } }
      if ($escolhido === null) {
        $sz = 14; $restantes = array_values($s['paragrafos']); $boxCont = SLIDES_BOX['texto'];
        while ($restantes) {
          $b = $paginas ? $boxCont : $box;
          [, , $alt] = slides_medir_paragrafos($pdf, $restantes, $b['w'], $sz);
          $acum = 0; $qtd = 0;
          foreach ($alt as $i => $a) { if ($acum + $a > $b['h'] && $qtd > 0) break; $acum += $a; $qtd++; }
          $parte = array_slice($restantes, 0, $qtd); $restantes = array_slice($restantes, $qtd);
          [, $ls] = slides_medir_paragrafos($pdf, $parte, $b['w'], $sz);
          $paginas[] = ['paragrafos' => $parte, 'linhas' => $ls, 'sz' => $sz, 'box' => $b];
        }
        $divididos++;
        $add('AVISO', $num, 'O texto não cabe no espaço livre: o slide foi dividido em ' . count($paginas) . ' partes (continuação). Reduza o texto se preferir um único slide.');
      } elseif ($escolhido < 16) {
        $add('AVISO', $num, "Texto em {$escolhido} pt para caber no espaço livre (o padrão prefere 16 pt).");
      }
    } else {
      $paginas[] = ['paragrafos' => [], 'linhas' => [], 'sz' => 16, 'box' => $box];
      if (!$imgs) $add('AVISO', $num, 'Slide apenas com título, sem conteúdo.');
    }
    foreach ($paginas as $pi => $pg) {
      $plano[] = ['tipo' => 'conteudo', 'n' => $num, 'parte' => $pi + 1, 'partes' => count($paginas),
                  'titulo' => $s['titulo'] . ($pi > 0 ? ' (continuação)' : ''), 'titulo_sz' => $tz,
                  'paragrafos' => $pg['paragrafos'], 'linhas' => $pg['linhas'], 'texto_sz' => $pg['sz'], 'box' => $pg['box'],
                  'imagens' => $pi === 0 ? $imgs : [], 'palavras' => $pi === 0 ? $s['palavras'] : 0];
    }
  }

  $temErro = (bool)array_filter($laudo, fn($l) => $l['nivel'] === 'ERRO');
  $capa = ['tipo' => 'capa', 'curso' => $curso['nome_curso'], 'modulo' => "Módulo {$modulo}: {$nomeModulo}"];
  $resumo = ['slides' => $n, 'slides_gerados' => count($plano), 'palavras' => array_sum(array_column($plano, 'palavras')), 'imagens' => $numFig,
             'erros' => count(array_filter($laudo, fn($l) => $l['nivel'] === 'ERRO')), 'avisos' => count(array_filter($laudo, fn($l) => $l['nivel'] === 'AVISO'))];
  return ['laudo' => $laudo, 'aprovado' => !$temErro && $n > 0, 'plano' => array_merge([$capa], $plano), 'resumo' => $resumo];
}

/* ============================================================
 * 3) PDF
 * ============================================================ */
function slides_gerar_pdf(array $plano, array $curso, string $destino): void {
  require_once __DIR__ . '/slides_pdf.php';
  $pdf = new SlidesPdf();
  $pdf->SetTitle($curso['nome_curso'] . ' — ' . ($plano[0]['modulo'] ?? 'Slides'), true);
  $pdf->SetAuthor($curso['professor_nome'] ?? 'AutoriaSCS', true);
  $pdf->SetCreator('AutoriaSCS • Gestão de Cursos (CECAPE)', true);
  $bg = slides_dir_app() . '/bg';

  $pdf->AddPage(); $pdf->Image("$bg/abertura.png", 0, 0, SLIDES_W, SLIDES_H);
  foreach ($plano as $sl) {
    $pdf->AddPage();
    if ($sl['tipo'] === 'capa') {
      $pdf->Image("$bg/titulo.png", 0, 0, SLIDES_W, SLIDES_H);
      $b = SLIDES_BOX['capa_titulo'];
      $pdf->SetTextColor(0x05, 0x82, 0x85); $pdf->SetFont('Comfortaa', 'B', 24);
      $y = $b['y'] + 24;
      foreach (slides_quebrar($pdf, $sl['curso'], $b['w']) as $l) { $pdf->TextoCentral($b['x'], $y, $b['w'], $l, false); $y += 36; }
      $pdf->SetFont('Comfortaa', 'B', 20);
      foreach (slides_quebrar($pdf, $sl['modulo'], $b['w']) as $l) { $pdf->TextoCentral($b['x'], $y, $b['w'], $l, true); $y += 30; }
      continue;
    }
    $pdf->Image("$bg/conteudo.png", 0, 0, SLIDES_W, SLIDES_H);
    if ($sl['titulo'] !== '') {
      $t = SLIDES_BOX['titulo'];
      $pdf->SetTextColor(0x05, 0x82, 0x85); $pdf->SetFont('Comfortaa', 'B', $sl['titulo_sz']);
      $y = $t['y'] + $sl['titulo_sz'];
      foreach (slides_quebrar($pdf, $sl['titulo'], $t['w']) as $l) { $pdf->Text($t['x'], $y, $l); $y += $sl['titulo_sz'] * 1.25; }
    }
    foreach ($sl['imagens'] as $img) {
      $r = $img['rect'];
      $tmp = tempnam(sys_get_temp_dir(), 'sl') . '.' . ($img['mime'] === 'image/png' ? 'png' : 'jpg');
      file_put_contents($tmp, $img['bytes']);
      try { $pdf->Image($tmp, $r['x'], $r['y'], $r['w'], $r['h']); } catch (Throwable $e) { /* imagem inválida: omitida */ }
      @unlink($tmp);
      if ($img['legenda'] !== '') {
        $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Comfortaa', '', 10);
        $ly = min(SLIDES_H - 8, $r['y'] + $r['h'] + 13);
        foreach (slides_quebrar($pdf, $img['legenda'], max($r['w'], 200)) as $l) { $pdf->TextoCentral($r['x'] + $r['w'] / 2 - max($r['w'], 200) / 2, $ly, max($r['w'], 200), $l, false); $ly += 13; }
      }
    }
    if ($sl['paragrafos']) {
      $box = $sl['box']; $sz = $sl['texto_sz']; $lh = $sz * 1.3; $esp = $sz * 0.45;
      $pdf->SetTextColor(0, 0, 0);
      $y = $box['y'] + $sz;
      foreach (array_values($sl['paragrafos']) as $i => $p) {
        if ($i > 0) $y += $esp;
        $indent = $p['bullet'] ? 16 + $p['nivel'] * 16 : 0;
        $pdf->SetFont('Comfortaa', $p['negrito'] ? 'B' : '', $sz);
        $ls = $sl['linhas'][$i] ?? slides_quebrar($pdf, $p['texto'], $box['w'] - $indent);
        foreach ($ls as $j => $l) {
          if ($j === 0 && $p['bullet']) $pdf->Marcador($box['x'] + $indent - 9, $y - $sz * 0.32, $sz * 0.16, $p['nivel'] === 0);
          $pdf->Text($box['x'] + $indent, $y, $l); $y += $lh;
        }
      }
    }
  }
  $pdf->AddPage(); $pdf->Image("$bg/encerramento.png", 0, 0, SLIDES_W, SLIDES_H);
  $pdf->Output('F', $destino);
}

/* ============================================================
 * 4) PPTX (a partir do modelo oficial)
 * ============================================================ */
function slides_x(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

function slides_run_xml(string $texto, int $sz, string $cor, bool $b = false, bool $i = false): string {
  return '<a:r><a:rPr lang="pt-BR" sz="' . ($sz * 100) . '"' . ($b ? ' b="1"' : '') . ($i ? ' i="1"' : '') . '><a:solidFill><a:srgbClr val="' . $cor . '"/></a:solidFill>'
       . '<a:latin typeface="Comfortaa"/><a:ea typeface="Comfortaa"/><a:cs typeface="Comfortaa"/><a:sym typeface="Comfortaa"/></a:rPr><a:t>' . slides_x($texto) . '</a:t></a:r>';
}
function slides_txbox_xml(int $id, array $boxPt, string $parasXml, string $anchor = 't'): string {
  $x = (int)round($boxPt['x'] * SLIDES_PT_EMU); $y = (int)round($boxPt['y'] * SLIDES_PT_EMU);
  $w = (int)round($boxPt['w'] * SLIDES_PT_EMU); $h = (int)round($boxPt['h'] * SLIDES_PT_EMU);
  return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Texto ' . $id . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
       . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln><a:noFill/></a:ln></p:spPr>'
       . '<p:txBody><a:bodyPr anchor="' . $anchor . '" lIns="91425" tIns="45700" rIns="91425" bIns="45700" wrap="square"><a:normAutofit/></a:bodyPr><a:lstStyle/>' . $parasXml . '</p:txBody></p:sp>';
}
function slides_pic_xml(int $id, string $rid, array $boxPt): string {
  $x = (int)round($boxPt['x'] * SLIDES_PT_EMU); $y = (int)round($boxPt['y'] * SLIDES_PT_EMU);
  $w = (int)round($boxPt['w'] * SLIDES_PT_EMU); $h = (int)round($boxPt['h'] * SLIDES_PT_EMU);
  return '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="Imagem ' . $id . '"/><p:cNvPicPr preferRelativeResize="0"/><p:nvPr/></p:nvPicPr>'
       . '<p:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
       . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $w . '" cy="' . $h . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln><a:noFill/></a:ln></p:spPr></p:pic>';
}
function slides_slide_xml(string $corpo): string {
  return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
       . '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
       . $corpo . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
}
function slides_logo_topo_xml(): string {
  return '<p:pic><p:nvPicPr><p:cNvPr id="2" name="Logo AutoriaSCS"/><p:cNvPicPr preferRelativeResize="0"/><p:nvPr/></p:nvPicPr><p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
       . '<p:spPr><a:xfrm><a:off x="5309263" y="261550"/><a:ext cx="1573475" cy="1026900"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln><a:noFill/></a:ln></p:spPr></p:pic>';
}

function slides_gerar_pptx(array $plano, array $curso, string $destino): void {
  $modelo = slides_dir_app() . '/modelo.pptx';
  $src = new ZipArchive(); if ($src->open($modelo) !== true) throw new RuntimeException('Modelo PPTX não encontrado em app/slides/modelo.pptx.');
  $dst = new ZipArchive(); @unlink($destino);
  if ($dst->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Não foi possível criar o PPTX.');

  $pres = $src->getFromName('ppt/presentation.xml');
  $presRels = slides_rels($src, 'ppt/_rels/presentation.xml.rels', 'ppt');
  preg_match_all('~<p:sldId\s[^>]*id="(\d+)"[^>]*r:id="([^"]+)"[^>]*/>~', $pres, $mm, PREG_SET_ORDER);
  $ordem = []; foreach ($mm as $m) $ordem[] = ['id' => (int)$m[1], 'rid' => $m[2], 'path' => $presRels[$m[2]]['target'] ?? '', 'tag' => $m[0]];
  $manter = [$ordem[0], end($ordem)];
  $remover = []; $removerPaths = [];
  foreach ($ordem as $o) {
    if ($o === $manter[0] || $o === $manter[1]) continue;
    $remover[] = $o; $removerPaths[$o['path']] = true; $removerPaths['ppt/slides/_rels/' . basename($o['path']) . '.rels'] = true;
    foreach (slides_rels($src, 'ppt/slides/_rels/' . basename($o['path']) . '.rels', 'ppt/slides') as $r) {
      if (str_contains($r['type'], '/notesSlide')) { $removerPaths[$r['target']] = true; $removerPaths['ppt/notesSlides/_rels/' . basename($r['target']) . '.rels'] = true; }
    }
  }
  $logoRel = 'ppt/media/image12.png'; $layoutCapa = 'slideLayout2.xml'; $layoutConteudo = 'slideLayout15.xml';
  $relsS2 = slides_rels($src, 'ppt/slides/_rels/' . basename($ordem[1]['path'] ?? '') . '.rels', 'ppt/slides');
  foreach ($relsS2 as $r) { if (str_contains($r['type'], '/image')) $logoRel = $r['target']; if (str_contains($r['type'], '/slideLayout')) $layoutCapa = basename($r['target']); }
  foreach (slides_rels($src, 'ppt/slides/_rels/' . basename($ordem[3]['path'] ?? $ordem[1]['path']) . '.rels', 'ppt/slides') as $r) if (str_contains($r['type'], '/slideLayout')) $layoutConteudo = basename($r['target']);

  $novos = []; $novosRels = ''; $novosCT = ''; $sldIds = ''; $midia = []; $nextId = 1000; $nextRid = 900; $baseNum = 100;
  foreach ($plano as $k => $sl) {
    $num = $baseNum + $k; $corpo = slides_logo_topo_xml(); $shapeId = 10;
    $rels = [['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout', '../slideLayouts/' . ($sl['tipo'] === 'capa' ? $layoutCapa : $layoutConteudo)],
             ['rId2', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', '../media/' . basename($logoRel)]];
    if ($sl['tipo'] === 'capa') {
      $ppr = '<a:pPr algn="ctr"><a:lnSpc><a:spcPct val="150000"/></a:lnSpc><a:buNone/></a:pPr>';
      $corpo .= slides_txbox_xml($shapeId++, SLIDES_BOX['capa_titulo'], '<a:p>' . $ppr . slides_run_xml($sl['curso'], 24, SLIDES_COR_TIT, true) . '</a:p><a:p>' . $ppr . slides_run_xml($sl['modulo'], 20, SLIDES_COR_TIT, true, true) . '</a:p>');
    } else {
      if ($sl['titulo'] !== '') $corpo .= slides_txbox_xml($shapeId++, SLIDES_BOX['titulo'], '<a:p><a:pPr><a:buNone/></a:pPr>' . slides_run_xml($sl['titulo'], $sl['titulo_sz'], SLIDES_COR_TIT, true) . '</a:p>');
      foreach ($sl['imagens'] as $im => $img) {
        $r = $img['rect']; $ext = $img['mime'] === 'image/png' ? 'png' : 'jpeg';
        $nomeMidia = "ppt/media/autoria_{$num}_{$im}.{$ext}"; $midia[$nomeMidia] = $img['bytes'];
        $rid = 'rId' . (1 + count($rels));
        $rels[] = [$rid, 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', '../media/' . basename($nomeMidia)];
        $corpo .= slides_pic_xml($shapeId++, $rid, $r);
        if ($img['legenda'] !== '') $corpo .= slides_txbox_xml($shapeId++, ['x' => $r['x'] + $r['w'] / 2 - max($r['w'], 200) / 2, 'y' => $r['y'] + $r['h'] + 2, 'w' => max($r['w'], 200), 'h' => 28],
          '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>' . slides_run_xml($img['legenda'], 10, '000000') . '</a:p>');
      }
      if ($sl['paragrafos']) {
        $paras = '';
        foreach ($sl['paragrafos'] as $p) {
          $marL = $p['bullet'] ? 228600 + $p['nivel'] * 228600 : 0;
          $ppr = $p['bullet']
            ? '<a:pPr marL="' . $marL . '" indent="-171450"><a:lnSpc><a:spcPct val="115000"/></a:lnSpc><a:spcBef><a:spcPts val="' . ($sl['texto_sz'] * 45) . '"/></a:spcBef><a:buClr><a:srgbClr val="000000"/></a:buClr><a:buFont typeface="Arial"/><a:buChar char="' . ($p['nivel'] > 0 ? '◦' : '●') . '"/></a:pPr>'
            : '<a:pPr><a:lnSpc><a:spcPct val="115000"/></a:lnSpc><a:spcBef><a:spcPts val="' . ($sl['texto_sz'] * 45) . '"/></a:spcBef><a:buNone/></a:pPr>';
          $paras .= '<a:p>' . $ppr . slides_run_xml($p['texto'], $sl['texto_sz'], '000000', $p['negrito']) . '</a:p>';
        }
        $corpo .= slides_txbox_xml($shapeId++, $sl['box'], $paras);
      }
    }
    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($rels as [$id, $type, $target]) $relsXml .= '<Relationship Id="' . $id . '" Type="' . $type . '" Target="' . slides_x($target) . '"/>';
    $relsXml .= '</Relationships>';
    $novos["ppt/slides/slide{$num}.xml"] = slides_slide_xml($corpo);
    $novos["ppt/slides/_rels/slide{$num}.xml.rels"] = $relsXml;
    $rid = 'rId' . ($nextRid++);
    $novosRels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $num . '.xml"/>';
    $novosCT .= '<Override PartName="/ppt/slides/slide' . $num . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
    $sldIds .= '<p:sldId id="' . ($nextId++) . '" r:id="' . $rid . '"/>';
  }
  $pres = preg_replace('~<p:sldIdLst>.*?</p:sldIdLst>~s', '<p:sldIdLst>' . $manter[0]['tag'] . $sldIds . $manter[1]['tag'] . '</p:sldIdLst>', $pres, 1);
  $presRelsXml = $src->getFromName('ppt/_rels/presentation.xml.rels');
  foreach ($remover as $o) $presRelsXml = preg_replace('~<Relationship\s[^>]*Id="' . preg_quote($o['rid'], '~') . '"[^>]*/>~', '', $presRelsXml, 1);
  $presRelsXml = str_replace('</Relationships>', $novosRels . '</Relationships>', $presRelsXml);
  $ct = $src->getFromName('[Content_Types].xml');
  foreach (array_keys($removerPaths) as $pth) $ct = preg_replace('~<Override\s[^>]*PartName="/' . preg_quote($pth, '~') . '"[^>]*/>~', '', $ct, 1);
  if (!preg_match('~Extension="jpeg"~i', $ct)) $ct = str_replace('</Types>', '<Default Extension="jpeg" ContentType="image/jpeg"/></Types>', $ct);
  if (!preg_match('~Extension="png"~i', $ct)) $ct = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>', $ct);
  $ct = str_replace('</Types>', $novosCT . '</Types>', $ct);
  for ($i = 0; $i < $src->numFiles; $i++) {
    $name = $src->getNameIndex($i);
    if (isset($removerPaths[$name]) || substr($name, -1) === '/') continue;
    if ($name === 'ppt/presentation.xml') { $dst->addFromString($name, $pres); continue; }
    if ($name === 'ppt/_rels/presentation.xml.rels') { $dst->addFromString($name, $presRelsXml); continue; }
    if ($name === '[Content_Types].xml') { $dst->addFromString($name, $ct); continue; }
    $dst->addFromString($name, $src->getFromIndex($i));
  }
  foreach ($novos as $n => $c) $dst->addFromString($n, $c);
  foreach ($midia as $n => $c) $dst->addFromString($n, $c);
  $dst->close(); $src->close();
}

/* ============================================================
 * 5) Persistência e orquestração (storage/cursos/<id>/slides_padrao/<token>/)
 * ============================================================ */
function slides_dir_curso(int $idCurso): string {
  $d = realpath(__DIR__ . '/../storage') . "/cursos/{$idCurso}/slides_padrao";
  if (!is_dir($d)) mkdir($d, 0755, true);
  return $d;
}

function slides_salvar_registro(array $reg): void {
  file_put_contents(slides_dir_curso((int)$reg['id_curso']) . "/{$reg['token']}/laudo.json", json_encode($reg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/** Processa um PPTX enviado: lê, analisa, gera (se sem erros) e registra o PDF como entrega. */
function slides_processar(int $idCurso, array $curso, int $modulo, string $nomeModulo, string $tmpPptx, string $nomeOriginal, array $user): array {
  $token = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
  $dir = slides_dir_curso($idCurso) . '/' . $token; mkdir($dir, 0755, true);
  $orig = "$dir/original.pptx";
  if (!@move_uploaded_file($tmpPptx, $orig) && !@rename($tmpPptx, $orig) && !@copy($tmpPptx, $orig)) throw new RuntimeException('Falha ao guardar o arquivo enviado.');

  $lido = slides_ler_pptx($orig);
  $an = slides_analisar($lido, $curso, $modulo, $nomeModulo);
  $reg = ['token' => $token, 'id_curso' => $idCurso, 'modulo' => $modulo, 'nome_modulo' => $nomeModulo, 'original' => $nomeOriginal,
          'quando' => date('Y-m-d H:i:s'), 'por' => $user['nome'] ?? '', 'id_user' => (int)($user['id_user'] ?? 0),
          'aprovado' => $an['aprovado'], 'laudo' => $an['laudo'], 'resumo' => $an['resumo'], 'pdf' => null, 'pptx' => null, 'registrado' => null, 'erro_geracao' => null, 'erro_registro' => null];
  if ($an['aprovado']) {
    try {
      slides_gerar_pdf($an['plano'], $curso, "$dir/slides.pdf");  $reg['pdf'] = 'slides.pdf';
      slides_gerar_pptx($an['plano'], $curso, "$dir/slides.pptx"); $reg['pptx'] = 'slides.pptx';
    } catch (Throwable $e) { $reg['erro_geracao'] = $e->getMessage(); }
  }
  slides_salvar_registro($reg);
  // registra automaticamente como entrega "Slide" do módulo (aguardando aprovação da TI)
  if ($reg['pdf'] && !$reg['erro_geracao']) {
    try { slides_registrar_entrega($idCurso, $token, $user); $reg = slides_registro($idCurso, $token) ?: $reg; }
    catch (Throwable $e) { $reg['erro_registro'] = $e->getMessage(); slides_salvar_registro($reg); }
  }
  return $reg;
}

function slides_registro(int $idCurso, string $token): ?array {
  if (!preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $token)) return null;
  $f = slides_dir_curso($idCurso) . "/$token/laudo.json";
  return is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
}

function slides_registros(int $idCurso): array {
  $out = [];
  foreach (glob(slides_dir_curso($idCurso) . '/*/laudo.json') ?: [] as $f) { $r = json_decode((string)file_get_contents($f), true); if ($r) $out[] = $r; }
  usort($out, fn($a, $b) => strcmp($b['token'], $a['token']));
  return $out;
}

function slides_arquivo(int $idCurso, string $token, string $qual): ?string {
  $reg = slides_registro($idCurso, $token); if (!$reg) return null;
  $map = ['pdf' => 'slides.pdf', 'pptx' => 'slides.pptx', 'original' => 'original.pptx'];
  if (!isset($map[$qual])) return null;
  $p = slides_dir_curso($idCurso) . "/$token/" . $map[$qual];
  return is_file($p) ? $p : null;
}

/** Registra o PDF gerado como entrega "Slide" do módulo (tb_curso_files), como no upload.php. */
function slides_registrar_entrega(int $idCurso, string $token, array $user): int {
  require_once __DIR__ . '/entregas_repo.php';
  require_once __DIR__ . '/audit.php';
  $reg = slides_registro($idCurso, $token);
  if (!$reg || !$reg['aprovado'] || !$reg['pdf']) throw new Exception('Esta análise não gerou o PDF.');
  if (!empty($reg['registrado'])) throw new Exception('Este PDF já foi registrado como entrega.');
  $categoria = 'Slide';
  foreach (entregas_categorias((int)$reg['modulo']) as $c) if (($c['tipo_especial'] ?? '') === 'SLIDE') { $categoria = $c['nome']; break; }
  $chk = entrega_pode_receber($idCurso, (int)$reg['modulo'], $categoria);
  if ($chk !== 'ok') throw new Exception("A entrega não pode ser registrada agora ({$chk}).");
  $src = slides_arquivo($idCurso, $token, 'pdf');
  $base = realpath(__DIR__ . '/../storage') . "/cursos/{$idCurso}";
  if (!is_dir($base)) mkdir($base, 0755, true);
  $stored = bin2hex(random_bytes(16)) . '.pdf';
  if (!copy($src, "$base/$stored")) throw new Exception('Falha ao copiar o PDF para a pasta do curso.');
  $nome = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $reg['nome_modulo'] !== '' ? "Slides - Módulo {$reg['modulo']} - {$reg['nome_modulo']}.pdf" : "Slides - Módulo {$reg['modulo']}.pdf");
  db()->prepare("INSERT INTO tb_curso_files (id_curso, id_user, original_name, stored_name, mime_type, file_size, categoria, modulo) VALUES (?,?,?,?,?,?,?,?)")
      ->execute([$idCurso, (int)$user['id_user'], $nome, $stored, 'application/pdf', filesize($src), $categoria, (int)$reg['modulo']]);
  $idFile = (int)db()->lastInsertId();
  audit_log('arquivo_enviado', 'curso', $idCurso, null, ['arquivo' => $nome, 'categoria' => $categoria, 'modulo' => (int)$reg['modulo'], 'tamanho' => filesize($src), 'origem' => 'slides_padrao', 'token' => $token]);
  $reg['registrado'] = ['id_file' => $idFile, 'quando' => date('Y-m-d H:i:s'), 'nome' => $nome];
  slides_salvar_registro($reg);
  return $idFile;
}
