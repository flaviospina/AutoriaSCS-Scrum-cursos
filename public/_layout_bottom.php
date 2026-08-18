</main>

<footer class="border-top py-3 bg-white">
  <div class="container small text-muted d-flex flex-wrap justify-content-between gap-2">
    <span>AutoriaSCS / CECAPE • Acompanhamento Scrum/Kanban da Produção de Cursos</span>
    <span>Suporte: ti.cecape@scseduca.com.br</span>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
  if (typeof Swal === 'undefined') return; // CDN indisponível: formulários seguem funcionando sem confirmação

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
        confirmButtonColor: perigo ? '#dc3545' : '#058285',
        cancelButtonColor: '#6c757d',
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
      showConfirmButton: false
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
