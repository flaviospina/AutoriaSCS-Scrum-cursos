# Tutorial — Equipe TI (Revisão)

Guia do sistema **AutoriaSCS • Gestão de Cursos** para a equipe de revisão técnica/pedagógica.

> Endereço: `https://cecapescs.com.br/autoriascs/scrum/` • Suporte: **ti.cecape@scseduca.com.br**

---

## 1. Quadro Kanban

A equipe enxerga **todos os cursos** no quadro Kanban (Dashboard), organizado pelas
colunas do fluxo — do Backlog à Publicação. Arraste os cartões ou use as ações da
página do curso; os filtros (formador, unidade, status, prioridade) têm
autocompletar com dados do banco.

![Quadro Kanban](img/kanban.png)

## 2. Revisar um curso

Quando o formador move o curso para **Pronto para Análise**, a TI recebe e-mail.
Abra o curso e mova para **Em Revisão**. Ao final da análise, as ações são:

![Ações da revisão](img/ti-acoes-revisao.png)

- **Aprovado** — segue para a fila da MB Estúdios;
- **Recusado - Ajustes Necessários** — de preferência use o botão
  **Recusar com relatório**, que registra os apontamentos e muda o status numa
  única operação:

![Modal de recusa com relatório](img/ti-modal-recusa.png)

Cada apontamento é tipificado (Técnico, Pedagógico, ABNT, Outro) e o formador é
notificado por e-mail com a lista completa. O acompanhamento fica em **Apontamentos**
(pendente/resolvido).

## 3. Encaminhar para a MB

Curso **Aprovado** → mova para **Enviado para Inserção**. A MB Estúdios recebe o aviso
e passa a conduzir as etapas de inserção na plataforma.

## 4. Agendar a publicação

Depois que o formador valida o curso inserido (**Validado**), a TI define a data de
entrada na plataforma movendo para **Pronto para Publicação** — a data é obrigatória
e será comunicada por e-mail ao formador e à MB:

![Data de entrada na plataforma](img/ti-data-publicacao.png)

## 5. Transições da TI

| De | Para |
|---|---|
| Pronto para Análise | Em Revisão |
| Pronto para Nova Análise | Em Revisão |
| Em Revisão | Aprovado |
| Em Revisão | Recusado - Ajustes Necessários |
| Aprovado | Enviado para Inserção |
| Validado | Pronto para Publicação |

## 6. Ferramentas de apoio

- **Modelos** — publique os templates oficiais (com versão e marcação "vigente")
  que os formadores baixam;
- **Relatórios** — visão por status, formador e cursos com prazo estourado; exportação CSV;
- **Arquivos do curso** — a lista aparece na **ordem oficial de entrega** (módulo e
  categoria), e o botão **Baixar todos (ZIP)** está disponível para conferência completa;
- **E-mails automáticos** — toda mudança de status feita por formador ou MB gera aviso
  para **ti.cecape@scseduca.com.br**, além dos alertas diários de prazo e do resumo semanal.
