/* mailingmaker/brands.js — Oculta las plataformas de marcas que el usuario no ve (Admin › Marcas).
 * Es la misma lógica que g7_brand_js() de access.php, pero sin código en línea (CSP estricta, SEC-24 fase 2):
 * los datos llegan en <script type="application/json" id="g7-brands-data"> (brands_inc.php) y los botones de plataforma
 * se reconocen tanto por el atributo onclick viejo (selectPlat(this,'x')) como por data-on-click="selectPlat" data-click-N="s:x" (assets/g7-on.js). */
(function () {
  var el = document.getElementById('g7-brands-data');
  if (!el) return;
  var B;
  try { B = JSON.parse(el.textContent); } catch (e) { return; }
  window.G7_BRANDS = B;
  function n(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]/g, ''); }
  window.g7BrandAllows = function (t) {
    var x = n(t); if (!x) return true;
    var h = B.brands.filter(function (b) { return b.k.some(function (k) { return x.indexOf(k) >= 0; }); }).map(function (b) { return b.id; });
    if (!h.length) return B.unassigned;
    return h.some(function (id) { return B.scope.indexOf(id) >= 0; });
  };
  var RX = /project|proyecto|platform|plataforma|brand|marca/i;
  var FN = /^(setProject|selectPlat|setPlat|selectPlatform|setPlatform)$/;
  var FX = /(setProject|selectPlat|setPlat|selectPlatform|setPlatform)\(\s*(?:this\s*,\s*)?['"]([^'"]+)/;
  /** [función, plataforma] del botón, o null. Lee onclick (código viejo) o los atributos de g7-on. */
  function call(b) {
    var on = b.getAttribute('data-on-click');
    if (on !== null) {
      if (!FN.test(on)) return null;
      for (var i = 1; i < 10; i++) {
        var v = b.getAttribute('data-click-' + i);
        if (v === null) break;
        if (v.slice(0, 2) === 's:') return [on, v.slice(2)];
      }
      return null;
    }
    var m = FX.exec(b.getAttribute('onclick') || '');
    return m ? [m[1], m[2]] : null;
  }
  function prune(root) {
    root = root || document;
    root.querySelectorAll('select').forEach(function (s) {
      if (!RX.test((s.id || '') + ' ' + (s.name || '') + ' ' + (s.className || ''))) return;
      Array.prototype.slice.call(s.options).forEach(function (o) { var v = o.value || o.text; if (v && !g7BrandAllows(v + ' ' + o.text)) { if (o.selected) { o.selected = false; } o.remove(); } });
    });
    root.querySelectorAll('input[type=checkbox],input[type=radio]').forEach(function (i) {
      if (!RX.test(i.name || '') || !i.value) return;
      if (!g7BrandAllows(i.value)) { i.checked = false; var l = i.closest('label') || i; l.style.display = 'none'; i.disabled = true; }
    });
    root.querySelectorAll('[onclick],[data-on-click]').forEach(function (b) {
      var m = call(b);
      if (!m || g7BrandAllows(m[1])) return;
      b.style.display = 'none';
      if (b.classList.contains('active')) {
        b.classList.remove('active');
        var sib = Array.prototype.slice.call(b.parentNode.children).find(function (x) { var mm = call(x); return mm && mm[0] === m[0] && x.style.display !== 'none' && g7BrandAllows(mm[1]); });
        if (sib) setTimeout(function () { sib.click(); }, 0);
      }
    });
    root.querySelectorAll('[data-brand-text]').forEach(function (e) { if (!g7BrandAllows(e.getAttribute('data-brand-text'))) e.style.display = 'none'; });
  }
  window.g7BrandPrune = prune;
  function go() { prune(); var t; new MutationObserver(function () { clearTimeout(t); t = setTimeout(prune, 60); }).observe(document.body, { childList: true, subtree: true }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
})();
