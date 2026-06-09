<?php
// escola/rh/documentacao/index.php - Gestão Documental
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Processar upload de documento
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['documento'])) {
    $tipo = $_POST['tipo_documento'];
    $descricao = $_POST['descricao'];
    $categoria = $_POST['categoria'];
    
    $upload_dir = __DIR__ . '/../../../uploads/rh/documentos/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    
    $ext = strtolower(pathinfo($_FILES['documento']['name'], PATHINFO_EXTENSION));
    $nome_arquivo = $tipo . '_' . time() . '_' . uniqid() . '.' . $ext;
    
    if (move_uploaded_file($_FILES['documento']['tmp_name'], $upload_dir . $nome_arquivo)) {
        $stmt = $conn->prepare("
            INSERT INTO rh_documentos (escola_id, tipo, categoria, descricao, nome_arquivo, caminho_arquivo, data_upload)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$escola_id, $tipo, $categoria, $descricao, $nome_arquivo, 'uploads/rh/documentos/' . $nome_arquivo]);
        $success = "Documento enviado com sucesso!";
    } else {
        $error = "Erro ao enviar documento.";
    }
}

// Buscar documentos
$stmt = $conn->prepare("SELECT * FROM rh_documentos WHERE escola_id = ? ORDER BY data_upload DESC");
$stmt->execute([$escola_id]);
$documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tipos_documento = [
    'contrato' => 'Contrato de Trabalho',
    'declaracao' => 'Declaração',
    'avaliacao' => 'Avaliação de Desempenho',
    'formacao' => 'Certificado de Formação',
    'comunicado' => 'Comunicado Interno',
    'regulamento' => 'Regulamento',
    'outro' => 'Outro'
];

$categorias = ['RH', 'Financeiro', 'Pedagógico', 'Legal', 'Outros'];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentação RH | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .documento-icon { font-size: 3em; color: #006B3E; }
    </style>
</head>
<body>
    
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-folder-open"></i> Gestão Documental</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalUploadDocumento">
                <i class="fas fa-upload"></i> Upload Documento
            </button>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-folder"></i> Documentos RH
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaDocumentos">
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Categoria</th>
                                <th>Descrição</th>
                                <th>Data Upload</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documentos as $doc): ?>
                            <tr>
                                <td>
                                    <i class="fas fa-<?php 
                                        echo $doc['tipo'] == 'contrato' ? 'file-contract' : 
                                            ($doc['tipo'] == 'declaracao' ? 'file-alt' : 
                                            ($doc['tipo'] == 'avaliacao' ? 'star' : 
                                            ($doc['tipo'] == 'formacao' ? 'certificate' : 'file'))); 
                                    ?> fa-2x documento-icon"></i>
                                    <br><?php echo $tipos_documento[$doc['tipo']] ?? $doc['tipo']; ?>
                                </td>
                                <td><span class="badge bg-secondary"><?php echo $doc['categoria']; ?></span></td>
                                <td><?php echo htmlspecialchars($doc['descricao']); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($doc['data_upload'])); ?></td>
                                <td>
                                    <a href="../../../<?php echo $doc['caminho_arquivo']; ?>" target="_blank" class="btn btn-sm btn-primary">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <button class="btn btn-sm btn-danger" onclick="excluirDocumento(<?php echo $doc['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Upload Documento -->
    <div class="modal fade" id="modalUploadDocumento" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-upload"></i> Upload de Documento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Tipo de Documento</label>
                            <select name="tipo_documento" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($tipos_documento as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Categoria</label>
                            <select name="categoria" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($categorias as $cat): ?>
                                    <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Descrição</label>
                            <textarea name="descricao" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label>Arquivo (PDF, DOC, JPG, PNG)</label>
                            <input type="file" name="documento" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Enviar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
                const submenu = parent.querySelector('.nav-submenu');
                if (submenu) submenu.classList.toggle('show');
            }
        }
        
        $(document).ready(function() {
            $('#tabelaDocumentos').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
                },
                order: [[3, 'desc']]
            });
        });
        
        function excluirDocumento(id) {
            if (confirm('Tem certeza que deseja excluir este documento?')) {
                window.location.href = 'excluir_documento.php?id=' + id;
            }
        }
    </script>
</body>
</html>