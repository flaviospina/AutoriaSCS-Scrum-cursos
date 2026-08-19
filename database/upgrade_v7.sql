-- ============================================================
-- UPGRADE V7 — Fluxo ordenado de entrega de materiais
-- Execute uma única vez (faça backup antes).
-- ============================================================

-- 1) A categoria do arquivo passa a guardar o nome real da entrega
--    (Apresentação do Módulo, Slide, Vídeo...). Valores antigos
--    (PLANEJAMENTO/PRODUCAO/ENTREGA/OUTROS) são preservados no histórico.
ALTER TABLE tb_curso_files
  MODIFY categoria VARCHAR(60) NOT NULL DEFAULT 'OUTROS';

-- 2) Registro de "sem material": categorias opcionais (Texto Complementar,
--    Atividade Avaliativa) que o formador declarou não possuir, liberando
--    a categoria seguinte da sequência.
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
