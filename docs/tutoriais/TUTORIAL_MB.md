# Tutorial Completo — MB Estúdios

**Sistema AutoriaSCS • Gestão de Cursos** — guia passo a passo da equipe da MB
Estúdios, escrito para quem está começando no sistema.

> **Endereço:** `https://cecapescs.com.br/autoriascs/scrum/`
> **Suporte:** ti.cecape@scseduca.com.br

---

## 1. Entrar no sistema

1. Abra o navegador (Chrome, Edge ou Firefox);
2. Acesse `cecapescs.com.br/autoriascs/scrum`;
3. Digite o **e-mail** e a **senha** fornecidos pelo CECAPE e clique em **Entrar**.

A sessão dura 8 horas e se renova a cada clique. Para sair, use o botão **Sair**
no canto superior direito.

## 2. O papel da MB no fluxo

A MB entra em cena **duas vezes** na vida de um curso:

**Momento 1 — Inserção.** A TI aprova o curso e move para **Enviado para
Inserção** → a MB recebe um e-mail. A MB então baixa os materiais, monta o curso
na Plataforma AutoriaSCS e registra o avanço no sistema.

**Momento 2 — Publicação.** Depois que o formador valida o curso montado e a TI
agenda a data (**Pronto para Publicação**), a MB recebe novo e-mail com a data de
entrada. No dia, publica o curso na plataforma e marca **Publicado** no sistema.

**Todas as movimentações da MB:**

| De | Para | Significado |
|---|---|---|
| Enviado para Inserção | Em Inserção | "Começamos a montar o curso" |
| Em Inserção | Inserido | "Curso montado — formador pode conferir" |
| Pronto para Publicação | Publicado | "Curso publicado na plataforma" |

Ao marcar **Inserido**, o formador e a TI recebem e-mail automático para iniciar a
validação. Ao marcar **Publicado**, o formador recebe a confirmação. Você não
precisa avisar ninguém por fora.

## 3. Encontrar os cursos que esperam a MB

No **Dashboard**, o quadro Kanban mostra todas as colunas do fluxo. Os cursos da
sua alçada estão nas colunas **MB Estúdio** (Enviado para Inserção, Em Inserção,
Inserido) e **Publicado** (Pronto para Publicação):

![Quadro Kanban](img/kanban.png)

Clique no botão **Abrir** do cartão para entrar na página do curso.

## 4. Mover o status do curso

Na página do curso, quadro **Ações de Status**:

![Ações de status da MB](img/mb-acoes.png)

1. A lista suspensa mostra **apenas** a movimentação permitida à MB naquele momento
   (ex.: "Em Inserção");
2. Se quiser, escreva uma observação no campo ao lado;
3. Clique em **Atualizar Status** e confirme na janelinha que aparece.

## 5. Baixar os materiais — um único pacote, já na ordem certa

Na página do curso, seção **Arquivos do Curso**, a MB usa o botão verde:

![Botão Baixar todos](img/mb-zip.png)

Clique nele e o navegador baixa **um único arquivo ZIP** com todos os materiais.
Ao extrair (clique duplo no arquivo baixado, ou botão direito → *Extrair tudo*),
as pastas já vêm numeradas **na ordem exata de inserção na plataforma**:

```
00 - Geral/01 - Apresentação do(s) Formador(es) - arquivo.pdf
00 - Geral/02 - Apresentação do Curso - arquivo.pdf
00 - Geral/03 - Objetivos - arquivo.pdf
01 - Módulo 1/01 - Apresentação do Módulo - arquivo.pdf
01 - Módulo 1/02 - Slide - arquivo.pptx
01 - Módulo 1/03 - Vídeo - arquivo.mp4
01 - Módulo 1/04 - Anexo - arquivo.zip
02 - Módulo 2/...
```

**Como usar:** insira na plataforma seguindo a numeração — primeiro a pasta
`00 - Geral` inteira, depois `01 - Módulo 1` na ordem dos arquivos, e assim por
diante.

A lista na tela segue a mesma ordem, para conferência:

![Arquivos do curso na ordem de entrega](img/mb-arquivos.png)

**Bom saber:**

- Categorias que o formador declarou como **"sem material"** (Texto Complementar,
  Atividade Avaliativa) simplesmente não existem no pacote — não é erro, não
  procure por elas;
- **Vídeos brutos e arquivos grandes** (acima de 25MB) ficam na seção
  **Links Externos** da mesma página — clique no título do link para abrir o
  Google Drive;
- Cada download em pacote fica registrado no sistema (auditoria).

## 6. O que a conta MB não faz

Para proteger o fluxo, o perfil MB **não tem** estas opções (elas nem aparecem na
tela):

- **Enviar arquivos** — o formulário de upload é exclusivo do formador;
- **Download avulso de um arquivo só** — o material desce sempre completo, pelo
  ZIP, garantindo a ordem;
- **Editar curso, checklist ou apontamentos** — produção é do formador, revisão é
  da TI.

## 7. Problemas comuns

**O botão "Baixar todos" não aparece.**
O curso ainda não tem arquivos enviados. Aguarde o formador concluir os envios
(em geral o curso só chega à MB depois disso).

**Não consigo mover o status.**
Confira a fase atual do curso: a MB só move nas três transições da tabela da
seção 2. Se o curso está em outra fase, a ação é de outra equipe.

**Esqueci a senha.**
Escreva para ti.cecape@scseduca.com.br pedindo a redefinição.
