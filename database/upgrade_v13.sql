-- ============================================================
-- UPGRADE V13 — Coautores: perfis TI/ADMIN elegíveis + e-mails de inclusão
--   • tb_curso_professores.adicionado_por : quem incluiu o coautor
--   • tb_curso_professores.notificado_em  : quando os e-mails foram enviados
--     (NULL = aguardando o envio agrupado de 20 s)
--
-- Idempotente: pode ser executado mais de uma vez. Não apaga dados,
-- não recria tabelas, não altera IDs. Os coautores já existentes são
-- marcados como notificados para NÃO receberem e-mail retroativo.
-- ============================================================
SET NAMES utf8mb4;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_curso_professores' AND COLUMN_NAME='adicionado_por')=0,
  'ALTER TABLE tb_curso_professores ADD COLUMN adicionado_por INT UNSIGNED NULL AFTER tipo',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_curso_professores' AND COLUMN_NAME='notificado_em')=0,
  'ALTER TABLE tb_curso_professores ADD COLUMN notificado_em DATETIME NULL AFTER created_at, ADD KEY ix_cprof_notif (notificado_em)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- coautores anteriores à V13: considerados já comunicados
UPDATE tb_curso_professores SET notificado_em = created_at WHERE notificado_em IS NULL;
