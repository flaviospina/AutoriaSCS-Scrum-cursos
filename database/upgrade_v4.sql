-- ============================================================
-- AutoriaSCS • Upgrade V4 — Perfis dinâmicos (CRUD pelo Admin)
-- Para bancos que já rodaram o upgrade_v3.sql (ou schema completo V3).
-- Execute UMA única vez. Faça backup antes.
-- ============================================================
SET NAMES utf8mb4;

-- 1) Tabela de perfis com permissões
CREATE TABLE IF NOT EXISTS tb_perfis (
  id_perfil              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo                 VARCHAR(30) NOT NULL,   -- usado em tb_users.role e tb_status_transicoes.role
  nome                   VARCHAR(60) NOT NULL,
  descricao              VARCHAR(255) NULL,
  admin_total            TINYINT(1) NOT NULL DEFAULT 0,  -- acesso completo (área Admin, todas as ações)
  ve_todos_cursos        TINYINT(1) NOT NULL DEFAULT 0,  -- enxerga todos os cursos, Kanban e relatórios
  propoe_cursos          TINYINT(1) NOT NULL DEFAULT 0,  -- propõe/edita os próprios cursos (formador)
  move_kanban            TINYINT(1) NOT NULL DEFAULT 0,  -- arrasta cards no Kanban
  revisa_cursos          TINYINT(1) NOT NULL DEFAULT 0,  -- apontamentos, recusa, edição de qualquer curso
  gerencia_modelos       TINYINT(1) NOT NULL DEFAULT 0,  -- publica/exclui na Biblioteca de Modelos
  recebe_email_revisao   TINYINT(1) NOT NULL DEFAULT 0,  -- e-mails da equipe de revisão (TI)
  recebe_email_insercao  TINYINT(1) NOT NULL DEFAULT 0,  -- e-mails de inserção na plataforma (MB)
  is_sistema             TINYINT(1) NOT NULL DEFAULT 0,  -- perfis do sistema: não podem ser excluídos
  ativo                  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id_perfil),
  UNIQUE KEY uq_perfis_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Seed dos 4 perfis do sistema (permissões equivalentes ao comportamento atual)
INSERT INTO tb_perfis
  (codigo, nome, descricao, admin_total, ve_todos_cursos, propoe_cursos, move_kanban,
   revisa_cursos, gerencia_modelos, recebe_email_revisao, recebe_email_insercao, is_sistema, ativo)
VALUES
  ('PROFESSOR', 'Professor(a) Formador(a)', 'Propõe e produz os próprios cursos.',
   0, 0, 1, 0, 0, 0, 0, 0, 1, 1),
  ('TI', 'Equipe TI & AutoriaSCS', 'Revisão técnica/pedagógica, Kanban completo e Biblioteca de Modelos.',
   0, 1, 0, 1, 1, 1, 1, 0, 1, 1),
  ('MB', 'MB Estúdios', 'Acompanha e move os status de inserção na plataforma.',
   0, 1, 0, 0, 0, 0, 0, 1, 1, 1),
  ('ADMIN', 'Administrador', 'Acesso completo, incluindo a área Admin.',
   1, 1, 1, 1, 1, 1, 1, 1, 1, 1);

-- 3) Campos de perfil deixam de ser ENUM fixo
ALTER TABLE tb_users
  MODIFY role VARCHAR(30) NOT NULL DEFAULT 'PROFESSOR';

ALTER TABLE tb_status_transicoes
  MODIFY role VARCHAR(30) NOT NULL;
