-- ============================================================
-- AutoriaSCS • Reparo do fluxo de publicação (V5)
-- Corrige bancos onde o upgrade_v5.sql executou parcialmente
-- (status "Pronto para Publicação" ausente e/ou transições finais perdidas).
-- IDEMPOTENTE: pode ser executado quantas vezes for necessário.
-- ============================================================
SET NAMES utf8mb4;

-- 0) Garante que as colunas finais do Kanban estão ativas
UPDATE tb_kanban_colunas SET ativo=1 WHERE nome IN ('Validação Autor','Publicado');

-- 1) Cria o status "Pronto para Publicação" se não existir,
--    na MESMA coluna do status "Publicado" (independente do nome da coluna)
INSERT INTO tb_status (nome, id_coluna, cor, ordem, is_inicial, is_final, ativo)
SELECT 'Pronto para Publicação', s.id_coluna, '#fd7e14', s.ordem, 0, 0, 1
FROM tb_status s
WHERE s.nome = 'Publicado'
  AND NOT EXISTS (SELECT 1 FROM tb_status x WHERE x.nome = 'Pronto para Publicação');

-- 2) Garante que os status finais estão ativos e com as marcações corretas
UPDATE tb_status SET ativo=1, is_final=0 WHERE nome IN ('Validado','Pronto para Publicação');
UPDATE tb_status SET ativo=1, is_final=1 WHERE nome = 'Publicado';

-- 3) Ordem: "Pronto para Publicação" imediatamente antes de "Publicado"
UPDATE tb_status p
JOIN tb_status r ON r.nome = 'Pronto para Publicação'
SET p.ordem = r.ordem + 1
WHERE p.nome = 'Publicado' AND p.ordem <= r.ordem;

-- 4) Remove a transição antiga TI: Validado -> Publicado (se ainda existir)
DELETE t FROM tb_status_transicoes t
JOIN tb_status sd ON sd.id_status = t.id_status_de  AND sd.nome = 'Validado'
JOIN tb_status sp ON sp.id_status = t.id_status_para AND sp.nome = 'Publicado'
WHERE t.role = 'TI';

-- 5) Garante as duas transições do novo fluxo (INSERT IGNORE = sem duplicar)
INSERT IGNORE INTO tb_status_transicoes (role, id_status_de, id_status_para)
SELECT 'TI', sd.id_status, sp.id_status
FROM tb_status sd, tb_status sp
WHERE sd.nome = 'Validado' AND sp.nome = 'Pronto para Publicação';

INSERT IGNORE INTO tb_status_transicoes (role, id_status_de, id_status_para)
SELECT 'MB', sd.id_status, sp.id_status
FROM tb_status sd, tb_status sp
WHERE sd.nome = 'Pronto para Publicação' AND sp.nome = 'Publicado';

-- ============================================================
-- CONFERÊNCIA — os dois SELECTs abaixo devem mostrar:
--   a) os status da coluna final, com "Pronto para Publicação" antes de "Publicado"
--   b) as transições: TI Validado->Pronto para Publicação e MB Pronto para Publicação->Publicado
-- ============================================================
SELECT s.nome AS status, k.nome AS coluna, s.ordem, s.ativo, s.is_final
FROM tb_status s
JOIN tb_kanban_colunas k ON k.id_coluna = s.id_coluna
WHERE s.nome IN ('Aguardando Validação','Validado','Pronto para Publicação','Publicado')
ORDER BY s.ordem;

SELECT t.role, sd.nome AS de, sp.nome AS para
FROM tb_status_transicoes t
JOIN tb_status sd ON sd.id_status = t.id_status_de
JOIN tb_status sp ON sp.id_status = t.id_status_para
WHERE sd.nome IN ('Validado','Pronto para Publicação') OR sp.nome IN ('Pronto para Publicação','Publicado')
ORDER BY t.role, sd.ordem;
