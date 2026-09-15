(function () {
    'use strict';

    const estado = {
        selectActivo: null,
        menuSelect: null,
        confirmacionResolver: null,
        confirmacion: null,
        toastRegion: null
    };

    function etiquetaSelect(select) {
        const label = select.closest('label');
        const texto = label?.querySelector(':scope > span')?.textContent?.trim();
        return texto || select.getAttribute('aria-label') || 'Seleccionar opción';
    }

    function sincronizarSelect(select) {
        const control = select.closest('.si-select');
        if (!control) return;
        const trigger = control.querySelector('.si-select__trigger');
        const opcion = select.options[select.selectedIndex];
        trigger.querySelector('.si-select__value').textContent = opcion ? opcion.textContent.trim() : 'Seleccionar';
        trigger.disabled = select.disabled;
        trigger.setAttribute('aria-disabled', select.disabled ? 'true' : 'false');
        trigger.setAttribute('aria-label', etiquetaSelect(select) + ': ' + (opcion ? opcion.textContent.trim() : 'sin selección'));
    }

    function cerrarSelect(restaurarFoco) {
        if (!estado.selectActivo || !estado.menuSelect) return;
        const trigger = estado.selectActivo.trigger;
        estado.menuSelect.hidden = true;
        estado.menuSelect.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
        estado.selectActivo = null;
        if (restaurarFoco) trigger.focus();
    }

    function posicionarSelect() {
        if (!estado.selectActivo || !estado.menuSelect || estado.menuSelect.hidden) return;
        const rect = estado.selectActivo.trigger.getBoundingClientRect();
        const margen = 8;
        const ancho = Math.max(190, rect.width);
        estado.menuSelect.style.width = Math.min(ancho, window.innerWidth - margen * 2) + 'px';
        estado.menuSelect.style.left = Math.max(margen, Math.min(rect.left, window.innerWidth - estado.menuSelect.offsetWidth - margen)) + 'px';
        const espacioAbajo = window.innerHeight - rect.bottom - margen;
        const alto = Math.min(estado.menuSelect.scrollHeight, 280);
        estado.menuSelect.style.maxHeight = alto + 'px';
        estado.menuSelect.style.top = (espacioAbajo >= Math.min(alto, 180) ? rect.bottom + 7 : Math.max(margen, rect.top - alto - 7)) + 'px';
    }

    function abrirSelect(select, trigger) {
        if (select.disabled) return;
        if (estado.selectActivo?.select === select) return cerrarSelect(true);
        cerrarSelect(false);
        sincronizarSelect(select);
        estado.menuSelect.replaceChildren();
        Array.from(select.options).forEach(function (opcion, indice) {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'si-select__option' + (indice === select.selectedIndex ? ' is-selected' : '');
            item.dataset.optionIndex = String(indice);
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', indice === select.selectedIndex ? 'true' : 'false');
            item.disabled = opcion.disabled;
            item.textContent = opcion.textContent;
            estado.menuSelect.appendChild(item);
        });
        estado.selectActivo = { select: select, trigger: trigger };
        trigger.setAttribute('aria-expanded', 'true');
        estado.menuSelect.hidden = false;
        estado.menuSelect.classList.add('is-open');
        posicionarSelect();
        window.requestAnimationFrame(function () {
            estado.menuSelect.querySelector('.is-selected:not(:disabled), .si-select__option:not(:disabled)')?.focus();
        });
    }

    function mejorarSelect(select) {
        if (!(select instanceof HTMLSelectElement)
            || select.multiple
            || select.size > 1
            || select.classList.contains('si-select__native')
            || select.classList.contains('ventas-select__native')
            || select.closest('.topbar, .sidebar, .topbar-profile-modal')
            || select.hasAttribute('data-si-native-select')) return;

        const control = document.createElement('div');
        control.className = 'si-select';
        select.parentNode.insertBefore(control, select);
        control.appendChild(select);
        select.classList.add('si-select__native');
        select.tabIndex = -1;

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'si-select__trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.innerHTML = '<span class="si-select__value"></span><span class="si-select__chevron" aria-hidden="true"></span>';
        control.appendChild(trigger);
        sincronizarSelect(select);

        trigger.addEventListener('click', function () { abrirSelect(select, trigger); });
        trigger.addEventListener('keydown', function (evento) {
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(evento.key)) {
                evento.preventDefault();
                abrirSelect(select, trigger);
            }
        });
        select.addEventListener('change', function () { sincronizarSelect(select); });
        select.form?.addEventListener('reset', function () { window.requestAnimationFrame(function () { sincronizarSelect(select); }); });
        new MutationObserver(function () { sincronizarSelect(select); }).observe(select, {
            attributes: true,
            childList: true,
            subtree: true,
            attributeFilter: ['disabled', 'selected', 'label']
        });
    }

    function mejorarSelects(root) {
        if (root instanceof HTMLSelectElement) mejorarSelect(root);
        root.querySelectorAll?.('select').forEach(mejorarSelect);
    }

    function crearSelectCompartido() {
        estado.menuSelect = document.createElement('div');
        estado.menuSelect.className = 'si-select__menu';
        estado.menuSelect.setAttribute('role', 'listbox');
        estado.menuSelect.hidden = true;
        document.body.appendChild(estado.menuSelect);

        estado.menuSelect.addEventListener('click', function (evento) {
            const item = evento.target.closest('[data-option-index]');
            if (!item || !estado.selectActivo || item.disabled) return;
            const opcion = estado.selectActivo.select.options[Number(item.dataset.optionIndex)];
            if (!opcion) return;
            estado.selectActivo.select.value = opcion.value;
            estado.selectActivo.select.dispatchEvent(new Event('change', { bubbles: true }));
            sincronizarSelect(estado.selectActivo.select);
            cerrarSelect(true);
        });

        estado.menuSelect.addEventListener('keydown', function (evento) {
            const opciones = Array.from(estado.menuSelect.querySelectorAll('.si-select__option:not(:disabled)'));
            const actual = opciones.indexOf(document.activeElement);
            if (evento.key === 'Escape') { evento.preventDefault(); cerrarSelect(true); return; }
            if (evento.key === 'Tab') { cerrarSelect(false); return; }
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(evento.key)) return;
            evento.preventDefault();
            const indice = evento.key === 'Home'
                ? 0
                : (evento.key === 'End' ? opciones.length - 1 : (actual + (evento.key === 'ArrowDown' ? 1 : -1) + opciones.length) % opciones.length);
            opciones[indice]?.focus();
        });

        document.addEventListener('pointerdown', function (evento) {
            if (!estado.selectActivo || estado.menuSelect.contains(evento.target) || estado.selectActivo.trigger.contains(evento.target)) return;
            cerrarSelect(false);
        });
        document.addEventListener('scroll', function (evento) { if (evento.target !== estado.menuSelect) cerrarSelect(false); }, true);
        window.addEventListener('resize', function () { cerrarSelect(false); });
    }

    function crearConfirmacion() {
        const modal = document.createElement('div');
        modal.className = 'si-ui-confirm';
        modal.hidden = true;
        modal.innerHTML = '<section class="si-ui-confirm__card" role="alertdialog" aria-modal="true" aria-labelledby="siUiConfirmTitle" aria-describedby="siUiConfirmText">'
            + '<header class="si-ui-confirm__header"><div><span>CONFIRMACIÓN</span><h2 id="siUiConfirmTitle">Confirmar acción</h2></div><button type="button" class="si-ui-confirm__close" aria-label="Cerrar">×</button></header>'
            + '<div class="si-ui-confirm__body"><span class="si-ui-confirm__icon" aria-hidden="true">✓</span><p id="siUiConfirmText"></p></div>'
            + '<footer class="si-ui-confirm__footer"><button type="button" class="si-ui-confirm__cancel">Volver</button><button type="button" class="si-ui-confirm__accept">Confirmar</button></footer>'
            + '</section>';
        document.body.appendChild(modal);
        estado.confirmacion = modal;

        const resolver = function (valor) {
            const finalizar = estado.confirmacionResolver;
            estado.confirmacionResolver = null;
            modal.hidden = true;
            document.body.classList.remove('si-ui-confirm-open');
            if (finalizar) finalizar(valor);
        };
        modal.querySelector('.si-ui-confirm__close').addEventListener('click', function () { resolver(false); });
        modal.querySelector('.si-ui-confirm__cancel').addEventListener('click', function () { resolver(false); });
        modal.querySelector('.si-ui-confirm__accept').addEventListener('click', function () { resolver(true); });
        document.addEventListener('keydown', function (evento) {
            if (evento.key === 'Escape' && !modal.hidden) resolver(false);
        });
    }

    function confirmar(mensaje, opciones) {
        const config = Object.assign({ titulo: 'Confirmar acción', aceptar: 'Confirmar', peligro: false }, opciones || {});
        if (!estado.confirmacion) return Promise.resolve(false);
        if (estado.confirmacionResolver) estado.confirmacionResolver(false);
        const modal = estado.confirmacion;
        modal.querySelector('#siUiConfirmTitle').textContent = config.titulo;
        modal.querySelector('#siUiConfirmText').textContent = String(mensaje || '¿Deseas continuar?');
        const icono = modal.querySelector('.si-ui-confirm__icon');
        icono.textContent = config.peligro ? '!' : '✓';
        icono.classList.toggle('is-danger', Boolean(config.peligro));
        const aceptar = modal.querySelector('.si-ui-confirm__accept');
        aceptar.textContent = config.aceptar;
        aceptar.classList.toggle('is-danger', Boolean(config.peligro));
        modal.hidden = false;
        document.body.classList.add('si-ui-confirm-open');
        window.requestAnimationFrame(function () { aceptar.focus(); });
        return new Promise(function (resolve) { estado.confirmacionResolver = resolve; });
    }

    function toast(mensaje, tipo, duracion) {
        if (!estado.toastRegion || !mensaje) return;
        const clase = ['success', 'error', 'warning', 'info'].includes(tipo) ? tipo : 'info';
        const item = document.createElement('div');
        item.className = 'si-ui-toast si-ui-toast--' + clase;
        item.setAttribute('role', clase === 'error' ? 'alert' : 'status');
        const texto = document.createElement('span');
        texto.textContent = String(mensaje);
        const cerrar = document.createElement('button');
        cerrar.type = 'button';
        cerrar.className = 'si-ui-toast__close';
        cerrar.setAttribute('aria-label', 'Cerrar mensaje');
        cerrar.textContent = '×';
        item.append(texto, cerrar);
        estado.toastRegion.appendChild(item);
        let temporizador = null;
        const retirar = function () {
            window.clearTimeout(temporizador);
            item.classList.add('is-leaving');
            window.setTimeout(function () { item.remove(); }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180);
        };
        cerrar.addEventListener('click', retirar);
        temporizador = window.setTimeout(retirar, Number(duracion) > 0 ? Number(duracion) : 4600);
    }

    function iniciar() {
        if (!document.body.classList.contains('si-module-dark')) return;
        crearSelectCompartido();
        crearConfirmacion();
        estado.toastRegion = document.createElement('div');
        estado.toastRegion.className = 'si-ui-toast-region';
        estado.toastRegion.setAttribute('aria-live', 'polite');
        document.body.appendChild(estado.toastRegion);
        mejorarSelects(document);

        new MutationObserver(function (cambios) {
            cambios.forEach(function (cambio) {
                cambio.addedNodes.forEach(function (nodo) {
                    if (nodo.nodeType === Node.ELEMENT_NODE) mejorarSelects(nodo);
                });
                if (cambio.type === 'attributes' && cambio.target instanceof HTMLElement && !cambio.target.hidden) {
                    cambio.target.querySelectorAll?.('select.si-select__native').forEach(sincronizarSelect);
                }
            });
        }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
    }

    window.siUI = { confirmar: confirmar, toast: toast, sincronizarSelects: function () { document.querySelectorAll('select.si-select__native').forEach(sincronizarSelect); } };
    window.siConfirmar = confirmar;
    window.siToast = toast;

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    else iniciar();
})();
