-- ============================================================
-- UPGRADE V11 — Ajustes do PROMPT MESTRE (Blocos B a G)
--   B) perfil efetivo do Admin (sem alteração de banco)
--   C) checklists por perfil (Professor × TI/Admin)
--   D) entrega livre + documentos obrigatórios + aprovação de slide
--   E) apontamentos: arquivo vinculado, status, histórico, manifestações
--   F) mais de um professor por curso (responsável + coautores)
--   G) exclusão protegida (soft delete) de etapas e categorias
--
-- Idempotente: pode ser executado mais de uma vez. Não apaga dados,
-- não recria tabelas, não altera IDs. Faça backup antes.
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- E) APONTAMENTOS (tb_curso_apontamentos)
-- ------------------------------------------------------------
-- arquivo/material relacionado (pelo ID do arquivo; NULL = apontamento geral)
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_curso_apontamentos' AND COLUMN_NAME='id_file')=0,
  'ALTER TABLE tb_curso_apontamentos ADD COLUMN id_file INT UNSIGNED NULL AFTER id_user, ADD KEY ix_apont_file (id_file)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_curso_apontamentos' AND CONSTRAINT_NAME='fk_apont_file')=0,
  'ALTER TABLE tb_curso_apontamentos ADD CONSTRAINT fk_apont_file FOREIGN KEY (id_file) REFERENCES tb_curso_files (id_file) ON DELETE SET NULL',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- status do apontamento (item 15). A coluna antiga "resolvido" é mantida e
-- sincronizada pela aplicação (1 = APROVADO/CONCLUIDO) por compatibilidade.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_curso_apontamentos' AND COLUMN_NAME='status')=0,
  "ALTER TABLE tb_curso_apontamentos ADD COLUMN status ENUM('PENDENTE_ANALISE','CORRECAO_SOLICITADA','EM_CORRECAO','REENVIADO_ANALISE','APROVADO','CONCLUIDO') NOT NULL DEFAULT 'PENDENTE_ANALISE' AFTER resolvido, ADD KEY ix_apont_status (id_curso, status)",
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_curso_apontamentos' AND COLUMN_NAME='updated_at')=0,
  'ALTER TABLE tb_curso_apontamentos ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- apontamentos já resolvidos antes da V11 passam a CONCLUIDO (uma única vez)
UPDATE tb_curso_apontamentos SET status='CONCLUIDO'
WHERE resolvido=1 AND status='PENDENTE_ANALISE'
  AND NOT EXISTS (SELECT 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_apontamento_historico');

-- histórico de status (item 16) — status_de NULL = criação
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

-- manifestações do professor (item 19): concordância / objeção — nunca apagadas
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

-- registro de criação no histórico para os apontamentos anteriores à V11
INSERT INTO tb_apontamento_historico (id_apontamento, status_de, status_para, id_user, observacao, created_at)
SELECT a.id_apontamento, NULL, a.status, a.id_user, 'Apontamento registrado (anterior à V11)', a.created_at
FROM tb_curso_apontamentos a
WHERE NOT EXISTS (SELECT 1 FROM tb_apontamento_historico h WHERE h.id_apontamento = a.id_apontamento);

-- ------------------------------------------------------------
-- D) ARQUIVOS: aprovação de slide (libera o vídeo do módulo)
-- ------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_curso_files' AND COLUMN_NAME='aprovado')=0,
  'ALTER TABLE tb_curso_files ADD COLUMN aprovado TINYINT(1) NOT NULL DEFAULT 0 AFTER modulo, ADD COLUMN aprovado_por INT UNSIGNED NULL AFTER aprovado, ADD COLUMN aprovado_em DATETIME NULL AFTER aprovado_por',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- categorias: papel especial (SLIDE libera VIDEO) + soft delete (Bloco G)
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_categorias' AND COLUMN_NAME='tipo_especial')=0,
  "ALTER TABLE tb_categorias ADD COLUMN tipo_especial ENUM('NENHUM','SLIDE','VIDEO') NOT NULL DEFAULT 'NENHUM' AFTER obrigatoria",
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_categorias' AND COLUMN_NAME='excluida_em')=0,
  'ALTER TABLE tb_categorias ADD COLUMN excluida_em DATETIME NULL AFTER ativo',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE tb_categorias SET tipo_especial='SLIDE' WHERE escopo='MODULO' AND nome='Slide' AND tipo_especial='NENHUM';
UPDATE tb_categorias SET tipo_especial='VIDEO' WHERE escopo='MODULO' AND nome='Vídeo' AND tipo_especial='NENHUM';

-- status do fluxo: exige documentos obrigatórios + soft delete (Bloco G)
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_status' AND COLUMN_NAME='exige_entregas')=0,
  'ALTER TABLE tb_status ADD COLUMN exige_entregas TINYINT(1) NOT NULL DEFAULT 0 AFTER is_final',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_status' AND COLUMN_NAME='excluido_em')=0,
  'ALTER TABLE tb_status ADD COLUMN excluido_em DATETIME NULL AFTER ativo',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- a entrega dos materiais é exigida ao enviar o curso para análise da TI
UPDATE tb_status SET exige_entregas=1
WHERE nome IN ('Pronto para Análise','Pronto para Nova Análise');

-- cursos: controle de cooldown do e-mail de documentos pendentes (item 11)
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_cursos' AND COLUMN_NAME='aviso_pendencias_em')=0,
  'ALTER TABLE tb_cursos ADD COLUMN aviso_pendencias_em DATETIME NULL AFTER projeto_aprovado_em',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ------------------------------------------------------------
