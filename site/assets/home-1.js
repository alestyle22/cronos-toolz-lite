/* assets/home-1.js — antes era un <script> en línea de index.php (CSP estricta, SEC-24 fase 2). */
  (function(){ var s = document.getElementById('d3-working');
    s.querySelectorAll('[data-wk]').forEach(function(b){ b.addEventListener('click', function(){
      s.querySelectorAll('[data-wk]').forEach(function(x){ var on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-selected', on); });
      s.querySelectorAll('[data-wkp]').forEach(function(p){ p.hidden = p.dataset.wkp !== b.dataset.wk; });
    }); });
  })();
  
