-- ============================================================
-- AutoriaSCS • Upgrade V2 — para bancos JÁ EXISTENTES (versão anterior do app)
-- Adiciona: Kanban dinâmico (colunas/status/transições), perfil ADMIN,
--           prioridade, nível de ensino e unidade escolar nos cursos.
-- Execute UMA única vez. Faça backup antes (mysqldump).
-- ============================================================
SET NAMES utf8mb4;

-- 1) Perfil ADMIN no enum de usuários
ALTER TABLE tb_users
  MODIFY role ENUM('PROFESSOR','TI','MB','ADMIN') NOT NULL DEFAULT 'PROFESSOR';

-- 2) Novos campos dos cursos
ALTER TABLE tb_cursos
  ADD COLUMN nivel_ensino VARCHAR(60) NULL AFTER publico_alvo,
  ADD COLUMN unidade_escolar VARCHAR(120) NULL AFTER nivel_ensino,
  ADD COLUMN prioridade ENUM('BAIXA','MEDIA','ALTA','URGENTE') NOT NULL DEFAULT 'MEDIA' AFTER unidade_escolar;

-- 3) Tabelas do Kanban dinâmico
CREATE TABLE IF NOT EXISTS tb_kanban_colunas (
  id_coluna  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome       VARCHAR(80) NOT NULL,
  cor        CHAR(7) NOT NULL DEFAULT '#e9ecef',
  ordem      INT NOT NULL DEFAULT 0,
  wip_limit  INT UNSIGNED NULL,
  ativo      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id_coluna),
  UNIQUE KEY uq_kanban_col_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_status (
  id_status  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome       VARCHAR(80) NOT NULL,
  id_coluna  INT UNSIGNED NOT NULL,
  cor        CHAR(7) NOT NULL DEFAULT '#6c757d',
  ordem      INT NOT NULL DEFAULT 0,
  is_inicial TINYINT(1) NOT NULL DEFAULT 0,
  is_final   TINYINT(1) NOT NULL DEFAULT 0,
  ativo      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id_status),
  UNIQUE KEY uq_status_nome (nome),
  KEY ix_status_coluna (id_coluna),
  CONSTRAINT fk_status_coluna FOREIGN KEY (id_coluna) REFERENCES tb_kanban_colunas (id_coluna)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_status_transicoes (
  id_transicao   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role           ENUM('PROFESSOR','TI','MB') NOT NULL,
  id_status_de   INT UNSIGNED NOT NULL,
  id_status_para INT UNSIGNED NOT NULL,
  PRIMARY KEY (id_transicao),
  UNIQUE KEY uq_transicao (role, id_status_de, id_status_para),
  CONSTRAINT fk_trans_de   FOREIGN KEY (id_status_de)   REFERENCES tb_status (id_status),
  CONSTRAINT fk_trans_para FOREIGN KEY (id_status_para) REFERENCES tb_status (id_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Seeds do fluxo padrão (idênticos ao schema.sql)
INSERT INTO tb_kanban_colunas (nome, cor, ordem, wip_limit, ativo) VALUES
  ('Backlog',          '#f8f9fa', 1,  NULL, 1),
  ('Planejamento',     '#fff3cd', 2,  NULL, 1),
  ('Produção',         '#cff4fc', 3,  NULL, 1),
  ('Entrega',          '#cfe2ff', 4,  NULL, 1),
  ('Revisão TI',       '#e2e3e5', 5,  5,    1),
  ('Ajustes',          '#f8d7da', 6,  NULL, 1),
  ('Aprovado',         '#d1e7dd', 7,  NULL, 1),
  ('MB Estúdio',       '#ced4da', 8,  NULL, 1),
  ('Validação Autor',  '#fff3cd', 9,  NULL, 1),
  ('Publicado',        '#d1e7dd', 10, NULL, 1);

INSERT INTO tb_status (nome, id_coluna, cor, ordem, is_inicial, is_final, ativo) VALUES
  ('Curso Proposto',                 (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Backlog'),         '#adb5bd', 1,  1, 0, 1),
  ('Em Planejamento',                (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Planejamento'),    '#ffc107', 2,  0, 0, 1),
  ('Em Desenvolvimento',             (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Produção'),        '#0dcaf0', 3,  0, 0, 1),
  ('Pronto para Análise',            (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Entrega'),         '#058285', 4,  0, 0, 1),
  ('Em Revisão',                     (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Revisão TI'),      '#6c757d', 5,  0, 0, 1),
  ('Recusado - Ajustes Necessários', (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Ajustes'),         '#dc3545', 6,  0, 0, 1),
  ('Em Ajuste',                      (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Ajustes'),         '#e35d6a', 7,  0, 0, 1),
  ('Pronto para Nova Análise',       (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Ajustes'),         '#b02a37', 8,  0, 0, 1),
  ('Aprovado',                       (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Aprovado'),        '#198754', 9,  0, 0, 1),
  ('Enviado para Inserção',          (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='MB Estúdio'),      '#212529', 10, 0, 0, 1),
  ('Em Inserção',                    (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='MB Estúdio'),      '#343a40', 11, 0, 0, 1),
  ('Inserido',                       (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='MB Estúdio'),      '#495057', 12, 0, 0, 1),
  ('Aguardando Validação',           (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Validação Autor'), '#ffc107', 13, 0, 0, 1),
  ('Validado',                       (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Validação Autor'), '#d39e00', 14, 0, 0, 1),
  ('Publicado',                      (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Publicado'),       '#198754', 15, 0, 1, 1);

INSERT INTO tb_status_transicoes (role, id_status_de, id_status_para)
SELECT r.role, sd.id_status, sp.id_status
FROM (
  SELECT 'PROFESSOR' role, 'Curso Proposto' de, 'Em Planejamento' para UNION ALL
  SELECT 'PROFESSOR', 'Em Planejamento', 'Em Desenvolvimento' UNION ALL
  SELECT 'PROFESSOR', 'Em Desenvolvimento', 'Pronto para Análise' UNION ALL
  SELECT 'PROFESSOR', 'Recusado - Ajustes Necessários', 'Em Ajuste' UNION ALL
  SELECT 'PROFESSOR', 'Em Ajuste', 'Pronto para Nova Análise' UNION ALL
  SELECT 'PROFESSOR', 'Inserido', 'Aguardando Validação' UNION ALL
  SELECT 'PROFESSOR', 'Aguardando Validação', 'Validado' UNION ALL
  SELECT 'TI', 'Pronto para Análise', 'Em Revisão' UNION ALL
  SELECT 'TI', 'Pronto para Nova Análise', 'Em Revisão' UNION ALL
  SELECT 'TI', 'Em Revisão', 'Aprovado' UNION ALL
  SELECT 'TI', 'Em Revisão', 'Recusado - Ajustes Necessários' UNION ALL
  SELECT 'TI', 'Aprovado', 'Enviado para Inserção' UNION ALL
  SELECT 'TI', 'Validado', 'Publicado' UNION ALL
  SELECT 'MB', 'Enviado para Inserção', 'Em Inserção' UNION ALL
  SELECT 'MB', 'Em Inserção', 'Inserido'
) r
JOIN tb_status sd ON sd.nome = r.de
JOIN tb_status sp ON sp.nome = r.para;

-- 5) Usuário administrador inicial (troque a senha no primeiro acesso)
-- E-mail: admin@scseduca.com.br | Senha: admin123
INSERT INTO tb_users (nome, email, senha_hash, role, ativo) VALUES
  ('Administrador CECAPE', 'admin@scseduca.com.br',
   '$2y$12$pMB304Ini2Ia9zTyfT5Pfua5aJZemFvaxphnQNOJfFRMC3pEy86WK', 'ADMIN', 1);
