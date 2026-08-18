-- ============================================================
-- AutoriaSCS • Upgrade V5 — Etapa "Pronto para Publicação"
-- Novo fluxo final:
--   TI:  Validado -> Pronto para Publicação (informando a data de publicação)
--   MB:  Pronto para Publicação -> Publicado
-- Para bancos que já rodaram o upgrade_v4.sql. Execute UMA única vez.
-- ============================================================
SET NAMES utf8mb4;

-- 1) Novo status na coluna "Publicado" (antes do status final)
INSERT INTO tb_status (nome, id_coluna, cor, ordem, is_inicial, is_final, ativo)
SELECT 'Pronto para Publicação', id_coluna, '#fd7e14',
       (SELECT o.ordem - 1 FROM (SELECT ordem FROM tb_status WHERE nome='Publicado') o),
       0, 0, 1
FROM tb_kanban_colunas WHERE nome='Publicado' LIMIT 1;

-- 2) Remove a transição antiga TI: Validado -> Publicado
DELETE t FROM tb_status_transicoes t
JOIN tb_status sd ON sd.id_status = t.id_status_de AND sd.nome = 'Validado'
JOIN tb_status sp ON sp.id_status = t.id_status_para AND sp.nome = 'Publicado'
WHERE t.role = 'TI';

-- 3) Novas transições do fluxo de publicação
INSERT INTO tb_status_transicoes (role, id_status_de, id_status_para)
SELECT r.role, sd.id_status, sp.id_status
FROM (
  SELECT 'TI' role, 'Validado' de, 'Pronto para Publicação' para UNION ALL
  SELECT 'MB', 'Pronto para Publicação', 'Publicado'
) r
JOIN tb_status sd ON sd.nome = r.de
JOIN tb_status sp ON sp.nome = r.para;

-- Conferência
SELECT t.role, sd.nome AS de, sp.nome AS para
FROM tb_status_transicoes t
JOIN tb_status sd ON sd.id_status = t.id_status_de
JOIN tb_status sp ON sp.id_status = t.id_status_para
WHERE sd.nome IN ('Validado','Pronto para Publicação')
ORDER BY t.role;
