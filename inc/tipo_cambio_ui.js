(function () {
    'use strict';

    const panels = Array.from(document.querySelectorAll('[data-si-tipo-cambio]'));
    if (!panels.length) return;

    let cargando = false;
    let ultimoEstado = null;

    function numero(valor, decimales) {
        const n = Number(valor || 0);
        return Number.isFinite(n)
            ? n.toLocaleString('es-MX', { minimumFractionDigits: decimales, maximumFractionDigits: decimales })
            : '0.0000';
    }

    function fechaCorta(valor) {
        const texto = String(valor || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(texto)) return texto;
        const [anio, mes, dia] = texto.split('-');
        return dia + '/' + mes + '/' + anio;
    }

    function endpoint(panel) {
        return panel.dataset.endpoint || '../funciones/alertas_funciones.php';
    }

    function csrf(panel) {
        return panel.dataset.csrf || '';
    }

    function render(data, mensajeExtra) {
        ultimoEstado = data || ultimoEstado || {};
        const estado = ultimoEstado || {};
        const valor = Number(estado.tipo_cambio || 0);
        const fecha = fechaCorta(estado.fecha || '');
        const fuente = String(estado.fuente || '').trim();
        const desactualizado = estado.desactualizado === true;

        panels.forEach((panel) => {
            const resumen = panel.querySelector('[data-si-tc-resumen]');
            const detalle = panel.querySelector('[data-si-tc-detalle]');
            const boton = panel.querySelector('[data-si-tc-actualizar]');

            if (resumen) {
                resumen.textContent = valor > 0
                    ? '1 USD = $' + numero(valor, 4) + ' MXN'
                    : 'Sin FIX USD/MXN disponible';
            }

            if (detalle) {
                const partes = [];
                if (fecha) partes.push('FIX ' + fecha);
                if (fuente) partes.push(fuente);
                if (desactualizado && valor > 0) partes.push('dato local pendiente de actualización');
                if (mensajeExtra) partes.push(mensajeExtra);
                detalle.textContent = partes.join(' · ') || 'Banco de México SIE';
            }

            if (boton) {
                if (estado.puede_actualizar === false) boton.hidden = true;
                boton.disabled = cargando;
                boton.textContent = cargando ? 'Consultando...' : 'Actualizar dólar';
            }
        });
    }

    async function leerEstado() {
        const panel = panels[0];
        try {
            const response = await fetch(endpoint(panel) + '?accion=TIPO_CAMBIO_ESTADO', {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const text = await response.text();
            const data = JSON.parse(text);
            if (!response.ok || data.success !== true) {
                throw new Error(data.mensaje || 'No fue posible consultar el tipo de cambio.');
            }
            render(data, '');
        } catch (error) {
            render(ultimoEstado || {}, 'No fue posible leer el estado del FIX');
        }
    }

    async function actualizar(panel) {
        if (cargando) return;
        cargando = true;
        let mensajeFinal = '';
        render(ultimoEstado || {}, '');

        const body = new URLSearchParams();
        body.set('accion', 'ACTUALIZAR_TIPO_CAMBIO');
        body.set('csrf_token', csrf(panel));

        try {
            const response = await fetch(endpoint(panel), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: body.toString(),
            });

            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error('El servidor devolvió una respuesta no válida.');
            }

            const estado = {
                tipo_cambio: data.tipo_cambio ?? ultimoEstado?.tipo_cambio ?? null,
                fecha: data.fecha ?? ultimoEstado?.fecha ?? null,
                fuente: data.fuente ?? ultimoEstado?.fuente ?? null,
                desactualizado: false,
                puede_actualizar: true,
            };

            if (!response.ok || data.success !== true) {
                mensajeFinal = data.mensaje || 'No fue posible actualizar; se conserva el último valor local';
                render(estado, mensajeFinal);
                return;
            }

            mensajeFinal = 'actualizado ahora';
            render(estado, mensajeFinal);
            window.dispatchEvent(new CustomEvent('si:tipo-cambio-actualizado', { detail: estado }));
        } catch (error) {
            mensajeFinal = error.message || 'No fue posible actualizar el FIX';
            render(ultimoEstado || {}, mensajeFinal);
        } finally {
            cargando = false;
            render(ultimoEstado || {}, mensajeFinal);
        }
    }

    panels.forEach((panel) => {
        const boton = panel.querySelector('[data-si-tc-actualizar]');
        if (boton) boton.addEventListener('click', () => actualizar(panel));
    });

    window.addEventListener('si:tipo-cambio-actualizado', () => {
        window.setTimeout(leerEstado, 150);
    });

    leerEstado();
})();
