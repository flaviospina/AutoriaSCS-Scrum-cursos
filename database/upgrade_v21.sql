-- ============================================================
-- UPGRADE V21 — Apontamentos: novo tipo "Técnico-pedagógico"
--   tb_curso_apontamentos.tipo passa a aceitar TECNICO_PEDAGOGICO
--   (os tipos existentes são mantidos; nenhum dado é alterado)
--
-- Idempotente: pode ser executado mais de uma vez.
-- ============================================================
SET NAMES utf8mb4;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_curso_apontamentos' AND COLUMN_NAME='tipo'
    AND COLUMN_TYPE NOT LIKE '%TECNICO_PEDAGOGICO%')=1,
  'ALTER TABLE tb_curso_apontamentos MODIFY COLUMN tipo ENUM(''TECNICO'',''PEDAGOGICO'',''TECNICO_PEDAGOGICO'',''ABNT'',''OUTRO'') NOT NULL DEFAULT ''OUTRO''',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'V21 aplicada: tipo Técnico-pedagógico disponível nos apontamentos.' AS resultado;
