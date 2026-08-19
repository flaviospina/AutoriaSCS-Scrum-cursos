-- ============================================================
-- UPGRADE V8 — Ferramenta de análise e revisão de vídeos
-- Execute uma única vez (faça backup antes).
-- ============================================================

-- Vídeo em revisão: agrupa as versões enviadas de um mesmo vídeo do curso
CREATE TABLE IF NOT EXISTS tb_videos (
  id_video   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_curso   INT UNSIGNED NOT NULL,
  modulo     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  titulo     VARCHAR(180) NOT NULL,
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
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(100) NOT NULL,
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
