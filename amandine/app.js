// Cocher / décocher toute une colonne de réservations
document.querySelectorAll('[data-toggle]').forEach(btn => {
  btn.addEventListener('click', () => {
    const boxes = [...document.querySelectorAll(`input[data-col="${btn.dataset.toggle}"]:not(:disabled)`)];
    const tout = boxes.every(b => b.checked);
    boxes.forEach(b => { b.checked = !tout; });
  });
});

// Bouton « maintenant » à côté des champs d'heure
document.querySelectorAll('[data-now]').forEach(btn => {
  btn.addEventListener('click', () => {
    const d = new Date();
    document.getElementById(btn.dataset.now).value =
      String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    const pres = document.getElementById(btn.dataset.pres);
    if (pres && pres.value === '') pres.value = '1';
  });
});

// Appli installable : service worker + bouton « Installer l'appli »
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
const btnInstaller = document.getElementById('btn-installer');
const dejaInstallee = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
let invite = null;
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault(); invite = e;
  if (btnInstaller && !dejaInstallee) btnInstaller.style.display = '';
});
const iPhone = /iphone|ipad|ipod/i.test(navigator.userAgent);
if (btnInstaller && iPhone && !dejaInstallee) btnInstaller.style.display = '';
btnInstaller?.addEventListener('click', async () => {
  if (invite) { invite.prompt(); await invite.userChoice; invite = null; btnInstaller.style.display = 'none'; }
  else alert("Sur iPhone : touchez le bouton Partager (carré avec une flèche vers le haut) en bas de Safari, puis « Sur l'écran d'accueil ». L'icône Amandine apparaîtra comme une appli.");
});

// Photos : réduites dans le navigateur avant l'envoi (une photo de téléphone de 5 Mo devient ~300 Ko,
// sinon le serveur peut la refuser), dans le bon sens.
async function reduirePhoto(f, max = 1600) {
  if (!f.type.startsWith('image/')) return f;
  try {
    const img = await createImageBitmap(f, { imageOrientation: 'from-image' });
    const r = Math.min(1, max / Math.max(img.width, img.height));
    const c = document.createElement('canvas');
    c.width = Math.round(img.width * r); c.height = Math.round(img.height * r);
    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
    const blob = await new Promise(ok => c.toBlob(ok, 'image/jpeg', 0.85));
    return blob ? new File([blob], f.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : f;
  } catch { return f; }
}

// Photo de profil : aperçu tout de suite dans le rond, puis enregistrement automatique
document.querySelectorAll('input[data-photo-profil]').forEach(inp => {
  inp.addEventListener('change', async () => {
    if (!inp.files.length) return;
    const f = await reduirePhoto(inp.files[0]);
    const dt = new DataTransfer(); dt.items.add(f); inp.files = dt.files;
    const rond = inp.form.querySelector('.apercu-profil') || document.getElementById(inp.dataset.photoProfil);
    if (rond) rond.innerHTML = `<img src="${URL.createObjectURL(f)}" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover;vertical-align:middle">`;
    inp.form.requestSubmit ? inp.form.requestSubmit() : inp.form.submit();
  });
});

// Album (photos de fond) : on peut choisir des photos en plusieurs fois, elles s'ajoutent ; ✕ pour en enlever une
document.querySelectorAll('input[data-album]').forEach(inp => {
  const dt = new DataTransfer();
  const zone = document.createElement('div');
  zone.style.cssText = 'display:flex;flex-wrap:wrap;gap:.5rem;margin:.4rem 0';
  inp.closest('label').after(zone);
  const afficher = () => {
    inp.files = dt.files;
    zone.innerHTML = '';
    [...dt.files].forEach((f, i) => {
      const d = document.createElement('div');
      d.style.cssText = 'position:relative';
      d.innerHTML = `<img src="${URL.createObjectURL(f)}" alt="" style="width:84px;height:64px;object-fit:cover;border-radius:6px;display:block">`
        + `<button type="button" class="danger small" style="position:absolute;top:2px;right:2px;padding:0 .35rem" aria-label="Enlever">✕</button>`;
      d.querySelector('button').onclick = () => { dt.items.remove(i); afficher(); };
      zone.appendChild(d);
    });
    if (dt.files.length) zone.insertAdjacentHTML('beforeend', `<span class="muted" style="align-self:center">${dt.files.length} photo(s) prête(s) : cliquez sur « ${inp.dataset.album} »</span>`);
  };
  inp.addEventListener('change', async () => {
    const nouvelles = [...inp.files];
    for (const f of nouvelles) dt.items.add(await reduirePhoto(f));
    afficher();
  });
});

// Demande de confirmation avant une action destructive
document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', e => { if (!confirm(el.dataset.confirm)) e.preventDefault(); });
});
