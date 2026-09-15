-- ============================================================
-- UPGRADE V10 — Cadastros administráveis: Níveis de Ensino e
--               Categorias de entrega (antes fixos no código PHP)
--
-- Idempotente: pode ser executado mais de uma vez sem efeito colateral.
-- Não apaga nem altera nenhum dado existente. Faça backup antes.
--
-- Vínculo com as tabelas atuais: continua sendo pelo NOME (texto), exatamente
-- como já funciona hoje —
--   tb_cursos.nivel_ensino        VARCHAR(60)  <->  tb_niveis_ensino.nome  VARCHAR(60)
--   tb_curso_files.categoria      VARCHAR(60)  <->  tb_categorias.nome     VARCHAR(60)
--   tb_curso_dispensas.categoria  VARCHAR(60)  <->  tb_categorias.nome     VARCHAR(60)
-- Por isso nenhuma coluna das tabelas existentes precisa ser alterada e
-- nenhum ID muda. (Mesmo modelo adotado pelos status do Kanban.)
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) Níveis de ensino (Guia 01, seção 7.1-b)
--    Admin: cadastrar, renomear, reordenar, ativar/inativar.
--    Sem exclusão física quando em uso por algum curso.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_niveis_ensino (
  id_nivel   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome       VARCHAR(60) NOT NULL,                  -- mesmo tamanho de tb_cursos.nivel_ensino
  ordem      SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- ordem de exibição nos selects
  ativo      TINYINT(1) NOT NULL DEFAULT 1,         -- inativo = não aparece para novos cursos
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_nivel),
  UNIQUE KEY uq_niveis_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sementes: os 5 valores que hoje estão fixos em app/status_repo.php (niveis_ensino())
-- + o novo "Ensino Fundamental - Médio". INSERT IGNORE + UNIQUE(nome) evita duplicidade.
INSERT IGNORE INTO tb_niveis_ensino (nome, ordem, ativo) VALUES
  ('Educação Infantil',                   1, 1),
  ('Ensino Fundamental - Anos Iniciais',  2, 1),
  ('Ensino Fundamental - Anos Finais',    3, 1),
  ('Ensino Fundamental - Médio',          4, 1),
  ('Ensino Médio',                        5, 1),
  ('Formação Transversal / Complementar', 6, 1);

-- Preservação: qualquer nível já gravado em cursos (texto livre de versões antigas)
-- que não esteja na lista acima entra no cadastro como ATIVO, para o curso continuar
-- exibindo/editando o valor sem perda. (Nenhum curso é alterado.)
INSERT IGNORE INTO tb_niveis_ensino (nome, ordem, ativo)
SELECT DISTINCT c.nivel_ensino, 90, 1
FROM tb_cursos c
WHERE c.nivel_ensino IS NOT NULL AND TRIM(c.nivel_ensino) <> ''
  AND NOT EXISTS (SELECT 1 FROM tb_niveis_ensino n WHERE n.nome = c.nivel_ensino);

-- ------------------------------------------------------------
-- 2) Categorias de entrega de material (fluxo ordenado, V7)
--    escopo GERAL  = módulo 0 (materiais gerais do curso)
--    escopo MODULO = módulos 1..8 (mesma lista para todos os módulos)
--    Admin: cadastrar, renomear, marcar obrigatória/opcional, reordenar,
--    ativar/inativar. Exclusão física só quando nunca usada.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tb_categorias (
  id_categoria INT UNSIGNED NOT NULL AUTO_INCREMENT,
  escopo       ENUM('GERAL','MODULO') NOT NULL DEFAULT 'MODULO',
  nome         VARCHAR(60) NOT NULL,                 -- mesmo tamanho de tb_curso_files.categoria
  obrigatoria  TINYINT(1) NOT NULL DEFAULT 1,        -- 0 = pode ser dispensada ("não possuo este material")
  ordem        SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- ordem de exibição / de entrega
  ativo        TINYINT(1) NOT NULL DEFAULT 1,        -- inativo = não aparece para novos envios
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_categoria),
  UNIQUE KEY uq_categorias_escopo_nome (escopo, nome),
  KEY ix_categorias_escopo (escopo, ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sementes: exatamente as 11 categorias hoje fixas em app/entregas_repo.php
-- (entregas_categorias()), com a mesma obrigatoriedade e a mesma ordem.
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

-- Preservação: categorias gravadas em arquivos antigos (antes do fluxo V7, ex.: 'OUTROS')
-- que não estejam na lista entram como INATIVAS e opcionais — os arquivos continuam
-- listados normalmente no curso, mas a categoria não é oferecida para novos envios.
INSERT IGNORE INTO tb_categorias (escopo, nome, obrigatoria, ordem, ativo)
SELECT DISTINCT IF(f.modulo = 0, 'GERAL', 'MODULO'), f.categoria, 0, 90, 0
FROM tb_curso_files f
WHERE f.categoria IS NOT NULL AND TRIM(f.categoria) <> ''
  AND NOT EXISTS (
    SELECT 1 FROM tb_categorias k
    WHERE k.escopo = IF(f.modulo = 0, 'GERAL', 'MODULO') AND k.nome = f.categoria
  );

-- Conferência
SELECT 'niveis' AS cadastro, ordem, nome, ativo FROM tb_niveis_ensino ORDER BY ordem, nome;
SELECT 'categorias' AS cadastro, escopo, ordem, nome, obrigatoria, ativo FROM tb_categorias ORDER BY escopo, ordem, nome;
