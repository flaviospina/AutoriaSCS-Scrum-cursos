# AutoriaSCS • Gestão Scrum/Kanban da Produção de Cursos

Sistema web (PHP + MySQL + Bootstrap 5) do CECAPE / Rede Municipal de São Caetano do Sul para
acompanhar todo o ciclo de produção dos cursos da **Plataforma AutoriaSCS** — da proposta do(a)
formador(a) até a publicação — com metodologia **Scrum/Kanban**, conforme o *Guia 01 de Orientação
para Formadores*.

## Perfis de acesso

| Perfil | O que faz |
|---|---|
| `PROFESSOR` (formador) | Propõe e edita seus cursos, preenche checklist, envia arquivos, **analisa e aprova os vídeos da MB**, move seus status permitidos |
| `TI` | **Aprova o projeto e define a carga horária**, revisão técnica/pedagógica, apontamentos, aprovação/recusa, Kanban completo, relatórios |
| `MB` | **Produz e disponibiliza os vídeos para análise**, acompanha e move os status de inserção na plataforma (MB Estúdios) |
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
- **Níveis de ensino** (`admin/niveis.php`) e **Categorias de entrega** (`admin/categorias.php`) — V10:
  cadastros administráveis (criar, renomear, reordenar, obrigatória/opcional, ativar/inativar).
  Renomear atualiza cursos/arquivos vinculados; itens em uso não podem ser excluídos, apenas inativados.
- **Checklists** (`admin/checklists.php`) — V11: itens do Checklist do Professor e do Checklist TI/Admin.
- **Alternar visualização** (seletor "Ver como" no cabeçalho) — V11: o ADMIN vê o sistema como outro
  perfil; validado no backend, auditado e exclusivo do ADMIN (403 para os demais).
- **Exclusão protegida de etapas** (status e categorias) — V11: bloqueada quando algum curso já concluiu a
  etapa; com dados vinculados exige dupla confirmação; soft delete com opção de restaurar.
- **Diagnóstico do sistema** (`admin/diagnostico_sistema.php`): confere arquivos, migrações e OPcache.
- **Zerar dados** (`admin/zerar_dados.php`, V13): limpeza da base para a entrada em produção. Apaga cursos,
  entregas, vídeos, apontamentos, checklists respondidos, coautores e histórico (e, opcionalmente, fila de
  e-mails, auditoria, modelos e usuários não administradores). Preserva colunas, status, transições, perfis,
  escolas, níveis, categorias, itens de checklist e os administradores. Exige a senha do ADMIN e a frase
  `ZERAR DADOS`; antes de apagar gera `storage/backups/reset-<data>/dados.sql` (reimportável no phpMyAdmin)
  e **move** `storage/cursos` e `storage/videos` para a mesma pasta — apague-a pelo cPanel depois.
  Alternativa manual: `database/zerar_dados.sql`.

## Ajustes V11 (PROMPT MESTRE)

- **Editar Curso**: unidade escolar, prioridade, previsões e carga horária só são alteradas por TI/ADMIN
  (o formador as vê como rótulos; o backend recusa alterações com 403 e audita antes/depois).
- **Entrega de materiais em ordem livre**; todos os documentos **obrigatórios** precisam estar entregues
  para o curso entrar em um status com "Exige entregas" (padrão: Pronto para Análise / Nova Análise).
  A tentativa bloqueada mostra a lista do que falta (SweetAlert) e envia e-mail ao(s) professor(es) e à
  TI com cooldown de 6h por curso. O **vídeo** de um módulo só é aceito após a **aprovação do slide** pela TI.
- **Checklists por perfil**: o formador vê o Checklist do Professor; TI vê o Checklist TI/Admin; ADMIN vê ambos.
- **Apontamentos TI/Qualidade**: arquivo relacionado, status (Pendente de análise → Correção solicitada →
  Em correção pelo professor → Reenviado para análise → Aprovado → Concluído), histórico (linha do tempo),
  resposta formal do professor (**Concordo** / **Não concordo — objeção com justificativa**), e-mail a cada
  mudança para professor(es) + ti.cecape, e aviso destacado enquanto houver pendência. Nada é apagado.
