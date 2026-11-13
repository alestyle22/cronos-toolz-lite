/* assets/login.js — Comportamiento de login.php sin código en línea (la página tiene CSP estricta: script-src 'self'). */
document.addEventListener('DOMContentLoaded', () => {
  // Al enviar: botón deshabilitado y texto de espera ("Ingresando…", "Verificando…")
  document.querySelectorAll('form[data-busy]').forEach(f => f.addEventListener('submit', () => {
    const b = f.querySelector('.btn'); if (!b) return;
    b.disabled = true; const s = b.querySelector('span'); if (s) s.textContent = f.dataset.busy;
  }));
  // Mostrar u ocultar la contraseña
  document.querySelectorAll('[data-toggle-pass]').forEach(btn => btn.addEventListener('click', () => {
    const i = document.getElementById(btn.dataset.togglePass); if (!i) return;
    const show = i.type === 'password';
    i.type = show ? 'text' : 'password';
    btn.innerHTML = show ? '<i class="fa-regular fa-eye-slash"></i>' : '<i class="fa-regular fa-eye"></i>';
    btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
  }));
});