-- C) CHECKLISTS por perfil (Professor × TI/Admin)
--    A tabela antiga tb_curso_checklist é MANTIDA (não é apagada);
--    as respostas existentes são copiadas para a nova estrutura.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_checklists (
  id_checklist   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo         VARCHAR(20) NOT NULL,          -- PROFESSOR | TI
  nome           VARCHAR(120) NOT NULL,
  perfil_destino VARCHAR(20) NOT NULL,          -- PROFESSOR | TI (TI = TI/ADMIN)
  ativo          TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id_checklist),
  UNIQUE KEY uq_checklists_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_checklist_itens (
  id_item      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_checklist INT UNSIGNED NOT NULL,
  chave        VARCHAR(60) NULL,                -- coluna antiga equivalente (migração)
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

INSERT IGNORE INTO tb_checklists (codigo, nome, perfil_destino, ativo) VALUES
  ('PROFESSOR', 'Checklist do Professor (Planejamento, Produção e Entrega)', 'PROFESSOR', 1),
  ('TI',        'Checklist TI/Admin (critérios internos de validação)',      'TI',        1);

SET @ckProf := (SELECT id_checklist FROM tb_checklists WHERE codigo='PROFESSOR');
SET @ckTI   := (SELECT id_checklist FROM tb_checklists WHERE codigo='TI');

-- itens do professor = os 12 critérios já existentes (mesmos rótulos da tela atual)
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
  (@ckProf, 'material_enviado',          'Entrega / Validação', 'Material enviado oficialmente',                          0, 12, 1);

-- itens TI/Admin = critérios internos de validação (mesmos eixos dos apontamentos:
-- técnico, pedagógico, ABNT). Editáveis em Admin → Checklists.
INSERT IGNORE INTO tb_checklist_itens (id_checklist, chave, grupo, descricao, obrigatorio, ordem, ativo) VALUES
  (@ckTI, 'ti_identificacao',   'Revisão técnica',   'Identificação oficial do curso conferida (nome - formador(es) - carga horária)', 0, 1, 1),
  (@ckTI, 'ti_materiais',       'Revisão técnica',   'Todos os materiais obrigatórios entregues e abrindo corretamente',               0, 2, 1),
  (@ckTI, 'ti_slides',          'Revisão técnica',   'Slides aprovados (identidade visual e legibilidade)',                            0, 3, 1),
  (@ckTI, 'ti_videos',          'Revisão técnica',   'Vídeos analisados e aprovados pelo(a) formador(a)',                              0, 4, 1),
  (@ckTI, 'ti_pedagogico',      'Revisão pedagógica','Objetivos, público-alvo e avaliações coerentes com a carga horária',             0, 5, 1),
  (@ckTI, 'ti_abnt',            'Revisão pedagógica','Referências bibliográficas conforme ABNT NBR 6023/2018',                         0, 6, 1),
  (@ckTI, 'ti_apontamentos',    'Encerramento',      'Todos os apontamentos concluídos',                                               0, 7, 1),
  (@ckTI, 'ti_liberado_mb',     'Encerramento',      'Curso liberado para inserção pela MB Estúdios',                                  0, 8, 1);

-- copia as respostas existentes (coluna antiga = chave do item) — só onde ainda não há resposta
INSERT IGNORE INTO tb_curso_checklist_respostas (id_curso, id_item, marcado)
SELECT c.id_curso, i.id_item,
  CASE i.chave
    WHEN 'modulos_definidos'         THEN c.modulos_definidos
    WHEN 'estrutura_introducao'      THEN c.estrutura_introducao
    WHEN 'planejamento_videos'       THEN c.planejamento_videos
    WHEN 'planejamento_textos_apoio' THEN c.planejamento_textos_apoio
    WHEN 'planejamento_avaliacoes'   THEN c.planejamento_avaliacoes
    WHEN 'referencias_abnt'          THEN c.referencias_abnt
    WHEN 'videos_produzidos'         THEN c.videos_produzidos
    WHEN 'textos_escritos'           THEN c.textos_escritos
    WHEN 'avaliacoes_criadas'        THEN c.avaliacoes_criadas
    WHEN 'revisao_interna_professor' THEN c.revisao_interna_professor
    WHEN 'criterios_atendidos'       THEN c.criterios_atendidos
    WHEN 'material_enviado'          THEN c.material_enviado
    ELSE 0 END
FROM tb_curso_checklist c
JOIN tb_checklist_itens i ON i.id_checklist = @ckProf AND i.chave IS NOT NULL;

-- ------------------------------------------------------------
-- F) PROFESSORES DO CURSO (responsável + coautores)
--    tb_cursos.id_professor é mantido como RESPONSÁVEL.
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

INSERT IGNORE INTO tb_curso_professores (id_curso, id_usuario, tipo)
SELECT id_curso, id_professor, 'RESPONSAVEL' FROM tb_cursos;

-- ------------------------------------------------------------
-- Conferência
-- ------------------------------------------------------------
SELECT 'apontamentos' AS item, status, COUNT(*) n FROM tb_curso_apontamentos GROUP BY status;
SELECT 'checklist_itens' AS item, id_checklist, COUNT(*) n FROM tb_checklist_itens GROUP BY id_checklist;
SELECT 'curso_professores' AS item, tipo, COUNT(*) n FROM tb_curso_professores GROUP BY tipo;
SELECT 'status_exige_entregas' AS item, nome FROM tb_status WHERE exige_entregas=1;
