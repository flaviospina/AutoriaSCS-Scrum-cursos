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

## Instalação

1. Crie o banco e execute `database/schema.sql` (instalação nova) **ou**
   `database/upgrade_v2.sql` (banco da versão anterior — faça backup antes).
2. Copie `app/config.php` para `app/config.local.php` e preencha as credenciais reais
   (o arquivo local é ignorado pelo git).
3. Publique o projeto no servidor (o *document root* deve apontar para `public/`).
4. Garanta permissão de escrita em `storage/cursos/`.
5. Acesse com o usuário inicial `admin@scseduca.com.br` / `admin123` e **troque a senha**.

## Estrutura

```
app/        código de domínio (auth, cursos, kanban dinâmico, csrf, n8n)
public/     páginas (dashboard kanban/tabela, curso, apontamentos, relatórios)
public/admin/  área administrativa (ADMIN)
database/   schema.sql (novo) e upgrade_v2.sql (migração)
storage/    uploads dos cursos (fora do webroot lógico; download via download.php)
docs/       análise completa do sistema e roadmap de funcionalidades
```

## Documentação

- [`docs/ANALISE_E_PROPOSTAS.md`](docs/ANALISE_E_PROPOSTAS.md) — análise completa do sistema,
  pesquisa de boas práticas e roadmap de novas funcionalidades.

Suporte: **ti.cecape@scseduca.com.br**
