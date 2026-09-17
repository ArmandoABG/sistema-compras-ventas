(function () {
    'use strict';

    const estado = {
        selectActivo: null,
        menuSelect: null,
        confirmacionResolver: null,
        confirmacion: null,
        confirmacionFoco: null,
        solicitudTexto: null,
        solicitudTextoResolver: null,
        solicitudTextoFoco: null,
        toastRegion: null,
        mensajeTimers: new WeakMap(),
        tablasRaf: 0,
        volverArribaRaf: 0
    };

    const selectorKpis = '.stats-grid, .catalogos-kpis, .warehouse-summary, .transfer-summary, .audit-summary, .usuarios-kpis';
    const selectorContenedorTabla = '.table-wrap, .usuarios-table-wrap, .reportes-table-wrap, .prod-table-wrap, .line-table-wrap';
    const selectorTablas = selectorContenedorTabla.split(', ').map(function (selector) { return selector + ' table'; }).join(', ');

    const iconosKpi = {
        usuarios: '<circle cx="8" cy="8" r="3"/><path d="M2.5 20v-2.2A4.8 4.8 0 0 1 7.3 13h1.4a4.8 4.8 0 0 1 4.8 4.8V20"/><path d="M15.5 4.8a3 3 0 0 1 0 5.8M17 13a4.8 4.8 0 0 1 4.5 4.8V20"/>',
        check: '<path d="m4 12 5 5L20 6"/>',
        pausa: '<path d="M9 5v14M15 5v14"/>',
        candado: '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"/>',
        reloj: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        alerta: '<path d="M12 3 2.5 20h19L12 3Z"/><path d="M12 9v4M12 17h.01"/>',
        cartera: '<path d="M3 7.5h16a2 2 0 0 1 2 2V19H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h13v3.5"/><path d="M16 12h5v4h-5a2 2 0 0 1 0-4Z"/>',
        dinero: '<circle cx="12" cy="12" r="9"/><path d="M15 8.5c-.7-.7-1.7-1-3-1-1.7 0-3 .8-3 2s1.1 1.8 3 2.2 3 1 3 2.3-1.3 2.3-3 2.3c-1.2 0-2.4-.4-3.2-1.2M12 5.5v13"/>',
        cajas: '<path d="m4 7 8-4 8 4-8 4-8-4Z"/><path d="m4 12 8 4 8-4M4 17l8 4 8-4"/>',
        paquete: '<path d="m4 7 8-4 8 4v10l-8 4-8-4V7Z"/><path d="m4 7 8 4 8-4M12 11v10"/>',
        carrito: '<path d="M3 4h2l2.2 10h10.6l2-7H6"/><circle cx="9" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/>',
        bolsa: '<path d="M5 8h14l-1 13H6L5 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/>',
        camion: '<path d="M3 6h11v11H3V6Zm11 4h4l3 3v4h-7v-7Z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
        edificio: '<path d="M4 21V5l8-3v19M12 8h8v13M7 7h2M7 11h2M7 15h2M15 11h2M15 15h2"/>',
        engranes: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9 7 7M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1"/>',
        archivo: '<path d="M6 2h8l4 4v16H6V2Z"/><path d="M14 2v5h5M9 12h6M9 16h6"/>',
        capas: '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/>',
        calendario: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18M12 14v3M12 19h.01"/>',
        transferencia: '<path d="M4 7h14l-3-3M20 17H6l3 3M18 7l-3 3M6 17l3-3"/>',
        lista: '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
        qr: '<path d="M3 3h7v7H3V3Zm11 0h7v7h-7V3ZM3 14h7v7H3v-7Zm11 0h3v3h-3v-3Zm4 0h3v7h-7v-3M6 6h1M17 6h1M6 17h1"/>',
        apartado: '<path d="M6 3h12v18l-6-4-6 4V3Z"/><path d="M9 8h6M9 12h4"/>',
        recibo: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        merma: '<path d="m4 7 8-4 8 4v10l-8 4-8-4V7Z"/><path d="m4 7 8 4 8-4M12 11v3M12 18h.01"/>',
        tarjeta: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
        cancelar: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
        grafica: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>'
    };

    function textoNormalizado(valor) {
        return String(valor || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }

    function tipoIconoKpi(tarjeta) {
        const etiqueta = textoNormalizado(tarjeta.querySelector(':scope > span')?.textContent);
        const pagina = tarjeta.closest('[class*="-page"]') || document.querySelector('main');
        const contexto = textoNormalizado((pagina?.className || '') + ' ' + (document.querySelector('h1')?.textContent || ''));
        if (/ventas/.test(contexto)) {
            if (/^total$/.test(etiqueta)) return 'carrito';
            if (/^confirmadas?$/.test(etiqueta)) return 'check';
            if (/^contado$/.test(etiqueta)) return 'dinero';
            if (/^credito$/.test(etiqueta)) return 'tarjeta';
            if (/^canceladas?$/.test(etiqueta)) return 'cancelar';
            if (/importe confirmado/.test(etiqueta)) return 'grafica';
        }
        if (/verificar|qr-/.test(contexto)) {
            if (/rechazo|incidencia/.test(etiqueta)) return 'alerta';
            if (/salida confirmada/.test(etiqueta)) return 'check';
            return 'qr';
        }
        if (/transferencia/.test(etiqueta)) return 'transferencia';
        if (/movimiento|renglones|reverso|revertid/.test(etiqueta)) return 'transferencia';
        if (/merma/.test(etiqueta)) return 'merma';
        if (/bloquead/.test(etiqueta)) return 'candado';
        if (/vencid/.test(etiqueta)) return 'calendario';
        if (/critico|alerta|sin disponible|sin stock|excedid/.test(etiqueta)) return 'alerta';
        if (/sin ingreso|pendiente|por vencer|reorden|hoy|dias/.test(etiqueta)) return 'reloj';
        if (/inactiv|cancelad/.test(etiqueta)) return 'pausa';
        if (/borrador/.test(etiqueta)) return 'archivo';
        if (/parcial/.test(etiqueta)) return 'capas';
        if (/por recibir|recepcion/.test(etiqueta)) return 'camion';
        if (/cotizacion/.test(contexto) && /^(total|generadas?)$/.test(etiqueta)) return 'recibo';
        if (/apartado/.test(contexto) && /^total$/.test(etiqueta)) return 'apartado';
        if (/auditoria/.test(contexto) && /^(registros?|total)$/.test(etiqueta)) return 'lista';
        if (/produccion/.test(contexto) && /^total$/.test(etiqueta)) return 'engranes';
        if (/produccion/.test(contexto) && /este mes/.test(etiqueta)) return 'calendario';
        if (/ventas/.test(contexto) && /^total$/.test(etiqueta)) return 'carrito';
        if (/clientes/.test(contexto) && /^(disponible|linea autorizada|credito utilizado|solo contado)$/.test(etiqueta)) return 'dinero';
        if (/transferencias/.test(contexto) && /almacenes/.test(etiqueta)) return 'cajas';
        if (/activ|aplicad|aceptad|confirmad|pagad|recibid|completad|con existencia|disponible/.test(etiqueta)) return 'check';
        if (/usuario|cliente/.test(etiqueta) || (/usuarios/.test(contexto) && /^(total|registros?)$/.test(etiqueta))) return 'usuarios';
        if (/proveedor/.test(etiqueta)) return 'edificio';
        if (/almacen/.test(etiqueta)) return 'cajas';
        if (/inventario|existencia|stock|fisic|reservad/.test(etiqueta) || (/inventario|inv-page/.test(contexto) && /^(total|registros?)$/.test(etiqueta))) return 'cajas';
        if (/producto|articulo|resultado/.test(etiqueta)) return 'paquete';
        if (/venta/.test(etiqueta) || (/ventas/.test(contexto) && /total/.test(etiqueta))) return 'carrito';
        if (/compra/.test(etiqueta) || (/compras/.test(contexto) && /^total$/.test(etiqueta))) return 'bolsa';
        if (/cobro|abono|credito|importe|monto/.test(etiqueta)) return 'dinero';
        if (/pago|deuda|saldo/.test(etiqueta) || (/cxp|cuentas por pagar/.test(contexto) && /total/.test(etiqueta))) return 'cartera';
        if (/produccion/.test(etiqueta)) return 'engranes';
        if (/recibid/.test(etiqueta)) return 'camion';
        if (/apartado/.test(contexto)) return 'apartado';
        if (/auditoria/.test(contexto)) return 'lista';
        if (/produccion/.test(contexto)) return 'engranes';
        return 'grafica';
    }

    function mejorarKpis(root) {
        const contenedores = [];
        if (root instanceof HTMLElement && root.matches(selectorKpis)) contenedores.push(root);
        root.querySelectorAll?.(selectorKpis).forEach(function (contenedor) { contenedores.push(contenedor); });
        contenedores.forEach(function (contenedor) {
            contenedor.querySelectorAll(':scope > article').forEach(function (tarjeta) {
                tarjeta.classList.add('si-kpi-card');
                let visual = tarjeta.querySelector(':scope > .si-kpi-card__visual');
                if (!visual) {
                    visual = document.createElement('span');
                    visual.className = 'si-kpi-card__visual';
                    visual.setAttribute('aria-hidden', 'true');
                    tarjeta.appendChild(visual);
                }
                const tipo = tipoIconoKpi(tarjeta);
                if (visual.dataset.icono !== tipo) {
                    visual.dataset.icono = tipo;
                    visual.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' + iconosKpi[tipo] + '</svg>';
                }
            });
        });
    }

    function filasVisibles(tabla) {
        return Array.from(tabla.tBodies).flatMap(function (tbody) { return Array.from(tbody.rows); }).filter(function (fila) {
            return !fila.hidden && !fila.querySelector('.empty-cell') && fila.getClientRects().length > 0;
        });
    }

    function ajustarScrollTablas() {
        estado.tablasRaf = 0;
        document.querySelectorAll(selectorTablas).forEach(function (tabla) {
            const contenedor = tabla.closest(selectorContenedorTabla);
            if (!contenedor) return;
            const filas = filasVisibles(tabla);
            if (filas.length <= 20) {
                contenedor.dataset.siTableScroll = 'natural';
                contenedor.style.removeProperty('--si-table-visible-height');
                return;
            }
            const cabecera = tabla.tHead?.getBoundingClientRect().height || 0;
            const altoFilas = filas.slice(0, 20).reduce(function (total, fila) { return total + fila.getBoundingClientRect().height; }, 0);
            if (altoFilas > 0) {
                contenedor.dataset.siTableScroll = 'limited';
                contenedor.style.setProperty('--si-table-visible-height', Math.ceil(cabecera + altoFilas + 2) + 'px');
            }
        });
    }

    function programarScrollTablas() {
        if (estado.tablasRaf) return;
        estado.tablasRaf = window.requestAnimationFrame(ajustarScrollTablas);
    }

    function iniciarTablas() {
        programarScrollTablas();
        new MutationObserver(programarScrollTablas).observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['hidden']
        });
        window.addEventListener('resize', programarScrollTablas, { passive: true });
    }

    function iniciarVolverArriba() {
        if (!document.querySelector('.app-shell')) return;

        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'si-back-to-top';
        boton.setAttribute('aria-label', 'Volver al inicio de la página');
        boton.setAttribute('title', 'Volver arriba');
        boton.setAttribute('aria-hidden', 'true');
        boton.tabIndex = -1;
        boton.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 14 6-6 6 6"/></svg>';
        document.body.appendChild(boton);

        const obstaculos = '.pagination, .module-pagination, .catalogos-pagination, .usuarios-paginacion, .reportes-pagination, .recipe-pagination, .email-alert-pagination, .session-pagination';

        function modalVisible() {
            return Array.from(document.querySelectorAll('[aria-modal="true"]')).some(function (modal) {
                const contenedor = modal.closest('[hidden], .modal-backdrop, .topbar-profile-modal, .inv-stock-modal, .si-ui-confirm, .si-ui-prompt') || modal;
                return !contenedor.hidden && contenedor.getClientRects().length > 0;
            });
        }

        function actualizar() {
            estado.volverArribaRaf = 0;
            const documento = document.scrollingElement || document.documentElement;
            const umbral = window.innerWidth <= 720 ? 320 : 480;
            const desplazamiento = window.scrollY || documento.scrollTop || 0;
            const tieneRecorrido = documento.scrollHeight > window.innerHeight + umbral;
            const visible = tieneRecorrido && desplazamiento > umbral && !modalVisible();
            let separacion = window.innerWidth <= 720 ? 16 : 24;

            if (visible) {
                document.querySelectorAll(obstaculos).forEach(function (elemento) {
                    if (elemento.hidden || elemento.getClientRects().length === 0) return;
                    const rect = elemento.getBoundingClientRect();
                    if (rect.top < window.innerHeight && rect.bottom > window.innerHeight - 150) {
                        separacion = Math.max(separacion, Math.ceil(window.innerHeight - rect.top + 14));
                    }
                });
            }

            boton.style.setProperty('--si-back-to-top-bottom', separacion + 'px');
            boton.classList.toggle('is-visible', visible);
            boton.setAttribute('aria-hidden', visible ? 'false' : 'true');
            boton.tabIndex = visible ? 0 : -1;
        }

        function programar() {
            if (estado.volverArribaRaf) return;
            estado.volverArribaRaf = window.requestAnimationFrame(actualizar);
        }

        boton.addEventListener('click', function () {
            window.scrollTo({
                top: 0,
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
            });
        });
        window.addEventListener('scroll', programar, { passive: true });
        window.addEventListener('resize', programar, { passive: true });
        new MutationObserver(programar).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'class'] });
        actualizar();
    }

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
        const alto = Math.min(estado.menuSelect.scrollHeight, 280, Math.max(80, window.innerHeight - margen * 2));
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
            if (opcion.hidden || opcion.parentElement?.hidden) return;
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'si-select__option' + (indice === select.selectedIndex ? ' is-selected' : '');
            item.dataset.optionIndex = String(indice);
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', indice === select.selectedIndex ? 'true' : 'false');
            item.disabled = opcion.disabled || Boolean(opcion.closest('optgroup')?.disabled);
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
        trigger.setAttribute('aria-controls', 'siSelectMenu');
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
        select.addEventListener('invalid', function () {
            trigger.setAttribute('aria-invalid', 'true');
            trigger.focus();
        });
        select.addEventListener('change', function () {
            trigger.setAttribute('aria-invalid', select.validity.valid ? 'false' : 'true');
        });
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
        estado.menuSelect.id = 'siSelectMenu';
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
            if (evento.key === 'Escape') { evento.preventDefault(); evento.stopPropagation(); cerrarSelect(true); return; }
            if (evento.key === 'Tab') { cerrarSelect(true); return; }
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
            if (estado.confirmacionFoco?.isConnected) estado.confirmacionFoco.focus();
            estado.confirmacionFoco = null;
            if (finalizar) finalizar(valor);
        };
        modal.querySelector('.si-ui-confirm__close').addEventListener('click', function () { resolver(false); });
        modal.querySelector('.si-ui-confirm__cancel').addEventListener('click', function () { resolver(false); });
        modal.querySelector('.si-ui-confirm__accept').addEventListener('click', function () { resolver(true); });
        document.addEventListener('keydown', function (evento) {
            if (modal.hidden) return;
            if (evento.key === 'Escape') {
                evento.preventDefault();
                evento.stopImmediatePropagation();
                resolver(false);
            } else if (evento.key === 'Tab') {
                const controles = Array.from(modal.querySelectorAll('button:not(:disabled)'));
                const primero = controles[0];
                const ultimo = controles[controles.length - 1];
                if (evento.shiftKey && (document.activeElement === primero || !modal.contains(document.activeElement))) {
                    evento.preventDefault(); ultimo.focus();
                } else if (!evento.shiftKey && (document.activeElement === ultimo || !modal.contains(document.activeElement))) {
                    evento.preventDefault(); primero.focus();
                }
            }
        }, true);
    }

    function confirmar(mensaje, opciones) {
        const config = Object.assign({ titulo: 'Confirmar acción', aceptar: 'Confirmar', peligro: false }, opciones || {});
        if (!estado.confirmacion) return Promise.resolve(false);
        if (estado.confirmacionResolver) estado.confirmacionResolver(false);
        const modal = estado.confirmacion;
        if (modal.hidden) estado.confirmacionFoco = document.activeElement;
        cerrarSelect(false);
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

    function crearSolicitudTexto() {
        const modal = document.createElement('div');
        modal.className = 'si-ui-prompt';
        modal.hidden = true;
        modal.innerHTML = '<section class="si-ui-prompt__card" role="dialog" aria-modal="true" aria-labelledby="siUiPromptTitle" aria-describedby="siUiPromptText">'
            + '<header class="si-ui-prompt__header"><div><span>INFORMACIÓN REQUERIDA</span><h2 id="siUiPromptTitle">Capturar información</h2></div><button type="button" class="si-ui-prompt__close" aria-label="Cerrar">×</button></header>'
            + '<div class="si-ui-prompt__body"><label><span id="siUiPromptText"></span><textarea rows="4"></textarea><small></small></label></div>'
            + '<footer class="si-ui-prompt__footer"><button type="button" class="si-ui-prompt__cancel">Volver</button><button type="button" class="si-ui-prompt__accept">Continuar</button></footer>'
            + '</section>';
        document.body.appendChild(modal);
        estado.solicitudTexto = modal;

        const resolver = function (valor) {
            const finalizar = estado.solicitudTextoResolver;
            estado.solicitudTextoResolver = null;
            modal.hidden = true;
            document.body.classList.remove('si-ui-confirm-open');
            if (estado.solicitudTextoFoco?.isConnected) estado.solicitudTextoFoco.focus();
            estado.solicitudTextoFoco = null;
            if (finalizar) finalizar(valor);
        };
        const textarea = modal.querySelector('textarea');
        modal.querySelector('.si-ui-prompt__close').addEventListener('click', function () { resolver(null); });
        modal.querySelector('.si-ui-prompt__cancel').addEventListener('click', function () { resolver(null); });
        modal.querySelector('.si-ui-prompt__accept').addEventListener('click', function () { resolver(textarea.value); });
        document.addEventListener('keydown', function (evento) {
            if (modal.hidden) return;
            if (evento.key === 'Escape') {
                evento.preventDefault();
                evento.stopImmediatePropagation();
                resolver(null);
            } else if (evento.key === 'Tab') {
                const controles = Array.from(modal.querySelectorAll('textarea, button:not(:disabled)'));
                const primero = controles[0];
                const ultimo = controles[controles.length - 1];
                if (evento.shiftKey && (document.activeElement === primero || !modal.contains(document.activeElement))) {
                    evento.preventDefault(); ultimo.focus();
                } else if (!evento.shiftKey && (document.activeElement === ultimo || !modal.contains(document.activeElement))) {
                    evento.preventDefault(); primero.focus();
                }
            } else if ((evento.ctrlKey || evento.metaKey) && evento.key === 'Enter' && document.activeElement === textarea) {
                evento.preventDefault();
                resolver(textarea.value);
            }
        }, true);
    }

    function solicitarTexto(mensaje, opciones) {
        const config = Object.assign({ titulo: 'Capturar información', aceptar: 'Continuar', valor: '', placeholder: '', ayuda: '', maxLength: 1000 }, opciones || {});
        if (!estado.solicitudTexto) return Promise.resolve(null);
        if (estado.solicitudTextoResolver) estado.solicitudTextoResolver(null);
        cerrarSelect(false);
        const modal = estado.solicitudTexto;
        estado.solicitudTextoFoco = document.activeElement;
        modal.querySelector('#siUiPromptTitle').textContent = config.titulo;
        modal.querySelector('#siUiPromptText').textContent = String(mensaje || 'Escribe la información solicitada.');
        const textarea = modal.querySelector('textarea');
        textarea.value = String(config.valor || '');
        textarea.placeholder = String(config.placeholder || '');
        textarea.maxLength = Math.max(1, Number(config.maxLength) || 1000);
        const ayuda = modal.querySelector('.si-ui-prompt__body small');
        ayuda.textContent = String(config.ayuda || 'Puedes cancelar sin aplicar cambios.');
        modal.querySelector('.si-ui-prompt__accept').textContent = config.aceptar;
        modal.hidden = false;
        document.body.classList.add('si-ui-confirm-open');
        window.requestAnimationFrame(function () { textarea.focus(); });
        return new Promise(function (resolve) { estado.solicitudTextoResolver = resolve; });
    }

    function tipoMensaje(elemento) {
        const clases = elemento.className;
        if (/error|danger/i.test(clases)) return 'error';
        if (/warning|advertencia/i.test(clases)) return 'warning';
        if (/success|exito/i.test(clases)) return 'success';
        return 'info';
    }

    function mejorarMensaje(elemento) {
        if (!(elemento instanceof HTMLElement)) return;
        window.clearTimeout(estado.mensajeTimers.get(elemento));
        estado.mensajeTimers.delete(elemento);
        if (elemento.hidden || elemento.querySelector(':scope > .si-inline-message__content')) return;
        const mensaje = elemento.textContent.trim();
        if (!mensaje) return;
        const tipo = tipoMensaje(elemento);
        const icono = document.createElement('span');
        icono.className = 'si-inline-message__icon';
        icono.setAttribute('aria-hidden', 'true');
        icono.textContent = { success: '✓', error: '!', warning: '!', info: 'i' }[tipo];
        const contenido = document.createElement('span');
        contenido.className = 'si-inline-message__content';
        contenido.textContent = mensaje;
        const cerrar = document.createElement('button');
        cerrar.type = 'button';
        cerrar.className = 'si-inline-message__close';
        cerrar.setAttribute('aria-label', 'Cerrar mensaje');
        cerrar.textContent = '×';
        const retirar = function () {
            elemento.classList.add('is-leaving');
            window.setTimeout(function () {
                elemento.hidden = true;
                elemento.classList.remove('is-leaving');
            }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180);
        };
        cerrar.addEventListener('click', retirar);
        elemento.replaceChildren(icono, contenido, cerrar);
        elemento.dataset.siMessageType = tipo;
        elemento.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
        elemento.setAttribute('aria-live', tipo === 'error' ? 'assertive' : 'polite');
        if (tipo === 'success') estado.mensajeTimers.set(elemento, window.setTimeout(retirar, 5200));
    }

    function mejorarMensajes(root) {
        const selector = '.module-message, .catalogos-message, .usuarios-message, .roles-message, .topbar-profile-message';
        if (root instanceof HTMLElement && root.matches(selector)) mejorarMensaje(root);
        root.querySelectorAll?.(selector).forEach(mejorarMensaje);
    }

    function toast(mensaje, tipo, duracion) {
        if (!estado.toastRegion || !mensaje) return;
        const clase = ['success', 'error', 'warning', 'info'].includes(tipo) ? tipo : 'info';
        const item = document.createElement('div');
        item.className = 'si-ui-toast si-ui-toast--' + clase;
        item.setAttribute('role', clase === 'error' ? 'alert' : 'status');
        const texto = document.createElement('span');
        texto.textContent = String(mensaje);
        const icono = document.createElement('span');
        icono.className = 'si-ui-toast__icon';
        icono.setAttribute('aria-hidden', 'true');
        icono.textContent = { success: '✓', error: '!', warning: '!', info: 'i' }[clase];
        const cerrar = document.createElement('button');
        cerrar.type = 'button';
        cerrar.className = 'si-ui-toast__close';
        cerrar.setAttribute('aria-label', 'Cerrar mensaje');
        cerrar.textContent = '×';
        item.append(icono, texto, cerrar);
        estado.toastRegion.appendChild(item);
        let temporizador = null;
        const retirar = function () {
            window.clearTimeout(temporizador);
            item.classList.add('is-leaving');
            window.setTimeout(function () { item.remove(); }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180);
        };
        cerrar.addEventListener('click', retirar);
        const espera = duracion === 0 ? 0 : (Number(duracion) > 0 ? Number(duracion) : { success: 4600, info: 6000, warning: 8000, error: 0 }[clase]);
        if (espera > 0) temporizador = window.setTimeout(retirar, espera);
    }

    function iniciar() {
        iniciarTablas();
        iniciarVolverArriba();
        const esModuloOscuro = document.body.classList.contains('si-module-dark');
        const esVentas = document.body.classList.contains('ventas-body');
        if (!esModuloOscuro && !esVentas) return;
        mejorarKpis(document);
        if (esVentas) return;
        crearSelectCompartido();
        crearConfirmacion();
        crearSolicitudTexto();
        estado.toastRegion = document.createElement('div');
        estado.toastRegion.className = 'si-ui-toast-region';
        estado.toastRegion.setAttribute('aria-live', 'polite');
        document.body.appendChild(estado.toastRegion);
        mejorarSelects(document);
        mejorarMensajes(document);

        new MutationObserver(function (cambios) {
            cambios.forEach(function (cambio) {
                cambio.addedNodes.forEach(function (nodo) {
                    if (nodo.nodeType === Node.ELEMENT_NODE) {
                        mejorarSelects(nodo);
                        mejorarMensajes(nodo);
                        mejorarKpis(nodo);
                    }
                });
                if (cambio.type === 'attributes' && cambio.target instanceof HTMLElement && !cambio.target.hidden) {
                    cambio.target.querySelectorAll?.('select.si-select__native').forEach(sincronizarSelect);
                    mejorarMensajes(cambio.target);
                    mejorarKpis(cambio.target);
                }
            });
        }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'class'] });
    }

    window.siUI = { confirmar: confirmar, solicitarTexto: solicitarTexto, toast: toast, sincronizarSelects: function () { document.querySelectorAll('select.si-select__native').forEach(sincronizarSelect); } };
    window.siConfirmar = confirmar;
    window.siSolicitarTexto = solicitarTexto;
    window.siToast = toast;

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    else iniciar();
})();
