<?php
// escola/includes/zoom_config.php - Configuração de Zoom Padronizado

// Definir nível de zoom padrão (em porcentagem)
$zoom_padrao = 90; // 90%

// Permitir que o usuário altere via GET
if (isset($_GET['zoom'])) {
    $zoom = (int)$_GET['zoom'];
    if ($zoom >= 50 && $zoom <= 150) {
        $_SESSION['user_zoom'] = $zoom;
    }
}

// Pegar zoom da sessão ou usar padrão
$zoom_atual = $_SESSION['user_zoom'] ?? $zoom_padrao;
?>

<style>
    /* Aplicar zoom via CSS */
    body {
        zoom: <?php echo $zoom_atual; ?>%;
        -moz-transform: scale(<?php echo $zoom_atual / 100; ?>);
        -moz-transform-origin: top left;
    }
    
    /* Para Firefox puro */
    @-moz-document url-prefix() {
        body {
            transform: scale(<?php echo $zoom_atual / 100; ?>);
            transform-origin: top left;
            width: <?php echo 100 / ($zoom_atual / 100); ?>%;
        }
    }
</style>

<script>
    // Salvar zoom no localStorage
    var zoomAtual = <?php echo $zoom_atual; ?>;
    
    function aplicarZoom(nivel) {
        document.body.style.zoom = nivel + '%';
        document.body.style.MozTransform = 'scale(' + (nivel / 100) + ')';
        document.body.style.MozTransformOrigin = 'top left';
        
        // Salvar no localStorage
        localStorage.setItem('zoom_nivel', nivel);
        
        // Opcional: salvar via AJAX na sessão
        fetch(window.location.href.split('?')[0] + '?zoom=' + nivel, { method: 'GET' });
    }
    
    // Carregar zoom salvo
    var zoomSalvo = localStorage.getItem('zoom_nivel');
    if (zoomSalvo && zoomSalvo != zoomAtual) {
        aplicarZoom(parseInt(zoomSalvo));
    }
</script>