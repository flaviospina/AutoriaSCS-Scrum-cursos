// Comportamentos comuns dos tutoriais: lightbox nas imagens, índice no celular e destaque da seção atual
(function () {
  var lb = document.createElement('div'); lb.className = 'lb'; lb.innerHTML = '<img alt="">';
  document.body.appendChild(lb);
  lb.addEventListener('click', function () { lb.classList.remove('on'); });
  document.querySelectorAll('figure img').forEach(function (im) {
    im.addEventListener('click', function () { lb.querySelector('img').src = im.src; lb.classList.add('on'); });
  });
  var btn = document.querySelector('.btn-menu'), toc = document.querySelector('aside.toc');
  if (btn && toc) btn.addEventListener('click', function () { toc.classList.toggle('aberto'); });
  if (toc) toc.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', function () { if (window.innerWidth < 900) toc.classList.remove('aberto'); }); });
  var links = toc ? Array.from(toc.querySelectorAll('a[href^="#"]')) : [];
  var secs = links.map(function (a) { return document.querySelector(a.getAttribute('href')); });
  function marca() {
    var y = window.scrollY + 100, atual = null;
    secs.forEach(function (s, i) { if (s && s.offsetTop <= y) atual = i; });
    links.forEach(function (a, i) { a.classList.toggle('ativo', i === atual); });
  }
  window.addEventListener('scroll', marca); marca();
})();
