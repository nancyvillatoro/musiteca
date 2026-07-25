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
 * Revisión de jerarquía visual: vencidos y reportes de soporte pendientes
 * dejaron de ser tarjetas KPI fijas (visibles siempre, incluso marcando 0
 * la mayoría de los días) y pasaron a ser avisos condicionales que solo se
 * muestran cuando su conteo es mayor que cero. Esto evita que información
 * de "todo en orden" ocupe el mismo espacio y peso visual que información
 * realmente urgente.
 *
 * El aviso de incidencias pendientes que existía aquí se retiró: en la
 * práctica casi siempre describía lo mismo que la tarjeta "En
 * mantenimiento" del panel de KPI, y los pocos casos donde divergía
 * (incidencia reportada durante un préstamo activo, o sin préstamo
 * asociado) siguen siendo visibles y accionables desde la ficha del
 * instrumento en el Catálogo, solo que sin un aviso propio en el
 * dashboard.
 *
 * Consume el endpoint de solo lectura api/dashboard_resumen.php.
 * No depende de ni modifica inventario.js / control.js.
 */

(function () {
  const avisoVencidos = document.getElementById('avisoVencidos');
  const avisoSoporte = document.getElementById('avisoSoporte');

  const hayPanelOperativo = avisoVencidos || avisoSoporte;

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
      renderAvisoSoporte(data.soporte_pendientes || 0);
    } catch (e) {
      // Si falla la carga, no se muestran avisos con datos inciertos:
      // se ocultan (en vez de mostrar "—" en una tarjeta siempre visible)
      // para no aparentar una alerta que no se pudo confirmar.
      [avisoVencidos, avisoSoporte].forEach((el) => {
        if (el) el.classList.add('d-none');
      });
    }
  }

  // Expuesto para que otras acciones del panel (p. ej. registrar una
  // devolución desde el offcanvas de detalle) puedan refrescar estos
  // avisos de inmediato, sin esperar a recargar la página.
  window.actualizarResumenOperativo = cargarResumenOperativo;

  document.addEventListener('DOMContentLoaded', cargarResumenOperativo);
  if (document.readyState !== 'loading') cargarResumenOperativo();
})();
