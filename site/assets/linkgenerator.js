/* assets/linkgenerator.js — Script de linkgenerator.html (Link Generator), sin código en línea (CSP estricta, SEC-24). */
let selectedProject = '';
let selectedAction = '';
let lastActionBtn = null;

function setProject(domain, btn) {
    selectedProject = domain;
    document.querySelectorAll('#project-selector .select-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    updateURL();
}

function setAction(path, btn) {
    selectedAction = path;
    document.querySelectorAll('#action-selector .select-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    updateURL();
}

function openGameModal(btn) {
    lastActionBtn = btn;
    document.getElementById('gameModal').classList.remove('hidden');
    document.getElementById('gameUrlInput').focus();
}

function closeGameModal() {
    document.getElementById('gameModal').classList.add('hidden');
}

function processGameUrl() {
    const rawUrl = document.getElementById('gameUrlInput').value;
    if (!rawUrl) return;

    try {
        // Intentamos parsear como URL, si no, tomamos el string tal cual
        const urlObj = new URL(rawUrl.startsWith('http') ? rawUrl : `https://${rawUrl}`);
        const path = urlObj.pathname + urlObj.search;
        selectedAction = `/?logdialog=1&gameurl=${path}`;
    } catch (e) {
        // Si falla el parseo (ej. no es url válida), tratamos de limpiar a mano
        selectedAction = `/?logdialog=1&gameurl=${rawUrl}`;
    }

    setAction(selectedAction, lastActionBtn);
    closeGameModal();
}

function updateURL() {
    const display = document.getElementById('final-url');
    if (selectedProject && selectedAction) {
        display.innerText = `https://${selectedProject}${selectedAction}`;
        display.classList.replace('text-gray-400', 'text-white');
    } else {
        display.innerText = 'Selecciona proyecto y acción...';
    }
}

function copyToClipboard(type) {
    if (!selectedProject || !selectedAction) return;

    const textToCopy = (type === 'full') 
        ? `https://${selectedProject}${selectedAction}` 
        : selectedAction;

    navigator.clipboard.writeText(textToCopy).then(() => {
        const btnId = type === 'full' ? 'txt-full' : 'txt-cms';
        const el = document.getElementById(btnId);
        const original = el.innerText;
        el.innerText = '¡Copiado!';
        setTimeout(() => el.innerText = original, 1500);
    });
}
