-- ============================================================
-- UPGRADE V9 — Aprovação do projeto com carga horária (TI)
--               + inversão do fluxo de vídeos (MB publica, formador analisa)
--
-- Idempotente: pode ser executado mais de uma vez sem efeito colateral.
-- Faça backup antes.
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) Nova fase após o Backlog: a TI aprova o projeto do curso e
--    define oficialmente a carga horária.
-- ------------------------------------------------------------
SET @existe        := (SELECT COUNT(*) FROM tb_status WHERE nome = 'Projeto Aprovado');
SET @ordemProposto := (SELECT ordem     FROM tb_status WHERE nome = 'Curso Proposto');
SET @colProposto   := (SELECT id_coluna FROM tb_status WHERE nome = 'Curso Proposto');

-- abre espaço na ordem (só na primeira execução)
UPDATE tb_status
SET ordem = ordem + 1
WHERE @existe = 0 AND ordem > @ordemProposto;

INSERT INTO tb_status (nome, id_coluna, cor, ordem, is_inicial, is_final, ativo)
SELECT 'Projeto Aprovado', @colProposto, '#0d9488', @ordemProposto + 1, 0, 0, 1
FROM DUAL
WHERE @existe = 0;

-- o formador não passa mais direto do Backlog para o planejamento:
-- o projeto precisa ser aprovado pela TI (que define a carga horária)
DELETE t FROM tb_status_transicoes t
JOIN tb_status sd ON sd.id_status = t.id_status_de
JOIN tb_status sp ON sp.id_status = t.id_status_para
WHERE t.role = 'PROFESSOR' AND sd.nome = 'Curso Proposto' AND sp.nome = 'Em Planejamento';

INSERT IGNORE INTO tb_status_transicoes (role, id_status_de, id_status_para)
SELECT r.role, sd.id_status, sp.id_status
FROM (
  SELECT 'TI' role, 'Curso Proposto' de, 'Projeto Aprovado' para UNION ALL
  SELECT 'PROFESSOR', 'Projeto Aprovado', 'Em Planejamento'
) r
JOIN tb_status sd ON sd.nome = r.de
JOIN tb_status sp ON sp.nome = r.para;

-- registra quando o projeto foi aprovado (a carga horária oficial fica em tb_cursos)
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_cursos'
       AND COLUMN_NAME = 'projeto_aprovado_em') = 0,
  'ALTER TABLE tb_cursos ADD COLUMN projeto_aprovado_em DATETIME NULL AFTER publication_due_date',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ------------------------------------------------------------
-- 2) Vídeos: a MB publica o vídeo e descreve exatamente o material;
--    a descrição vai no e-mail enviado ao formador.
-- ------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_videos'
       AND COLUMN_NAME = 'descricao') = 0,
  'ALTER TABLE tb_videos ADD COLUMN descricao TEXT NULL AFTER titulo',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Conferência: deve listar "Projeto Aprovado" logo após "Curso Proposto"
SELECT s.ordem, s.nome, c.nome AS coluna
FROM tb_status s JOIN tb_kanban_colunas c ON c.id_coluna = s.id_coluna
ORDER BY s.ordem LIMIT 5;