- **Mais de um professor por curso**: responsável (`tb_cursos.id_professor`) + coautores
  (`tb_curso_professores`), que acessam o curso, recebem os e-mails e compõem a identificação oficial.

## Slides no padrão AutoriaSCS (V15)

- **Ferramenta** (`public/slides_padrao.php`, botão "Analisar / converter apresentação" na página do curso;
  professores do curso e equipe; MB não envia): o(a) professor(a) envia a apresentação do módulo **sem
  formatação** (.pptx) e informa o módulo e o seu nome. O sistema lê o arquivo (`app/slides_repo.php`:
  ZipArchive + DOM), aplica as regras e devolve o **laudo** item a item (ERRO impede, AVISO recomenda, INFO).
- **Regras:** proibidos vídeo/áudio embutido e link para vídeo; cada slide com título; até 120 palavras
  (aviso a partir de 80) e o texto **precisa caber no espaço livre** (16 pt, senão 14 pt, senão erro, com a
  sugestão de quantas palavras cabem); imagem JPG/PNG até 2 MB, mínimo 800 px (aviso), sempre com legenda
  ("Figura N – Título. Fonte: AUTOR, ano."); 6 a 30 slides; animações/transições removidas; a capa do
  professor é substituída pela capa padrão (nome do curso + "Módulo N: nome").
- **Saída (se aprovada):** PDF 16:9 (960×540 pt) com os fundos do modelo oficial (`app/slides/bg`), Comfortaa
  (TTF em `app/slides/fonts`, tFPDF em `app/lib/tfpdf`), título negrito #058285 (20/18 pt), texto #000000
  (16/14 pt), legenda 10 pt, abertura e encerramento com as logos; e **PPTX editável** gerado a partir de
  `app/slides/modelo.pptx` (layouts oficiais). O botão "Registrar como entrega" grava o PDF como entrega
  **Slide** do módulo (mesma regra de aprovação da TI). Análises ficam em `storage/cursos/<id>/slides_padrao/`.
- Não há migração de banco.

## Indicadores gerenciais e relatórios (V14)

- **Indicadores** (`public/indicadores.php`, menu *Indicadores*; TI/MB/ADMIN): painel com mais de 60 KPIs em
  9 grupos — produção de cursos, fluxo Kanban e tempos (lead time, etapa mais demorada, WIP, parados), prazos,
  formadores e equipe, qualidade (apontamentos, manifestações, recusas), materiais, vídeos, checklists e sistema —
  com gráficos (produção mensal, cursos por etapa, apontamentos por status/tipo, carga horária, entregas) e,
  em cada indicador, o link **Ver relatório →** para o relatório que o detalha.
- **Relatórios** (`public/relatorios.php`): 25 relatórios por grupo, com filtros (período, status, formador,
  escola, nível, prioridade, carga, situação, janela de dias, tipo, agrupamento), tabela com link para o registro,
  **Exportar CSV** (Excel, `;` e UTF-8 com BOM; auditado) e **Imprimir**.
- Lógica em `app/indicadores_repo.php` (`ind_todos()`, `ind_graficos()`, `rel_catalogo()`, `rel_gerar()`).
  Não há migração de banco: usa as tabelas existentes e tolera as ainda não migradas.

## Coautores: TI/ADMIN elegíveis e e-mails de inclusão (V13)

- A lista "Adicionar professor(a)" (página do curso e proposta) passa a incluir, além dos formadores,
  os usuários com perfil que revisa cursos (TI) e os administradores, identificados pelo perfil entre parênteses.
