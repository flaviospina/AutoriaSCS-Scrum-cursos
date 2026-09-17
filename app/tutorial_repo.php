<?php
/**
 * Tutoriais por perfil (V12): regras de acesso e montagem das páginas.
 *
 * Conteúdo: app/tutoriais/{index,professor,ti,mb,admin}.html (fora de public/).
 * Imagens:  storage/tutoriais/img (entregues por public/tutorial_img.php).
 * PDFs:     storage/tutoriais/pdf/{perfil}.pdf (pré-gerados; public/tutorial_pdf.php).
 */
require_once __DIR__ . '/perfis_repo.php';

const TUTORIAL_PERFIS = ['professor', 'ti', 'mb', 'admin'];

/** Tutorial correspondente ao perfil (efetivo) do usuário. */
function tutorial_perfil_usuario(array $u): string {
  $role = $u['role'] ?? '';
  if (perfil_flag($role, 'admin_total')) return 'admin';
  if (perfil_flag($role, 'revisa_cursos')) return 'ti';
  if (perfil_flag($role, 'recebe_email_insercao')) return 'mb';
  return 'professor';
}

/** Somente o ADMIN (real ou efetivo) vê o índice e todos os tutoriais. */
function tutorial_ve_todos(array $u): bool {
  return perfil_flag($u['role'] ?? '', 'admin_total') || (function_exists('is_admin_real') && is_admin_real());
}

function tutorial_pode_ver(array $u, string $p): bool {
  if ($p === 'index') return tutorial_ve_todos($u);
  if (!in_array($p, TUTORIAL_PERFIS, true)) return false;
  return tutorial_ve_todos($u) || tutorial_perfil_usuario($u) === $p;
}

/** Imagem permitida: prefixo do perfil (prof-, ti-, mb-, admin-) ou imagens comuns. */
function tutorial_imagem_permitida(array $u, string $arquivo): bool {
  if (!preg_match('/^[a-z0-9\-]+\.png$/', $arquivo)) return false;
  if (tutorial_ve_todos($u)) return true;
  $prefixo = ['professor' => 'prof-', 'ti' => 'ti-', 'mb' => 'mb-', 'admin' => 'admin-'][tutorial_perfil_usuario($u)];
  return strpos($arquivo, $prefixo) === 0 || in_array($arquivo, ['login.png', 'perfil.png'], true);
}

function tutorial_dir_img(): string { return realpath(__DIR__ . '/../storage') . '/tutoriais/img'; }
function tutorial_dir_pdf(): string { return realpath(__DIR__ . '/../storage') . '/tutoriais/pdf'; }

/**
 * HTML da página já com os caminhos reescritos para os endpoints protegidos e
 * a barra de ações (Imprimir / Exportar PDF).
 */
function tutorial_html(string $p, array $u): ?string {
  $f = __DIR__ . '/tutoriais/' . $p . '.html';
  if (!is_file($f)) return null;
  $html = (string)file_get_contents($f);

  // imagens e assets
  $html = str_replace('src="img/', 'src="tutorial_img.php?f=', $html);
  $html = str_replace('href="tutorial.css"', 'href="tutoriais/tutorial.css"', $html);
  $html = str_replace('src="_base.js"', 'src="tutoriais/_base.js"', $html);
  // links entre tutoriais
  $html = preg_replace('/href="(professor|ti|mb|admin)\.html"/', 'href="tutorial.php?p=$1"', $html);
  $html = str_replace('href="index.html"', 'href="tutorial.php"', $html);
  $html = str_replace('href="../dashboard.php"', 'href="dashboard.php"', $html);

  // perfis que não veem tudo: sem link para o índice
  if (!tutorial_ve_todos($u)) {
    $html = str_replace('<a href="tutorial.php">Todos os tutoriais</a>', '', $html);
  }

  // barra de ações: Imprimir / Exportar PDF (não no índice)
  if ($p !== 'index') {
    $temPdf = is_file(tutorial_dir_pdf() . '/' . $p . '.pdf');
    $acoes = '<div class="acoes-tut no-print">'
      . '<button type="button" class="btn-tut" onclick="window.print()" title="Imprimir este tutorial">🖨 Imprimir</button>'
      . ($temPdf
          ? '<a class="btn-tut" href="tutorial_pdf.php?p=' . htmlspecialchars($p) . '" title="Baixar o tutorial em PDF">⬇ Exportar PDF</a>'
          : '<button type="button" class="btn-tut" onclick="window.print()" title="Use a opção &quot;Salvar como PDF&quot; da janela de impressão">⬇ Exportar PDF</button>')
      . '</div>';
    $html = preg_replace('/(<p class="lead">.*?<\/p>)/s', '$1' . $acoes, $html, 1);
    // rodapé de impressão com data
    $html = str_replace('<footer class="rodape">', '<footer class="rodape"><span class="so-print">Impresso em ' . date('d/m/Y H:i') . ' • </span>', $html);
  }
  return $html;
}
