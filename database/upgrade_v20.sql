-- ============================================================
-- UPGRADE V20 — Administração total (etapas 2 e 3)
--   • tb_users: senha_trocar (exigir troca no próximo acesso), login_falhas e
--     bloqueado_ate (bloqueio após N tentativas), sessoes_desde (encerrar sessões)
--   • tb_config: parâmetros do sistema editáveis em Admin → Parâmetros
--
-- Idempotente: pode ser executado mais de uma vez. Não apaga dados.
-- ============================================================
SET NAMES utf8mb4;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_users' AND COLUMN_NAME='senha_trocar')=0,
  'ALTER TABLE tb_users ADD COLUMN senha_trocar TINYINT(1) NOT NULL DEFAULT 0 AFTER excluido_em, ADD COLUMN login_falhas TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER senha_trocar, ADD COLUMN bloqueado_ate DATETIME NULL AFTER login_falhas, ADD COLUMN sessoes_desde DATETIME NULL AFTER bloqueado_ate',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS tb_config (
  chave      VARCHAR(60)  NOT NULL,
  valor      VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tb_config (chave, valor) VALUES
  ('upload_max_mb', '25'),
  ('dias_curso_parado', '30'),
  ('lixeira_retencao_dias', '90'),
  ('login_max_tentativas', '5'),
  ('login_bloqueio_min', '15'),
  ('intervencoes_email', '1');

SELECT 'V20 aplicada: colunas de usuário e parâmetros do sistema prontos.' AS resultado;
