-- ============================================================
-- AutoriaSCS • ZERAR DADOS (alternativa via phpMyAdmin)
-- ------------------------------------------------------------
-- Use a tela Admin → Zerar dados sempre que possível: ela faz backup,
-- move os arquivos e registra a auditoria. Este script é a alternativa
-- manual, para quem prefere o phpMyAdmin.
--
-- ANTES DE EXECUTAR:
--   1. phpMyAdmin → Exportar → banco inteiro (backup);
--   2. cPanel → Gerenciador de arquivos → mova (ou apague) o conteúdo de
--      storage/cursos e storage/videos.
--
-- Apaga: cursos e tudo que depende deles. Preserva: colunas, status,
-- transições, perfis, escolas, níveis, categorias, checklists, modelos
-- e TODOS os usuários (apague usuários de teste em Admin → Usuários).
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE tb_curso_links;
TRUNCATE TABLE tb_curso_professores;
TRUNCATE TABLE tb_curso_checklist_respostas;
TRUNCATE TABLE tb_apontamento_manifestacoes;
TRUNCATE TABLE tb_apontamento_historico;
TRUNCATE TABLE tb_curso_apontamentos;
TRUNCATE TABLE tb_video_respostas;
TRUNCATE TABLE tb_video_marcacoes;
TRUNCATE TABLE tb_video_versoes;
TRUNCATE TABLE tb_videos;
TRUNCATE TABLE tb_curso_dispensas;
TRUNCATE TABLE tb_curso_files;
TRUNCATE TABLE tb_curso_status_history;
TRUNCATE TABLE tb_curso_checklist;
TRUNCATE TABLE tb_cursos;

-- Opcional: fila de e-mails e auditoria dos testes (descomente para apagar)
-- TRUNCATE TABLE tb_notificacoes;
-- TRUNCATE TABLE tb_audit_log;

SET FOREIGN_KEY_CHECKS = 1;
