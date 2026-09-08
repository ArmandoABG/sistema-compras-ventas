(function (global) {
    'use strict';

    const operaciones = new Map();

    function claveNueva() {
        if (global.crypto && typeof global.crypto.randomUUID === 'function') {
            return global.crypto.randomUUID();
        }
        if (global.crypto && typeof global.crypto.getRandomValues === 'function') {
            const bytes = new Uint8Array(16);
            global.crypto.getRandomValues(bytes);
            return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        }
        return 'legacy-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
    }

    function normalizar(valor) {
        if (Array.isArray(valor)) return valor.map(normalizar);
        if (!valor || typeof valor !== 'object') return valor;
        return Object.keys(valor).sort().reduce((salida, clave) => {
            salida[clave] = normalizar(valor[clave]);
            return salida;
        }, {});
    }

    function adjuntar(operacion, datos) {
        const firma = JSON.stringify(normalizar(datos || {}));
        let registro = operaciones.get(operacion);
        if (!registro || registro.firma !== firma) {
            registro = { clave: claveNueva(), firma };
            operaciones.set(operacion, registro);
        }
        return Object.assign({}, datos, { idempotency_key: registro.clave });
    }

    function confirmar(operacion) {
        operaciones.delete(operacion);
    }

    function prepararFormulario(formulario, operacion) {
        let campo = formulario.querySelector('input[name="idempotency_key"]');
        if (!campo) {
            campo = document.createElement('input');
            campo.type = 'hidden';
            campo.name = 'idempotency_key';
            formulario.appendChild(campo);
        }

        const datos = new FormData(formulario);
        datos.delete('csrf_token');
        datos.delete('idempotency_key');
        const firma = JSON.stringify(Array.from(datos.entries()));
        if (!campo.value || campo.dataset.firma !== firma) {
            campo.value = claveNueva();
            campo.dataset.firma = firma;
        }
        return campo.value;
    }

    function confirmarFormulario(formulario) {
        const campo = formulario.querySelector('input[name="idempotency_key"]');
        if (campo) {
            campo.value = '';
            delete campo.dataset.firma;
        }
    }

    global.SIIdempotencia = { adjuntar, confirmar, prepararFormulario, confirmarFormulario };
}(window));
