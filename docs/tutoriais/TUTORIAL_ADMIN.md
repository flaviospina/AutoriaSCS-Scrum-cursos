# Tutorial Completo — Administrador(a)

**Sistema AutoriaSCS • Gestão de Cursos** — guia passo a passo da área
administrativa. O perfil ADMIN pode tudo o que os outros perfis podem e, além
disso, configura o próprio funcionamento do sistema.

> **Endereço:** `https://cecapescs.com.br/autoriascs/scrum/`
> **Suporte:** ti.cecape@scseduca.com.br

---

## 1. O menu Admin

Depois de entrar, o menu **Admin** aparece no topo (somente para administradores).
Clique nele para abrir as opções:

![Menu Admin](img/admin-menu.png)

A primeira opção, **Visão geral**, mostra um painel com cartões — cada cartão traz
um contador e o botão **Gerenciar** que leva à tela correspondente:

![Cards da administração](img/admin-cards.png)

Vamos ver cada tela, na ordem do menu.

## 2. Colunas do Kanban

**O que é:** as colunas verticais do quadro (Backlog, Planejamento, Produção...).

**O que dá para fazer:**

- **Adicionar** coluna nova: preencha nome, escolha a cor de fundo do cabeçalho e
  clique em salvar;
- **Renomear** e **trocar a cor**: edite os campos na própria linha e salve;
- **Reordenar**: setas ↑ ↓ movem a coluna para a esquerda/direita do quadro;
- **Limite WIP**: número máximo recomendado de cursos na coluna (o contador fica
  vermelho quando passa). Vazio = sem limite;
- **Ativar/desativar**: coluna desativada some do quadro sem perder os dados.

**Efeito:** qualquer mudança vale **na hora** para todos os usuários.

## 3. Status

**O que é:** as fases que os cursos percorrem (Curso Proposto, Em Revisão...).
Cada status pertence a uma coluna do Kanban.

**O que dá para fazer:**

- **Criar** status: nome, coluna onde aparece, cor da etiqueta (badge);
- **Editar/mover** entre colunas e **reordenar** dentro da coluna;
- **Status inicial** (bolinha marcada): é o status que todo curso novo recebe —
  só pode haver um;
- **Status finais**: marcam o fim do fluxo (ex.: Publicado);
- **Renomear**: o sistema atualiza automaticamente todos os cursos e o histórico —
  não quebra nada;
- **Excluir**: só é permitido se nenhum curso estiver naquele status (caso
  contrário, desative ou renomeie).

## 4. Transições por perfil

**O que é:** a tabela que define **quem pode mover o quê**. Cada linha diz:
o perfil X pode mover um curso do status A para o status B.

**Como adicionar:** escolha o perfil, o status de origem e o de destino nas três
listas suspensas e clique em adicionar. Para remover, clique no botão de excluir
da linha.

**Atenção:** é aqui que o fluxo "trava ou destrava". Se um formador reclama que
não consegue mover um curso, confira se a transição existe para o perfil
PROFESSOR. O ADMIN não precisa de transição — pode tudo.

**Fases especiais (com campo obrigatório):**

| Transição | Quem faz | Campo exigido |
|---|---|---|
| Curso Proposto → **Projeto Aprovado** | TI | Carga horária do curso (10/20/30/40 h) |
| Validado → **Pronto para Publicação** | TI | Data de entrada na plataforma |

Ao aprovar o projeto, o sistema monta a **identificação oficial do curso**
(*nome do curso - formador(a) - carga horária*) e comunica por e-mail à MB Estúdios e
à TI. Essa identificação nomeia a pasta do curso e o pacote ZIP dos materiais.

> **Socorro rápido:** se as transições ficarem bagunçadas (por testes, por
> exemplo), rode o script `database/reset_transicoes.sql` no phpMyAdmin — ele
> restaura o fluxo oficial completo.

## 5. Usuários

**O que é:** o cadastro de todas as pessoas que acessam o sistema.

![Cadastro de usuários](img/admin-usuarios.png)

**Para cadastrar alguém:**

1. **Nome** — nome completo (é o que aparece nos cartões e e-mails);
2. **E-mail** — será o login da pessoa;
3. **Perfil** — lista suspensa: PROFESSOR, TI, MB, ADMIN (e perfis personalizados,
   se existirem);
4. **Senha** — defina uma senha inicial e informe à pessoa (oriente a trocar no
   primeiro acesso, em Meu Perfil);
5. Clique em **Criar**.

**Na lista:** cada linha tem **Editar** (nome, e-mail, perfil, ativo),
**Redefinir senha** e a chave **Ativo** — desativar impede o login sem apagar o
histórico da pessoa. O campo de busca aceita digitar ou escolher o nome na lista.

## 6. Perfis de acesso

**O que é:** os "tipos de usuário" e o que cada um pode fazer. Além dos quatro
padrão, você pode criar outros (ex.: COORDENADOR só-leitura).

**Para criar um perfil:** informe código (ex.: COORDENADOR), nome e descrição, e
marque as permissões:

