-- ============================================================
--  AutoriaSCS — upgrade V19: Filmagens (Estúdio MB)
--  Controle das reservas de estúdio (lidas do Sistema de Reservas, no
--  mesmo servidor) e do registro da filmagem realizada pela MB.
--  Idempotente: pode ser executado mais de uma vez.
--  Depois, configure a conexão em app/config.local.php (bloco 'reservas').
-- ============================================================

CREATE TABLE IF NOT EXISTS tb_filmagens (
  id_filmagem        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_reserva         INT UNSIGNED NULL,                 -- id da tabela `reservas` do Sistema de Reservas (NULL = filmagem avulsa)
  id_curso           INT UNSIGNED NULL,                 -- curso do AutoriaSCS (vínculo automático ou manual)
  modulo             TINYINT UNSIGNED NULL,
  vinculo_manual     TINYINT(1) NOT NULL DEFAULT 0,     -- 1 = ligado pela TI (a sincronização não altera)
  professor_nome     VARCHAR(150) NULL,
  professor_email    VARCHAR(150) NULL,
  curso_nome         VARCHAR(250) NULL,                 -- nome informado na reserva
  sala               VARCHAR(150) NULL,
  ambiente           VARCHAR(100) NULL,
  data_reserva       DATE NOT NULL,
  hora_reserva       TIME NULL,
  estado_reserva     VARCHAR(20) NOT NULL DEFAULT 'Pendente', -- Pendente / Aceita / Recusada (como no Sistema de Reservas)
  motivo_reserva     TEXT NULL,
  reserva_criada_em  DATETIME NULL,
  removida_na_origem TINYINT(1) NOT NULL DEFAULT 0,     -- a reserva foi apagada no Sistema de Reservas
  filmagem           ENUM('PENDENTE','REALIZADA','NAO_REALIZADA') NOT NULL DEFAULT 'PENDENTE',
  filmada_em         DATE NULL,
  filmagem_obs       VARCHAR(500) NULL,
  registrado_por     INT UNSIGNED NULL,
  registrado_em      DATETIME NULL,
  sincronizado_em    DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_filmagem),
  UNIQUE KEY uq_filmagens_reserva (id_reserva),
  KEY ix_filmagens_curso (id_curso),
  KEY ix_filmagens_data (data_reserva),
  KEY ix_filmagens_filmagem (filmagem),
  CONSTRAINT fk_filmagens_curso FOREIGN KEY (id_curso) REFERENCES tb_cursos (id_curso) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'V19 aplicada: tabela tb_filmagens pronta.' AS resultado;
