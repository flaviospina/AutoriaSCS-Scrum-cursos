# AutoriaSCS • Gestão Scrum/Kanban da Produção de Cursos

Sistema web (PHP + MySQL + Bootstrap 5) do CECAPE / Rede Municipal de São Caetano do Sul para
acompanhar todo o ciclo de produção dos cursos da **Plataforma AutoriaSCS** — da proposta do(a)
formador(a) até a publicação — com metodologia **Scrum/Kanban**, conforme o *Guia 01 de Orientação
para Formadores*.

## Perfis de acesso

| Perfil | O que faz |
|---|---|
| `PROFESSOR` (formador) | Propõe e edita seus cursos, preenche checklist, envia arquivos, move seus status permitidos |
| `TI` | Revisão técnica/pedagógica, apontamentos, aprovação/recusa, Kanban completo, relatórios |
| `MB` | Acompanha e move os status de inserção na plataforma (MB Estúdios) |
| `ADMIN` | Tudo acima + **área Admin**: colunas do Kanban, status, transições e usuários |

## Área Admin (Kanban dinâmico)

O fluxo do Kanban não é mais fixo no código — é configurável em `Admin`:

- **Colunas do Kanban** (`admin/kanban.php`): adicionar, renomear, reordenar, cor do cabeçalho,
  limite WIP e ativar/desativar.
- **Status** (`admin/status.php`): criar/editar status, mover entre colunas, cor do badge,
  status inicial (novos cursos) e finais (encerram o fluxo). Renomear um status atualiza
  automaticamente cursos e histórico; exclusão só é permitida sem cursos no status.
- **Transições por perfil** (`admin/transicoes.php`): define quais movimentações PROFESSOR,
  TI e MB podem fazer (ADMIN pode todas).
- **Usuários** (`admin/usuarios.php`): cadastro, perfil, ativação e redefinição de senha.

## Auditoria, E-mails e Biblioteca de Modelos (V3)

- **Auditoria** (`Admin → Auditoria`): toda ação fica registrada em `tb_audit_log`
  (quem, o quê, quando, valores antes/depois, IP), incluindo logins, downloads e ações
  administrativas. Exportável em CSV;
- **Notificações por e-mail**: fila em `tb_notificacoes` processada por cron; eventos do fluxo
  (curso proposto, aguardando revisão, recusado com apontamentos, aprovado, inserido, publicado,
  novo apontamento), alertas diários de prazo e resumo semanal às segundas. Cada usuário escolhe
  em **Meu Perfil**: imediato, resumo diário ou desativado;
- **Biblioteca de Modelos** (`Modelos`): TI/ADMIN publica os templates oficiais com versão e
  marcação "vigente"; todos os formadores baixam (máx. 50MB por arquivo);
- **Arquivos por módulo** + **links externos** (Google Drive para mídia pesada) em cada curso.

## Instalação

1. Crie o banco e execute `database/schema.sql` (instalação nova). Migrações a partir de banco
   antigo, na ordem: `upgrade_v2.sql` (fluxo dinâmico), `upgrade_v3.sql` (auditoria/e-mails/modelos),
   `upgrade_v4.sql` (perfis dinâmicos), `upgrade_v5.sql` (etapa Pronto para Publicação),
   `upgrade_v6.sql` (cadastro de escolas) e `upgrade_v7.sql` (fluxo ordenado de entrega
   de materiais) — faça backup antes.
2. Copie `app/config.php` para `app/config.local.php` e preencha as credenciais reais do banco,
   a seção `mail` (método `mail` do cPanel ou `smtp`) e a `cron.chave`
   (o arquivo local é ignorado pelo git).
3. Publique o projeto no servidor (o *document root* deve apontar para `public/`).
4. Garanta permissão de escrita em `storage/cursos/` e `storage/modelos/`.
5. Agende os crons no cPanel:
   - a cada 5 min: `php /home/USUARIO/caminho/cron/cron_notificacoes.php SUA_CHAVE`
   - 1x ao dia (07h): `php /home/USUARIO/caminho/cron/cron_prazos.php SUA_CHAVE`
   (também aceitam chamada via URL: `cron/cron_notificacoes.php?chave=SUA_CHAVE`).
6. Acesse com o usuário inicial `admin@scseduca.com.br` / `admin123` e **troque a senha**.

## Estrutura

```
app/        código de domínio (auth, cursos, kanban dinâmico, csrf, auditoria, notificações)
public/     páginas (dashboard kanban/tabela, curso, apontamentos, relatórios, modelos, perfil)
public/admin/  área administrativa (ADMIN): kanban, status, transições, usuários, auditoria, e-mails
cron/       cron_notificacoes.php (fila de e-mails) e cron_prazos.php (prazos + resumo semanal)
database/   schema.sql (novo), upgrade_v2.sql e upgrade_v3.sql (migrações)
storage/    uploads dos cursos e biblioteca de modelos (download via páginas autenticadas)
docs/       análise completa, proposta de auditoria/e-mails/repositório e roadmap
```

## Documentação

- [`docs/ANALISE_E_PROPOSTAS.md`](docs/ANALISE_E_PROPOSTAS.md) — análise completa do sistema,
  pesquisa de boas práticas e roadmap de novas funcionalidades.
- [`docs/PROPOSTA_LOGS_EMAILS_REPOSITORIO.md`](docs/PROPOSTA_LOGS_EMAILS_REPOSITORIO.md) —
  proposta aprovada de auditoria, notificações e repositório de modelos (V3).
- [`docs/APRESENTACAO_DIRECAO.md`](docs/APRESENTACAO_DIRECAO.md) — apresentação do
  funcionamento lógico do sistema para a direção do CECAPE (com diagrama do fluxo).
- **Tutoriais por perfil** (com imagens das telas): [`docs/tutoriais/TUTORIAL_PROFESSOR.md`](docs/tutoriais/TUTORIAL_PROFESSOR.md),
  [`docs/tutoriais/TUTORIAL_TI.md`](docs/tutoriais/TUTORIAL_TI.md),
  [`docs/tutoriais/TUTORIAL_MB.md`](docs/tutoriais/TUTORIAL_MB.md) e
  [`docs/tutoriais/TUTORIAL_ADMIN.md`](docs/tutoriais/TUTORIAL_ADMIN.md).

Suporte: **ti.cecape@scseduca.com.br**
