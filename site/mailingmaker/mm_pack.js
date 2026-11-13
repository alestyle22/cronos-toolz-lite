/* mailingmaker/mm_pack.js — Ayudas comunes de los generadores para leer el paquete de marcas del sitio.
 * marcas_js.php define window.MM_PACK (o nada, si el sitio no tiene paquete). Cada generador pide su parte con
 * mmPack('mailings' | 'comms' | 'commsOld', ejemplo) y recibe la del paquete o, sin paquete, su plataforma de ejemplo neutra.
 * Las tarjetas de plataforma y las opciones del generador de links se arman desde esos datos (no están escritas en el HTML). */
(function () {
  'use strict';
  /** Parte del paquete para un generador, completada con los valores del ejemplo. */
  window.mmPack = function (section, def) {
    var p = window.MM_PACK && window.MM_PACK[section];
    var ok = !!(p && p.platforms && typeof p.platforms === 'object' && Object.keys(p.platforms).length);
    var r = Object.assign({}, def, ok ? p : {});
    if (!ok) r.platforms = def.platforms;
    // Tarjetas: solo las de plataformas que existen; sin lista, una por plataforma en el orden de los datos
    var cards = (Array.isArray(r.cards) && r.cards.length ? r.cards : Object.keys(r.platforms).map(function (id) { return { id: id }; }))
      .filter(function (c) { return c && r.platforms[c.id]; });
    if (!cards.length) cards = Object.keys(r.platforms).map(function (id) { return { id: id }; });
    r.cards = cards;
    r.cardById = {};
    cards.forEach(function (c) { r.cardById[c.id] = c; });
    if (!r.platforms[r.defaultPlat]) r.defaultPlat = cards[0].id;
    r.lgDomains = r.lgDomains || {};
    if (!Array.isArray(r.lgProjects) || !r.lgProjects.length) {
      // Sin lista propia: los dominios de las plataformas, sin repetir
      var seen = {};
      r.lgProjects = [];
      Object.keys(r.lgDomains).forEach(function (k) {
        var d = r.lgDomains[k];
        if (d && !seen[d]) { seen[d] = 1; r.lgProjects.push({ domain: d, label: d }); }
      });
    }
    // URL pública de mailingmaker/ (para las imágenes subidas): la del paquete o la de esta misma página
    if (!r.serverBase) r.serverBase = location.origin + location.pathname.replace(/[^\/]*$/, '');
    /** Clave de plataforma válida (la pedida si existe; si no, la de por defecto). */
    r.key = function (id) { return r.platforms[id] ? id : r.defaultPlat; };
    return r;
  };

  /** Arma las tarjetas .plat-card dentro de cont (vacía lo que hubiera). Cada una llama selectPlat(this, id) vía assets/g7-on.js. */
  window.mmRenderPlatCards = function (cont, pack, activeId) {
    if (!cont) return;
    cont.textContent = '';
    pack.cards.forEach(function (c) {
      var p = pack.platforms[c.id] || {};
      var card = document.createElement('div');
      card.className = 'plat-card' + (c.id === activeId ? ' active' : '');
      card.setAttribute('data-plat', c.id);
      card.setAttribute('data-on-click', 'selectPlat');
      card.setAttribute('data-click-1', 'this');
      card.setAttribute('data-click-2', 's:' + c.id);
      if (c.color) card.style.setProperty('--pc', c.color);
      var radio = document.createElement('div'); radio.className = 'plat-radio';
      var info = document.createElement('div');
      var name = document.createElement('div'); name.className = 'plat-name';
      name.textContent = c.label || p.name || c.id;
      if (c.badge) {
        var b = document.createElement('span');
        b.style.cssText = 'font-size:9px;font-weight:700;';
        if (c.badgeColor) b.style.color = c.badgeColor;
        b.textContent = c.badge;
        name.appendChild(document.createTextNode(' '));
        name.appendChild(b);
      }
      var tag = document.createElement('div'); tag.className = 'plat-tag';
      tag.textContent = c.tag != null ? c.tag : (p.country || '');
      info.appendChild(name); info.appendChild(tag);
      card.appendChild(radio); card.appendChild(info);
      cont.appendChild(card);
    });
  };

  /** Llena un <select> con los proyectos del generador de links. */
  window.mmFillProjectSelect = function (sel, pack) {
    if (!sel) return;
    sel.textContent = '';
    pack.lgProjects.forEach(function (pr) {
      var o = document.createElement('option');
      o.value = pr.domain; o.textContent = pr.label || pr.domain;
      sel.appendChild(o);
    });
  };

  /** Dominios (host) de todas las plataformas: sirve para saber si un campo todavía tiene un link por defecto. */
  window.mmPackHosts = function (pack, fields) {
    var hosts = [];
    Object.keys(pack.platforms).forEach(function (k) {
      (fields || []).forEach(function (f) {
        try { var h = new URL(pack.platforms[k][f]).hostname; if (h && hosts.indexOf(h) < 0) hosts.push(h); } catch (e) {}
      });
    });
    Object.keys(pack.lgDomains).forEach(function (k) { var d = pack.lgDomains[k]; if (d && hosts.indexOf(d) < 0) hosts.push(d); });
    return hosts;
  };
})();
