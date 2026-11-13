/* assets/stats-produccion.js — Exportar la tabla de Producción de stats.php a CSV, sin código en línea (CSP estricta, SEC-24). */
function exportProduccionCSV(){
  const table = document.getElementById('prod-table');
  if(!table){ alert('Sin datos para exportar'); return; }
  const mes   = document.querySelector('input[name="mes"]')?.value || '';
  const proj  = document.querySelector('select[name="proyecto"]')?.value || 'todos';
  let csv = '';
  table.querySelectorAll('tr').forEach(row => {
    const cols = [...row.querySelectorAll('th,td')].map(c => '"'+c.innerText.trim().replace(/"/g,'""')+'"');
    csv += cols.join(',') + '\n';
  });
  const blob = new Blob(['﻿'+csv], {type:'text/csv;charset=utf-8'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `produccion_${mes}_${proj}.csv`;
  a.click();
}
