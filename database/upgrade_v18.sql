-- ============================================================
-- UPGRADE V18 — Administração total (etapa 1)
--   • tb_cursos.situacao: ATIVO | PAUSADO | SUSPENSO | CANCELADO | ARQUIVADO | EXCLUIDO (lixeira)
--     + motivo, data, autor e status anterior (para retomar)
--   • tb_users.excluido_em: exclusão lógica (anonimizada) de usuários
--
-- Idempotente: pode ser executado mais de uma vez. Não apaga dados.
-- ============================================================
SET NAMES utf8mb4;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_cursos' AND COLUMN_NAME='situacao')=0,
  'ALTER TABLE tb_cursos ADD COLUMN situacao ENUM(''ATIVO'',''PAUSADO'',''SUSPENSO'',''CANCELADO'',''ARQUIVADO'',''EXCLUIDO'') NOT NULL DEFAULT ''ATIVO'' AFTER status_atual, ADD KEY ix_cursos_situacao (situacao)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_cursos' AND COLUMN_NAME='situacao_motivo')=0,
  'ALTER TABLE tb_cursos ADD COLUMN situacao_motivo VARCHAR(500) NULL AFTER situacao, ADD COLUMN situacao_em DATETIME NULL AFTER situacao_motivo, ADD COLUMN situacao_por INT UNSIGNED NULL AFTER situacao_em, ADD COLUMN status_anterior VARCHAR(80) NULL AFTER situacao_por',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_users' AND COLUMN_NAME='excluido_em')=0,
  'ALTER TABLE tb_users ADD COLUMN excluido_em DATETIME NULL AFTER ativo',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
