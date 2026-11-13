/* assets/users-page.js — Script de users.php (Usuarios). Sin código en línea (CSP estricta, SEC-24). */
function openPwd(id, username) {
  document.getElementById('pwd-user-id').value = id;
  document.getElementById('pwd-username').textContent = username;
  document.getElementById('pwd-modal').classList.remove('hidden');
}
function closePwd() { document.getElementById('pwd-modal').classList.add('hidden'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closePwd(); });
function uGen() {
  const sets = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKMNPQRSTUVWXYZ', '23456789', '!#$%&*+-=?@'], all = sets.join('');
  const rnd = n => { const a = new Uint32Array(1); crypto.getRandomValues(a); return a[0] % n; };
  const out = sets.map(s => s[rnd(s.length)]); while (out.length < 14) out.push(all[rnd(all.length)]);
  for (let i = out.length - 1; i > 0; i--) { const j = rnd(i + 1); [out[i], out[j]] = [out[j], out[i]]; }
  const i = document.getElementById('u-pw'); i.value = out.join(''); i.type = 'text'; i.select();
}
function uCopy(t, b) {
  const ok = () => { const o = b.innerHTML; b.innerHTML = '<i class="fa-solid fa-check" style="margin-right:6px"></i>Copiado'; setTimeout(() => b.innerHTML = o, 1800); };
  if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(ok);
  else { const x = document.createElement('textarea'); x.value = t; document.body.append(x); x.select(); document.execCommand('copy'); x.remove(); ok(); }
}
/** Copia el mensaje con las credenciales del usuario recién creado. */
function uCopyCreds(b) { uCopy(document.getElementById('u-creds').value, b); }
/** Confirma la baja de un usuario antes de mandar el formulario. */
function uConfirmDel(name) { return confirm('¿Eliminar a ' + name + '?'); }
