-- ============================================================
-- AutoriaSCS • Gestão Scrum/Kanban da Produção de Cursos
-- Schema completo (instalação nova) — MySQL 5.7+/MariaDB 10.3+
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Perfis de acesso (dinâmicos — CRUD na área Admin)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_perfis (
  id_perfil              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo                 VARCHAR(30) NOT NULL,
  nome                   VARCHAR(60) NOT NULL,
  descricao              VARCHAR(255) NULL,
  admin_total            TINYINT(1) NOT NULL DEFAULT 0,
  ve_todos_cursos        TINYINT(1) NOT NULL DEFAULT 0,
  propoe_cursos          TINYINT(1) NOT NULL DEFAULT 0,
  move_kanban            TINYINT(1) NOT NULL DEFAULT 0,
  revisa_cursos          TINYINT(1) NOT NULL DEFAULT 0,
  gerencia_modelos       TINYINT(1) NOT NULL DEFAULT 0,
  recebe_email_revisao   TINYINT(1) NOT NULL DEFAULT 0,
  recebe_email_insercao  TINYINT(1) NOT NULL DEFAULT 0,
  is_sistema             TINYINT(1) NOT NULL DEFAULT 0,
  ativo                  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id_perfil),
  UNIQUE KEY uq_perfis_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Usuários
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_users (
  id_user     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome        VARCHAR(120) NOT NULL,
  email       VARCHAR(160) NOT NULL,
  senha_hash  VARCHAR(255) NOT NULL,
  role        VARCHAR(30) NOT NULL DEFAULT 'PROFESSOR',
  ativo       TINYINT(1) NOT NULL DEFAULT 1,
  notif_pref  ENUM('IMEDIATO','DIARIO','DESATIVADO') NOT NULL DEFAULT 'IMEDIATO',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_user),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Kanban dinâmico: colunas, status e transições (área Admin)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_kanban_colunas (
  id_coluna  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome       VARCHAR(80) NOT NULL,
  cor        CHAR(7) NOT NULL DEFAULT '#e9ecef',   -- cor do cabeçalho da coluna
  ordem      INT NOT NULL DEFAULT 0,
  wip_limit  INT UNSIGNED NULL,                    -- limite WIP opcional
  ativo      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id_coluna),
  UNIQUE KEY uq_kanban_col_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_status (
  id_status  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome       VARCHAR(80) NOT NULL,
  id_coluna  INT UNSIGNED NOT NULL,
  cor        CHAR(7) NOT NULL DEFAULT '#6c757d',   -- cor do badge
  ordem      INT NOT NULL DEFAULT 0,
  is_inicial TINYINT(1) NOT NULL DEFAULT 0,        -- status dos cursos recém-criados (apenas um)
  is_final   TINYINT(1) NOT NULL DEFAULT 0,        -- encerra o fluxo (sem alerta de prazo)
  exige_entregas TINYINT(1) NOT NULL DEFAULT 0,    -- V11: só recebe o curso com documentos obrigatórios entregues
  ativo      TINYINT(1) NOT NULL DEFAULT 1,
  excluido_em DATETIME NULL,                       -- V11: soft delete (exclusão protegida)
  PRIMARY KEY (id_status),
  UNIQUE KEY uq_status_nome (nome),
  KEY ix_status_coluna (id_coluna),
  CONSTRAINT fk_status_coluna FOREIGN KEY (id_coluna) REFERENCES tb_kanban_colunas (id_coluna)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_status_transicoes (
  id_transicao   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role           VARCHAR(30) NOT NULL,  -- código do perfil (admin_total pode tudo, regra na aplicação)
  id_status_de   INT UNSIGNED NOT NULL,
  id_status_para INT UNSIGNED NOT NULL,
  PRIMARY KEY (id_transicao),
  UNIQUE KEY uq_transicao (role, id_status_de, id_status_para),
  CONSTRAINT fk_trans_de   FOREIGN KEY (id_status_de)   REFERENCES tb_status (id_status),
  CONSTRAINT fk_trans_para FOREIGN KEY (id_status_para) REFERENCES tb_status (id_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Cursos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_cursos (
  id_curso                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_professor                INT UNSIGNED NOT NULL,
  nome_curso                  VARCHAR(200) NOT NULL,
  carga_horaria               TINYINT UNSIGNED NOT NULL DEFAULT 10,   -- 10/20/30/40 (Guia 01, seção 3.1)
  publico_alvo                VARCHAR(200) NOT NULL,
  nivel_ensino                VARCHAR(60) NULL,                       -- Guia 01, seção 7.1-b
  unidade_escolar             VARCHAR(120) NULL,                      -- filtro do Guia 01, seção 7.2
  prioridade                  ENUM('BAIXA','MEDIA','ALTA','URGENTE') NOT NULL DEFAULT 'MEDIA',
  data_prevista_inicio        DATE NULL,
  data_prevista_entrega_final DATE NULL,
  descricao_breve             TEXT NULL,
  status_atual                VARCHAR(80) NOT NULL,
  publication_due_date        DATE NULL,
  projeto_aprovado_em         DATETIME NULL,
  aviso_pendencias_em         DATETIME NULL,                           -- V11: cooldown do e-mail de documentos pendentes
  validated_at                DATETIME NULL,
  inserted_at                 DATETIME NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_curso),
  KEY ix_cursos_prof (id_professor),
  KEY ix_cursos_status (status_atual),
  CONSTRAINT fk_cursos_prof FOREIGN KEY (id_professor) REFERENCES tb_users (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Checklist do curso (critérios do Guia 01)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_curso_checklist (
  id_curso                   INT UNSIGNED NOT NULL,
  modulos_definidos          TINYINT(1) NOT NULL DEFAULT 0,
  estrutura_introducao       TINYINT(1) NOT NULL DEFAULT 0,
  planejamento_videos        TINYINT(1) NOT NULL DEFAULT 0,
  planejamento_textos_apoio  TINYINT(1) NOT NULL DEFAULT 0,
  planejamento_avaliacoes    TINYINT(1) NOT NULL DEFAULT 0,
  referencias_abnt           TINYINT(1) NOT NULL DEFAULT 0,
  videos_produzidos          TINYINT(1) NOT NULL DEFAULT 0,
  textos_escritos            TINYINT(1) NOT NULL DEFAULT 0,
  avaliacoes_criadas         TINYINT(1) NOT NULL DEFAULT 0,
  revisao_interna_professor  TINYINT(1) NOT NULL DEFAULT 0,
  criterios_atendidos        TINYINT(1) NOT NULL DEFAULT 0,
  material_enviado           TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id_curso),
  CONSTRAINT fk_check_curso FOREIGN KEY (id_curso) REFERENCES tb_cursos (id_curso) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Histórico de status
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_curso_status_history (
  id_history  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_curso    INT UNSIGNED NOT NULL,
  status_de   VARCHAR(80) NOT NULL,
  status_para VARCHAR(80) NOT NULL,
  id_user     INT UNSIGNED NOT NULL,
  observacao  TEXT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_history),
  KEY ix_hist_curso (id_curso),
  CONSTRAINT fk_hist_curso FOREIGN KEY (id_curso) REFERENCES tb_cursos (id_curso) ON DELETE CASCADE,
  CONSTRAINT fk_hist_user  FOREIGN KEY (id_user)  REFERENCES tb_users (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Arquivos do curso
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_curso_files (
  id_file       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_curso      INT UNSIGNED NOT NULL,
  id_user       INT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(100) NOT NULL,
  mime_type     VARCHAR(120) NOT NULL,
  file_size     INT UNSIGNED NOT NULL DEFAULT 0,
  categoria     VARCHAR(60) NOT NULL DEFAULT 'OUTROS',
  modulo        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  aprovado      TINYINT(1) NOT NULL DEFAULT 0,      -- V11: slide aprovado pela TI (libera o vídeo do módulo)
  aprovado_por  INT UNSIGNED NULL,
  aprovado_em   DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_file),
  KEY ix_files_curso (id_curso),
  CONSTRAINT fk_files_curso FOREIGN KEY (id_curso) REFERENCES tb_cursos (id_curso) ON DELETE CASCADE,
  CONSTRAINT fk_files_user  FOREIGN KEY (id_user)  REFERENCES tb_users (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Entregas "sem material" (fluxo ordenado de upload por módulo)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_curso_dispensas (
  id_dispensa INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_curso    INT UNSIGNED NOT NULL,
  modulo      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  categoria   VARCHAR(60) NOT NULL,
  id_user     INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_dispensa),
  UNIQUE KEY uq_dispensa (id_curso, modulo, categoria),
  KEY ix_dispensa_curso (id_curso),
  CONSTRAINT fk_disp_curso FOREIGN KEY (id_curso) REFERENCES tb_cursos (id_curso) ON DELETE CASCADE,
  CONSTRAINT fk_disp_user  FOREIGN KEY (id_user)  REFERENCES tb_users (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Revisão de vídeos (V8): vídeos, versões, marcações e respostas
-- ------------------------------------------------------------
-- Vídeo em revisão: agrupa as versões enviadas de um mesmo vídeo do curso
CREATE TABLE IF NOT EXISTS tb_videos (
  id_video   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_curso   INT UNSIGNED NOT NULL,
  modulo     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  titulo     VARCHAR(180) NOT NULL,
  descricao  TEXT NULL,
  status     ENUM('EM_ANALISE','EM_CORRECAO','APROVADO') NOT NULL DEFAULT 'EM_ANALISE',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_video),
  KEY ix_videos_curso (id_curso),
  CONSTRAINT fk_videos_curso FOREIGN KEY (id_curso) REFERENCES tb_cursos (id_curso) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versões do vídeo (v1, v2, ... — o histórico completo fica guardado)
CREATE TABLE IF NOT EXISTS tb_video_versoes (
  id_versao     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_video      INT UNSIGNED NOT NULL,
  numero        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  id_user       INT UNSIGNED NOT NULL,
  origem        ENUM('UPLOAD','DRIVE') NOT NULL DEFAULT 'UPLOAD', -- V12: arquivo no servidor ou link do Google Drive
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(100) NOT NULL,                             -- vazio quando origem = DRIVE
  drive_file_id VARCHAR(120) NULL,
  drive_url     VARCHAR(500) NULL,
  mime_type     VARCHAR(120) NOT NULL,
  file_size     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  observacao    VARCHAR(500) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_versao),
  KEY ix_versoes_video (id_video),
  CONSTRAINT fk_versoes_video FOREIGN KEY (id_video) REFERENCES tb_videos (id_video) ON DELETE CASCADE,
  CONSTRAINT fk_versoes_user  FOREIGN KEY (id_user)  REFERENCES tb_users (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marcações (apontamentos no timecode) feitas pela TI em cada versão
CREATE TABLE IF NOT EXISTS tb_video_marcacoes (
  id_marcacao INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_versao   INT UNSIGNED NOT NULL,
  id_user     INT UNSIGNED NOT NULL,
  tempo_seg   DECIMAL(10,3) NOT NULL DEFAULT 0,
  frame       INT UNSIGNED NULL,
  categoria   ENUM('AUDIO','IMAGEM','CONTEUDO','ACESSIBILIDADE','EDICAO','IDENTIDADE_VISUAL','ERRO_TECNICO')
              NOT NULL DEFAULT 'CONTEUDO',
  descricao   TEXT NOT NULL,
  captura     VARCHAR(100) NULL,
  status      ENUM('PENDENTE','EM_CORRECAO','CORRIGIDO','APROVADO') NOT NULL DEFAULT 'PENDENTE',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_marcacao),
  KEY ix_marc_versao (id_versao),
  CONSTRAINT fk_marc_versao FOREIGN KEY (id_versao) REFERENCES tb_video_versoes (id_versao) ON DELETE CASCADE,
  CONSTRAINT fk_marc_user   FOREIGN KEY (id_user)   REFERENCES tb_users (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Respostas do professor (ou da TI) em cada marcação
CREATE TABLE IF NOT EXISTS tb_video_respostas (
  id_resposta INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_marcacao INT UNSIGNED NOT NULL,
  id_user     INT UNSIGNED NOT NULL,
  conteudo    TEXT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_resposta),
  KEY ix_resp_marc (id_marcacao),
  CONSTRAINT fk_resp_marc FOREIGN KEY (id_marcacao) REFERENCES tb_video_marcacoes (id_marcacao) ON DELETE CASCADE,
  CONSTRAINT fk_resp_user FOREIGN KEY (id_user)     REFERENCES tb_users (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Apontamentos (revisão TI / qualidade)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_curso_apontamentos (
  id_apontamento INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_curso       INT UNSIGNED NOT NULL,
  id_user        INT UNSIGNED NOT NULL,
  id_file        INT UNSIGNED NULL,                 -- V11: arquivo/material relacionado
  tipo           ENUM('TECNICO','PEDAGOGICO','ABNT','OUTRO') NOT NULL DEFAULT 'OUTRO',
  conteudo       TEXT NOT NULL,
  resolvido      TINYINT(1) NOT NULL DEFAULT 0,     -- espelho: 1 quando APROVADO/CONCLUIDO
  status         ENUM('PENDENTE_ANALISE','CORRECAO_SOLICITADA','EM_CORRECAO','REENVIADO_ANALISE','APROVADO','CONCLUIDO') NOT NULL DEFAULT 'PENDENTE_ANALISE',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_apontamento),
  KEY ix_apont_curso (id_curso),
  KEY ix_apont_file (id_file),
  KEY ix_apont_status (id_curso, status),
  CONSTRAINT fk_apont_curso FOREIGN KEY (id_curso) REFERENCES tb_cursos (id_curso) ON DELETE CASCADE,
  CONSTRAINT fk_apont_user  FOREIGN KEY (id_user)  REFERENCES tb_users (id_user),
  CONSTRAINT fk_apont_file  FOREIGN KEY (id_file)  REFERENCES tb_curso_files (id_file) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- V11: histórico de status e manifestações (concordância/objeção) do professor
CREATE TABLE IF NOT EXISTS tb_apontamento_historico (
  id_historico   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_apontamento INT UNSIGNED NOT NULL,
  status_de      VARCHAR(30) NULL,
  status_para    VARCHAR(30) NOT NULL,
  id_user        INT UNSIGNED NULL,
  observacao     TEXT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_historico),
  KEY ix_aphist_apont (id_apontamento, created_at),
  CONSTRAINT fk_aphist_apont FOREIGN KEY (id_apontamento) REFERENCES tb_curso_apontamentos (id_apontamento) ON DELETE CASCADE,
  CONSTRAINT fk_aphist_user  FOREIGN KEY (id_user) REFERENCES tb_users (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_apontamento_manifestacoes (
  id_manifestacao INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_apontamento  INT UNSIGNED NOT NULL,
  id_user         INT UNSIGNED NOT NULL,
  tipo            ENUM('CONCORDO','OBJECAO') NOT NULL,
  justificativa   TEXT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_manifestacao),
  KEY ix_apman_apont (id_apontamento, created_at),
  CONSTRAINT fk_apman_apont FOREIGN KEY (id_apontamento) REFERENCES tb_curso_apontamentos (id_apontamento) ON DELETE CASCADE,
  CONSTRAINT fk_apman_user  FOREIGN KEY (id_user) REFERENCES tb_users (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Auditoria de todas as ações
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Fila de notificações por e-mail
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_notificacoes (
  id_notificacao     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  destinatario_email VARCHAR(160) NOT NULL,
  destinatario_nome  VARCHAR(120) NOT NULL,
  assunto            VARCHAR(200) NOT NULL,
  corpo_html         MEDIUMTEXT NOT NULL,
  status             ENUM('PENDENTE','ENVIADO','ERRO') NOT NULL DEFAULT 'PENDENTE',
  tentativas         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  erro_msg           VARCHAR(500) NULL,
  send_after         DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at            DATETIME NULL,
  PRIMARY KEY (id_notificacao),
  KEY ix_notif_status (status, send_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Biblioteca de Modelos (templates oficiais)
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Escolas (unidades escolares) — autocompletar dos cursos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_escolas (
  id_escola  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome       VARCHAR(150) NOT NULL,
  ativo      TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_escola),
  UNIQUE KEY uq_escolas_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Níveis de ensino (V10) — cadastro administrável; vínculo pelo nome
-- (tb_cursos.nivel_ensino), como os status do Kanban
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_niveis_ensino (
  id_nivel   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome       VARCHAR(60) NOT NULL,
  ordem      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ativo      TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_nivel),
  UNIQUE KEY uq_niveis_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Categorias de entrega de material (V10) — cadastro administrável;
-- escopo GERAL = módulo 0, MODULO = módulos 1..8; vínculo pelo nome
-- (tb_curso_files.categoria / tb_curso_dispensas.categoria)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_categorias (
  id_categoria INT UNSIGNED NOT NULL AUTO_INCREMENT,
  escopo       ENUM('GERAL','MODULO') NOT NULL DEFAULT 'MODULO',
  nome         VARCHAR(60) NOT NULL,
  obrigatoria  TINYINT(1) NOT NULL DEFAULT 1,
  tipo_especial ENUM('NENHUM','SLIDE','VIDEO') NOT NULL DEFAULT 'NENHUM', -- V11: slide aprovado libera o vídeo
  ordem        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ativo        TINYINT(1) NOT NULL DEFAULT 1,
  excluida_em  DATETIME NULL,                      -- V11: soft delete (exclusão protegida)
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_categoria),
  UNIQUE KEY uq_categorias_escopo_nome (escopo, nome),
  KEY ix_categorias_escopo (escopo, ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Checklists por perfil (V11): Professor × TI/Admin
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_checklists (
  id_checklist   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo         VARCHAR(20) NOT NULL,
  nome           VARCHAR(120) NOT NULL,
  perfil_destino VARCHAR(20) NOT NULL,
  ativo          TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id_checklist),
  UNIQUE KEY uq_checklists_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_checklist_itens (
  id_item      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_checklist INT UNSIGNED NOT NULL,
  chave        VARCHAR(60) NULL,
  grupo        VARCHAR(80) NOT NULL DEFAULT '',
  descricao    VARCHAR(200) NOT NULL,
  obrigatorio  TINYINT(1) NOT NULL DEFAULT 0,
  ordem        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ativo        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id_item),
  KEY ix_chkitem_lista (id_checklist, ativo, ordem),
  UNIQUE KEY uq_chkitem_chave (id_checklist, chave),
  CONSTRAINT fk_chkitem_lista FOREIGN KEY (id_checklist) REFERENCES tb_checklists (id_checklist)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_curso_checklist_respostas (
  id_curso   INT UNSIGNED NOT NULL,
  id_item    INT UNSIGNED NOT NULL,
  marcado    TINYINT(1) NOT NULL DEFAULT 0,
  id_user    INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_curso, id_item),
  CONSTRAINT fk_chkresp_curso FOREIGN KEY (id_curso) REFERENCES tb_cursos (id_curso) ON DELETE CASCADE,
  CONSTRAINT fk_chkresp_item  FOREIGN KEY (id_item)  REFERENCES tb_checklist_itens (id_item),
  CONSTRAINT fk_chkresp_user  FOREIGN KEY (id_user)  REFERENCES tb_users (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Professores do curso (V11): responsável + coautores
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_curso_professores (
  id_curso_professor INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_curso   INT UNSIGNED NOT NULL,
  id_usuario INT UNSIGNED NOT NULL,
  tipo       ENUM('RESPONSAVEL','COAUTOR') NOT NULL DEFAULT 'COAUTOR',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_curso_professor),
  UNIQUE KEY uq_curso_prof (id_curso, id_usuario),
  KEY ix_cprof_usuario (id_usuario),
  CONSTRAINT fk_cprof_curso FOREIGN KEY (id_curso)   REFERENCES tb_cursos (id_curso) ON DELETE CASCADE,
  CONSTRAINT fk_cprof_user  FOREIGN KEY (id_usuario) REFERENCES tb_users (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Links externos por curso (Google Drive / vídeos MB / outros)
-- ------------------------------------------------------------
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

-- ============================================================
-- SEEDS — fluxo padrão AutoriaSCS (idêntico ao fluxo original)
-- ============================================================

-- Perfis do sistema
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
  ('Projeto Aprovado',               (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Backlog'),         '#0d9488', 2,  0, 0, 1),
  ('Em Planejamento',                (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Planejamento'),    '#ffc107', 3,  0, 0, 1),
  ('Em Desenvolvimento',             (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Produção'),        '#0dcaf0', 4,  0, 0, 1),
  ('Pronto para Análise',            (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Entrega'),         '#058285', 5,  0, 0, 1),
  ('Em Revisão',                     (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Revisão TI'),      '#6c757d', 6,  0, 0, 1),
  ('Recusado - Ajustes Necessários', (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Ajustes'),         '#dc3545', 7,  0, 0, 1),
  ('Em Ajuste',                      (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Ajustes'),         '#e35d6a', 8,  0, 0, 1),
  ('Pronto para Nova Análise',       (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Ajustes'),         '#b02a37', 9,  0, 0, 1),
  ('Aprovado',                       (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Aprovado'),        '#198754', 10, 0, 0, 1),
  ('Enviado para Inserção',          (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='MB Estúdio'),      '#212529', 11, 0, 0, 1),
  ('Em Inserção',                    (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='MB Estúdio'),      '#343a40', 12, 0, 0, 1),
  ('Inserido',                       (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='MB Estúdio'),      '#495057', 13, 0, 0, 1),
  ('Aguardando Validação',           (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Validação Autor'), '#ffc107', 14, 0, 0, 1),
  ('Validado',                       (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Validação Autor'), '#d39e00', 15, 0, 0, 1),
  ('Pronto para Publicação',         (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Publicado'),       '#fd7e14', 16, 0, 0, 1),
  ('Publicado',                      (SELECT id_coluna FROM tb_kanban_colunas WHERE nome='Publicado'),       '#198754', 17, 0, 1, 1);

-- Transições (mesmas regras do antigo status_rules.php + publicação pela TI)
INSERT INTO tb_status_transicoes (role, id_status_de, id_status_para)
SELECT r.role, sd.id_status, sp.id_status
FROM (
  SELECT 'TI' role, 'Curso Proposto' de, 'Projeto Aprovado' para UNION ALL
  SELECT 'PROFESSOR', 'Projeto Aprovado', 'Em Planejamento' UNION ALL
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
  SELECT 'TI', 'Validado', 'Pronto para Publicação' UNION ALL
  SELECT 'MB', 'Enviado para Inserção', 'Em Inserção' UNION ALL
  SELECT 'MB', 'Em Inserção', 'Inserido' UNION ALL
  SELECT 'MB', 'Pronto para Publicação', 'Publicado'
) r
JOIN tb_status sd ON sd.nome = r.de
JOIN tb_status sp ON sp.nome = r.para;

-- Níveis de ensino (V10)
INSERT IGNORE INTO tb_niveis_ensino (nome, ordem, ativo) VALUES
  ('Educação Infantil',                   1, 1),
  ('Ensino Fundamental - Anos Iniciais',  2, 1),
  ('Ensino Fundamental - Anos Finais',    3, 1),
  ('Ensino Fundamental - Médio',          4, 1),
  ('Ensino Médio',                        5, 1),
  ('Formação Transversal / Complementar', 6, 1);

-- Categorias de entrega (V10) — mesma sequência do fluxo ordenado (V7)
INSERT IGNORE INTO tb_categorias (escopo, nome, obrigatoria, ordem, ativo) VALUES
  ('GERAL',  'Apresentação do(s) Formador(es)', 1, 1, 1),
  ('GERAL',  'Apresentação do Curso',           1, 2, 1),
  ('GERAL',  'Objetivos',                       1, 3, 1),
  ('GERAL',  'Atividade Avaliativa Geral',      0, 4, 1),
  ('GERAL',  'Referência Bibliográfica',        1, 5, 1),
  ('MODULO', 'Apresentação do Módulo',          1, 1, 1),
  ('MODULO', 'Slide',                           1, 2, 1),
  ('MODULO', 'Vídeo',                           1, 3, 1),
  ('MODULO', 'Anexo',                           1, 4, 1),
  ('MODULO', 'Texto Complementar',              0, 5, 1),
  ('MODULO', 'Atividade Avaliativa',            0, 6, 1);

-- V11: papéis especiais das categorias e status que exigem entregas
UPDATE tb_categorias SET tipo_especial='SLIDE' WHERE escopo='MODULO' AND nome='Slide';
UPDATE tb_categorias SET tipo_especial='VIDEO' WHERE escopo='MODULO' AND nome='Vídeo';
UPDATE tb_status SET exige_entregas=1 WHERE nome IN ('Pronto para Análise','Pronto para Nova Análise');

-- V11: checklists por perfil
INSERT IGNORE INTO tb_checklists (codigo, nome, perfil_destino, ativo) VALUES
  ('PROFESSOR', 'Checklist do Professor (Planejamento, Produção e Entrega)', 'PROFESSOR', 1),
  ('TI',        'Checklist TI/Admin (critérios internos de validação)',      'TI',        1);
SET @ckProf := (SELECT id_checklist FROM tb_checklists WHERE codigo='PROFESSOR');
SET @ckTI   := (SELECT id_checklist FROM tb_checklists WHERE codigo='TI');
INSERT IGNORE INTO tb_checklist_itens (id_checklist, chave, grupo, descricao, obrigatorio, ordem, ativo) VALUES
  (@ckProf, 'modulos_definidos',         'Planejamento',        'Definição de módulos',                                   0, 1,  1),
  (@ckProf, 'estrutura_introducao',      'Planejamento',        'Estrutura da introdução',                                0, 2,  1),
  (@ckProf, 'planejamento_videos',       'Planejamento',        'Planejamento dos vídeos (MB Estúdios)',                  0, 3,  1),
  (@ckProf, 'planejamento_textos_apoio', 'Planejamento',        'Planejamento textos de apoio',                           0, 4,  1),
  (@ckProf, 'planejamento_avaliacoes',   'Planejamento',        'Planejamento avaliações (+5 p/ randomização)',           0, 5,  1),
  (@ckProf, 'referencias_abnt',          'Planejamento',        'Referências ABNT NBR 6023/2018',                         0, 6,  1),
  (@ckProf, 'videos_produzidos',         'Produção',            'Vídeos produzidos',                                      0, 7,  1),
  (@ckProf, 'textos_escritos',           'Produção',            'Textos escritos',                                        0, 8,  1),
  (@ckProf, 'avaliacoes_criadas',        'Produção',            'Avaliações criadas',                                     0, 9,  1),
  (@ckProf, 'revisao_interna_professor', 'Produção',            'Revisão interna (formador)',                             0, 10, 1),
  (@ckProf, 'criterios_atendidos',       'Entrega / Validação', 'Critérios atendidos (autor)',                            0, 11, 1),
  (@ckProf, 'material_enviado',          'Entrega / Validação', 'Material enviado oficialmente',                          0, 12, 1),
  (@ckTI, 'ti_identificacao',   'Revisão técnica',   'Identificação oficial do curso conferida (nome - formador(es) - carga horária)', 0, 1, 1),
  (@ckTI, 'ti_materiais',       'Revisão técnica',   'Todos os materiais obrigatórios entregues e abrindo corretamente',               0, 2, 1),
  (@ckTI, 'ti_slides',          'Revisão técnica',   'Slides aprovados (identidade visual e legibilidade)',                            0, 3, 1),
  (@ckTI, 'ti_videos',          'Revisão técnica',   'Vídeos analisados e aprovados pelo(a) formador(a)',                              0, 4, 1),
  (@ckTI, 'ti_pedagogico',      'Revisão pedagógica','Objetivos, público-alvo e avaliações coerentes com a carga horária',             0, 5, 1),
  (@ckTI, 'ti_abnt',            'Revisão pedagógica','Referências bibliográficas conforme ABNT NBR 6023/2018',                         0, 6, 1),
  (@ckTI, 'ti_apontamentos',    'Encerramento',      'Todos os apontamentos concluídos',                                               0, 7, 1),
  (@ckTI, 'ti_liberado_mb',     'Encerramento',      'Curso liberado para inserção pela MB Estúdios',                                  0, 8, 1);

-- Usuário administrador inicial
-- E-mail: admin@scseduca.com.br | Senha: admin123  (TROQUE NO PRIMEIRO ACESSO)
INSERT INTO tb_users (nome, email, senha_hash, role, ativo) VALUES
  ('Administrador CECAPE', 'admin@scseduca.com.br',
   '$2y$12$pMB304Ini2Ia9zTyfT5Pfua5aJZemFvaxphnQNOJfFRMC3pEy86WK', 'ADMIN', 1);
