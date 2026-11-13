/* assets/g7-on.js — Eventos declarativos sin código en línea (CSP estricta, SEC-24 fase 2). Lo carga topbar.php en todas las páginas.
 * En vez de onclick="fn('a', this.value)" se escribe:
 *   data-on-click="fn" data-click-1="s:a" data-click-2="value"
 * Argumentos: s:texto · n:número · j:JSON · this (el elemento) · event · value (el.value) · nvalue (+el.value) · tvalue (el.value sin espacios) · checked · true · false · null
 * Funciones propias: @remove (borra el elemento) · @removeParent · @stop (corta la propagación) · @set (asigna: arg 1 = ruta "A.b.c", arg 2 = valor).
 * "fn" puede ser un método: "BZ.editPlan" se llama con BZ como this (el objeto tiene que estar en window).
 * "fn" puede ser varias separadas por "|" (se llaman en orden, con los mismos argumentos de su número: data-click-1… para la primera,
 * data-click2-1… para la segunda). En keydown, data-keydown-key="Enter" filtra la tecla.
 * Se escucha en captura, en document: llega aunque un contenedor corte la propagación, y antes de que la página exista entera. */
(function () {
  if (window.__g7on) return; window.__g7on = true;
  var EVENTS = ['click', 'dblclick', 'change', 'input', 'keydown', 'keyup', 'submit', 'focusin', 'focusout', 'mouseover', 'mouseout', 'dragstart', 'dragover', 'dragend', 'drop', 'paste', 'error', 'load'];
  function arg(el, e, v) {
    if (v === null || v === undefined) return undefined;
    switch (v) {
      case 'this': return el; case 'event': return e; case 'value': return el.value; case 'checked': return el.checked;
      case 'nvalue': return +el.value; case 'tvalue': return String(el.value).trim();
      case 'true': return true; case 'false': return false; case 'null': return null;
    }
    var p = v.slice(0, 2);
    if (p === 's:') return v.slice(2);
    if (p === 'n:') return Number(v.slice(2));
    if (p === 'j:') { try { return JSON.parse(v.slice(2)); } catch (x) { return undefined; } }
    return v;
  }
  /** Busca "A.b.c" desde window. Devuelve [objeto dueño, valor]. */
  function resolve(path) {
    var parts = path.split('.'), owner = window, cur = window;
    for (var i = 0; i < parts.length; i++) { owner = cur; cur = cur == null ? undefined : cur[parts[i]]; }
    return [owner, cur];
  }
  function callOne(name, el, e, prefix) {
    if (name === '@remove') { el.remove(); return; }
    if (name === '@removeParent') { if (el.parentNode) el.parentNode.remove(); return; }
    if (name === '@parentOn') { if (el.parentNode) el.parentNode.classList.toggle('on', !!el.checked); return; }   // casilla que marca a su etiqueta
    if (name === '@select') { if (el.select) el.select(); return; }
    if (name === '@stop') { e.stopPropagation(); return; }
    var args = [];
    for (var i = 1; i < 10; i++) { var v = el.getAttribute('data-' + prefix + '-' + i); if (v === null) break; args.push(arg(el, e, v)); }
    // @set: asigna un valor a una ruta global (antes onX="DR.draft.color=this.value")
    if (name === '@set') {
      var path = String(args[0] || ''), dot = path.lastIndexOf('.');
      var tgt = dot < 0 ? window : resolve(path.slice(0, dot))[1];
      if (tgt != null) tgt[path.slice(dot + 1)] = args[1];
      return;
    }
    var r = resolve(name), fn = r[1];
    if (typeof fn !== 'function') { if (window.console) console.warn('g7-on: no existe la función', name); return; }
    return fn.apply(name.indexOf('.') < 0 ? el : r[0], args);   // método de un objeto: se llama con ese objeto como this
  }
  function handle(e) {
    var type = e.type === 'focusin' ? 'focusin' : e.type;
    var attr = 'data-on-' + type;
    var el = e.target && e.target.closest ? e.target.closest('[' + attr + ']') : null;
    // load/error no suben: solo cuentan en el propio elemento
    if ((type === 'error' || type === 'load') && (!e.target.getAttribute || !e.target.hasAttribute(attr))) return;
    if (!el) return;
    if (type === 'keydown' || type === 'keyup') { var k = el.getAttribute('data-' + type + '-key'); if (k && e.key !== k) return; }
    var names = el.getAttribute(attr).split('|');
    for (var n = 0; n < names.length; n++) {
      var r = callOne(names[n].trim(), el, e, type + (n ? String(n + 1) : ''));
      if (r === false) { e.preventDefault(); }
    }
  }
  EVENTS.forEach(function (t) { document.addEventListener(t, handle, true); });
})();
