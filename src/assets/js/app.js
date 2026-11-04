/* Senarath Online Banking - splash + top loading bar (look & feel). LAB. */
(function () {
  // Hide splash after first load (once per browser session)
  window.addEventListener('load', function () {
    var s = document.getElementById('splash');
    if (!s) return;
    var seen = false; try { seen = sessionStorage.getItem('sb_splash') === '1'; } catch (e) {}
    if (seen) { s.parentNode && s.remove(); return; }
    setTimeout(function () {
      s.classList.add('hide');
      try { sessionStorage.setItem('sb_splash', '1'); } catch (e) {}
      setTimeout(function () { s.parentNode && s.remove(); }, 650);
    }, 1100);
  });

  // Thin top progress bar on same-site navigation + form submit
  var np = document.getElementById('nprogress');
  var bar = np ? np.querySelector('.bar') : null;
  function startNP() {
    if (!np || !bar) return;
    np.classList.add('active'); bar.style.width = '0';
    requestAnimationFrame(function () { bar.style.width = '82%'; });
  }
  if (np) {
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a');
      if (!a) return;
      var href = a.getAttribute('href') || '';
      if (a.target === '_blank' || href === '' || href.charAt(0) === '#' ||
          href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0 || href.indexOf('javascript') === 0) return;
      startNP();
    }, true);
    document.addEventListener('submit', startNP, true);
    window.addEventListener('pageshow', function () { np.classList.remove('active'); if (bar) bar.style.width = '0'; });
  }
})();
