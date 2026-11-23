/* ============================================================
   AutoriaSCS • Acessibilidade (V17)
   Painel flutuante: tema (escuro/claro/alto contraste), cor de fundo,
   tamanho do texto, fonte legível, espaçamento, destaque de links,
   sem animações, cursor grande, VLibras (Libras).
   Preferências: localStorage (aplicação imediata, sem "piscar") +
   servidor (prefs_ui.php) para valer em qualquer computador do usuário.
   ============================================================ */
(function () {
  'use strict';
  var CHAVE = 'autoria_a11y';
  var html = document.documentElement;
  var PADRAO = { tema: 'escuro', bg: '', fonte: 0, dislexia: false, espacamento: false, links: false, semAnimacao: false, cursor: false, libras: false };
  var prefs = carregar();

  function carregar() {
    var p = {};
    try { p = JSON.parse(localStorage.getItem(CHAVE) || '{}') || {}; } catch (e) { p = {}; }
    if (window.A11Y_PREFS && typeof window.A11Y_PREFS === 'object' && !Object.keys(p).length) p = window.A11Y_PREFS; // 1º acesso neste navegador: usa o que está no servidor
    var out = {}; for (var k in PADRAO) out[k] = (k in p) ? p[k] : PADRAO[k];
    return out;
  }
  function luminancia(hex) { if (!/^#[0-9a-f]{6}$/i.test(hex)) return 0; var r = parseInt(hex.substr(1, 2), 16), g = parseInt(hex.substr(3, 2), 16), b = parseInt(hex.substr(5, 2), 16); return (0.299 * r + 0.587 * g + 0.114 * b); }

  function aplicar() {
    var cls = ['a11y-tema-claro', 'a11y-contraste', 'a11y-fonte-1', 'a11y-fonte-2', 'a11y-fonte-3', 'a11y-dislexia', 'a11y-espacamento', 'a11y-links', 'a11y-sem-animacao', 'a11y-cursor', 'a11y-bg', 'a11y-bg-claro', 'a11y-bg-escuro'];
    cls.forEach(function (c) { html.classList.remove(c); });
    if (prefs.tema === 'claro') html.classList.add('a11y-tema-claro');
    if (prefs.tema === 'contraste') html.classList.add('a11y-contraste');
    if (prefs.bg && prefs.tema !== 'contraste') { html.classList.add('a11y-bg'); html.style.setProperty('--a11y-bg', prefs.bg); html.classList.add(luminancia(prefs.bg) > 150 ? 'a11y-bg-claro' : 'a11y-bg-escuro'); }
    else html.style.removeProperty('--a11y-bg');
    if (prefs.fonte > 0) html.classList.add('a11y-fonte-' + Math.min(3, prefs.fonte));
    if (prefs.dislexia) { html.classList.add('a11y-dislexia'); carregarFonteLegivel(); }
    if (prefs.espacamento) html.classList.add('a11y-espacamento');
    if (prefs.links) html.classList.add('a11y-links');
    if (prefs.semAnimacao) html.classList.add('a11y-sem-animacao');
    if (prefs.cursor) html.classList.add('a11y-cursor');
    if (prefs.libras) carregarLibras();
    atualizarPainel();
  }
  function salvar() {
    try { localStorage.setItem(CHAVE, JSON.stringify(prefs)); } catch (e) {}
    clearTimeout(salvar._t);
    salvar._t = setTimeout(function () {
      var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
      if (!csrf || !window.A11Y_URL) return;
      var fd = new FormData(); fd.append('csrf_token', csrf); fd.append('prefs', JSON.stringify(prefs));
      fetch(window.A11Y_URL, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
    }, 600);
  }
  function set(k, v) { prefs[k] = v; aplicar(); salvar(); }

  function carregarFonteLegivel() {
    if (document.getElementById('a11yFonte')) return;
    var l = document.createElement('link'); l.id = 'a11yFonte'; l.rel = 'stylesheet';
    l.href = 'https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap';
    document.head.appendChild(l);
  }
  function carregarLibras() {
    if (document.getElementById('a11yLibras')) return;
    // o script roda no <head>: sem <body> ainda, adia para o DOM pronto (antes isso quebrava e o botão sumia)
    if (!document.body) { document.addEventListener('DOMContentLoaded', carregarLibras); return; }
    var d = document.createElement('div'); d.setAttribute('vw', ''); d.className = 'enabled';
    d.innerHTML = '<div vw-access-button class="active"></div><div vw-plugin-wrapper><div class="vw-plugin-top-wrapper"></div></div>';
    document.body.appendChild(d);
    var s = document.createElement('script'); s.id = 'a11yLibras'; s.src = 'https://vlibras.gov.br/app/vlibras-plugin.js';
    s.onload = function () { try { new window.VLibras.Widget('https://vlibras.gov.br/app'); } catch (e) {} };
    document.body.appendChild(s);
  }
  function removerLibras() { var d = document.querySelector('[vw]'); if (d) d.remove(); var s = document.getElementById('a11yLibras'); if (s) s.remove(); }

  // ── painel ────────────────────────────────────────────────
  var CORES = [['', 'Padrão do tema'], ['#0b1628', 'Azul-marinho'], ['#000000', 'Preto'], ['#1f2937', 'Grafite'], ['#0f3d3e', 'Verde-escuro'], ['#f5f0e6', 'Bege'], ['#e8f1fb', 'Azul-claro'], ['#ffffff', 'Branco']];
  var painel, botao;
  function montar() {
    botao = document.createElement('button');
    botao.type = 'button'; botao.className = 'a11y-btn'; botao.setAttribute('aria-label', 'Abrir opções de acessibilidade'); botao.setAttribute('aria-expanded', 'false'); botao.setAttribute('aria-controls', 'a11yPainel'); botao.title = 'Acessibilidade (Alt+A)';
    botao.innerHTML = '&#9855;';
    painel = document.createElement('section');
    painel.id = 'a11yPainel'; painel.className = 'a11y-painel'; painel.hidden = true; painel.setAttribute('role', 'dialog'); painel.setAttribute('aria-label', 'Opções de acessibilidade');
    painel.innerHTML =
      '<h2>Acessibilidade <button type="button" class="a11y-fechar" aria-label="Fechar">&times;</button></h2>' +
      '<h3>Tema</h3><div class="a11y-grupo" data-grupo="tema">' +
        '<button type="button" class="a11y-opt" data-v="escuro">🌙 Escuro (padrão)</button>' +
        '<button type="button" class="a11y-opt" data-v="claro">☀️ Claro</button>' +
        '<button type="button" class="a11y-opt" data-v="contraste">◐ Alto contraste</button></div>' +
      '<h3>Cor de fundo</h3><div class="a11y-cores" data-grupo="bg">' +
        CORES.map(function (c) { return '<button type="button" class="a11y-cor" data-v="' + c[0] + '" title="' + c[1] + '" aria-label="Fundo ' + c[1] + '" style="background:' + (c[0] || 'linear-gradient(135deg,#0b1628,#22d3ee)') + '"></button>'; }).join('') +
        '<label class="ms-1 small" for="a11yCorLivre">Outra:</label> <input type="color" id="a11yCorLivre" aria-label="Escolher outra cor de fundo"></div>' +
      '<h3>Tamanho do texto</h3><div class="a11y-grupo" data-grupo="fonte">' +
        '<button type="button" class="a11y-opt" data-v="0">A</button><button type="button" class="a11y-opt" data-v="1">A+</button>' +
        '<button type="button" class="a11y-opt" data-v="2">A++</button><button type="button" class="a11y-opt" data-v="3">A+++</button></div>' +
      '<h3>Leitura</h3><div class="a11y-grupo">' +
        '<button type="button" class="a11y-opt" data-t="dislexia">Fonte legível (dislexia)</button>' +
        '<button type="button" class="a11y-opt" data-t="espacamento">Mais espaçamento</button>' +
        '<button type="button" class="a11y-opt" data-t="links">Destacar links e foco</button>' +
        '<button type="button" class="a11y-opt" data-t="semAnimacao">Sem animações</button>' +
        '<button type="button" class="a11y-opt" data-t="cursor">Cursor grande</button>' +
        '<button type="button" class="a11y-opt" data-t="libras">Libras (VLibras)</button></div>' +
      '<div class="a11y-rodape"><button type="button" class="a11y-opt" data-acao="padrao">Restaurar padrão</button><span class="small text-muted">Atalhos: Alt+A abre • Esc fecha</span></div>';
    document.body.appendChild(botao); document.body.appendChild(painel);

    botao.addEventListener('click', function () { alternar(); });
    painel.querySelector('.a11y-fechar').addEventListener('click', function () { alternar(false); botao.focus(); });
    painel.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      var grupo = b.parentElement.getAttribute('data-grupo');
      if (grupo === 'tema') set('tema', b.getAttribute('data-v'));
      else if (grupo === 'bg') set('bg', b.getAttribute('data-v'));
      else if (grupo === 'fonte') set('fonte', parseInt(b.getAttribute('data-v'), 10));
      else if (b.getAttribute('data-t')) { var k = b.getAttribute('data-t'); if (k === 'libras' && prefs.libras) removerLibras(); set(k, !prefs[k]); }
      else if (b.getAttribute('data-acao') === 'padrao') { removerLibras(); for (var k2 in PADRAO) prefs[k2] = PADRAO[k2]; aplicar(); salvar(); }
    });
    painel.querySelector('#a11yCorLivre').addEventListener('input', function (e) { set('bg', e.target.value); });
    document.addEventListener('keydown', function (e) {
      if (e.altKey && (e.key === 'a' || e.key === 'A')) { e.preventDefault(); alternar(); }
      if (e.key === 'Escape' && !painel.hidden) { alternar(false); botao.focus(); }
    });
    document.addEventListener('click', function (e) { if (!painel.hidden && !painel.contains(e.target) && e.target !== botao && !(e.target.closest && e.target.closest('[data-a11y-abrir]'))) alternar(false); });
  }
  function alternar(abrir) {
    if (abrir === undefined) abrir = painel.hidden;
    painel.hidden = !abrir; botao.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    if (abrir) { atualizarPainel(); var f = painel.querySelector('.a11y-opt'); if (f) f.focus(); }
  }
  function atualizarPainel() {
    if (!painel) return;
    painel.querySelectorAll('[data-grupo="tema"] .a11y-opt').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-v') === prefs.tema ? 'true' : 'false'); });
    painel.querySelectorAll('[data-grupo="bg"] .a11y-cor').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-v') === (prefs.bg || '') ? 'true' : 'false'); });
    painel.querySelectorAll('[data-grupo="fonte"] .a11y-opt').forEach(function (b) { b.setAttribute('aria-pressed', parseInt(b.getAttribute('data-v'), 10) === (prefs.fonte || 0) ? 'true' : 'false'); });
    painel.querySelectorAll('[data-t]').forEach(function (b) { b.setAttribute('aria-pressed', prefs[b.getAttribute('data-t')] ? 'true' : 'false'); });
    var cl = painel.querySelector('#a11yCorLivre'); if (cl && /^#[0-9a-f]{6}$/i.test(prefs.bg || '')) cl.value = prefs.bg;
  }

  // Reset de emergência: ?a11y=reset na URL limpa as preferências deste navegador
  if (/[?&]a11y=reset\b/.test(location.search)) { try { localStorage.removeItem(CHAVE); } catch (e) {} prefs = {}; for (var k0 in PADRAO) prefs[k0] = PADRAO[k0]; salvar(); }

  try { aplicar(); } catch (e) { /* uma preferência inválida nunca pode impedir o botão de aparecer */ }
  function iniciar() { try { montar(); atualizarPainel(); } catch (e) { if (window.console) console.error('Acessibilidade:', e); } }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
  window.a11yAlternar = function (abrir) { if (!painel) iniciar(); if (painel) alternar(abrir); }; // usado pelo link do menu
})();
