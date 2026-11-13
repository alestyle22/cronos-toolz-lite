/* assets/home-2.js — antes era un <script> en línea de index.php (CSP estricta, SEC-24 fase 2). */
  (function(){
    var dlg = document.getElementById('d3-cust'), list = document.getElementById('d3-cs-list');
    document.getElementById('d3-cust-btn').addEventListener('click', function(){ dlg.showModal(); });
    dlg.addEventListener('click', function(e){ if (e.target === dlg || e.target.closest('[data-x]')) dlg.close(); });
    list.addEventListener('click', function(e){
      var b = e.target.closest('[data-up],[data-down]'); if (!b) return;
      var row = b.closest('.d3-cs');
      if (b.hasAttribute('data-up') && row.previousElementSibling) list.insertBefore(row, row.previousElementSibling);
      if (b.hasAttribute('data-down') && row.nextElementSibling) list.insertBefore(row.nextElementSibling, row);
      b.focus();
    });
    var drag = null;
    list.addEventListener('dragstart', function(e){ drag = e.target.closest('.d3-cs'); if (drag) { drag.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); } });
    list.addEventListener('dragend', function(){ if (drag) drag.classList.remove('dragging'); drag = null; });
    list.addEventListener('dragover', function(e){
      var over = e.target.closest('.d3-cs'); if (!drag || !over || over === drag) return;
      e.preventDefault(); var r = over.getBoundingClientRect();
      list.insertBefore(drag, e.clientY < r.top + r.height / 2 ? over : over.nextSibling);
    });
    function save(value){
      return fetch('/toolz/user_config.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ key: 'home_layout', value: value }) })
        .then(function(r){ return r.json(); }).then(function(d){ if (d.error) throw new Error(d.error); location.reload(); })
        .catch(function(){ alert('No se pudo guardar. Prueba de nuevo.'); });
    }
    document.getElementById('d3-cust-save').addEventListener('click', function(){
      var rows = Array.prototype.slice.call(list.querySelectorAll('.d3-cs'));
      var tiles = Array.prototype.slice.call(document.querySelectorAll('#d3-ct input'));
      save({
        order: rows.map(function(r){ return r.dataset.id; }),
        hidden: rows.filter(function(r){ return !r.querySelector('input').checked; }).map(function(r){ return r.dataset.id; }),
        tiles_hidden: tiles.filter(function(i){ return !i.checked; }).map(function(i){ return i.value; })
      });
    });
    document.getElementById('d3-cust-reset').addEventListener('click', function(){ save({}); });
  })();
  
