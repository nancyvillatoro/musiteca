/**
 * Panel operativo del dashboard de Gestión de Instrumentos.
 *
 * Antes este archivo también dibujaba tres widgets analíticos (gráfica de
 * préstamos por mes con Chart.js, ranking de instrumentos más solicitados
 * y actividad reciente), retirados por no aportar al flujo operativo
 * diario. Una iteración posterior agregó además una tarjeta de "préstamos
 * activos" y una barra de "accesos rápidos": ambas se quitaron por ser
 * redundantes (la tarjeta duplicaba en la práctica "Actualmente
 * asignados"; cada botón de accesos rápidos ya existía en otro lugar
 * visible de la misma pantalla).
 *
 * Revisión de jerarquía visual: vencidos, incidencias y reportes de
 * soporte pendientes dejaron de ser tarjetas KPI fijas (visibles siempre,
 * incluso marcando 0 la mayoría de los días) y pasaron a ser avisos
 * condicionales que solo se muestran cuando su conteo es mayor que cero.
 * Esto evita que información de "todo en orden" ocupe el mismo espacio y
 * peso visual que información realmente urgente.
 *
 * Consume el endpoint de solo lectura api/dashboard_resumen.php.
 * No depende de ni modifica inventario.js / control.js.
 */

(function () {
  const avisoVencidos = document.getElementById('avisoVencidos');
  const avisoIncidencias = document.getElementById('avisoIncidencias');
  const avisoSoporte = document.getElementById('avisoSoporte');

  const hayPanelOperativo = avisoVencidos || avisoIncidencias || avisoSoporte;

  // ---------------------------------------------------------------
  // Aviso de préstamos vencidos: reutiliza el mismo dato que ya podía
  // consultarse filtrando manualmente Movimientos por "Vencido", pero lo
  // sube a la vista principal para que nadie tenga que ir a buscarlo.
  // Es la única vía directa a "ver vencidos" fuera del propio filtro de
  // Movimientos: solo aparece cuando hay al menos uno, así que no compite
  // con un botón fijo que estuviera siempre visible sin necesidad.
  // ---------------------------------------------------------------
  function renderAvisoVencidos(total) {
    const texto = document.getElementById('avisoVencidosTexto');
    if (!avisoVencidos || !texto) return;

    if (!total) {
      avisoVencidos.classList.add('d-none');
      return;
    }

    texto.textContent = total === 1
      ? 'Hay 1 préstamo vencido pendiente de devolución.'
      : `Hay ${total} préstamos vencidos pendientes de devolución.`;
    avisoVencidos.classList.remove('d-none');
  }

  // Incidencias abiertas o en atención: no existe una vista de lista
  // dedicada, así que el aviso incluye su propio detalle desplegable
  // (máx. 5, las más recientes) con acceso directo al expediente del
  // instrumento — reutiliza el mismo offcanvas que abre el Catálogo
  // (window.mostrarDetalleInstrumento, expuesto por inventario.js) en vez
  // de duplicar esa lógica aquí.
  const etiquetasMotivoIncidencia = {
    dano_fisico: 'Daño físico',
    mal_funcionamiento: 'Mal funcionamiento',
    piezas_faltantes: 'Piezas faltantes',
    desgaste: 'Desgaste por uso',
    otro: 'Otro',
  };

  function renderAvisoIncidencias(total, detalle) {
    const texto = document.getElementById('avisoIncidenciasTexto');
    const lista = document.getElementById('listaIncidencias');
    if (!avisoIncidencias || !texto) return;

    if (!total) {
      avisoIncidencias.classList.add('d-none');
      return;
    }

    texto.textContent = total === 1
      ? 'Hay 1 incidencia pendiente de revisión.'
      : `Hay ${total} incidencias pendientes de revisión.`;
    avisoIncidencias.classList.remove('d-none');

    if (lista) {
      lista.innerHTML = (detalle || []).map((inc) => {
        const motivo = etiquetasMotivoIncidencia[inc.motivo] || 'Incidencia reportada';
        return `
          <li>
            <button type="button" class="incidencia-item" data-instrumento-id="${inc.instrumento_id}">
              <span>
                <strong>${sanearHTMLLocal(inc.num_inventario)}</strong> · ${sanearHTMLLocal(inc.nombre)}
                <span class="d-block text-muted" style="font-size:.78rem;">${sanearHTMLLocal(motivo)}</span>
              </span>
              <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
          </li>`;
      }).join('') || '<li class="text-muted">Sin detalle disponible.</li>';
    }
  }

  // Escape mínimo para texto insertado como HTML (mismo criterio que
  // sanearHTML en inventario.js; se define localmente para no depender de
  // ese archivo).
  function sanearHTMLLocal(texto) {
    const div = document.createElement('div');
    div.textContent = texto ?? '';
    return div.innerHTML;
  }

  const btnToggleIncidencias = document.getElementById('btnToggleIncidencias');
  const listaIncidencias = document.getElementById('listaIncidencias');
  if (btnToggleIncidencias && listaIncidencias) {
    btnToggleIncidencias.addEventListener('click', () => {
      const expandido = listaIncidencias.classList.toggle('d-none') === false;
      btnToggleIncidencias.setAttribute('aria-expanded', String(expandido));
      btnToggleIncidencias.innerHTML = expandido
        ? 'Ocultar detalle <i class="bi bi-chevron-up" aria-hidden="true"></i>'
        : 'Ver detalle <i class="bi bi-chevron-down" aria-hidden="true"></i>';
    });
    listaIncidencias.addEventListener('click', (e) => {
      const btn = e.target.closest('.incidencia-item');
      if (!btn) return;
      const id = btn.dataset.instrumentoId;
      if (id && window.mostrarDetalleInstrumento) window.mostrarDetalleInstrumento(id);
    });
  }

  // Reportes de soporte técnico pendientes o en atención: sí tienen una
  // vista de lista dedicada (soporte.php), así que el aviso enlaza
  // directamente ahí.
  function renderAvisoSoporte(total) {
    const texto = document.getElementById('avisoSoporteTexto');
    if (!avisoSoporte || !texto) return;

    if (!total) {
      avisoSoporte.classList.add('d-none');
      return;
    }

    texto.textContent = total === 1
      ? 'Hay 1 reporte de soporte pendiente de atención.'
      : `Hay ${total} reportes de soporte pendientes de atención.`;
    avisoSoporte.classList.remove('d-none');
  }

  // Cambia a la pestaña Movimientos con el filtro de estado preseleccionado.
  // Punto único para todo lo que en el panel principal significa "ver estos
  // instrumentos en Movimientos": el aviso de vencidos y las tarjetas mini
  // de "Actualmente asignados" / "En mantenimiento" comparten exactamente
  // el mismo comportamiento, así que no tiene sentido repetirlo tres veces.
  function irAMovimientosFiltrado(estado) {
    const tabControl = document.getElementById('tabControlBtn');
    const filtroEstado = document.getElementById('filtroEstado');
    if (filtroEstado) filtroEstado.value = estado;
    if (tabControl) tabControl.click(); // reutiliza el cambio de pestaña ya existente en main.js
    else if (window.cargarControl) window.cargarControl(1);
  }

  const btnVerVencidos = document.getElementById('btnVerVencidos');
  if (btnVerVencidos) {
    btnVerVencidos.addEventListener('click', () => irAMovimientosFiltrado('vencido'));
  }

  // Tarjetas mini del panel principal: "Actualmente asignados" y "En
  // mantenimiento" son atajos directos a Movimientos ya filtrado por ese
  // estado. "Patrimonio total" no tiene un filtro equivalente en Movimientos
  // (es el conteo del catálogo completo), por eso se queda como texto simple.
  const kpiMiniEnUso = document.getElementById('kpiMiniEnUso');
  if (kpiMiniEnUso) {
    kpiMiniEnUso.addEventListener('click', () => irAMovimientosFiltrado('en_uso'));
  }

  const kpiMiniMantenimiento = document.getElementById('kpiMiniMantenimiento');
  if (kpiMiniMantenimiento) {
    kpiMiniMantenimiento.addEventListener('click', () => irAMovimientosFiltrado('en_reparacion'));
  }

  if (!hayPanelOperativo) return;

  async function cargarResumenOperativo() {
    try {
      const resp = await fetch('api/dashboard_resumen.php');
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'No se pudo cargar el resumen operativo.');

      renderAvisoVencidos(data.prestamos_vencidos || 0);
      renderAvisoIncidencias(data.incidencias_pendientes || 0, data.incidencias_detalle || []);
      renderAvisoSoporte(data.soporte_pendientes || 0);
    } catch (e) {
      // Si falla la carga, no se muestran avisos con datos inciertos:
      // se ocultan (en vez de mostrar "—" en una tarjeta siempre visible)
      // para no aparentar una alerta que no se pudo confirmar.
      [avisoVencidos, avisoIncidencias, avisoSoporte].forEach((el) => {
        if (el) el.classList.add('d-none');
      });
    }
  }

  // Expuesto para que otras acciones del panel (p. ej. resolver una
  // incidencia desde el offcanvas de detalle) puedan refrescar estos
  // avisos de inmediato, sin esperar a recargar la página.
  window.actualizarResumenOperativo = cargarResumenOperativo;

  document.addEventListener('DOMContentLoaded', cargarResumenOperativo);
  if (document.readyState !== 'loading') cargarResumenOperativo();
})();