| Permissão | O que libera |
|---|---|
| Administração total | Tudo, inclusive esta área Admin |
| Vê todos os cursos | Kanban completo (senão, só os próprios) |
| Propõe cursos | Botão "Propor Novo Curso" e envio de arquivos |
| Move o Kanban | Arrastar cartões / Ações de Status |
| Revisa cursos | Aprovar, recusar com relatório, apontamentos |
| Gerencia modelos | Publicar na Biblioteca de Modelos |
| Recebe e-mail de revisão | Avisos de cursos aguardando análise |
| Recebe e-mail de inserção | Avisos das etapas de inserção/publicação |

O perfil ADMIN é protegido — o sistema impede alterações que travariam o acesso.

## 7. Escolas — cadastro em lote

**O que é:** a lista de unidades escolares que alimenta o autocompletar dos
formulários (proposta de curso, filtros).

![Cadastro de escolas em lote](img/admin-escolas.png)

**Para cadastrar várias de uma vez:**

1. Cole na caixa de texto a lista de escolas, **uma por linha** (pode colar direto
   de uma planilha);
2. Clique em **Cadastrar todas**;
3. O sistema insere tudo de uma vez e informa quantas entraram e quantas eram
   repetidas (repetidas são ignoradas, sem erro).

**Na lista ao lado:** busca (com autocompletar), **Renomear** (atualiza também os
cursos que usam o nome antigo), **Desativar** (some do autocompletar sem apagar) e
**Excluir** (só se nenhum curso estiver vinculado).

## 8. Auditoria — quem fez o quê

**O que é:** o registro automático de todas as ações do sistema: login, criação e
edição de cursos, mudanças de status, uploads, downloads, exclusões, declarações
"sem material" — tudo, com data/hora, usuário, valores antes/depois e endereço IP.

![Filtros da auditoria](img/admin-auditoria.png)

**Como pesquisar:** combine os filtros — **Usuário** (digite ou escolha na lista),
**Ação**, **Entidade**, período **De/Até** — e clique em Filtrar.
**Exportar CSV** baixa o resultado para abrir no Excel.

## 9. Notificações — a fila de e-mails

**O que é:** o monitor dos e-mails do sistema. Cada aviso enviado aparece aqui com
o status: **ENVIADO**, **PENDENTE** (aguardando reenvio) ou **ERRO**.

**Como funciona o envio:** os e-mails saem **na hora** da ação. Se algum falhar,
o cron de 5 minutos tenta de novo automaticamente. Nesta tela você também pode
processar a fila manualmente (botão de processar) e ver o erro exato de cada
mensagem que falhou.

**Configuração no servidor (cPanel → Cron Jobs):**

- A cada 5 min: `php /home/USUARIO/.../cron/cron_notificacoes.php CHAVE`
- Diário às 7h: `php /home/USUARIO/.../cron/cron_prazos.php CHAVE`
  (alertas de prazo + resumo semanal às segundas)

## 10. Vídeos: quem faz o quê

A tela **🎬 Vídeos** de cada curso segue esta divisão de papéis (o ADMIN pode tudo):

| Ação | Quem faz |
|---|---|
| Disponibilizar o vídeo (com descrição obrigatória) e novas versões | MB Estúdios |
| Assistir, marcar os pontos com problema e aprovar o vídeo | Formador(a) do curso |
| Responder apontamentos e marcar Em correção / Corrigido | MB Estúdios |
| Acompanhar e receber cópia dos e-mails | Equipe TI |

Os arquivos ficam em `storage/videos/` (fora da pasta pública) e as capturas de frame em
`storage/videos/<id_curso>/capturas/`. Para vídeos grandes, confira no cPanel →
**MultiPHP INI Editor** se `upload_max_filesize` e `post_max_size` estão em `512M`.

## 11. Excluir arquivos enviados por engano

Somente o ADMIN pode excluir um arquivo que o formador enviou errado. Abra o curso,
seção **Arquivos do Curso** — cada linha tem o botão vermelho **Excluir**:

![Botão Excluir na lista de arquivos](img/admin-excluir.png)

1. Clique em **Excluir** na linha do arquivo errado;
2. Confirme na janela (a ação é registrada na auditoria);
3. O arquivo é apagado do servidor e a **sequência de entregas é recalculada** —
   se era o único da categoria, ela volta a ser a etapa pendente do formador.

## 12. Rotina e boas práticas do administrador

- **Backup do banco** antes de qualquer migração (`database/upgrade_v*.sql`) —
  no cPanel: phpMyAdmin → Exportar;
- **Troque a senha padrão** do usuário inicial (`admin@scseduca.com.br`) no
  primeiro acesso;
- **Revise usuários** periodicamente: desative quem saiu da rede;
- **Não teste em produção**: mudanças em colunas/status/transições valem
  imediatamente para todos. Se precisar experimentar, combine com a equipe;
- **Acompanhe a Auditoria** e a fila de **Notificações** semanalmente — erros de
  e-mail aparecem lá primeiro.
