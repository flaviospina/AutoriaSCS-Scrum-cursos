-- ============================================================
-- AutoriaSCS • Upgrade V3 — Auditoria, Notificações e Repositório
-- Para bancos que já rodaram schema.sql (V2) ou upgrade_v2.sql.
-- Execute UMA única vez. Faça backup antes (Exportar no phpMyAdmin).
-- ============================================================
SET NAMES utf8mb4;

-- 1) Auditoria de todas as ações
CREATE TABLE IF NOT EXISTS tb_audit_log (
  id_audit     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_user      INT UNSIGNED NULL,
  acao         VARCHAR(60) NOT NULL,
  entidade     VARCHAR(40) NOT NULL,
  id_entidade  INT UNSIGNED NULL,
  dados_antes  TEXT NULL,               -- JSON
  dados_depois TEXT NULL,               -- JSON
  ip           VARCHAR(45) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_audit),
  KEY ix_audit_user (id_user),
  KEY ix_audit_acao (acao),
  KEY ix_audit_entidade (entidade, id_entidade),
  KEY ix_audit_data (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Fila de notificações por e-mail
CREATE TABLE IF NOT EXISTS tb_notificacoes (
  id_notificacao     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  destinatario_email VARCHAR(160) NOT NULL,
  destinatario_nome  VARCHAR(120) NOT NULL,
  assunto            VARCHAR(200) NOT NULL,
  corpo_html         MEDIUMTEXT NOT NULL,
  status             ENUM('PENDENTE','ENVIADO','ERRO') NOT NULL DEFAULT 'PENDENTE',
  tentativas         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  erro_msg           VARCHAR(500) NULL,
  send_after         DATETIME NULL,      -- NULL = enviar no próximo ciclo do cron
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at            DATETIME NULL,
  PRIMARY KEY (id_notificacao),
  KEY ix_notif_status (status, send_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Preferência de notificação por usuário
ALTER TABLE tb_users
  ADD COLUMN notif_pref ENUM('IMEDIATO','DIARIO','DESATIVADO') NOT NULL DEFAULT 'IMEDIATO' AFTER ativo;

-- 4) Biblioteca de Modelos (templates oficiais)
CREATE TABLE IF NOT EXISTS tb_modelos (
  id_modelo     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo        VARCHAR(150) NOT NULL,
  descricao     VARCHAR(255) NULL,
  categoria     ENUM('TEMPLATE_SLIDES','ESTRUTURA_CURSO','GUIA','MINIBIO','AVALIACAO','OUTROS') NOT NULL DEFAULT 'OUTROS',
  versao        VARCHAR(20) NOT NULL DEFAULT '1.0',
  vigente       TINYINT(1) NOT NULL DEFAULT 0,
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(100) NOT NULL,
  mime_type     VARCHAR(120) NOT NULL,
  file_size     INT UNSIGNED NOT NULL DEFAULT 0,
  id_user       INT UNSIGNED NOT NULL,
  ativo         TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_modelo),
  KEY ix_modelos_cat (categoria, vigente),
  CONSTRAINT fk_modelos_user FOREIGN KEY (id_user) REFERENCES tb_users (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Módulo do curso nos arquivos (0 = geral)
ALTER TABLE tb_curso_files
  ADD COLUMN modulo TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER categoria;

-- 6) Links externos por curso (Google Drive / vídeos MB / outros)
CREATE TABLE IF NOT EXISTS tb_curso_links (
  id_link    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_curso   INT UNSIGNED NOT NULL,
  id_user    INT UNSIGNED NOT NULL,
  titulo     VARCHAR(150) NOT NULL,
  url        VARCHAR(500) NOT NULL,
  tipo       ENUM('DRIVE','VIDEO','OUTRO') NOT NULL DEFAULT 'DRIVE',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_link),
  KEY ix_links_curso (id_curso),
  CONSTRAINT fk_links_curso FOREIGN KEY (id_curso) REFERENCES tb_cursos (id_curso) ON DELETE CASCADE,
  CONSTRAINT fk_links_user  FOREIGN KEY (id_user)  REFERENCES tb_users (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
