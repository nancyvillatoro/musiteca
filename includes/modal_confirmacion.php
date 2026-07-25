<!-- ============================================================
     Modal global de Confirmación / Aviso
     Un solo modal reutilizable en toda la app (dashboard y app móvil)
     controlado desde assets/js/main.js -> confirmarAccion() / avisar()
     ============================================================ -->
<div class="modal fade" id="modalConfirmacionGlobal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content confirm-modal-content">
      <div class="modal-body text-center p-4">
        <div class="confirm-modal-icono mb-3" id="confirmIcono">
          <i class="bi"></i>
        </div>
        <h5 class="fw-bold mb-2" id="confirmTitulo">¿Confirmar acción?</h5>
        <p class="text-muted mb-0" id="confirmMensaje">¿Deseas continuar?</p>
      </div>
      <div class="modal-footer border-0 justify-content-center pb-4 gap-2">
        <button type="button" class="btn btn-outline-secondary px-4" id="confirmBtnCancelar">Cancelar</button>
        <button type="button" class="btn px-4" id="confirmBtnAceptar">Confirmar</button>
      </div>
    </div>
  </div>
</div>
