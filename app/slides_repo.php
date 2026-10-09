<?php
/**
 * Slides no padrão AutoriaSCS (V15).
 *
 *  1) slides_ler_pptx()   — lê o PPTX "sem formatação" do professor (ZipArchive +
 *                           DOM): títulos, parágrafos, marcadores, imagens, legendas,
 *                           links, e detecta vídeo/áudio/animações/transições.
 *  2) slides_analisar()   — aplica as regras do padrão e monta o PLANO dos slides
 *                           (tamanho de fonte que cabe no espaço livre, posição das
 *                           imagens). Devolve o laudo (ERRO / AVISO / INFO).
 *  3) slides_gerar_pdf()  — PDF 16:9 (960×540 pt) com os fundos do modelo oficial,
 *                           Comfortaa, cores e tamanhos do padrão (tFPDF).
 *  4) slides_gerar_pptx() — PPTX editável a partir do modelo oficial
 *                           (app/slides/modelo.pptx): mantém abertura/encerramento,
 *                           recria os slides de conteúdo nos layouts do modelo.
 *
 * Regras (acordadas com a coordenação, out/2026):
 *   título 18–24 pt negrito #058285 · subtítulo itálico negrito #058285 ·
 *   texto 14–18 pt #000000 · legenda 10–12 pt · fonte única Comfortaa ·
 *   ~80 palavras por slide (aviso), 120 (erro), e o texto PRECISA caber no
 *   espaço livre (16 pt; se não couber, 14 pt; se ainda não couber → erro) ·
 *   proibido vídeo, áudio, link para vídeo, animação e transição (removidas) ·
 *   imagem JPG/PNG até 2 MB, mínimo 800 px (aviso), sempre com legenda
 *   "Figura N – Título. Fonte: AUTOR, ano." · 6 a 30 slides por módulo.
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
const SLIDES_TAM_TEXTO     = [16, 14];   // tentativas, em ordem
const SLIDES_VIDEO_EXT     = ['mp4', 'mov', 'avi', 'wmv', 'm4v', 'mpg', 'mpeg', 'webm', 'mkv', 'mp3', 'wav', 'm4a', 'aac', 'ogg', 'wma', 'asf', 'swf'];
const SLIDES_VIDEO_URL_RE  = '~(youtube\.com|youtu\.be|vimeo\.com|dailymotion\.com|tiktok\.com|\.mp4(\?|$)|\.mov(\?|$)|\.webm(\?|$)|drive\.google\.com/file/d/[^/]+/(view|preview))~i';
const SLIDES_LEGENDA_RE    = '~^\s*(figura|imagem|foto|gr[áa]fico|quadro|tabela|infogr[áa]fico|esquema|fonte)\b~iu';

// caixas do modelo (pt) — medidas tiradas do modelo oficial (slideLayout15 / slideLayout2)
const SLIDES_BOX = [
  'capa_titulo'  => ['x' => 43.2, 'y' => 230.0, 'w' => 665.0, 'h' => 112.0],
  'titulo'       => ['x' => 87.6, 'y' => 124.4, 'w' => 555.0, 'h' => 32.0],
  'texto'        => ['x' => 85.0, 'y' => 171.0, 'w' => 530.0, 'h' => 322.0],
  'texto_col'    => ['x' => 85.0, 'y' => 171.0, 'w' => 300.0, 'h' => 322.0],
  'imagem_col'   => ['x' => 395.0, 'y' => 171.0, 'w' => 226.0, 'h' => 300.0],
  'imagem_full'  => ['x' => 85.0, 'y' => 171.0, 'w' => 530.0, 'h' => 280.0],
];

function slides_dir_app(): string { return __DIR__ . '/slides'; }

/* ============================================================
 * 1) LEITURA DO PPTX
 * ============================================================ */
