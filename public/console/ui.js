/*
 * Tiny rendering / DOM helpers for the Operator Console.
 *
 * Deliberately *not* a framework. Each view is a function that takes
 * (root, engine) and replaces root.innerHTML, then attaches event
 * listeners on the resulting DOM. Idiomatic for a 4-tab UI; a
 * framework would carry its own boot cost on the cold-cache load.
 */

/**
 * Safe-text element creator. Always sets textContent (never innerHTML)
 * to avoid XSS on user-authored fields that round-tripped through the
 * server.
 */
export function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v === undefined || v === null || v === false) continue;
        if (k === 'class') node.className = v;
        else if (k === 'dataset') {
            for (const [dk, dv] of Object.entries(v)) node.dataset[dk] = dv;
        }
        else if (k === 'style' && typeof v === 'object') Object.assign(node.style, v);
        else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2).toLowerCase(), v);
        else if (k === 'text') node.textContent = v;
        else if (k === 'html') node.innerHTML = v; // caller is responsible
        else node.setAttribute(k, v);
    }
    for (const c of [].concat(children)) {
        if (c == null) continue;
        node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return node;
}

export function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
}

export function toast(message, kind = 'info', timeoutMs = 3500) {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = el('div', { id: 'toast-container', class: 'toast-container' });
        document.body.appendChild(container);
    }
    const t = el('div', { class: `toast toast-${kind}`, text: message });
    container.appendChild(t);
    setTimeout(() => {
        t.style.opacity = '0';
        t.style.transition = 'opacity 0.2s';
        setTimeout(() => t.remove(), 250);
    }, timeoutMs);
}

export function formatDate(iso) {
    if (!iso) return '—';
    try {
        const d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleString();
    } catch {
        return iso;
    }
}

/** Number formatting that drops trailing zeros after decimals. */
export function fmtNum(v, fallback = '0') {
    if (v === null || v === undefined || v === '') return fallback;
    const n = typeof v === 'number' ? v : Number.parseFloat(v);
    if (!Number.isFinite(n)) return fallback;
    return n.toLocaleString(undefined, { maximumFractionDigits: 4 });
}
