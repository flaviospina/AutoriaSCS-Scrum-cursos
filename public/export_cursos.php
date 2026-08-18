<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/audit.php';

require_login();
$u = auth_user();
if (!is_staff()) { http_response_code(403); exit('Exportação restrita à equipe de gestão.'); }

$rows = db()->query("
  SELECT c.id_curso, c.nome_curso, u.nome professor, c.carga_horaria, c.publico_alvo,
         c.nivel_ensino, c.unidade_escolar, c.prioridade, c.status_atual,
         c.data_prevista_inicio, c.data_prevista_entrega_final, c.publication_due_date,
         c.created_at, c.updated_at
  FROM tb_cursos c
  JOIN tb_users u ON u.id_user = c.id_professor
  ORDER BY c.id_curso
")->fetchAll();

audit_log('cursos_exportados', 'curso', null, null, ['quantidade' => count($rows)]);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="cursos_autoriascs_' . date('Y-m-d_His') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM p/ Excel
fputcsv($out, ['ID','Curso','Formador(a)','CH','Público-alvo','Nível de ensino','Unidade escolar',
               'Prioridade','Status','Prev. início','Prev. entrega','Publicação prevista',
               'Criado em','Atualizado em'], ';');
foreach ($rows as $r) fputcsv($out, array_values($r), ';');
fclose($out);
exit;
