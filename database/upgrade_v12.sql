-- ============================================================
-- UPGRADE V12 — Vídeos por link do Google Drive (MB Estúdios)
--
-- Cada versão de vídeo passa a ter uma ORIGEM: UPLOAD (arquivo no servidor,
-- como hoje) ou DRIVE (link do Google Drive; o sistema transmite o vídeo
-- pela Drive API sem copiá-lo para o servidor).
-- Idempotente; não apaga nem altera dados existentes. Faça backup antes.
-- ============================================================
SET NAMES utf8mb4;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_video_versoes' AND COLUMN_NAME='origem')=0,
  "ALTER TABLE tb_video_versoes
     ADD COLUMN origem ENUM('UPLOAD','DRIVE') NOT NULL DEFAULT 'UPLOAD' AFTER id_user,
     ADD COLUMN drive_file_id VARCHAR(120) NULL AFTER stored_name,
     ADD COLUMN drive_url VARCHAR(500) NULL AFTER drive_file_id",
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- versões antigas continuam UPLOAD (valor padrão); nada a converter.
SELECT origem, COUNT(*) n FROM tb_video_versoes GROUP BY origem;
