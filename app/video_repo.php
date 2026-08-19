<?php
/**
 * Revisão de vídeos (V8): vídeos por curso/módulo, versões, marcações
 * no timecode (feitas pela TI) e respostas do formador.
 *
 * Fluxo: formador envia o vídeo → TI assiste no sistema e marca os pontos
 * com problema (tempo/frame + categoria + orientação + captura opcional)
 * → formador responde/corrige e reenvia nova versão → TI aprova.
 */
require_once __DIR__ . '/db.php';

const VIDEO_CATEGORIAS = [
  'AUDIO'             => 'Áudio',
  'IMAGEM'            => 'Imagem',
  'CONTEUDO'          => 'Conteúdo',
  'ACESSIBILIDADE'    => 'Acessibilidade',
  'EDICAO'            => 'Edição',
  'IDENTIDADE_VISUAL' => 'Identidade visual',
  'ERRO_TECNICO'      => 'Erro técnico',
];

const VIDEO_MARC_STATUS = [
  'PENDENTE'    => ['label' => 'Pendente',     'cor' => '#fbbf24'],
  'EM_CORRECAO' => ['label' => 'Em correção',  'cor' => '#22d3ee'],
  'CORRIGIDO'   => ['label' => 'Corrigido',    'cor' => '#34d399'],
  'APROVADO'    => ['label' => 'Aprovado',     'cor' => '#198754'],
];

const VIDEO_STATUS = [
  'EM_ANALISE'  => ['label' => 'Em análise',   'cor' => '#22d3ee'],
  'EM_CORRECAO' => ['label' => 'Em correção',  'cor' => '#fbbf24'],
  'APROVADO'    => ['label' => 'Aprovado',     'cor' => '#34d399'],
];

