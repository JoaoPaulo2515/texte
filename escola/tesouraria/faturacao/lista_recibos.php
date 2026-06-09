<?php
// escola/tesouraria/faturacao/lista_recibos.php - Lista de Recibos
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Recibos | Faturação | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        .recibo-row { cursor: pointer; transition: all 0.2s; }
        .recibo-row:hover { background: #e8f5e9; transform: translateX(5px); }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-invoice"></i> Recibos de Faturação</h2>
                <p class="text-muted">Lista de recibos de pagamento</p>
            </div>
            <div>
                <a href="../index.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Últimos Recibos</h5>
            </div>
            <div class="card-body">
                <?php if (empty($lista_recibos)): ?>
                    <div class="alert alert-info text-center">Nenhum recibo encontrado.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>Data</th><th>Nº Fatura</th><th>Aluno</th><th>Valor</th><th>Forma</th><th>Ações</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lista_recibos as $recibo): ?>
                                <tr class="recibo-row" onclick="window.location.href='recibos.php?pagamento_id=<?php echo $recibo['id']; ?>'">
                                    <td><?php echo date('d/m/Y', strtotime($recibo['data_pagamento'])); ?></td>
                                    <td><span class="badge bg-dark"><?php echo htmlspecialchars($recibo['numero_fatura']); ?></span></td>
                                    <td><?php echo htmlspecialchars($recibo['aluno_nome']); ?></td>
                                    <td class="text-success fw-bold"><?php echo number_format($recibo['valor'], 2, ',', '.') . ' Kz'; ?></td>
                                    <td><?php echo ucfirst($recibo['metodo_pagamento']); ?></td>
                                    <td>
                                        <a href="recibos.php?pagamento_id=<?php echo $recibo['id']; ?>" class="btn btn-sm btn-info" target="_blank">
                                            <i class="fas fa-eye"></i> Ver
                                        </a>
                                        <a href="recibos.php?pagamento_id=<?php echo $recibo['id']; ?>&format=termico" class="btn btn-sm btn-secondary" target="_blank">
                                            <i class="fas fa-print"></i> Térmico
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
    </script>
</body>
</html>