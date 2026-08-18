-- ============================================================
-- AutoriaSCS • Upgrade V6 — Cadastro de Escolas (unidades escolares)
-- Alimenta o autocompletar do campo "Unidade escolar" nos cursos
-- e no filtro do dashboard. Execute UMA única vez.
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tb_escolas (
  id_escola  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome       VARCHAR(150) NOT NULL,
  ativo      TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_escola),
  UNIQUE KEY uq_escolas_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