function slides_xpath(string $xml): ?DOMXPath {
  $doc = new DOMDocument();
  $ok = @$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
  if (!$ok) return null;
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
  if ($xml === false) return $out;
  if (!preg_match_all('~<Relationship\s[^>]*>~', $xml, $mm)) return $out;
  foreach ($mm[0] as $tag) {
    $id = preg_match('~\bId="([^"]+)"~', $tag, $a) ? $a[1] : null;
    $type = preg_match('~\bType="([^"]+)"~', $tag, $b) ? $b[1] : '';
    $target = preg_match('~\bTarget="([^"]+)"~', $tag, $c) ? html_entity_decode($c[1]) : '';
    $externo = stripos($tag, 'TargetMode="External"') !== false;
    if (!$id) continue;
    $path = $target;
    if (!$externo) {
      $path = $baseDir . '/' . $target;
      // normaliza ../
      $parts = [];
      foreach (explode('/', $path) as $seg) { if ($seg === '..') array_pop($parts); elseif ($seg !== '.' && $seg !== '') $parts[] = $seg; }
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

/**
 * Lê o PPTX. Retorna ['slides' => [...], 'fontes' => [], 'erro' => null|string].
 * Cada slide: titulo, paragrafos [[texto, nivel, bullet, negrito]], imagens
 * [[bytes, ext, mime, w, h, nome, tamanho]], legendas [], links [], videos [],
 * tem_animacao, tem_transicao, tabela, palavras.
 */
function slides_ler_pptx(string $path): array {
  $z = new ZipArchive();
  if ($z->open($path) !== true) return ['slides' => [], 'fontes' => [], 'erro' => 'O arquivo não é um PPTX válido (não foi possível abrir o pacote).'];
  $pres = $z->getFromName('ppt/presentation.xml');
  if ($pres === false) { $z->close(); return ['slides' => [], 'fontes' => [], 'erro' => 'O arquivo não é uma apresentação PowerPoint (.pptx).']; }
  $presRels = slides_rels($z, 'ppt/_rels/presentation.xml.rels', 'ppt');
  preg_match_all('~<p:sldId\s[^>]*r:id="([^"]+)"~', $pres, $mm);
  $ordem = [];
  foreach ($mm[1] as $rid) if (isset($presRels[$rid])) $ordem[] = $presRels[$rid]['target'];
  if (!$ordem) { // fallback: ordem numérica
    for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); if (preg_match('~^ppt/slides/slide\d+\.xml$~', $n)) $ordem[] = $n; }
    usort($ordem, fn($a, $b) => (int)filter_var($a, FILTER_SANITIZE_NUMBER_INT) <=> (int)filter_var($b, FILTER_SANITIZE_NUMBER_INT));
  }

  $fontes = [];
  $slides = [];
  foreach ($ordem as $n => $slidePath) {
    $xml = $z->getFromName($slidePath);
    if ($xml === false) continue;
    $rels = slides_rels($z, 'ppt/slides/_rels/' . basename($slidePath) . '.rels', 'ppt/slides');
    $xp = slides_xpath($xml);
    $s = ['n' => $n + 1, 'titulo' => '', 'paragrafos' => [], 'imagens' => [], 'legendas' => [], 'links' => [], 'videos' => [],
          'tem_animacao' => false, 'tem_transicao' => false, 'tabela' => false, 'palavras' => 0, 'imagens_extra' => 0];
    if (!$xp) { $s['titulo'] = ''; $slides[] = $s; continue; }

    // mídia por relacionamento (vídeo/áudio) e por extensão
    foreach ($rels as $r) {
      $t = strtolower($r['type']); $tg = strtolower($r['target']);
      $ext = pathinfo(parse_url($tg, PHP_URL_PATH) ?: $tg, PATHINFO_EXTENSION);
      if (str_contains($t, '/video') || str_contains($t, '/audio') || str_contains($t, '/media') || in_array($ext, SLIDES_VIDEO_EXT, true)) {
        $s['videos'][] = $r['externo'] ? $r['target'] : basename($r['target']);
      }
    }
    foreach ($xp->query('//a:videoFile | //a:audioFile | //a:quickTimeFile | //p14:media | //*[local-name()="video"]') as $el) {
      $s['videos'][] = $el->localName;
    }
    $s['videos'] = array_values(array_unique($s['videos']));
    $s['tem_animacao']  = $xp->query('//p:timing')->length > 0;
    $s['tem_transicao'] = $xp->query('//*[local-name()="transition"]')->length > 0;

    // links
    foreach ($xp->query('//a:hlinkClick/@r:id') as $attr) {
      $r = $rels[$attr->nodeValue] ?? null;
      if ($r && $r['externo']) $s['links'][] = $r['target'];
    }
    $s['links'] = array_values(array_unique($s['links']));

    // fontes usadas (informativo)
    foreach ($xp->query('//a:latin/@typeface') as $attr) { $f = trim($attr->nodeValue); if ($f !== '' && $f[0] !== '+') $fontes[$f] = true; }

    // formas de texto (inclui as dentro de grupos)
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
          $t = $xp->query('./a:t', $run)->item(0);
          $rpr = $xp->query('./a:rPr', $run)->item(0);
          $tx = $t ? $t->textContent : '';
          if (trim($tx) === '') { $texto .= $tx; continue; }
          $runs++;
          if (!$rpr || $rpr->getAttribute('b') !== '1') $negrito = false;
          $texto .= $tx;
        }
        $texto = preg_replace('/[ \t]+/u', ' ', $texto);
        if (trim($texto) === '') continue;
        $ppr = $xp->query('./a:pPr', $p)->item(0);
        $lvl = $ppr ? (int)$ppr->getAttribute('lvl') : 0;
        $bullet = $ppr ? ($xp->query('./a:buChar | ./a:buAutoNum', $ppr)->length > 0) : false;
        // placeholders de corpo herdam o marcador do layout (sem a:buChar explícito), salvo a:buNone
        if (!$bullet && $ph && in_array($phType, ['body', 'obj'], true) && !($ppr && $xp->query('./a:buNone', $ppr)->length)) $bullet = true;
        foreach (preg_split('/\n/', $texto) as $linha) {
          if (trim($linha) === '') continue;
          $paras[] = ['texto' => trim($linha), 'nivel' => min(2, $lvl), 'bullet' => $bullet, 'negrito' => $runs > 0 && $negrito];
        }
      }
      // tabelas dentro de graphicFrame são tratadas abaixo
      if ($paras) $shapes[] = ['ph' => $phType, 'y' => $y, 'paras' => $paras];
    }
    // tabelas → texto
    foreach ($xp->query('//a:tbl') as $tbl) {
      $s['tabela'] = true;
      foreach ($xp->query('.//a:tr', $tbl) as $tr) {
        $cels = [];
        foreach ($xp->query('./a:tc', $tr) as $tc) { $tx = trim(preg_replace('/\s+/u', ' ', $tc->textContent)); if ($tx !== '') $cels[] = $tx; }
        if ($cels) $shapes[] = ['ph' => 'tabela', 'y' => PHP_INT_MAX, 'paras' => [['texto' => implode(' | ', $cels), 'nivel' => 0, 'bullet' => true, 'negrito' => false]]];
      }
    }

    // título: placeholder de título; senão, a forma mais alta com 1 parágrafo curto sem marcador
    $idxTitulo = null;
    foreach ($shapes as $i => $sh) if (in_array($sh['ph'], ['title', 'ctrTitle'], true)) { $idxTitulo = $i; break; }
    if ($idxTitulo === null) {
      usort($shapes, fn($a, $b) => $a['y'] <=> $b['y']);
      foreach ($shapes as $i => $sh) {
        if (count($sh['paras']) === 1 && !$sh['paras'][0]['bullet'] && slides_contar_palavras($sh['paras'][0]['texto']) <= 14 && $sh['y'] < 0.45 * 6858000) { $idxTitulo = $i; break; }
      }
    }
    if ($idxTitulo !== null) {
      $s['titulo'] = trim(implode(' ', array_map(fn($p) => $p['texto'], $shapes[$idxTitulo]['paras'])));
      unset($shapes[$idxTitulo]);
    }
    foreach ($shapes as $sh) {
      if ($sh['ph'] === 'subTitle' && count($sh['paras']) === 1) { $sh['paras'][0]['subtitulo'] = true; }
      foreach ($sh['paras'] as $p) {
        if (preg_match(SLIDES_LEGENDA_RE, $p['texto'])) { $s['legendas'][] = $p['texto']; continue; }
        $s['paragrafos'][] = $p;
      }
    }

    // imagens (p:pic sem vídeo)
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
      $info = @getimagesizefromstring($bytes);
      $s['imagens'][] = ['bytes' => $bytes, 'ext' => $ext, 'mime' => $info['mime'] ?? '', 'w' => $info[0] ?? 0, 'h' => $info[1] ?? 0, 'nome' => basename($r['target']), 'tamanho' => strlen($bytes)];
    }
    $s['palavras'] = slides_contar_palavras($s['titulo'] . ' ' . implode(' ', array_map(fn($p) => $p['texto'], $s['paragrafos'])));
    $slides[] = $s;
  }
  $z->close();
  return ['slides' => $slides, 'fontes' => array_keys($fontes), 'erro' => null];
}

