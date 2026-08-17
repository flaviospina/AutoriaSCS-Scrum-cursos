# Proposta: Auditoria, Notificações por E-mail e Repositório de Arquivos/Modelos

**Status:** APROVADA (todas as fases) — implementação nesta versão.
**Data:** Agosto/2026

---

## 1. Diagnóstico da situação anterior

| Ação no sistema | Ficava registrado? | E-mail enviado? |
|---|---|---|
| Mudança de status do curso | ✅ `tb_curso_status_history` | ❌ |
| Criação de curso | ✅ (histórico) | ❌ |
| Edição dos dados do curso | ❌ | ❌ |
| Upload de arquivo | ✅ parcial (`tb_curso_files`) | ❌ |
| Download de arquivo | ❌ | ❌ |
| Apontamento criado (revisão TI) | ✅ (autor/data) | ❌ formador não era avisado |
| Apontamento resolvido/reaberto | ❌ | ❌ |
| Alterações no checklist | ❌ | ❌ |
| Login / tentativas falhas | ❌ | — |
| Ações administrativas (usuários, Kanban) | ❌ | ❌ |

O único log real era o histórico de status; nenhum e-mail era enviado em nenhuma situação
(apenas o webhook n8n, desativado por padrão).

## 2. Módulo A — Auditoria completa

- Tabela única `tb_audit_log` (usuário, ação, entidade, id, dados antes/depois em JSON, IP,
  data/hora) registrando **toda ação de escrita** + login (sucesso e falha) + downloads;
- Tela **Admin → Auditoria** com filtros (usuário, ação, entidade, período) e exportação CSV;
- Retenção sugerida: 24 meses.

## 3. Módulo B — Notificações por e-mail

- **Fila no banco** (`tb_notificacoes`) + processamento por **cron** (`cron/cron_notificacoes.php`
  a cada 5 min): a página nunca trava esperando SMTP e falhas são reenviadas (até 3 tentativas);
- Remetente: conta institucional (ti.cecape@scseduca.com.br) via `mail()` nativo do cPanel
  ou SMTP autenticado (configurável em `config.local.php`, seção `mail`);
- **Matriz de eventos → destinatários:**

| Evento | Formador | TI | MB |
|---|---|---|---|
| Curso proposto | confirmação | novo curso no backlog | — |
| Pronto para (Nova) Análise | — | curso aguardando revisão | — |
| Recusado c/ apontamentos | ✉️ com a lista de apontamentos | — | — |
| Aprovado / Enviado p/ Inserção | ✉️ | — | novo curso para inserir |
| Inserido | ✉️ "valide seu curso" | ✉️ | — |
| Validado / Publicado | ✉️ com data prevista | ✉️ | — |
| Novo apontamento avulso | ✉️ | — | — |
| Prazo em 7 dias / vencido (cron diário) | ✉️ | resumo diário | — |
| Resumo semanal (segunda) | seus cursos | painel geral | pendências |

- Preferência individual por usuário: **Imediato / Resumo diário / Desativado**;
- Template HTML com identidade AutoriaSCS (Comfortaa, #058285) e link direto ao curso.

## 4. Módulo C — Repositório de arquivos e modelos (decisão: HÍBRIDO)

Comparativo avaliado:

| Critério | Google Drive (API) | 100% no sistema | **Híbrido (escolhido)** |
|---|---|---|---|
| Formador vê só o que é dele | ⚠️ frágil (permissões manuais/API) | ✅ garantido por código | ✅ |
| Templates para todos | ✅ | ✅ | ✅ |
| Vídeos/arquivos grandes | ✅ | ❌ disco do cPanel | ✅ ficam no Drive |
| Auditoria de acesso | ❌ | ✅ | ✅ |
| Complexidade | Alta | Baixa | Baixa/Média |

Implementação:

1. **Biblioteca de Modelos** (`modelos.php`): TI/ADMIN publica os templates oficiais
   (slides Comfortaa, Estrutura do Curso, guias, minibio, avaliações) com **versionamento** e
   marcação de "versão vigente"; todos os usuários logados leem/baixam (download auditado).
   Limite de 50 MB por arquivo;
2. **Arquivos do curso continuam no sistema** com o controle já existente (formador só acessa o
   próprio curso), agora vinculados a **módulos** (Geral, Módulo 1..8) e com download auditado;
3. **Google Drive apenas para mídia pesada**: cadastro de **links** do Drive por curso
   (vídeos brutos, materiais > 25 MB), sem dependência da API do Google — o sistema guarda,
   exibe e audita os links.

## 5. Fases executadas

- **Fase 1** – Auditoria (Módulo A);
- **Fase 2** – E-mails de eventos críticos + cron de prazos;
- **Fase 3** – Biblioteca de Modelos + arquivos por módulo + links Drive;
- **Fase 4** – Resumo semanal, página "Meu Perfil" (preferência de e-mail + troca da própria
  senha) e exportações CSV (auditoria e cursos).

## 6. Configuração operacional (cPanel)

1. Criar 2 cron jobs:
   - a cada 5 min: `php /home/USUARIO/caminho/cron/cron_notificacoes.php CHAVE`
   - 1x ao dia (07h): `php /home/USUARIO/caminho/cron/cron_prazos.php CHAVE`
   (a CHAVE é definida em `config.local.php`, seção `cron`; os scripts também aceitam chamada
   via URL para hospedagens sem acesso a PHP CLI: `cron/cron_notificacoes.php?chave=...`);
2. Configurar a seção `mail` no `config.local.php` (método `mail` nativo ou `smtp`);
3. Garantir permissão de escrita em `storage/modelos/`.

## 7. Registro para o futuro (backlog — NÃO implementado agora)

- **Visualizador de apresentações embutido** (sugestão do Prof. Flávio): ferramenta para abrir
  as apresentações/slides dentro do próprio sistema, com configurações limitadas (sem download
  ou edição fora do padrão), garantindo que o template oficial não seja alterado. Caminhos
  possíveis a estudar: renderização de PDF em `<iframe>`/PDF.js com controles restritos, ou
  conversão automática de PPTX→PDF no upload. Avaliar na próxima rodada de atualizações;
- Integração com API do Google Drive (caso o híbrido se mostre insuficiente);
- Autenticação Google Workspace (@scseduca.com.br).
