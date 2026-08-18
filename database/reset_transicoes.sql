-- ============================================================
-- AutoriaSCS • Restaura as Transições por Perfil ao fluxo oficial
-- Use quando as transições do banco estiverem divergentes do padrão
-- (ex.: criadas/alteradas em testes na tela Admin > Transições).
-- Execute no phpMyAdmin, no banco do sistema. Pode rodar quantas
-- vezes quiser — sempre deixa exatamente o fluxo oficial.
-- ============================================================
SET NAMES utf8mb4;

DELETE FROM tb_status_transicoes;

INSERT INTO tb_status_transicoes (role, id_status_de, id_status_para)
SELECT r.role, sd.id_status, sp.id_status
FROM (
  SELECT 'PROFESSOR' role, 'Curso Proposto' de, 'Em Planejamento' para UNION ALL
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

-- Requer o status "Pronto para Publicação" (upgrade_v5.sql) já criado.
-- Conferência: deve listar 16 linhas (7 PROFESSOR, 6 TI, 3 MB)
SELECT t.role, sd.nome AS de, sp.nome AS para
FROM tb_status_transicoes t
JOIN tb_status sd ON sd.id_status = t.id_status_de
JOIN tb_status sp ON sp.id_status = t.id_status_para
ORDER BY FIELD(t.role,'PROFESSOR','TI','MB'), sd.ordem, sp.ordem;
