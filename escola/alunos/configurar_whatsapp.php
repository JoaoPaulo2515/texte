<!-- escola/admin/configurar_whatsapp.php -->
<div class="card">
    <div class="card-header">
        <h5>Configuração do WhatsApp Institucional</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="salvar_config_whatsapp.php">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label>Número do WhatsApp da Escola</label>
                        <input type="text" name="whatsapp" class="form-control" 
                               placeholder="Ex: 244923456789" value="<?php echo $escola['whatsapp']; ?>">
                        <small>Número que aparecerá como remetente</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label>WhatsApp Business ID (opcional)</label>
                        <input type="text" name="whatsapp_business_id" class="form-control" 
                               value="<?php echo $escola['whatsapp_business_id']; ?>">
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="mb-3">
                        <label>Access Token (opcional)</label>
                        <textarea name="whatsapp_token" class="form-control" rows="3"><?php echo $escola['whatsapp_token']; ?></textarea>
                        <small>Necessário apenas para API do WhatsApp Business</small>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Salvar Configuração</button>
        </form>
    </div>
</div>