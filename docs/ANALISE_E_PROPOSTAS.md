# Análise Completa do Sistema AutoriaSCS • Gestão Scrum/Kanban de Cursos

**Data:** Julho/2026 • **Elaborado a partir de:** código-fonte do webapp, Guia 01 de Orientação
para Formadores (2026), Guia Rápido da Plataforma e pesquisa de boas práticas de mercado.

---

## 1. Contexto

Os(as) professores(as) da rede municipal de São Caetano do Sul criam cursos para a **Plataforma
AutoriaSCS** (https://eadcecape.com.br), controlada pelo **CECAPE**. O fluxo envolve quatro atores:

1. **Formador(a)** — propõe o curso, produz conteúdo (módulos, vídeos, textos, avaliações);
2. **Equipe TI & AutoriaSCS** — revisão técnica, pedagógica e editorial (padrões do Guia 01);
3. **MB Estúdios** — gravação dos vídeos e inserção/configuração do curso na plataforma;
4. **Direção CECAPE** — autorização final (Prof.ª Maiberte Brogliato, prazo estimado de 15 dias).

O webapp acompanha esse ciclo com um quadro **Kanban** e uma visão em tabela, conforme previsto no
Guia 01 (seção 7.2): *"será implementado um sistema de controle em ambiente Web, com acesso
restrito... com indicadores visuais (painéis, status, cores, prazos e etapas)... e filtros por
unidade escolar, formador, etapa do projeto, prioridade"*.

## 2. Análise do sistema original (o que existia)

### 2.1 Arquitetura

- **Stack:** PHP procedural + PDO/MySQL + Bootstrap 5 (CDN), sem framework — adequado à
  hospedagem compartilhada usada pelas demais aplicações do CECAPE.
- **Módulos:** login com perfis (`PROFESSOR`, `TI`, `MB`), cadastro de curso, Kanban com
  drag & drop (só TI), tabela com filtros/paginação/ordenação, checklist de 12 itens,
  apontamentos de revisão (técnico/pedagógico/ABNT), upload/download de arquivos (25 MB),
  histórico de status e webhook n8n para automações.
- **Fluxo:** 15 status agrupados em 10 colunas, com regras de transição por perfil
  codificadas em `status_rules.php` (hard-coded).

### 2.2 Pontos fortes

- O fluxo espelha fielmente o processo do Guia 01 (proposta → produção → revisão TI → ajustes →
  aprovação → MB Estúdios → validação do autor → publicação);
- Trilha de auditoria completa (histórico de cada mudança de status, com autor e observação);
- Apontamentos tipificados pelos mesmos critérios de revisão do guia (ABNT NBR 6023, técnico,
  pedagógico);
- Regra automática da data de publicação (1º dia útil do mês subsequente à validação).

### 2.3 Problemas encontrados (e corrigidos nesta versão)

| # | Problema | Correção aplicada |
|---|---|---|
| 1 | `index.php` imprimia um hash de senha (`password_hash('123456')`) — vazamento e porta de entrada indevida | Redireciona para login/dashboard |
| 2 | Credenciais reais do banco commitadas em `config.php` | Placeholders + `config.local.php` ignorado pelo git |
| 3 | Nenhum formulário tinha proteção **CSRF** | Token CSRF em todos os POSTs (`app/csrf.php`) |
| 4 | Fluxo terminava em `Validado`: **nenhum perfil conseguia mover para `Publicado`** | Transição `TI: Validado → Publicado` incluída no seed |
| 5 | Status/colunas/transições fixos no código — mudar o fluxo exigia programador | **Kanban dinâmico configurável na área Admin** |
| 6 | Sem perfil administrador | Novo perfil `ADMIN` |
| 7 | `curso_editar.php` existia vazio (não implementado) | Implementado com controle de permissão |
| 8 | Falha de conexão com o banco morria silenciosamente (`exit` sem mensagem) | Mensagem amigável + log |
| 9 | Uploads acessíveis por URL direta se o docroot fosse mal configurado | `.htaccess` de bloqueio em `storage/` |
| 10 | Sessão não regenerada no login (fixação de sessão) | `session_regenerate_id(true)` |
| 11 | MB não via a data em que o curso foi inserido | `inserted_at` gravado na transição para `Inserido` |

## 3. O que foi implementado nesta versão

### 3.1 Área Admin (pedido central)

O admin agora **adiciona, remove e altera qualquer campo do Kanban** sem tocar em código:

- **Colunas** (`admin/kanban.php`): criar, renomear, excluir (se vazia), reordenar (▲▼), cor do
  cabeçalho (seletor de cor), **limite WIP** opcional e ativar/desativar;
- **Status** (`admin/status.php`): criar, renomear (propaga para cursos e histórico
  automaticamente), mover de coluna, cor do badge, definir **status inicial** (o que novos cursos
  recebem) e **status finais** (encerram o fluxo, param alertas de prazo), ativar/desativar,
  excluir (bloqueado se houver cursos no status);
- **Transições por perfil** (`admin/transicoes.php`): matriz De → Para por perfil
  (PROFESSOR/TI/MB); ADMIN pode qualquer movimentação;
- **Usuários** (`admin/usuarios.php`): CRUD completo com redefinição de senha e proteção contra
  auto-remoção do próprio acesso ADMIN.

Salvaguardas de integridade: nomes únicos, exclusões bloqueadas quando há vínculos, renomeação em
transação com atualização em cascata.

### 3.2 Novas funcionalidades do fluxo (fundamentadas no Guia 01)

- **Prioridade** (Baixa/Média/Alta/Urgente) com badge colorido e ordenação do Kanban por
  prioridade — filtro previsto na seção 7.2 do guia;
- **Nível de ensino** (Educação Infantil, Fund. Anos Iniciais/Finais, Ensino Médio, Formação
  Transversal/Complementar) — categorização da seção 7.1-b;
- **Unidade escolar** — filtro previsto na seção 7.2;
- **Alertas de prazo**: cards e tabelas sinalizam `Atrasado` e `Prazo próximo` (≤ 7 dias);
- **Limite WIP por coluna** com aviso visual quando excedido (prática Kanban essencial);
- **Página Relatórios** (`relatorios.php`): total de cursos, concluídos, atrasados, movimentações
  em 30 dias, **lead time médio** (proposta → publicação), funil por etapa, produção por
  formador, por nível de ensino, por prioridade, pendências abertas e prazos críticos;
- Kanban ao mover para coluna com vários status agora pergunta **qual** status de destino;
- MB e ADMIN também enxergam o Kanban (antes só TI tinha visão útil);
- Identidade visual da plataforma aplicada: fonte **Comfortaa** e cor institucional **#058285**
  (padrões tipográficos do Guia 01, seção 6), mantendo Bootstrap 5 como nas demais aplicações.

> Observação: o acesso externo a `cecapescs.com.br` (mocapweb) foi bloqueado pela política de
> rede deste ambiente; a identidade aplicada seguiu o padrão oficial documentado nos guias
> (Comfortaa + #058285). Se a mocapweb usar outros detalhes (logo em imagem, tons), basta trocar
> as variáveis CSS em `public/_layout_top.php` (`--autoria-teal`) e o bloco `.brand-logo`.

## 4. Pesquisa de mercado — o que sistemas desse tipo oferecem

A pesquisa (Jira, Kanban Tool, OnePlan, guias de workflow de aprovação e plataformas EAD)
apontou como essenciais para quadros Kanban administráveis e fluxos de aprovação de conteúdo:

1. **Colunas/status configuráveis por administrador**, com múltiplos status por coluna e
   restrições de mapeamento — *implementado*;
2. **Limites WIP** para evitar sobrecarga da equipe de revisão — *implementado*;
3. **Fluxo de aprovação estruturado** com etapas, responsáveis e registros — *já existia,
   agora configurável*;
4. **Métricas de fluxo** (lead time, itens por etapa, gargalos) — *implementado em Relatórios*;
5. **Automações e integrações** (notificações, e-mail, calendários) — parcialmente coberto pelo
   webhook n8n; ver roadmap;
6. **Começar simples e evoluir o processo continuamente** — motivo pelo qual o fluxo padrão
   replica o atual e o Admin permite evoluir sem programador.

Fontes consultadas:
[Jira – Configure columns](https://support.atlassian.com/jira-software-cloud/docs/configure-columns/),
[OnePlan – Customize the Kanban board](https://support.oneplan.ai/hc/en-us/articles/4411642877197-Customize-the-KanBan-board),
[Kanban Tool – Kanban board](https://kanbantool.com/support/kanban-board),
[Virto – Digital Kanban Board Guide](https://blog.virtosoftware.com/digital-kanban-board-guide/),
[Aha! – How to set up a kanban board](https://www.aha.io/roadmapping/guide/agile/how-to-set-up-kanban-board),
[Checklist Fácil – Workflow de aprovação](https://checklistfacil.com/blog/workflow-de-aprovacao/),
[EAD Simples – Funcionalidades essenciais de plataforma EAD](https://www.eadsimples.com.br/destaques/plataforma-de-ensino-as-8-funcionalidades-essenciais-para-criar-seu-curso-ead/),
[Runrun.it – Método Kanban](https://blog.runrun.it/kanban/).

## 5. Roadmap — próximas funcionalidades propostas

### Prioridade ALTA (maior impacto no processo do Guia 01)

1. **Notificações por e-mail** nas mudanças de status e novos apontamentos (o canal oficial já é
   ti.cecape@scseduca.com.br; o webhook n8n já emite os eventos — basta criar o fluxo no n8n ou
   um `mail()` nativo);
2. **Cronogramas (sprints) do Guia 01, seção 8**: cadastro dos dois cronogramas oficiais
   (entrega pelos formadores e entrada em homologação) com vínculo dos cursos a "janelas" de
   publicação mensais e visão de calendário;
3. **Comentários por curso** (conversa formador ↔ TI dentro do sistema, hoje dispersa em e-mail);
4. **Checklist dinâmico** administrável como o Kanban (itens por etapa, obrigatórios para
   transição — ex.: bloquear "Pronto para Análise" sem `referencias_abnt`);
5. **Agendamento de gravação MB Estúdios** (seção 3.2 do guia): pedido de agendamento com
   briefing, local, nº de participantes e status próprio.

### Prioridade MÉDIA

6. **Contador de questões da avaliação** com validação da regra "+5 para randomização"
   (tabela da seção 3.3: 5→10, 10→15, 15→20, 20→25);
7. **Validador de estrutura por carga horária** (10h = 1-2 módulos/40 min de vídeo; 20h = 3-4/80;
   30h = 5-6/120; 40h = 7-8/160) alertando divergências na proposta;
8. **Versionamento de arquivos** (nova versão do mesmo material mantém histórico; hoje cada
   upload é um arquivo solto);
9. **Swimlanes no Kanban** (por formador ou por prioridade) e modo tela cheia para TV do CECAPE;
10. **Exportação CSV/PDF** dos relatórios para prestação de contas à SEEDUC.

### Prioridade BAIXA (evolução)

11. **Gráfico de fluxo cumulativo (CFD)** e burndown por sprint;
12. **API/integração com a plataforma Moodle (eadcecape.com.br)** para publicar automaticamente
    a estrutura do curso aprovado;
13. **Autenticação Google Workspace** (@scseduca.com.br) via OAuth;
14. **Modo somente-leitura público** para a direção acompanhar sem login;
15. **Trilha LGPD**: consentimento de uso de imagem do formador (minibio/foto) anexado ao curso.

## 6. Modelo de dados (resumo)

```
tb_users (id_user, nome, email, senha_hash, role[PROFESSOR|TI|MB|ADMIN], ativo)
tb_kanban_colunas (id_coluna, nome, cor, ordem, wip_limit, ativo)          ← NOVO
tb_status (id_status, nome, id_coluna→, cor, ordem, is_inicial, is_final, ativo) ← NOVO
tb_status_transicoes (role, id_status_de→, id_status_para→)               ← NOVO
tb_cursos (…, nivel_ensino, unidade_escolar, prioridade, status_atual, prazos…) ← campos novos
tb_curso_checklist / tb_curso_status_history / tb_curso_files / tb_curso_apontamentos
```

O nome do status é a chave de ligação com `tb_cursos.status_atual` (mantém compatibilidade com o
banco existente); a renomeação via Admin atualiza tudo em transação.
