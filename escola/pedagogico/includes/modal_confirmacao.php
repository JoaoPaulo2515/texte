<?php
// includes/modal_confirmacao.php - Modal de Confirmação Global
?>

<!-- Modal de Confirmação Global -->
<div id="modalConfirmacaoGlobal" class="modal-custom-global" style="display: none;">
    <div class="modal-custom-global-content">
        <div class="modal-custom-global-header">
            <h3 id="confirmacaoGlobalTitulo"><i class="fas fa-question-circle"></i> Confirmar Ação</h3>
            <span class="close-modal-global" onclick="fecharModalConfirmacaoGlobal()">&times;</span>
        </div>
        <div class="modal-custom-global-body">
            <p id="confirmacaoGlobalMensagem"></p>
            <div id="confirmacaoGlobalDetalhes" class="mt-2 text-muted small"></div>
        </div>
        <div class="modal-custom-global-footer">
            <button class="btn-cancelar-global" onclick="fecharModalConfirmacaoGlobal()">Cancelar</button>
            <button class="btn-confirmar-global" onclick="executarAcaoConfirmadaGlobal()">Confirmar</button>
        </div>
    </div>
</div>

<!-- Modal de Informação Global -->
<div id="modalInfoGlobal" class="modal-custom-global" style="display: none;">
    <div class="modal-custom-global-content" style="max-width: 400px;">
        <div class="modal-custom-global-header">
            <h3><i class="fas fa-info-circle"></i> Informação</h3>
            <span class="close-modal-global" onclick="fecharModalInfoGlobal()">&times;</span>
        </div>
        <div class="modal-custom-global-body">
            <p id="infoGlobalMensagem"></p>
        </div>
        <div class="modal-custom-global-footer">
            <button class="btn-info-global" onclick="fecharModalInfoGlobal()">OK</button>
        </div>
    </div>
</div>

<!-- Modal de Erro Global -->
<div id="modalErroGlobal" class="modal-custom-global" style="display: none;">
    <div class="modal-custom-global-content" style="max-width: 400px;">
        <div class="modal-custom-global-header">
            <h3><i class="fas fa-exclamation-triangle"></i> Erro</h3>
            <span class="close-modal-global" onclick="fecharModalErroGlobal()">&times;</span>
        </div>
        <div class="modal-custom-global-body">
            <p id="erroGlobalMensagem"></p>
        </div>
        <div class="modal-custom-global-footer">
            <button class="btn-info-global" onclick="fecharModalErroGlobal()">OK</button>
        </div>
    </div>
</div>

<style>
    .modal-custom-global {
        display: none;
        position: fixed;
        z-index: 100000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    .modal-custom-global-content {
        background: white;
        margin: 10% auto;
        width: 90%;
        max-width: 500px;
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        animation: modalSlideIn 0.3s ease;
    }
    @keyframes modalSlideIn {
        from { transform: translateY(-50px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    .modal-custom-global-header {
        background: linear-gradient(135deg, #1e5799, #2c3e50);
        color: white;
        padding: 15px 20px;
        border-radius: 16px 16px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .modal-custom-global-header h3 {
        margin: 0;
        font-size: 18px;
    }
    .close-modal-global {
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        transition: 0.3s;
    }
    .close-modal-global:hover { color: #ddd; }
    .modal-custom-global-body {
        padding: 20px;
        font-size: 14px;
        line-height: 1.5;
    }
    .modal-custom-global-footer {
        padding: 15px 20px;
        border-top: 1px solid #e9ecef;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    .btn-cancelar-global {
        background: #6c757d;
        color: white;
        padding: 8px 20px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    .btn-cancelar-global:hover { background: #5a6268; transform: translateY(-2px); }
    .btn-confirmar-global {
        background: linear-gradient(135deg, #dc3545, #c82333);
        color: white;
        padding: 8px 20px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    .btn-confirmar-global:hover { transform: translateY(-2px); box-shadow: 0 3px 10px rgba(220,53,69,0.3); }
    .btn-info-global {
        background: linear-gradient(135deg, #17a2b8, #138496);
        color: white;
        padding: 8px 20px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    .btn-info-global:hover { transform: translateY(-2px); }
    @media (max-width: 768px) {
        .modal-custom-global-content { width: 95%; margin: 20% auto; }
    }
</style>

<script>
    let acaoConfirmadaGlobalCallback = null;
    let confirmacaoGlobalData = null;
    
    function showModalConfirmacaoGlobal(titulo, mensagem, callback, detalhes = null, data = null) {
        const modal = document.getElementById('modalConfirmacaoGlobal');
        if (!modal) return;
        
        document.getElementById('confirmacaoGlobalTitulo').innerHTML = titulo;
        document.getElementById('confirmacaoGlobalMensagem').innerHTML = mensagem;
        
        const detalhesDiv = document.getElementById('confirmacaoGlobalDetalhes');
        if (detalhes && detalhesDiv) {
            detalhesDiv.innerHTML = detalhes;
            detalhesDiv.style.display = 'block';
        } else if (detalhesDiv) {
            detalhesDiv.style.display = 'none';
        }
        
        acaoConfirmadaGlobalCallback = callback;
        confirmacaoGlobalData = data;
        modal.style.display = 'block';
    }
    
    function showModalInfoGlobal(mensagem) {
        const modal = document.getElementById('modalInfoGlobal');
        if (!modal) return;
        
        document.getElementById('infoGlobalMensagem').innerHTML = mensagem;
        modal.style.display = 'block';
    }
    
    function showModalErroGlobal(mensagem) {
        const modal = document.getElementById('modalErroGlobal');
        if (!modal) return;
        
        document.getElementById('erroGlobalMensagem').innerHTML = mensagem;
        modal.style.display = 'block';
    }
    
    function fecharModalConfirmacaoGlobal() {
        const modal = document.getElementById('modalConfirmacaoGlobal');
        if (modal) modal.style.display = 'none';
        acaoConfirmadaGlobalCallback = null;
        confirmacaoGlobalData = null;
    }
    
    function fecharModalInfoGlobal() {
        const modal = document.getElementById('modalInfoGlobal');
        if (modal) modal.style.display = 'none';
    }
    
    function fecharModalErroGlobal() {
        const modal = document.getElementById('modalErroGlobal');
        if (modal) modal.style.display = 'none';
    }
    
    function executarAcaoConfirmadaGlobal() {
        if (acaoConfirmadaGlobalCallback) {
            acaoConfirmadaGlobalCallback(confirmacaoGlobalData);
        }
        fecharModalConfirmacaoGlobal();
    }
    
    window.onclick = function(event) {
        const modalConfirmacao = document.getElementById('modalConfirmacaoGlobal');
        const modalInfo = document.getElementById('modalInfoGlobal');
        const modalErro = document.getElementById('modalErroGlobal');
        
        if (event.target == modalConfirmacao) fecharModalConfirmacaoGlobal();
        if (event.target == modalInfo) fecharModalInfoGlobal();
        if (event.target == modalErro) fecharModalErroGlobal();
    }
</script>