function video_get(int $idVideo): ?array {
  $st = db()->prepare("
    SELECT v.*, c.nome_curso, c.id_professor, u.nome AS professor_nome, u.email AS professor_email
    FROM tb_videos v
    JOIN tb_cursos c ON c.id_curso = v.id_curso
    JOIN tb_users u  ON u.id_user = c.id_professor
    WHERE v.id_video = ?
  ");
  $st->execute([$idVideo]);
  return $st->fetch() ?: null;
}

/** Vídeos de um curso com contadores (versões e marcações pendentes da versão atual). */
function videos_do_curso(int $idCurso): array {
  $st = db()->prepare("
    SELECT v.*,
      (SELECT COUNT(*) FROM tb_video_versoes vv WHERE vv.id_video = v.id_video) AS n_versoes,
      (SELECT MAX(vv.id_versao) FROM tb_video_versoes vv WHERE vv.id_video = v.id_video) AS id_versao_atual
    FROM tb_videos v
    WHERE v.id_curso = ?
    ORDER BY v.modulo, v.titulo
  ");
  $st->execute([$idCurso]);
  $videos = $st->fetchAll();
  foreach ($videos as &$v) {
    $v['n_abertas'] = 0;
    if ($v['id_versao_atual']) {
      $sm = db()->prepare("SELECT COUNT(*) n FROM tb_video_marcacoes WHERE id_versao=? AND status IN ('PENDENTE','EM_CORRECAO')");
      $sm->execute([(int)$v['id_versao_atual']]);
      $v['n_abertas'] = (int)$sm->fetch()['n'];
    }
  }
  unset($v);
  return $videos;
}

/** Todas as versões do vídeo, da mais nova para a mais antiga. */
function video_versoes(int $idVideo): array {
  $st = db()->prepare("
    SELECT vv.*, u.nome AS user_nome,
      (SELECT COUNT(*) FROM tb_video_marcacoes m WHERE m.id_versao = vv.id_versao) AS n_marcacoes,
      (SELECT COUNT(*) FROM tb_video_marcacoes m WHERE m.id_versao = vv.id_versao AND m.status IN ('PENDENTE','EM_CORRECAO')) AS n_abertas
    FROM tb_video_versoes vv
    JOIN tb_users u ON u.id_user = vv.id_user
    WHERE vv.id_video = ?
    ORDER BY vv.numero DESC
  ");
  $st->execute([$idVideo]);
  return $st->fetchAll();
}

function video_versao_get(int $idVersao): ?array {
  $st = db()->prepare("SELECT * FROM tb_video_versoes WHERE id_versao=?");
  $st->execute([$idVersao]);
  return $st->fetch() ?: null;
}

/** Marcações de uma versão, em ordem de tempo, com as respostas. */
function video_marcacoes(int $idVersao): array {
  $st = db()->prepare("
    SELECT m.*, u.nome AS user_nome
    FROM tb_video_marcacoes m
    JOIN tb_users u ON u.id_user = m.id_user
    WHERE m.id_versao = ?
    ORDER BY m.tempo_seg, m.id_marcacao
  ");
  $st->execute([$idVersao]);
  $marcacoes = $st->fetchAll();
  if (!$marcacoes) return [];

  $ids = array_column($marcacoes, 'id_marcacao');
  $in = implode(',', array_fill(0, count($ids), '?'));
  $sr = db()->prepare("
    SELECT r.*, u.nome AS user_nome
    FROM tb_video_respostas r
    JOIN tb_users u ON u.id_user = r.id_user
    WHERE r.id_marcacao IN ($in)
    ORDER BY r.created_at
  ");
  $sr->execute($ids);
  $porMarc = [];
  foreach ($sr->fetchAll() as $r) $porMarc[$r['id_marcacao']][] = $r;
  foreach ($marcacoes as &$m) $m['respostas'] = $porMarc[$m['id_marcacao']] ?? [];
  unset($m);
  return $marcacoes;
}

/** Recalcula o status do vídeo a partir das marcações da versão mais recente. */
function video_atualiza_status(int $idVideo): string {
  $st = db()->prepare("SELECT MAX(id_versao) v FROM tb_video_versoes WHERE id_video=?");
  $st->execute([$idVideo]);
  $vAtual = (int)($st->fetch()['v'] ?? 0);

  $status = 'EM_ANALISE';
  if ($vAtual) {
    $sm = db()->prepare("SELECT COUNT(*) n FROM tb_video_marcacoes WHERE id_versao=? AND status IN ('PENDENTE','EM_CORRECAO')");
    $sm->execute([$vAtual]);
    if ((int)$sm->fetch()['n'] > 0) $status = 'EM_CORRECAO';
  }
  // não rebaixa um vídeo já aprovado (só a chegada de nova versão reabre)
  $cur = db()->prepare("SELECT status FROM tb_videos WHERE id_video=?");
  $cur->execute([$idVideo]);
  if (($cur->fetch()['status'] ?? '') !== 'APROVADO') {
    db()->prepare("UPDATE tb_videos SET status=? WHERE id_video=?")->execute([$status, $idVideo]);
  }
  return $status;
}

/** Formata segundos como mm:ss.mmm (ou hh:mm:ss.mmm acima de 1h). */
function video_fmt_tempo(float $seg): string {
  $h = (int)floor($seg / 3600);
  $m = (int)floor(($seg % 3600) / 60);
  $s = $seg - $h * 3600 - $m * 60;
  $sTxt = number_format($s, 3, '.', '');
  if (strlen($sTxt) < 6) $sTxt = '0' . $sTxt;
  return ($h > 0 ? sprintf('%d:%02d:%s', $h, $m, $sTxt) : sprintf('%d:%s', $m, $sTxt));
}

/**
 * Valida e move o arquivo de vídeo enviado. Devolve
 * [original, stored, mime, size] ou lança Exception com mensagem amigável.
 */
function video_receber_upload(int $idCurso, ?array $file): array {
  if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
      throw new Exception("O arquivo excede o limite de upload do servidor. Vídeos muito grandes: use um corte/compressão ou peça à TI para ampliar o limite do PHP.");
    }
    throw new Exception("Falha no envio do arquivo. Tente novamente.");
  }

  $max = 512 * 1024 * 1024; // 512MB (o php.ini do servidor também precisa permitir)
  if ($file['size'] > $max) throw new Exception("O vídeo excede o limite de 512MB.");

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
  $permitidos = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v'];
  if (!in_array($mime, $permitidos, true)) {
    throw new Exception("Formato não suportado ({$mime}). Envie MP4 (H.264) — é o formato que reproduz em todos os navegadores.");
  }

  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'mp4';
  $stored = bin2hex(random_bytes(16)) . '.' . $ext;
  $dest = video_storage_dir($idCurso) . '/' . $stored;
  if (!move_uploaded_file($file['tmp_name'], $dest)) {
    throw new Exception("Não foi possível gravar o arquivo no servidor.");
  }
  return [
    'original' => $file['name'],
    'stored'   => $stored,
    'mime'     => $mime,
    'size'     => (int)$file['size'],
  ];
}

/** Diretório de armazenamento dos vídeos do curso (cria se preciso). */
function video_storage_dir(int $idCurso): string {
  $base = realpath(__DIR__ . '/../storage');
  $dir = $base . "/videos/{$idCurso}";
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  return $dir;
}