- Ao incluir coautores pela página do curso, o sistema aguarda um tempo após a última inclusão
  (**padrão 20 s, ajustável pelo ADMIN em Admin → Notificações**, de 5 a 600 s; contagem regressiva visível
  para quem está incluindo; cada nova inclusão reinicia a contagem) e então envia,
  de uma vez: um e-mail personalizado a cada coautor(a) incluído(a) e **um único** e-mail ao(à) responsável
  com todos os nomes. A página exibe "E-mails enviados" ao concluir.
- Os e-mails de inclusão são **prioritários**: vão na hora mesmo que o(a) coautor(a) tenha escolhido
  "resumo diário" ou "desativado" em Meu Perfil (aviso pessoal e direto).
- Na proposta de curso os coautores escolhidos são comunicados imediatamente (envio único).
- Retaguarda: se quem incluiu sair da página antes dos 20 s, o cron de notificações envia os pendentes
  (coautores com mais de 2 minutos sem aviso).
- Migração: `database/upgrade_v13.sql` (colunas `adicionado_por` e `notificado_em` em `tb_curso_professores`;
  tabela `tb_config` com o parâmetro `coautor_espera_seg`;
  coautores já existentes são marcados como comunicados e **não** recebem e-mail retroativo).

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

## Aprovação do projeto e carga horária (V9)

No **Backlog** o formador apresenta o projeto do curso; a **equipe de TI aprova e define a
carga horária oficial** movendo o curso de `Curso Proposto` → `Projeto Aprovado`
(a carga horária é obrigatória nessa transição). Ao aprovar, a **MB Estúdios e a TI**
recebem e-mail com a **identificação oficial do curso**:

```
Nome do curso - Nome dos formadores - Carga Horária do curso
```

Essa identificação aparece na página do curso e nomeia o pacote ZIP entregue à MB.
Só depois da aprovação o formador pode seguir para `Em Planejamento`.

## Revisão de Vídeos (V8/V9)

Ferramenta integrada de análise de vídeos (`🎬 Vídeos` na página do curso). Quem produz é a
**MB Estúdios**; quem analisa é o(a) **formador(a)**:

- a **MB disponibiliza** o vídeo (MP4, até 512MB) com a **descrição exata do material**;
  o formador recebe e-mail com essa descrição e o link do vídeo já liberado na plataforma;
- o **formador assiste dentro do sistema** (streaming com suporte a seek) e registra
  **marcações no ponto exato** (minuto/segundo/frame) com classificação (áudio, imagem,
  conteúdo, acessibilidade, edição, identidade visual, erro técnico), orientação de
  correção e **captura automática do frame**;
- a **MB vê os apontamentos**, **responde** e marca o andamento (Pendente → Em correção →
  Corrigido); disponibiliza a **nova versão** pelo sistema;
- **controle de versões** com histórico completo das análises e comparação entre versões;
- **aprovação final pelo(a) formador(a)** quando todas as correções estiverem concluídas;
- e-mails automáticos a cada evento (vídeo disponível, novos apontamentos, resposta,
  nova versão, aprovação).

> Vídeos grandes: ajuste no servidor `upload_max_filesize` e `post_max_size`
> (ex.: `512M`) no cPanel → *MultiPHP INI Editor*.

### Vídeos por link do Google Drive (V12)

Para vídeos acima de 512MB (a maioria passa de 1GB), a MB Estúdios informa o **link do
arquivo no Google Drive** em vez de enviá-lo. O sistema não copia o vídeo para o servidor:

- **Com a conta de serviço configurada** (recomendado): o link é validado na hora (nome,
  tamanho, tipo) e o vídeo é transmitido ao navegador pela Drive API em trechos (`video_stream.php`),
  com o **mesmo player** — seek, "◉ agora", frame e captura funcionam normalmente.
- **Sem a conta de serviço** (contingência): o vídeo abre no player do Google (iframe) e o
  formador informa o minuto/segundo manualmente (sem captura de frame).

