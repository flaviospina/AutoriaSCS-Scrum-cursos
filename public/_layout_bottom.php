</main>

<footer class="site-footer mt-auto">
  <div class="container d-flex flex-wrap justify-content-center gap-2 text-center">
    <span>AutoriaSCS / CECAPE • Acompanhamento Scrum/Kanban da Produção de Cursos</span>
    <span>•</span>
    <span>Suporte: ti.cecape@scseduca.com.br</span>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
  if (typeof Swal === 'undefined') return; // CDN indisponível: formulários seguem funcionando sem confirmação

  var SWAL_BG = '#0f2044';
  var SWAL_FG = '#e8edf5';

  /**
   * 1) Confirmação SweetAlert em qualquer formulário com data-confirm.
   *    Atributos opcionais:
   *      data-confirm       -> texto da pergunta
   *      data-confirm-title -> título (padrão: "Confirmar operação")
   *      data-confirm-btn   -> rótulo do botão (padrão: "Sim, confirmar")
   *      data-confirm-type  -> "danger" para ações destrutivas (ícone/cor de alerta)
   */
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (form.dataset.confirmed === '1') return; // já confirmado — envia de verdade
      e.preventDefault();
      var perigo = form.dataset.confirmType === 'danger';
      var submitter = e.submitter || null;

      Swal.fire({
        title: form.dataset.confirmTitle || 'Confirmar operação',
        html: form.dataset.confirm || 'Deseja continuar?',
        icon: perigo ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: form.dataset.confirmBtn || 'Sim, confirmar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: perigo ? '#ef4444' : '#06b6d4',
        cancelButtonColor: '#374151',
        background: SWAL_BG,
        color: SWAL_FG,
        reverseButtons: true,
        focusCancel: perigo
      }).then(function (r) {
        if (!r.isConfirmed) return;
        form.dataset.confirmed = '1';
        if (form.requestSubmit) {
          submitter ? form.requestSubmit(submitter) : form.requestSubmit();
        } else {
          form.submit();
        }
      });
    });
  });

  /**
   * 1b) Anti duplo clique: ao enviar de verdade, os botões do formulário são
   *     desabilitados (evita operações duplicadas por cliques repetidos).
   */
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function () {
      if ((form.hasAttribute('data-confirm') || form.hasAttribute('data-etapa-regra')) && form.dataset.confirmed !== '1') return; // ainda vai confirmar
      setTimeout(function () {
        form.querySelectorAll('button[type=submit], button:not([type]), input[type=submit]').forEach(function (b) { b.disabled = true; });
      }, 0);
    });
  });

  /**
   * 1c) Exclusão protegida de etapas (status do fluxo / categorias de entrega — item 6).
   *     data-etapa-regra: "1" = etapa concluída por curso (bloqueia, só OK);
   *                       "2" = possui dados, não concluída (duas confirmações);
   *                       "0" = sem dados (confirmação simples). O backend aplica as mesmas regras.
   */
  document.querySelectorAll('form[data-etapa-regra]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (form.dataset.confirmed === '1') return;
      e.preventDefault(); e.stopImmediatePropagation();
      var regra = form.dataset.etapaRegra, nome = form.dataset.etapaNome || 'esta etapa';
      var go = function () { form.dataset.confirmed = '1'; form.requestSubmit ? form.requestSubmit() : form.submit(); };
      var base = { background: SWAL_BG, color: SWAL_FG, cancelButtonColor: '#374151', reverseButtons: true };
      if (regra === '1') {
        Swal.fire(Object.assign({}, base, { icon: 'error', title: 'Não é possível excluir esta etapa',
          html: 'Esta etapa já foi concluída por um ou mais cursos e faz parte do histórico do sistema.',
          confirmButtonText: 'OK', confirmButtonColor: '#06b6d4' }));
        return;
      }
      if (regra === '2') {
        Swal.fire(Object.assign({}, base, { icon: 'warning', title: 'Atenção',
          html: 'Esta etapa possui cursos ou informações vinculadas. A exclusão poderá remover informações relacionadas a esta etapa.<br><br><b>Deseja continuar?</b>',
          showCancelButton: true, confirmButtonText: 'Continuar', cancelButtonText: 'Cancelar', confirmButtonColor: '#f59e0b', focusCancel: true
        })).then(function (r) {
          if (!r.isConfirmed) return;
          Swal.fire(Object.assign({}, base, { icon: 'error', title: 'Confirmar exclusão',
            html: 'Esta operação poderá excluir dados relacionados à etapa e não poderá ser desfeita.<br><br><b>Tem certeza de que deseja excluir?</b>',
            showCancelButton: true, confirmButtonText: 'Excluir definitivamente', cancelButtonText: 'Cancelar', confirmButtonColor: '#ef4444', focusCancel: true
          })).then(function (r2) { if (r2.isConfirmed) go(); });
        });
        return;
      }
      Swal.fire(Object.assign({}, base, { icon: 'warning', title: 'Excluir ' + nome + '?',
        html: 'Esta etapa não possui dados vinculados.', showCancelButton: true,
        confirmButtonText: 'Sim, excluir', cancelButtonText: 'Cancelar', confirmButtonColor: '#ef4444', focusCancel: true
      })).then(function (r) { if (r.isConfirmed) go(); });
    });
  });

  /**
   * 2) Mensagens de resultado do servidor viram toasts SweetAlert.
   *    (alerts .alert-success/.alert-danger do conteúdo principal;
   *     avisos informativos .alert-info/.alert-warning permanecem na página)
   */
  function toastFrom(el, icon) {
    var text = (el.textContent || '').trim();
    if (!text || text.length > 220) return; // mensagens longas permanecem na página
    el.classList.add('d-none');
    Swal.fire({
      toast: true,
      position: 'top-end',
      icon: icon,
      title: text,
      timer: 4500,
      timerProgressBar: true,
      showConfirmButton: false,
      background: SWAL_BG,
      color: SWAL_FG
    });
  }
  document.querySelectorAll('main > .alert-success, main > .row .alert-success').forEach(function (el) {
    if (!el.closest('.modal')) toastFrom(el, 'success');
  });
  document.querySelectorAll('main > .alert-danger, main > .row .alert-danger').forEach(function (el) {
    if (!el.closest('.modal')) toastFrom(el, 'error');
  });
})();
</script>
</body>
</html>
