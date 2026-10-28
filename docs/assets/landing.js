// The Keel landing page: theme toggle, copy buttons, the waterline, and reveal-on-scroll motion.
(function () {
  var root = document.documentElement;
  var motion = root.classList.contains('motion');

  document.getElementById('theme').addEventListener('click', function () {
    var next = root.dataset.theme === 'dark' ? 'light' : 'dark';
    root.dataset.theme = next;
    try { localStorage.setItem('keel-theme', next); } catch (e) {}
  });

  document.querySelectorAll('[data-copy]').forEach(function (button) {
    var label = button.querySelector('[aria-live]');
    var timer;
    button.addEventListener('click', function () {
      try { navigator.clipboard.writeText(button.dataset.copy); } catch (e) {}
      label.textContent = 'Copied';
      clearTimeout(timer);
      timer = setTimeout(function () { label.textContent = 'Copy'; }, 1800);
    });
  });

  document.querySelectorAll('[data-wave]').forEach(function (path) {
    var args = path.dataset.wave.split(' ').map(Number), p = args[0], a = args[1];
    var d = 'M0 12 Q' + p / 4 + ' ' + (12 - a) + ' ' + p / 2 + ' 12';
    for (var x = p; x <= 2800; x += p / 2) d += ' T' + x + ' 12';
    path.setAttribute('d', d);
  });

  // The hero mark scales with the viewport, like the design.
  var hero = document.querySelector('.hero');
  function size() { hero.style.setProperty('--mark', Math.round(Math.max(130, Math.min(420, innerWidth * 0.3))) + 'px'); }
  size();
  addEventListener('resize', size);

  var steps = document.querySelectorAll('.steps li');
  if (!motion) return;

  var pending = Array.prototype.slice.call(document.querySelectorAll('[data-reveal],[data-bar],[data-count]'));
  pending.forEach(function (el) { if (el.dataset.count) el.textContent = '0'; });

  function count(el) {
    var target = +el.dataset.count, t0 = performance.now(), dur = 1600;
    function tick(now) {
      var p = Math.min(1, (now - t0) / dur), v = 1 - Math.pow(1 - p, 3);
      el.textContent = (p < 1 ? Math.round(target * v / 50) * 50 : target).toLocaleString('en-US');
      if (p < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }

  var stepTimer = null, step = 0;
  function showStep() {
    steps.forEach(function (li, i) {
      var past = step === 4 || step > i, active = step === i;
      li.classList.toggle('dim', !(past || active));
      li.classList.toggle('active', active);
    });
  }

  function check() {
    var vh = innerHeight;
    pending = pending.filter(function (el) {
      var r = el.getBoundingClientRect();
      if (r.top < vh * 0.94 && r.bottom > 0) {
        el.classList.add('in');
        if (el.dataset.count) count(el);
        return false;
      }
      return true;
    });
    var section = document.getElementById('binding');
    if (!stepTimer) {
      var r = section.getBoundingClientRect();
      if (r.top < vh * 0.6 && r.bottom > vh * 0.3) {
        showStep();
        stepTimer = setInterval(function () { step = (step + 1) % 5; showStep(); }, 2400);
      }
    }
  }
  addEventListener('scroll', check, { passive: true });
  addEventListener('resize', check);
  setTimeout(check, 60);
})();