/* ============================================================
 * 2) ANÁLISE: regras + plano (medição com as métricas reais da Comfortaa)
 * ============================================================ */
function slides_pdf_medidor() {
  static $pdf = null;
  if ($pdf === null) { require_once __DIR__ . '/slides_pdf.php'; $pdf = new SlidesPdf(); }
  return $pdf;
}

/** Quebra um texto em linhas que cabem em $w (pt) com a fonte/tamanho atuais. */
function slides_quebrar($pdf, string $texto, float $w): array {
  $linhas = []; $atual = '';
  foreach (preg_split('/\s+/u', trim($texto)) as $pal) {
    $teste = $atual === '' ? $pal : $atual . ' ' . $pal;
    if ($pdf->GetStringWidth($teste) <= $w) { $atual = $teste; continue; }
    if ($atual !== '') $linhas[] = $atual;
    // palavra maior que a linha: corta
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

/** Mede a altura (pt) de um bloco de parágrafos com tamanho $sz dentro da largura $w. Retorna [altura, linhas por parágrafo]. */
function slides_medir_paragrafos($pdf, array $paras, float $w, float $sz): array {
  $lh = $sz * 1.3; $esp = $sz * 0.45; $h = 0; $out = [];
  foreach ($paras as $i => $p) {
    $indent = $p['bullet'] ? 16 + $p['nivel'] * 16 : 0;
    $pdf->SetFont('Comfortaa', $p['negrito'] ? 'B' : '', $sz);
    $ls = slides_quebrar($pdf, $p['texto'], $w - $indent);
    $out[$i] = $ls;
    $h += count($ls) * $lh + ($i > 0 ? $esp : 0);
  }
  return [$h, $out];
}

/**
 * Analisa o conteúdo lido e monta o plano. Retorna
 * ['laudo' => [[nivel, slide, msg]], 'aprovado' => bool, 'plano' => [...], 'resumo' => [...]].
 */
function slides_analisar(array $lido, array $curso, int $modulo, string $nomeModulo): array {
  $laudo = []; $plano = [];
  $add = function (string $nivel, ?int $slide, string $msg) use (&$laudo) { $laudo[] = ['nivel' => $nivel, 'slide' => $slide, 'msg' => $msg]; };
  if ($lido['erro']) { $add('ERRO', null, $lido['erro']); return ['laudo' => $laudo, 'aprovado' => false, 'plano' => [], 'resumo' => []]; }
  $slides = $lido['slides'];
  if (!$slides) { $add('ERRO', null, 'Nenhum slide com conteúdo foi encontrado no arquivo.'); }

  // capa do professor (1º slide só com título / subtítulo curto, sem marcadores, imagem, vídeo ou link) → substituída pela capa padrão
  if ($slides && !$slides[0]['imagens'] && !$slides[0]['videos'] && !$slides[0]['links'] && !$slides[0]['legendas']
      && count($slides[0]['paragrafos']) <= 1 && $slides[0]['titulo'] !== '' && ($slides[0]['paragrafos'][0]['bullet'] ?? false) === false
      && slides_contar_palavras($slides[0]['paragrafos'][0]['texto'] ?? '') <= 20) {
    $add('INFO', 1, 'Slide 1 identificado como capa do(a) professor(a): foi substituído pela capa padrão (nome do curso + módulo).');
    array_shift($slides);
  }
  $n = count($slides);
  if ($n > SLIDES_MAX_SLIDES) $add('ERRO', null, "A apresentação tem {$n} slides de conteúdo; o máximo por módulo é " . SLIDES_MAX_SLIDES . '.');
  elseif ($n < SLIDES_MIN_SLIDES) $add('AVISO', null, "A apresentação tem {$n} slide(s) de conteúdo; o recomendado é ao menos " . SLIDES_MIN_SLIDES . ' por módulo.');
  if ($lido['fontes']) $add('INFO', null, 'Formatação original ignorada (fontes encontradas: ' . implode(', ', array_slice($lido['fontes'], 0, 6)) . '). Todo o texto sai em Comfortaa, nas cores e tamanhos do padrão.');

  $pdf = slides_pdf_medidor();
  $numFig = 0;
  foreach ($slides as $k => $s) {
    $num = $k + 1; // numeração no deck de conteúdo
    if ($s['videos']) $add('ERRO', $num, 'Vídeo ou áudio embutido (' . implode(', ', array_filter($s['videos'], fn($v) => str_contains($v, '.')) ?: ['mídia']) . '). A apresentação não pode conter vídeos: envie-os pela entrega de Vídeo do módulo.');
    foreach ($s['links'] as $l) { if (preg_match(SLIDES_VIDEO_URL_RE, $l)) $add('ERRO', $num, "Link para vídeo não permitido: {$l}"); else $add('INFO', $num, "Link mantido no texto: {$l}"); }
    if ($s['tem_animacao'])  $add('AVISO', $num, 'Animações foram removidas (o PDF não as reproduz).');
    if ($s['tem_transicao']) $add('AVISO', $num, 'Transição de slide removida.');
    if ($s['tabela'])        $add('AVISO', $num, 'Tabela convertida em linhas de texto (colunas separadas por " | ").');
    if ($s['titulo'] === '') $add('ERRO', $num, 'Slide sem título. Cada slide precisa de um título curto (a primeira linha, sem marcador).');
    elseif (slides_contar_palavras($s['titulo']) > 14) $add('AVISO', $num, 'Título longo (' . slides_contar_palavras($s['titulo']) . ' palavras). Recomenda-se até 8.');
    if ($s['palavras'] > SLIDES_MAX_PAL_ERRO) $add('ERRO', $num, "{$s['palavras']} palavras no slide; o máximo é " . SLIDES_MAX_PAL_ERRO . '. Divida o conteúdo em mais slides.');
    elseif ($s['palavras'] > SLIDES_MAX_PAL_AVISO) $add('AVISO', $num, "{$s['palavras']} palavras no slide; o ideal é até " . SLIDES_MAX_PAL_AVISO . '.');

    // imagens
    $img = null;
    if ($s['imagens']) {
      $img = $s['imagens'][0];
      if (count($s['imagens']) > 1) $add('AVISO', $num, count($s['imagens']) . ' imagens no slide: apenas a primeira é usada (uma imagem por slide, com legenda).');
      if (!in_array($img['mime'], ['image/jpeg', 'image/png'], true)) { $add('ERRO', $num, "Imagem em formato não permitido ({$img['ext']}). Use JPG ou PNG."); }
      if ($img['tamanho'] > SLIDES_IMG_MAX_BYTES) $add('ERRO', $num, 'Imagem com ' . round($img['tamanho'] / 1048576, 1) . ' MB; o máximo é 2 MB.');
      if ($img['w'] && $img['w'] < SLIDES_IMG_MIN_PX) $add('AVISO', $num, "Imagem com {$img['w']} px de largura; recomenda-se ao menos " . SLIDES_IMG_MIN_PX . ' px para boa definição.');
      if (!$s['legendas']) $add('ERRO', $num, 'Imagem sem legenda. Inclua um parágrafo "Figura N – Título. Fonte: AUTOR, ano." no mesmo slide.');
      elseif (!preg_match('~fonte~iu', implode(' ', $s['legendas']))) $add('AVISO', $num, 'A legenda da imagem não cita a fonte ("Fonte: AUTOR, ano").');
      $numFig++;
    } elseif ($s['legendas']) {
      $add('AVISO', $num, 'Há legenda, mas nenhuma imagem no slide; a legenda foi mantida como texto.');
      foreach ($s['legendas'] as $lg) $s['paragrafos'][] = ['texto' => $lg, 'nivel' => 0, 'bullet' => false, 'negrito' => false];
      $s['legendas'] = [];
    }

    // plano + medição (cabe no espaço livre?)
    $box = $img ? SLIDES_BOX['texto_col'] : SLIDES_BOX['texto'];
    $escolhido = null; $linhas = [];
    if ($s['paragrafos']) {
      foreach (SLIDES_TAM_TEXTO as $sz) {
        [$h, $ls] = slides_medir_paragrafos($pdf, $s['paragrafos'], $box['w'], $sz);
        if ($h <= $box['h']) { $escolhido = $sz; $linhas = $ls; $hUsada = $h; break; }
      }
      if ($escolhido === null) {
        [$h14] = slides_medir_paragrafos($pdf, $s['paragrafos'], $box['w'], 14);
        $cabem = max(10, (int)floor($s['palavras'] * $box['h'] / max($h14, 1)) - 3);
        $add('ERRO', $num, "O texto não cabe no espaço livre do slide, mesmo em 14 pt" . ($img ? ' (metade do slide fica para a imagem)' : '') . ". Reduza para cerca de {$cabem} palavras ou divida em dois slides.");
        $escolhido = 14; $linhas = [];
      } elseif ($escolhido < 16) {
        $add('AVISO', $num, 'Texto em 14 pt para caber no espaço livre (o padrão prefere 16 pt).');
      }
    } elseif (!$img) {
      $add('AVISO', $num, 'Slide apenas com título, sem conteúdo.');
    }
    // título cabe em 20 pt? senão 18
    $pdf->SetFont('Comfortaa', 'B', 20); $tz = 20;
    if (count(slides_quebrar($pdf, $s['titulo'], SLIDES_BOX['titulo']['w'])) > 1) { $pdf->SetFont('Comfortaa', 'B', 18); $tz = 18;
      if (count(slides_quebrar($pdf, $s['titulo'], SLIDES_BOX['titulo']['w'])) > 1) $add('AVISO', $num, 'Título ocupa duas linhas; prefira um título mais curto.'); }

    $plano[] = ['tipo' => 'conteudo', 'n' => $num, 'titulo' => $s['titulo'], 'titulo_sz' => $tz, 'paragrafos' => $s['paragrafos'], 'texto_sz' => $escolhido ?: 16,
                'linhas' => $linhas, 'imagem' => $img, 'legenda' => $img ? implode(' ', $s['legendas']) : '', 'palavras' => $s['palavras']];
  }

  $temErro = (bool)array_filter($laudo, fn($l) => $l['nivel'] === 'ERRO');
  $capa = ['tipo' => 'capa', 'curso' => $curso['nome_curso'], 'modulo' => "Módulo {$modulo}: {$nomeModulo}"];
  $resumo = ['slides' => $n, 'palavras' => array_sum(array_column($plano, 'palavras')), 'imagens' => $numFig,
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
      $pdf->SetTextColor(0x05, 0x82, 0x85);
      $pdf->SetFont('Comfortaa', 'B', 24);
      $ls = slides_quebrar($pdf, $sl['curso'], $b['w']);
      $y = $b['y'] + 24;
      foreach ($ls as $l) { $pdf->TextoCentral($b['x'], $y, $b['w'], $l, false); $y += 24 * 1.5; }
      $pdf->SetFont('Comfortaa', 'B', 20);
      foreach (slides_quebrar($pdf, $sl['modulo'], $b['w']) as $l) { $pdf->TextoCentral($b['x'], $y, $b['w'], $l, true); $y += 20 * 1.5; }
      continue;
    }
    $pdf->Image("$bg/conteudo.png", 0, 0, SLIDES_W, SLIDES_H);
    // título
    $t = SLIDES_BOX['titulo'];
    $pdf->SetTextColor(0x05, 0x82, 0x85); $pdf->SetFont('Comfortaa', 'B', $sl['titulo_sz']);
    $y = $t['y'] + $sl['titulo_sz'];
    foreach (slides_quebrar($pdf, $sl['titulo'], $t['w']) as $l) { $pdf->Text($t['x'], $y, $l); $y += $sl['titulo_sz'] * 1.25; }
    // imagem
    $img = $sl['imagem'] ?? null;
    $box = $img ? SLIDES_BOX['texto_col'] : SLIDES_BOX['texto'];
    if ($img) {
      $ib = $sl['paragrafos'] ? SLIDES_BOX['imagem_col'] : SLIDES_BOX['imagem_full'];
      $tmp = tempnam(sys_get_temp_dir(), 'sl') . '.' . ($img['mime'] === 'image/png' ? 'png' : 'jpg');
      file_put_contents($tmp, $img['bytes']);
      $esc = min($ib['w'] / max($img['w'], 1), $ib['h'] / max($img['h'], 1), 1e9);
      $w = $img['w'] * $esc; $h = $img['h'] * $esc;
      if ($img['w'] === 0) { $w = $ib['w']; $h = $ib['h'] * 0.6; }
      $ix = $ib['x'] + ($ib['w'] - $w) / 2;
      $pdf->Image($tmp, $ix, $ib['y'], $w, $h);
      @unlink($tmp);
      // legenda
      $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Comfortaa', '', 10);
      $ly = $ib['y'] + $h + 14;
      foreach (slides_quebrar($pdf, $sl['legenda'], $ib['w']) as $l) { $pdf->TextoCentral($ib['x'], $ly, $ib['w'], $l, false); $ly += 13; }
    }
    // texto
    if ($sl['paragrafos']) {
      $sz = $sl['texto_sz']; $lh = $sz * 1.3; $esp = $sz * 0.45;
      $pdf->SetTextColor(0, 0, 0);
      $y = $box['y'] + $sz;
      foreach ($sl['paragrafos'] as $i => $p) {
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

/** Logo AutoriaSCS no topo (presente em todos os slides do modelo). */
function slides_logo_topo_xml(): string {
  return '<p:pic><p:nvPicPr><p:cNvPr id="2" name="Logo AutoriaSCS"/><p:cNvPicPr preferRelativeResize="0"/><p:nvPr/></p:nvPicPr><p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
       . '<p:spPr><a:xfrm><a:off x="5309263" y="261550"/><a:ext cx="1573475" cy="1026900"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln><a:noFill/></a:ln></p:spPr></p:pic>';
}

function slides_gerar_pptx(array $plano, array $curso, string $destino): void {
  $modelo = slides_dir_app() . '/modelo.pptx';
  $src = new ZipArchive(); if ($src->open($modelo) !== true) throw new RuntimeException('Modelo PPTX não encontrado em app/slides/modelo.pptx.');
  $dst = new ZipArchive(); @unlink($destino);
  if ($dst->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Não foi possível criar o PPTX.');

  // slides do modelo: mantém o 1º (abertura) e o último (encerramento); remove os demais e suas notas
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
  // logo do topo: imagem usada pelos slides de conteúdo do modelo (rel "image" do 2º slide)
  $logoRel = 'ppt/media/image12.png';
  foreach (slides_rels($src, 'ppt/slides/_rels/' . basename($ordem[1]['path'] ?? '') . '.rels', 'ppt/slides') as $r) if (str_contains($r['type'], '/image')) { $logoRel = $r['target']; break; }
  $layoutCapa = 'slideLayout2.xml'; $layoutConteudo = 'slideLayout15.xml';
  foreach (slides_rels($src, 'ppt/slides/_rels/' . basename($ordem[1]['path'] ?? '') . '.rels', 'ppt/slides') as $r) if (str_contains($r['type'], '/slideLayout')) $layoutCapa = basename($r['target']);
  foreach (slides_rels($src, 'ppt/slides/_rels/' . basename($ordem[3]['path'] ?? $ordem[1]['path']) . '.rels', 'ppt/slides') as $r) if (str_contains($r['type'], '/slideLayout')) $layoutConteudo = basename($r['target']);

  // novos slides
  $novos = []; $novosRels = ''; $novosCT = ''; $sldIds = ''; $midia = []; $nextId = 1000; $nextRid = 900;
  $baseNum = 100;
  foreach ($plano as $k => $sl) {
    $num = $baseNum + $k; $rels = []; $corpo = slides_logo_topo_xml(); $shapeId = 10;
    $rels[] = ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout', '../slideLayouts/' . ($sl['tipo'] === 'capa' ? $layoutCapa : $layoutConteudo)];
    $rels[] = ['rId2', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', '../media/' . basename($logoRel)];
    if ($sl['tipo'] === 'capa') {
      $ppr = '<a:pPr algn="ctr"><a:lnSpc><a:spcPct val="150000"/></a:lnSpc><a:buNone/></a:pPr>';
      $paras = '<a:p>' . $ppr . slides_run_xml($sl['curso'], 24, SLIDES_COR_TIT, true) . '</a:p>'
             . '<a:p>' . $ppr . slides_run_xml($sl['modulo'], 20, SLIDES_COR_TIT, true, true) . '</a:p>';
      $corpo .= slides_txbox_xml($shapeId++, SLIDES_BOX['capa_titulo'], $paras);
    } else {
      $corpo .= slides_txbox_xml($shapeId++, SLIDES_BOX['titulo'], '<a:p><a:pPr><a:buNone/></a:pPr>' . slides_run_xml($sl['titulo'], $sl['titulo_sz'], SLIDES_COR_TIT, true) . '</a:p>');
      $img = $sl['imagem'] ?? null;
      $box = $img ? SLIDES_BOX['texto_col'] : SLIDES_BOX['texto'];
      if ($sl['paragrafos']) {
        $paras = '';
        foreach ($sl['paragrafos'] as $p) {
          $marL = $p['bullet'] ? 228600 + $p['nivel'] * 228600 : 0;
          $ppr = $p['bullet']
            ? '<a:pPr marL="' . $marL . '" indent="-171450"><a:lnSpc><a:spcPct val="115000"/></a:lnSpc><a:spcBef><a:spcPts val="' . ($sl['texto_sz'] * 45) . '"/></a:spcBef><a:buClr><a:srgbClr val="000000"/></a:buClr><a:buFont typeface="Comfortaa"/><a:buChar char="' . ($p['nivel'] > 0 ? '◦' : '•') . '"/></a:pPr>'
            : '<a:pPr><a:lnSpc><a:spcPct val="115000"/></a:lnSpc><a:spcBef><a:spcPts val="' . ($sl['texto_sz'] * 45) . '"/></a:spcBef><a:buNone/></a:pPr>';
          $paras .= '<a:p>' . $ppr . slides_run_xml($p['texto'], $sl['texto_sz'], '000000', $p['negrito']) . '</a:p>';
        }
        $corpo .= slides_txbox_xml($shapeId++, $box, $paras);
      }
      if ($img) {
        $ib = $sl['paragrafos'] ? SLIDES_BOX['imagem_col'] : SLIDES_BOX['imagem_full'];
        $esc = min($ib['w'] / max($img['w'], 1), $ib['h'] / max($img['h'], 1));
        $w = $img['w'] ? $img['w'] * $esc : $ib['w']; $h = $img['h'] ? $img['h'] * $esc : $ib['h'] * 0.6;
        $ext = $img['mime'] === 'image/png' ? 'png' : 'jpeg';
        $nomeMidia = 'ppt/media/autoria_' . $num . '.' . $ext; $midia[$nomeMidia] = $img['bytes'];
        $rid = 'rId' . (3 + count($rels));
        $rels[] = [$rid, 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', '../media/' . basename($nomeMidia)];
        $corpo .= slides_pic_xml($shapeId++, $rid, ['x' => $ib['x'] + ($ib['w'] - $w) / 2, 'y' => $ib['y'], 'w' => $w, 'h' => $h]);
        $corpo .= slides_txbox_xml($shapeId++, ['x' => $ib['x'], 'y' => $ib['y'] + $h + 4, 'w' => $ib['w'], 'h' => 30],
          '<a:p><a:pPr algn="ctr"><a:buNone/></a:pPr>' . slides_run_xml($sl['legenda'], 10, '000000') . '</a:p>');
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

  // presentation.xml: lista de slides = abertura + novos + encerramento
  $novaLista = '<p:sldIdLst>' . $manter[0]['tag'] . $sldIds . $manter[1]['tag'] . '</p:sldIdLst>';
  $pres = preg_replace('~<p:sldIdLst>.*?</p:sldIdLst>~s', $novaLista, $pres, 1);
  // presentation.xml.rels: remove rels dos slides apagados, acrescenta os novos
  $presRelsXml = $src->getFromName('ppt/_rels/presentation.xml.rels');
  foreach ($remover as $o) $presRelsXml = preg_replace('~<Relationship\s[^>]*Id="' . preg_quote($o['rid'], '~') . '"[^>]*/>~', '', $presRelsXml, 1);
  $presRelsXml = str_replace('</Relationships>', $novosRels . '</Relationships>', $presRelsXml);
  // [Content_Types].xml
  $ct = $src->getFromName('[Content_Types].xml');
  foreach (array_keys($removerPaths) as $pth) $ct = preg_replace('~<Override\s[^>]*PartName="/' . preg_quote($pth, '~') . '"[^>]*/>~', '', $ct, 1);
  if (!preg_match('~Extension="jpeg"~i', $ct)) $ct = str_replace('<Default Extension="png"', '<Default Extension="jpeg" ContentType="image/jpeg"/><Default Extension="png"', $ct);
  if (!preg_match('~Extension="png"~i', $ct)) $ct = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>', $ct);
  $ct = str_replace('</Types>', $novosCT . '</Types>', $ct);

  // copia o pacote
  for ($i = 0; $i < $src->numFiles; $i++) {
    $name = $src->getNameIndex($i);
    if (isset($removerPaths[$name])) continue;
    if ($name === 'ppt/presentation.xml') { $dst->addFromString($name, $pres); continue; }
    if ($name === 'ppt/_rels/presentation.xml.rels') { $dst->addFromString($name, $presRelsXml); continue; }
    if ($name === '[Content_Types].xml') { $dst->addFromString($name, $ct); continue; }
    if (substr($name, -1) === '/') continue;
    $dst->addFromString($name, $src->getFromIndex($i));
  }
  foreach ($novos as $n => $c) $dst->addFromString($n, $c);
  foreach ($midia as $n => $c) $dst->addFromString($n, $c);
  $dst->close(); $src->close();
}

/* ============================================================
 * Persistência das análises (storage/cursos/<id>/slides_padrao/<token>/)
 * ============================================================ */
function slides_dir_curso(int $idCurso): string {
  $d = realpath(__DIR__ . '/../storage') . "/cursos/{$idCurso}/slides_padrao";
  if (!is_dir($d)) mkdir($d, 0755, true);
  return $d;
}

/** Processa um PPTX enviado: lê, analisa, gera (se aprovado) e grava o laudo. Retorna o registro. */
function slides_processar(int $idCurso, array $curso, int $modulo, string $nomeModulo, string $tmpPptx, string $nomeOriginal, array $user): array {
  $token = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
  $dir = slides_dir_curso($idCurso) . '/' . $token; mkdir($dir, 0755, true);
  $orig = "$dir/original.pptx";
  if (!@move_uploaded_file($tmpPptx, $orig) && !@rename($tmpPptx, $orig) && !@copy($tmpPptx, $orig)) throw new RuntimeException('Falha ao guardar o arquivo enviado.');

  $lido = slides_ler_pptx($orig);
  $an = slides_analisar($lido, $curso, $modulo, $nomeModulo);
  $reg = ['token' => $token, 'id_curso' => $idCurso, 'modulo' => $modulo, 'nome_modulo' => $nomeModulo, 'original' => $nomeOriginal,
          'quando' => date('Y-m-d H:i:s'), 'por' => $user['nome'] ?? '', 'id_user' => (int)($user['id_user'] ?? 0),
          'aprovado' => $an['aprovado'], 'laudo' => $an['laudo'], 'resumo' => $an['resumo'], 'pdf' => null, 'pptx' => null, 'registrado' => null, 'erro_geracao' => null];
  if ($an['aprovado']) {
    try {
      slides_gerar_pdf($an['plano'], $curso, "$dir/slides.pdf");  $reg['pdf'] = 'slides.pdf';
      slides_gerar_pptx($an['plano'], $curso, "$dir/slides.pptx"); $reg['pptx'] = 'slides.pptx';
    } catch (Throwable $e) { $reg['erro_geracao'] = $e->getMessage(); }
  }
  file_put_contents("$dir/laudo.json", json_encode($reg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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
  if (!$reg || !$reg['aprovado'] || !$reg['pdf']) throw new Exception('Esta análise não foi aprovada ou o PDF não foi gerado.');
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
  file_put_contents(slides_dir_curso($idCurso) . "/$token/laudo.json", json_encode($reg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  return $idFile;
}