Configuração (uma vez, ~15 min):
1. `console.cloud.google.com` → criar projeto → **Ativar a Google Drive API**;
2. **IAM → Contas de serviço → Criar** (ex.: `autoriascs-videos`) → **Chaves → Adicionar chave JSON**;
3. salvar o JSON em `app/keys/drive.json` (fora de `public/`; a pasta é ignorada pelo git);
4. a MB compartilha a pasta dos vídeos, como **Leitor**, com o e-mail da conta de serviço
   (`client_email` do JSON — também exibido em *Admin → Diagnóstico do sistema*).
   Compartilhar "com o domínio" não funciona: o servidor precisa de uma identidade própria.
5. Executar `database/upgrade_v12.sql`.

## Instalação

1. Crie o banco e execute `database/schema.sql` (instalação nova). Migrações a partir de banco
   antigo, na ordem: `upgrade_v2.sql` (fluxo dinâmico), `upgrade_v3.sql` (auditoria/e-mails/modelos),
   `upgrade_v4.sql` (perfis dinâmicos), `upgrade_v5.sql` (etapa Pronto para Publicação),
   `upgrade_v6.sql` (cadastro de escolas), `upgrade_v7.sql` (fluxo ordenado de entrega
   de materiais), `upgrade_v8.sql` (revisão de vídeos), `upgrade_v9.sql` (aprovação do
   projeto com carga horária + inversão do fluxo de vídeos), `upgrade_v10.sql` (cadastros de
   níveis de ensino e categorias de entrega) e `upgrade_v11.sql` (ajustes do PROMPT MESTRE:
   checklists por perfil, entrega livre/aprovação de slide, apontamentos com status e histórico,
   coautores, exclusão protegida) e `upgrade_v12.sql` (vídeos por link do Google Drive) — faça backup antes.
2. Copie `app/config.php` para `app/config.local.php` e preencha as credenciais reais do banco,
   a seção `mail` (método `mail` do cPanel ou `smtp`) e a `cron.chave`
   (o arquivo local é ignorado pelo git).
3. Publique o projeto no servidor (o *document root* deve apontar para `public/`).
4. Garanta permissão de escrita em `storage/cursos/`, `storage/modelos/` e `storage/videos/`.
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
- **Vídeo tutorial do formador** (2 min, Full HD): [`docs/tutoriais/video/`](docs/tutoriais/video/) —
  com [roteiro de narração](docs/tutoriais/video/ROTEIRO_NARRACAO.md) para gravar a locução.
- **Apresentações em slides** (PPTX) para treinamento, uma por perfil, em `docs/tutoriais/`.
- **Tutoriais HTML responsivos (V12, atuais)** — link **Ajuda** no menu do sistema (`public/tutorial.php`).
  Somente o ADMIN vê o índice e os quatro tutoriais; os demais perfis veem apenas o do seu nível de acesso
  (professor/formador, TI, MB). Conteúdo em `app/tutoriais/*.html`, imagens em `storage/tutoriais/img`
  (servidas com login por `tutorial_img.php`) e PDFs pré-gerados em `storage/tutoriais/pdf` (botão
  **Exportar PDF** → `tutorial_pdf.php`); o botão **Imprimir** usa o CSS de impressão de `public/tutoriais/tutorial.css`.
- Tutoriais antigos em Markdown (fluxo V9, mantidos para referência): [`docs/tutoriais/TUTORIAL_PROFESSOR.md`](docs/tutoriais/TUTORIAL_PROFESSOR.md),
  [`docs/tutoriais/TUTORIAL_TI.md`](docs/tutoriais/TUTORIAL_TI.md),
  [`docs/tutoriais/TUTORIAL_MB.md`](docs/tutoriais/TUTORIAL_MB.md) e
  [`docs/tutoriais/TUTORIAL_ADMIN.md`](docs/tutoriais/TUTORIAL_ADMIN.md).

Suporte: **ti.cecape@scseduca.com.br**
