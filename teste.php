<?php
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/models/Produto.php';
require_once __DIR__ . '/app/models/ProdutoVariacao.php';
require_once __DIR__ . '/app/models/Venda.php';
require_once __DIR__ . '/app/models/VendaProduto.php';
require_once __DIR__ . '/app/models/ItemVenda.php';
require_once __DIR__ . '/app/models/Relatorio.php';


$variacaoModel   = new ProdutoVariacao($mysqli);   // ← <— Nova linha

use App\Models\Relatorio;

$relatorioModel = new Relatorio($mysqli);

// exemplo de uso
$ano = date('Y');
$faturMes = $relatorioModel->getFaturamentoMensal($ano);

$semanaInicio = '2025-06-01';
$semanaFim    = '2025-06-07';
$faturSem     = $relatorioModel->getFaturamentoPorPeriodo($semanaInicio, $semanaFim);

$maisVendidos = $relatorioModel->getTopProdutosVendidos($semanaInicio, $semanaFim, 1)[0];


$mesInicio = date('Y-m-01');
$mesFim    = date('Y-m-t');
$top5Cli   = $relatorioModel->getTopClientesPorFaturamento($mesInicio, $mesFim, 5);

$estatisticas = $relatorioModel->getEstatisticasDashboard();

session_start();


// Tempo máximo de inatividade em segundos (1 hora = 3600 segundos)
$tempoMaximo = 3600; 

// Verifica se o usuário está logado
if (!isset($_SESSION['id_usuario'])) {
    header("Location: Public/login/teste.php");
    exit;
}


// Verifica se existe um timestamp de atividade registrado
if (isset($_SESSION['ultimo_acesso'])) {
    $tempoInativo = time() - $_SESSION['ultimo_acesso'];
    
    if ($tempoInativo > $tempoMaximo) {
        // Destroi a sessão e redireciona para a página de login
        session_unset();
        session_destroy();
        header("Location: Public/login/teste.php?message=Sua sessão expirou. Por favor, faça login novamente.");
        exit;
    }
}

// Atualiza o timestamp de atividade para o tempo atual
$_SESSION['ultimo_acesso'] = time();

// Inicializa os modelos
$produtoModel   = new Produto($mysqli);
$vendaModel     = new Venda($mysqli);
$itemVendaModel = new ItemVenda($mysqli);

// 1) Estatísticas básicas
$estatisticas   = $vendaModel->getEstatisticasDashboard();
$produtos       = $produtoModel->getAll();
$vendas         = $vendaModel->getAll();

// 2) Valor total do estoque (baseado no preço de venda, não em 'preco_custo')
$valorEstoqueTotal = 0;
foreach ($produtos as $prod) {
    // Busca todas as variações (se houver) para este produto
    $variacoes = $variacaoModel->getAllByProduto($prod['id_produto']);
    
    if (count($variacoes) > 0) {
        // Se houver variações, ignora o estoque "pai" e soma apenas as variações:
        foreach ($variacoes as $var) {
            // preco_venda e estoque_atual já existem em produto_variacoes
            $precoVar   = (float) $var['preco_venda'];
            $qtdVar     = (int)   $var['estoque_atual'];
            $valorEstoqueTotal += $precoVar * $qtdVar;
        }
    } else {
        // Se NÃO houver variações, usa o preço de venda do produto "pai":
        $precoPai   = (float) $prod['preco_venda'];
        $qtdPai     = (int)   $prod['estoque_atual'];
        $valorEstoqueTotal += $precoPai * $qtdPai;
    }
}


// 3) Faturamento por mês (jan-dez)
$vendasPorMes = [];
for ($i = 1; $i <= 12; $i++) {
    $vendasPorMes[$i] = 0;
}
foreach ($vendas as $venda) {
    $mes = (int) date('n', strtotime($venda['data_venda']));
    $vendasPorMes[$mes] += (float)$venda['total_venda'];
}

// 4) Vendas dos últimos 7 dias
$vendasPorDia = [];
for ($i = 6; $i >= 0; $i--) {
    $data = date('Y-m-d', strtotime("-$i days"));
    $vendasPorDia[$data] = 0;
}
foreach ($vendas as $v) {
    $dataVenda = date('Y-m-d', strtotime($v['data_venda']));
    if (isset($vendasPorDia[$dataVenda])) {
        $vendasPorDia[$dataVenda] += (float)$v['total_venda'];
    }
}

// 5) Distribuição de estoque (pizza) incluindo variações
$produtosNomes  = [];
$estoquesAtuais = [];
foreach ($produtos as $prod) {
    $vars = $variacaoModel->getAllByProduto($prod['id_produto']);
    if (count($vars) > 0) {
        $sum = 0;
        foreach ($vars as $v) {
            $sum += $v['estoque_atual'];
        }
    } else {
        $sum = (int)$prod['estoque_atual'];
    }
    $produtosNomes[]  = $prod['nome'];
    $estoquesAtuais[] = $sum;
}

// 6) Top 5 Produtos (barras) por estoque somando variações
$tmp = [];
foreach ($produtos as $prod) {
    $vars = $variacaoModel->getAllByProduto($prod['id_produto']);
    $sum  = array_sum(array_column($vars, 'estoque_atual'));
    if ($sum === 0) {
        $sum = (int)$prod['estoque_atual'];
    }
    $tmp[$prod['nome']] = $sum;
}
arsort($tmp);
$topProdutos = array_slice($tmp, 0, 5, true);

// ================= ADICIONANDO MAIS GRÁFICOS ================= //

// A) Novos Clientes por Mês (exemplo) -- Ajuste conforme a estrutura real
//     Precisaria de uma tabela `clientes` com col. 'data_cadastro' ou similar
//     Exemplo fictício:
$clientesPorMes = [1=>0,2=>0,3=>0,4=>0,5=>0,6=>0,7=>0,8=>0,9=>0,10=>0,11=>0,12=>0];
$sqlClientes = "SELECT data_cadastro FROM clientes";
$resClientes = $mysqli->query($sqlClientes);
if ($resClientes) {
    while ($row = $resClientes->fetch_assoc()) {
        // Extrai o mês
        $mesCli = (int) date('n', strtotime($row['data_cadastro']));
        $clientesPorMes[$mesCli]++;
    }
}

// B) Top 5 Clientes por Faturamento (exemplo):
//     Precisaria somar total_venda de cada cliente
$topClientes = []; 
$sqlTopClientes = "
  SELECT c.nome AS cliente, SUM(v.total_venda) AS faturamento
  FROM vendas v
  JOIN clientes c ON v.id_cliente = c.id_cliente
  GROUP BY v.id_cliente
  ORDER BY SUM(v.total_venda) DESC
  LIMIT 5
";
$resTopClientes = $mysqli->query($sqlTopClientes);
if ($resTopClientes) {
    while ($row = $resTopClientes->fetch_assoc()) {
        $topClientes[$row['cliente']] = (float)$row['faturamento'];
    }
}
$topProdutosVendidos = $relatorioModel
  ->getTopProdutosVendidos($semanaInicio, $semanaFim, 5);
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Relatórios | Sistema de Gestão</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- Google Fonts - Poppins -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <!-- AOS - Animate On Scroll -->
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome para ícones -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Animate.css para animações -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">

    <!-- Google Fonts - Poppins -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style type="text/css" media="print">
    /* 1.1. Ocultar elementos não relacionados aos gráficos */
    .sidebar,
    .header,
    .breadcrumb-container,
    .stat-card,
    .bottom-nav,
    .mobile-header,
    .mobile-drawer,
    .drawer-overlay,
    .search-bar,
    .page-header,
    .mobile-tabs,
    .mobile-stats,
    .no-print {
        display: none !important;
    }


    /* 1.3. Garantir que os cartões dos gráficos não sejam quebrados entre páginas */
    .chart-card {
        page-break-inside: avoid;
        /* Para navegadores mais antigos */
        break-inside: avoid;
        /* Para navegadores modernos */
        margin: 20px auto;
        width: 90%;
        height: 122px;
    }

    /* 1.4. Forçar os canvas (ou o container de cada gráfico) a se ajustarem à página */
    /* Você pode definir um tamanho máximo e também permitir que se adaptem ao tamanho da página */
    .chart-container {
        width: 100% !important;
        height: auto !important;
        /* Permite que a altura se ajuste proporcionalmente */
        max-height: 90vh;
        /* Garante que o gráfico não ultrapasse a altura da página (90% da viewport height) */
    }

    /* 1.5. Ajustar a impressão para evitar overflow: o canvas deve se redimensionar sem ultrapassar os limites da página */
    canvas {
        max-width: 100% !important;
        height: auto !important;
    }

    /* 1.6. Remover margens e paddings do body para a impressão */
    body {
        margin: 0;
        padding: 0;
    }
    </style>

    <style>
    :root {
        --primary-color: #4e54e9;
        --primary-hover: #3a40d4;
        --sidebar-width: 240px;
        --header-height: 60px;
        --light-bg: #f8f9fa;
        --border-radius: 8px;
        --card-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    }

    body {
        font-family: 'Poppins', sans-serif;
        background-color: #f8f9fa;
        margin: 0;
        padding: 0;
        overflow-x: hidden;
        max-height: 400px;

    }

    /* Main Content */
    .main-content {
        margin-left: var(--sidebar-width);
        transition: all 0.3s;
    }

    /* Header */
    .header {
        height: var(--header-height);
        background-color: white;
        border-bottom: 1px solid #eaeaea;
        display: flex;
        align-items: center;
        padding: 0 20px;
        position: sticky;
        top: 0;
        z-index: 100;
    }

    .drawer-header {
        display: none;
    }

    .search-bar {
        flex-grow: 1;
        max-width: 500px;
        margin: 0 20px;
    }

    .search-input {
        background-color: #f5f5f5;
        border: none;
        border-radius: 50px;
        padding: 8px 15px 8px 40px;
        width: 100%;
        font-size: 14px;
    }

    .search-icon {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #888;
    }

    .header-actions {
        display: flex;
        align-items: center;
    }

    .header-actions button {
        background: none;
        border: none;
        font-size: 20px;
        color: #555;
        margin-left: 15px;
        cursor: pointer;
        position: relative;
    }

    /* Breadcrumb */
    .breadcrumb-container {
        padding: 15px 20px;
        background-color: white;
        border-bottom: 1px solid #eaeaea;
    }

    /* Page Content */
    .page-content {
        padding: 20px;
    }

    /* Page Header */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;

    }

    .page-title {
        display: flex;
        align-items: center;
        font-size: 24px;
        font-weight: 600;
        color: #333;
    }

    .page-title i {
        margin-right: 10px;
        color: var(--primary-color);
    }

    /* Cards */
    .stat-card {
        background-color: white;
        border-radius: var(--border-radius);
        box-shadow: var(--card-shadow);
        padding: 20px;
        margin-bottom: 20px;
        transition: transform 0.2s;
        border: none;
        display: flex;
        align-items: center;
    }

    .stat-card:hover {
        transform: translateY(-5px);
    }

    .stat-icon {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 15px;
        font-size: 24px;
    }

    .stat-icon.blue {
        background-color: rgba(78, 84, 233, 0.1);
        color: var(--primary-color);
    }

    .stat-icon.green {
        background-color: rgba(46, 204, 113, 0.1);
        color: #2ecc71;
    }

    .stat-icon.orange {
        background-color: rgba(255, 159, 67, 0.1);
        color: #ff9f43;
    }

    .stat-icon.red {
        background-color: rgba(255, 71, 87, 0.1);
        color: #ff4757;
    }

    .stat-info {
        flex-grow: 1;
    }

    .stat-value {
        font-size: 24px;
        font-weight: 600;
        margin-bottom: 5px;
        color: #333;
    }

    .stat-label {
        font-size: 14px;
        color: #777;
    }

    /* Chart Cards */
    .chart-card {
        background-color: white;
        border-radius: var(--border-radius);
        box-shadow: var(--card-shadow);
        margin-bottom: 20px;
        overflow: hidden;
        border: none;
        height: 100%;
    }

    .chart-card .card-header {
        padding: 15px 20px;
        background-color: white;
        border-bottom: 1px solid #eaeaea;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .chart-card .card-header h5 {
        margin: 0;
        font-weight: 600;
        color: #333;
        display: flex;
        align-items: center;
    }

    .chart-card .card-header h5 i {
        margin-right: 10px;
        color: var(--primary-color);
    }

    .chart-card .card-body {
        padding: 5px;
        position: relative;
    }

    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }

    /* Mobile Styles */
    @media (max-width: 992px) {
        .main-content {
            margin-left: 0;
            padding-bottom: 70px;
            /* Space for bottom nav */
        }

        .drawer-menu {
            display: flex;
            flex-direction: column;
        }

        .header {
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .breadcrumb-container {
            display: none;
        }

        /* Mobile Header */
        .mobile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background-color: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .mobile-logo {
            display: flex;
            align-items: center;
            font-size: 20px;
            font-weight: 600;
            color: var(--primary-color);
        }

        .mobile-logo i {
            margin-right: 8px;
            font-size: 24px;
        }

        .mobile-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .mobile-actions button {
            background: none;
            border: none;
            font-size: 20px;
            color: #555;
            position: relative;
        }

        /* Bottom Navigation */
        .bottom-nav {
            display: flex;
            justify-content: space-around;
            align-items: center;
            background-color: white;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 60px;
            z-index: 1000;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #777;
            text-decoration: none;
            font-size: 10px;
            padding: 8px 0;
            width: 20%;
            transition: all 0.2s;
        }

        .nav-item i {
            font-size: 20px;
            margin-bottom: 4px;
        }

        .nav-item.active {
            color: var(--primary-color);
        }

        .nav-item.center-item {
            transform: translateY(-15px);
        }

        .nav-item.center-item .nav-circle {
            width: 50px;
            height: 50px;
            background-color: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        /* Mobile Stats */
        .mobile-stats {
            display: flex;
            overflow-x: auto;
            padding: 10px 0;
            margin: 0 -10px 20px;
            scrollbar-width: none;
            /* Firefox */
        }

        .mobile-stats::-webkit-scrollbar {
            display: none;
            /* Chrome, Safari, Opera */
        }

        .mobile-stat-card {
            min-width: 140px;
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: 15px;
            margin: 0 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .mobile-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            font-size: 20px;
        }

        .mobile-stat-value {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .mobile-stat-label {
            font-size: 12px;
            color: #777;
        }
    }

    /* Dark Mode */
    body.dark-mode {
        background-color: #121212;
        color: #f1f1f1;
    }

    body.dark-mode .sidebar {
        background-color: #1a1a1a;
    }

    body.dark-mode .header,
    body.dark-mode .breadcrumb-container,
    body.dark-mode .stat-card,
    body.dark-mode .chart-card,
    body.dark-mode .card-header,
    body.dark-mode .mobile-header,
    body.dark-mode .mobile-drawer,
    body.dark-mode .bottom-nav {
        background-color: #1e1e1e;
        color: #f1f1f1;
        border-color: #333;
    }

    body.dark-mode .page-title,
    body.dark-mode .card-header h5,
    body.dark-mode .stat-value,
    body.dark-mode .mobile-logo {
        color: #f1f1f1;
    }

    body.dark-mode .stat-label,
    body.dark-mode .text-muted {
        color: #aaa !important;
    }

    body.dark-mode .search-input {
        background-color: #2a2a2a;
        color: #f1f1f1;
        border-color: #333;
    }

    body.dark-mode .drawer-item {
        color: #f1f1f1;
        border-color: #333;
    }

    body.dark-mode .mobile-stat-card {
        background-color: #1e1e1e;
    }

    body.dark-mode .mobile-stat-value {
        color: #f1f1f1;
    }

    body.dark-mode .mobile-stat-label {
        color: #aaa;
    }

    body.dark-mode canvas {
        filter: invert(0.9) hue-rotate(180deg);
    }

    /* Topbar icon for dark mode toggle */
    .topbar-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: transparent;
        border: none;
        cursor: pointer;
        transition: background-color 0.3s;
    }

    .topbar-icon:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }

    body.dark-mode .topbar-icon:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }

    /* Loader */
    .loader {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(255, 255, 255, 0.9);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        transition: opacity 0.5s;
    }

    .spinner {
        width: 50px;
        height: 50px;
        border: 5px solid rgba(78, 84, 233, 0.2);
        border-radius: 50%;
        border-top-color: var(--primary-color);
        animation: spin 1s ease-in-out infinite;
    }

    /* Forçando o fundo e a cor de texto da tabela no modo claro/escuro */
    .table:not(caption) {
        background-color: var(--bg-card) !important;
        color: var(--text-primary) !important;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* Animations */
    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes fadeInDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .fade-in {
        animation: fadeIn 0.5s ease-in-out;
    }

    .fade-in-up {
        animation: fadeInUp 0.5s ease-in-out;
    }

    .fade-in-down {
        animation: fadeInDown 0.5s ease-in-out;
    }

    /* Mobile Animations */
    .mobile-fade-in {
        animation: fadeIn 0.3s ease-in-out;
    }

    .mobile-slide-up {
        animation: slideUp 0.3s ease-in-out;
    }

    @keyframes slideUp {
        from {
            transform: translateY(20px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .mobile-menu-btn,
    [aria-label="Menu"],
    .navbar-toggler {
        position: relative;
        z-index: 1000;
        cursor: pointer;
        pointer-events: auto;
    }

    /* Garantir que o drawer tenha z-index correto */
    .mobile-drawer {
        z-index: 2000;
    }

    .drawer-overlay {
        z-index: 1999;
    }
    </style>
</head>
body>
<!-- Loader -->
<div class="loader" id="pageLoader">
    <div class="spinner"></div>
</div>

<!-- Sidebar backdrop for mobile -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-cube fa-lg"></i>
        <div class="sidebar-logo">Gestão</div>
    </div>

    <div class="sidebar-section">
        <!-- Catálogo -->
        <div class="sidebar-section-title">CATÁLOGO</div>
        <ul class="sidebar-nav">
            <!-- Produtos -->
            <li class="sidebar-nav-item">
                <a href="Public/produtos/teste.php" class="sidebar-nav-link">
                    <i class="fas fa-box"></i>
                    Produtos
                </a>
            </li>

            <!-- Etiquetas -->
            <li class="sidebar-nav-item">
                <a href="Public/etiqueta/teste.php" class="sidebar-nav-link active">
                    <i class="fas fa-tags"></i>
                    Etiquetas
                </a>
            </li>
            </li>

            <!-- Importar -->
            <li class="sidebar-nav-item">
                <a href="Public/importar/teste.php" class="sidebar-nav-link">
                    <i class="fas fa-file-import"></i>
                    Importar
                </a>
            </li>

            <!-- Clientes -->
            <li class="sidebar-nav-item">
                <a href="Public/clientes/clientes.php" class="sidebar-nav-link">
                    <i class="fas fa-users"></i>
                    Clientes
                </a>
            </li>

            <!-- Funcionarios -->
            <li class="sidebar-nav-item">
                <a href="Public/funcionarios/teste.php" class="sidebar-nav-link">
                    <i class="fas fa-user-tie"></i>
                    Funcionários
                </a>
            </li>

            <!-- Troca -->
            <li class="sidebar-nav-item">
                <a href="Public/troca/teste.php" class="sidebar-nav-link">
                    <i class="fa-solid fa-right-left"></i>
                    Troca
                </a>
            </li>

            <!-- vendas -->
            <li class="sidebar-nav-item">
                <a href="Public/vendas/teste.php" class="sidebar-nav-link">
                    <i class="fas fa-shopping-cart"></i>
                    Vendas
                </a>
            </li>
        </ul>
    </div>

    <div class="sidebar-section">
        <!-- Relatorios -->
        <div class="sidebar-section-title">RELATÓRIOS</div>
        <ul class="sidebar-nav">

            <!-- Relatorios -->
            <li class="sidebar-nav-item">
                <a href="teste.php" class="sidebar-nav-link">
                    <i class="fas fa-chart-bar"></i>
                    Relatórios
                </a>
            </li>

            <!-- Financeiro -->
            <li class="sidebar-nav-item">
                <a href="Public/financeiro/teste.php" class="sidebar-nav-link">
                    <i class="fas fa-wallet"></i>
                    Financeiro
                </a>
            </li>
        </ul>
    </div>

    <div class="sidebar-section">
        <!-- Despesas -->
        <div class="sidebar-section-title">Despesas</div>
        <ul class="sidebar-nav">
            <!-- Despesas gerais -->
            <li class="sidebar-nav-item">
                <a href="Public/despesas/teste.php?view=despesas&page=1" class="sidebar-nav-link">
                    <i class="fas fa-file-invoice-dollar"></i>
                    Despesas Gerais
                </a>
            </li>

            <!-- Linha divisória -->
            <li class="sidebar-nav-item">
                <div class="w-100 my-3" style="height: 1px; background-color: #fff;"></div>
            </li>

            <!-- Desconectar -->
            <li class="sidebar-nav-item">
                <a href="Public/login/logout.php"
                    class="sidebar-nav-link text-white fw-semibold rounded-2 d-flex align-items-center py-1 px-2 small">
                    <i class="bi bi-box-arrow-right me-1 fs-5"></i> Desconectar
                </a>
            </li>
        </ul>
    </div>
</div>

<style>
:root {
    --primary-color: #5468FF;
    --primary-hover: #4054F2;
    --sidebar-width: 240px;
    --border-radius: 12px;
    --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    --transition-speed: 0.3s;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
    transition: background-color var(--transition-speed), color var(--transition-speed);
    overflow-x: hidden;
    padding-bottom: 60px;
    /* Espaço para o menu mobile */
}

/* Sidebar styles */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: var(--sidebar-width);
    background: linear-gradient(180deg, #5468FF 0%, #4054F2 100%);
    color: white;
    z-index: 100;
    transition: transform var(--transition-speed);
    overflow-y: auto;
}

.sidebar-header {
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.sidebar-logo {
    font-size: 1.5rem;
    font-weight: bold;
}

.sidebar-section {
    margin-top: 1.5rem;
    padding: 0 1rem;
}

.sidebar-section-title {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.7;
    padding: 0 0.5rem;
    margin-bottom: 0.75rem;
}

.sidebar-nav {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sidebar-nav-item {
    margin-bottom: 0.25rem;
}

.sidebar-nav-link {
    display: flex;
    align-items: center;
    padding: 0.75rem 1rem;
    color: white;
    text-decoration: none;
    border-radius: 0.5rem;
    transition: background-color var(--transition-speed);
}

.sidebar-nav-link:hover {
    background-color: rgba(255, 255, 255, 0.1);
}

.sidebar-nav-link i {
    margin-right: 0.75rem;
    font-size: 1.25rem;
    width: 1.5rem;
    text-align: center;
}

@media (max-width: 992px) {
    .sidebar {
        transform: translateX(-100%);
        z-index: 1050;
    }

    .sidebar.show {
        transform: translateX(0);
    }

    .sidebar-toggle {
        display: block;
        position: fixed;
        top: 1rem;
        left: 1rem;
        z-index: 1001;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: var(--primary-color);
        color: white;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
    }

    /* Sidebar backdrop for mobile */
    .sidebar-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1040;
        display: none;
    }

    .sidebar-backdrop.show {
        display: block;
    }

    body.dark-mode .sidebar {
        background-color: var(--bg-sidebar);
    }
}
</style>


<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script src="https://unpkg.com/aos@next/dist/aos.js"></script>
<script>
// Inicializa AOS (Animate On Scroll)
AOS.init({
    duration: 800,
    once: true
});

// Loader: esconde o spinner ao carregar a página
window.addEventListener('load', () => {
    const loader = document.getElementById('pageLoader');
    if (loader) {
        loader.style.opacity = '0';
        setTimeout(() => {
            loader.style.display = 'none';
        }, 500);
    }
});

document.addEventListener('DOMContentLoaded', function() {
    // Sidebar toggle for mobile
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');

    document.getElementById('sidebarToggle').addEventListener('click', function() {
        sidebar.classList.toggle('show');
        backdrop.classList.toggle('show');
    });

    // Close sidebar when clicking on backdrop
    backdrop.addEventListener('click', function() {
        sidebar.classList.remove('show');
        backdrop.classList.remove('show');
    });

    // Close sidebar when clicking on a menu item (mobile)
    const menuItems = document.querySelectorAll('.sidebar-nav-link');
    menuItems.forEach(item => {
        item.addEventListener('click', function() {
            if (window.innerWidth < 992) {
                sidebar.classList.remove('show');
                backdrop.classList.remove('show');
            }
        });
    });

    // Adjust sidebar on window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 992) {
            sidebar.classList.remove('show');
            backdrop.classList.remove('show');
        }
    });
});
</script>
<!-- Main Content -->
<div class="main-content">
    <!-- Header (Desktop) -->
    <div class="header d-none d-lg-flex">
        <div class="search-bar position-relative">
            <i class="bi bi-search search-icon"></i>
            <input type="text" class="search-input" placeholder="Buscar relatórios...">
        </div>
        <div class="header-actions ms-auto">

            <!-- Dark-Mode -->
            <?php include_once 'frontend/includes/darkmode.php'?>
        </div>
    </div>

    <!-- Mobile Header -->
    <div class="mobile-header d-lg-none">
        <div class="mobile-logo">
            <i class="fas fa-cube"></i>
            <span>Gestão</span>
        </div>
        <div class="mobile-actions">
            <button id="btn-darkmode-mobile">
                <i class="bi bi-moon"></i>
            </button>
            <a href="Public/login/logout.php">Sair</a>
        </div>
    </div>

    <!-- Breadcrumb (Desktop) -->
    <div class="breadcrumb-container d-none d-lg-block">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 gap-3">
                <li class="breadcrumb-item"><a href="#"><i class="bi bi-house-door"></i>
                        Home</a></li>
                <li> / </li>
                <li aria-current="page">Relatórios</li>
            </ol>
        </nav>
    </div>

    <!-- Page Content -->
    <div class="page-content">
        <!-- Page Header (Desktop) -->
        <div class="page-header d-none d-lg-flex" data-aos="fade-down">
            <h1 class="page-title">
                <i class="fas fa-chart-bar"></i> Relatórios do Sistema
            </h1>
        </div>



        <?php

$dataInicial  = date('Y-m-01');
$dataFinal    = date('Y-m-t');
$hoje         = date('Y-m-d');


$sqlTotalCusto = "
  SELECT
    COALESCE(SUM(
      iv.quantidade
      * COALESCE(pdirect.preco_custo, pvar.preco_custo)
    ), 0) AS total_custo
  FROM itens_venda iv
  JOIN vendas v
    ON iv.id_venda = v.id_venda
  -- custo direto (produto sem variação)
  LEFT JOIN produtos pdirect
    ON iv.id_produto = pdirect.id_produto
  -- variação e, a partir dela, produto 'pai'
  LEFT JOIN produto_variacoes pv
    ON iv.id_variacao = pv.id_variacao
  LEFT JOIN produtos pvar
    ON pv.id_produto = pvar.id_produto
  WHERE v.data_venda BETWEEN '$dataInicial' AND '$dataFinal'
";
$resultCusto = $mysqli->query($sqlTotalCusto);
$rowCusto    = $resultCusto ? $resultCusto->fetch_assoc() : null;
$totalCusto  = $rowCusto ? floatval($rowCusto['total_custo']) : 0;

$lucroLiquido = $totalCusto ;

$totalBrutoMes     = array_sum($faturMes);
$totalLiquidoMes   = $lucroLiquido;

$totalBrutoSemana  = array_sum($faturSem);
$totalLiquidoSemana= $lucroLiquido;


$meses = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
?>
        <div class="container-fluid">
            <div class="row mb-4">

                <!-- Faturamento Mensal -->
                <?php
  // nomes dos meses
  $meses = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];

// 0) Recebe o mês via GET (YYYY-MM) ou usa o mês corrente
$mesSelecionado = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mesSelecionado)) {
   
 $mesSelecionado = date('Y-m');
}
// extrai ano e mês numéricos
list($ano, $mesNum) = explode('-', $mesSelecionado);

// 1) Mês atual (1–12) baseado no mês selecionado
$mesAtual = (int) $mesNum;

// 2) Faturamento bruto do mês selecionado (usa seu array pré-calculado)
$brutoMesAtual = $faturMes[$mesAtual] ?? 0;

// 3) Calcula deduções e líquido (mantendo sua lógica)
$liquidoMesAtual  = $brutoMesAtual - $lucroLiquido;

// 4) Define intervalo completo do mês selecionado, caso precise de queries adicionais
$dataInicioMes = "{$mesSelecionado}-01 00:00:00";
$dataFimMes    = date('Y-m-t', strtotime("{$mesSelecionado}-01")) . ' 23:59:59';


  // --- Cálculo do Faturamento Diário (hoje) ---
  $hoje           = date('Y-m-d');
  $brutoHoje      = $faturSem[$hoje] ?? 0;
  $deducoesHoje   = $brutoHoje;
  $liquidoHoje    = $brutoHoje - $deducoesHoje;
?>
                <!-- Faturamento Diário -->
                <?php
// 0) Recebe o dia via GET ou usa hoje como default
$diaSelecionado = $_GET['day'] ?? date('Y-m-d');
// opcional: validar formato YYYY-MM-DD
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $diaSelecionado)) {
    $diaSelecionado = date('Y-m-d');
}

// 1) Define intervalo desse dia
$inicioHoje = "$diaSelecionado 00:00:00";
$fimHoje    = "$diaSelecionado 23:59:59";

// 2) Cálculo do Bruto Diário
$sqlBrutoHoje = "
  SELECT COALESCE(SUM(
    iv.quantidade
    * COALESCE(pdirect.preco_venda, pvar.preco_venda)
  ),0) AS total_bruto
  FROM itens_venda iv
  JOIN vendas v ON iv.id_venda = v.id_venda
  LEFT JOIN produtos pdirect ON iv.id_produto = pdirect.id_produto
  LEFT JOIN produto_variacoes pv ON iv.id_variacao = pv.id_variacao
  LEFT JOIN produtos pvar ON pv.id_produto = pvar.id_produto
  WHERE v.data_venda BETWEEN '$inicioHoje' AND '$fimHoje'
";
$resB       = $mysqli->query($sqlBrutoHoje);
$rowB       = $resB ? $resB->fetch_assoc() : null;
$brutoHoje  = $rowB ? floatval($rowB['total_bruto']) : 0;

// 3) Cálculo das Deduções (Custo Diário)
$sqlCustoHoje = "
  SELECT COALESCE(SUM(
    iv.quantidade
    * COALESCE(pdirect.preco_custo, pvar.preco_custo)
  ),0) AS total_custo
  FROM itens_venda iv
  JOIN vendas v ON iv.id_venda = v.id_venda
  LEFT JOIN produtos pdirect ON iv.id_produto = pdirect.id_produto
  LEFT JOIN produto_variacoes pv ON iv.id_variacao = pv.id_variacao
  LEFT JOIN produtos pvar ON pv.id_produto = pvar.id_produto
  WHERE v.data_venda BETWEEN '$inicioHoje' AND '$fimHoje'
";
$resC         = $mysqli->query($sqlCustoHoje);
$rowC         = $resC ? $resC->fetch_assoc() : null;
$deducoesHoje = $rowC ? floatval($rowC['total_custo']) : 0;

// 4) Cálculo do Líquido Diário
$liquidoHoje = $brutoHoje - $deducoesHoje;

// 5) Top 5 Produtos Vendidos (unidades e valor)
$sqlTopHoje = "
  SELECT
    COALESCE(pdirect.nome, pvar.nome) AS nome_produto,
    SUM(iv.quantidade)               AS qtde,
    AVG(
      COALESCE(pdirect.preco_venda, pvar.preco_venda)
    ) AS preco_venda
  FROM itens_venda iv
  JOIN vendas v ON iv.id_venda = v.id_venda
  LEFT JOIN produtos pdirect ON iv.id_produto = pdirect.id_produto
  LEFT JOIN produto_variacoes pv ON iv.id_variacao = pv.id_variacao
  LEFT JOIN produtos pvar ON pv.id_produto = pvar.id_produto
  WHERE v.data_venda BETWEEN '$inicioHoje' AND '$fimHoje'
  GROUP BY COALESCE(pdirect.nome, pvar.nome)
  ORDER BY SUM(iv.quantidade) DESC
  LIMIT 5
";
$resTop         = $mysqli->query($sqlTopHoje);
$topProdutosHoje = [];
if ($resTop) {
  while ($p = $resTop->fetch_assoc()) {
    $topProdutosHoje[] = [
      'nome_produto' => $p['nome_produto'],
      'quantidade'   => (int)$p['qtde'],
      'preco_venda'  => (float)$p['preco_venda'],
    ];
  }
}
?>
                <!-- Faturamento Diário -->
                <div class="row g-2">
                    <!-- Faturamento Diário -->
                    <div class="col-lg-4 col-md-6">
                        <div class="modern-card" style="height: 464px;">
                            <div class="card-header-daily">
                                <h6>
                                    <i class="bi bi-calendar-day-fill header-icon"></i>
                                    Faturamento Diário
                                </h6>
                                <form method="get" class="header-form">
                                    <label>Escolha o dia:</label>
                                    <input type="date" name="day" value="<?= htmlspecialchars($diaSelecionado) ?>"
                                        onchange="this.form.submit()">
                                </form>
                            </div>
                            <div class="card-body">
                                <div class="main-revenue">
                                    <div class="main-revenue-label">Faturamento Bruto</div>
                                    <div class="main-revenue-value">
                                        R$ <?= number_format($brutoHoje, 2, ',', '.') ?>
                                    </div>
                                </div>

                                <div class="metrics-row">
                                    <div class="metric-item bruto">
                                        <div class="metric-label">Bruto</div>
                                        <div class="metric-value bruto">
                                            R$ <?= number_format($brutoHoje, 2, ',', '.') ?>
                                        </div>
                                    </div>
                                    <div class="metric-item liquido">
                                        <div class="metric-label">Líquido</div>
                                        <div class="metric-value liquido">
                                            R$ <?= number_format($liquidoHoje, 2, ',', '.') ?>
                                        </div>
                                    </div>
                                    <div class="metric-item deducoes">
                                        <div class="metric-label">Deduções</div>
                                        <div class="metric-value deducoes">
                                            R$ <?= number_format($deducoesHoje, 2, ',', '.') ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="top-products">
                                    <div class="product-section">
                                        <h6><i class="bi bi-trophy"></i> Top 5 Produtos (UN)</h6>
                                        <?php if (!empty($topProdutosHoje)): ?>
                                        <div class="product-list">
                                            <?php foreach ($topProdutosHoje as $p): ?>
                                            <div class="product-item">
                                                <span
                                                    class="product-name"><?= htmlspecialchars($p['nome_produto']) ?></span>
                                                <span class="product-value"><?= $p['quantidade'] ?> un.</span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="empty-state">Nenhum produto vendido neste dia.</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="product-section">
                                        <h6><i class="bi bi-currency-dollar"></i> Top 5 Produtos (R$)</h6>
                                        <?php $totalBrutoCalcHoje = 0; ?>
                                        <?php if (!empty($topProdutosHoje)): ?>
                                        <div class="product-list">
                                            <?php foreach ($topProdutosHoje as $p):
                                        $valorTotal = $p['quantidade'] * $p['preco_venda'];
                                        $totalBrutoCalcHoje += $valorTotal;
                                    ?>
                                            <div class="product-item">
                                                <span
                                                    class="product-name"><?= htmlspecialchars($p['nome_produto']) ?></span>
                                                <span class="product-value">R$
                                                    <?= number_format($valorTotal, 2, ',', '.') ?></span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="empty-state">Nenhum produto vendido neste dia.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <?php

$monday = date('Y-m-d', strtotime('monday this week', strtotime($diaSelecionado)));
$sunday = date('Y-m-d', strtotime('sunday this week', strtotime($diaSelecionado)));
$inicioSemana = "$monday 00:00:00";
$fimSemana    = "$sunday 23:59:59";

// Bruto Semanal
$sql = "
  SELECT COALESCE(SUM(iv.quantidade * COALESCE(pdirect.preco_venda,pvar.preco_venda)),0) AS total
  FROM itens_venda iv
  JOIN vendas v ON iv.id_venda=v.id_venda
  LEFT JOIN produtos pdirect ON iv.id_produto=pdirect.id_produto
  LEFT JOIN produto_variacoes pv ON iv.id_variacao=pv.id_variacao
  LEFT JOIN produtos pvar ON pv.id_produto=pvar.id_produto
  WHERE v.data_venda BETWEEN '$inicioSemana' AND '$fimSemana'
";
$row = $mysqli->query($sql)->fetch_assoc();
$brutoSemana = (float)$row['total'];

// Deduções Semanal
$sql = str_replace('preco_venda','preco_custo',$sql);
$row = $mysqli->query($sql)->fetch_assoc();
$deducoesSemana = (float)$row['total'];

$liquidoSemana = $brutoSemana - $deducoesSemana;

// Faturamento por dia da semana
$sqlDias = "
  SELECT DATE(v.data_venda) AS dia,
         COALESCE(SUM(iv.quantidade * COALESCE(pdirect.preco_venda,pvar.preco_venda)),0) AS bruto
  FROM itens_venda iv
  JOIN vendas v ON iv.id_venda=v.id_venda
  LEFT JOIN produtos pdirect ON iv.id_produto=pdirect.id_produto
  LEFT JOIN produto_variacoes pv ON iv.id_variacao=pv.id_variacao
  LEFT JOIN produtos pvar ON pv.id_produto=pvar.id_produto
  WHERE v.data_venda BETWEEN '$inicioSemana' AND '$fimSemana'
  GROUP BY DATE(v.data_venda)
  ORDER BY DATE(v.data_venda)
";
$resD = $mysqli->query($sqlDias);
$faturSemana = [];
while ($d = $resD->fetch_assoc()) {
    $faturSemana[$d['dia']] = (float)$d['bruto'];
}

// Top 5 Semana// Top 5 Semana (ajustado para casar com o template do dia)
 $sqlTop = "
   SELECT COALESCE(pdirect.nome,pvar.nome) AS nome_produto,
          SUM(iv.quantidade) AS quantidade,
         AVG(COALESCE(pdirect.preco_venda,pvar.preco_venda)) AS preco_venda
   FROM itens_venda iv
   JOIN vendas v ON iv.id_venda=v.id_venda
   LEFT JOIN produtos pdirect ON iv.id_produto=pdirect.id_produto
   LEFT JOIN produto_variacoes pv ON iv.id_variacao=pv.id_variacao
   LEFT JOIN produtos pvar ON pv.id_produto=pvar.id_produto
   WHERE v.data_venda BETWEEN '$inicioSemana' AND '$fimSemana'
   GROUP BY COALESCE(pdirect.nome,pvar.nome)
   ORDER BY SUM(iv.quantidade) DESC
   LIMIT 5
 ";
 $res = $mysqli->query($sqlTop);
 $topProdutosSemana = [];
 while ($p = $res->fetch_assoc()) {
    $topProdutosSemana[] = [
        'nome_produto' => $p['nome_produto'],
         'quantidade'   => (int)$p['quantidade'],
         'preco_venda'  => (float)$p['preco_venda'],
     ];
}

?>
                    <div class="col-lg-4 col-md-6">
                        <div class="modern-card" style="height: 464px;">
                            <div class="card-header-weekly">
                                <h6>
                                    <i class="bi bi-calendar-week header-icon"></i>
                                    Semana: <?= date('d/m', strtotime($monday)) ?> –
                                    <?= date('d/m', strtotime($sunday)) ?>
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="weekly-sales-list">
                                    <div class="weekly-sales-header">
                                        <span>Data</span>
                                        <span>Valor Bruto</span>
                                    </div>
                                    <?php if (!empty($faturSemana)): ?>
                                    <?php foreach ($faturSemana as $data => $bruto): ?>
                                    <div class="weekly-sales-item">
                                        <span class="weekly-sales-date"><?= date('d/m/Y', strtotime($data)) ?></span>
                                        <span class="weekly-sales-value">R$
                                            <?= number_format($bruto, 2, ',', '.') ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                    <div class="empty-state">Sem dados para esta semana.</div>
                                    <?php endif; ?>
                                </div>

                                <div class="metrics-row">
                                    <div class="metric-item bruto">
                                        <div class="metric-label">Bruto</div>
                                        <div class="metric-value bruto">
                                            R$ <?= number_format($brutoSemana, 2, ',', '.') ?>
                                        </div>
                                    </div>
                                    <div class="metric-item liquido">
                                        <div class="metric-label">Líquido</div>
                                        <div class="metric-value liquido">
                                            R$ <?= number_format($liquidoSemana, 2, ',', '.') ?>
                                        </div>
                                    </div>
                                    <div class="metric-item deducoes">
                                        <div class="metric-label">Deduções</div>
                                        <div class="metric-value deducoes">
                                            R$ <?= number_format($deducoesSemana, 2, ',', '.') ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="top-products">
                                    <div class="product-section">
                                        <h6><i class="bi bi-trophy"></i> Top 5 (UN)</h6>
                                        <?php if (!empty($topProdutosSemana)): ?>
                                        <div class="product-list">
                                            <?php foreach ($topProdutosSemana as $p): ?>
                                            <div class="product-item">
                                                <span
                                                    class="product-name"><?= htmlspecialchars($p['nome_produto']) ?></span>
                                                <span class="product-value"><?= $p['quantidade'] ?> un.</span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="empty-state">Nenhum produto vendido nesta semana.</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="product-section">
                                        <h6><i class="bi bi-currency-dollar"></i> Top 5 (R$)</h6>
                                        <?php $totalBrutoCalcSemana = 0; ?>
                                        <?php if (!empty($topProdutosSemana)): ?>
                                        <div class="product-list">
                                            <?php foreach ($topProdutosSemana as $p):
                                        $vt = $p['quantidade'] * $p['preco_venda'];
                                        $totalBrutoCalcSemana += $vt;
                                    ?>
                                            <div class="product-item">
                                                <span
                                                    class="product-name"><?= htmlspecialchars($p['nome_produto']) ?></span>
                                                <span class="product-value">R$
                                                    <?= number_format($vt, 2, ',', '.') ?></span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="empty-state">Nenhum produto vendido nesta semana.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <!-- Faturamento Mensal -->
                    <?php


// 0) Recebe o mês via GET (YYYY-MM) ou usa o mês corrente
$mesSelecionado = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mesSelecionado)) {
    $mesSelecionado = date('Y-m');
}

// 1) Define intervalo completo do mês selecionado
$dataInicioMes = "{$mesSelecionado}-01 00:00:00";
$dataFimMes    = date('Y-m-t', strtotime($dataInicioMes)) . ' 23:59:59';

// 2) Cálculo do Bruto Mensal (exemplo rápido; seu código já fazças semelhantes)
/*
$sqlBrutoMes = "..."; 
$resBmes     = $mysqli->query($sqlBrutoMes);
$rowBmes     = $resBmes ? $resBmes->fetch_assoc() : null;
$brutoMesAtual = $rowBmes ? floatval($rowBmes['total_bruto']) : 0.0;
*/

// 3) Cálculo das Deduções (Custo Mensal), baseado no código de Custo Diário
$sqlCustoMes = "
  SELECT COALESCE(SUM(
    iv.quantidade
    * COALESCE(pdirect.preco_custo, pvar.preco_custo)
  ), 0) AS total_custo
  FROM itens_venda iv
  JOIN vendas v ON iv.id_venda = v.id_venda
  LEFT JOIN produtos pdirect ON iv.id_produto = pdirect.id_produto
  LEFT JOIN produto_variacoes pv ON iv.id_variacao = pv.id_variacao
  LEFT JOIN produtos pvar ON pv.id_produto = pvar.id_produto
  WHERE v.data_venda BETWEEN '$dataInicioMes' AND '$dataFimMes'
";
$resCmes        = $mysqli->query($sqlCustoMes);
$rowCmes        = $resCmes ? $resCmes->fetch_assoc() : null;
$deducoesMesAtual = $rowCmes ? floatval($rowCmes['total_custo']) : 0.0;

// 4) Cálculo do Líquido Mensal
$liquidoMesAtual = $brutoMesAtual - $deducoesMesAtual;

// 5) Se não houve faturamento, zera tudo
if ($brutoMesAtual == 0) {
    $deducoesMesAtual = 0.0;
    $liquidoMesAtual  = 0.0;
}


                    $sqlTopMes = "
                    SELECT COALESCE(pdirect.nome,pvar.nome) AS nome_produto,
                    SUM(iv.quantidade) AS qtde,
                    AVG(COALESCE(pdirect.preco_venda,pvar.preco_venda)) AS preco_venda
                    FROM itens_venda iv
                    JOIN vendas v ON iv.id_venda = v.id_venda
                    LEFT JOIN produtos pdirect ON iv.id_produto = pdirect.id_produto
                    LEFT JOIN produto_variacoes pv ON iv.id_variacao = pv.id_variacao
                    LEFT JOIN produtos pvar ON pv.id_produto = pvar.id_produto
                    WHERE v.data_venda BETWEEN '$dataInicioMes' AND '$dataFimMes'
                    GROUP BY COALESCE(pdirect.nome,pvar.nome)
                    ORDER BY SUM(iv.quantidade) DESC
                    LIMIT 5
                    ";
                    $resTop = $mysqli->query($sqlTopMes);
                    $topProdutosMes = [];
                    while ($p = $resTop->fetch_assoc()) {
                    $topProdutosMes[] = [
                    'nome_produto' => $p['nome_produto'],
                    'quantidade' => (int)$p['qtde'],
                    'preco_venda' => (float)$p['preco_venda'],
                    ];
                    }
                    ?>



                    <!-- Faturamento Mensal -->
                    <div class="col-lg-4 col-md-6">
                        <div class="modern-card" style="height: 464px;">
                            <div class="card-header-monthly">
                                <h6>
                                    <i class="bi bi-calendar-event-fill header-icon"></i>
                                    Faturamento Mensal
                                </h6>
                                <form method="get" class="header-form">
                                    <label>Escolha o mês:</label>
                                    <input type="month" name="month" value="<?= htmlspecialchars($mesSelecionado) ?>"
                                        onchange="this.form.submit()">
                                </form>
                            </div>
                            <div class="card-body">
                                <div class="main-revenue">
                                    <div class="main-revenue-label">Faturamento Bruto</div>
                                    <div class="main-revenue-value">
                                        R$ <?= number_format($brutoMesAtual, 2, ',', '.') ?>
                                    </div>
                                </div>

                                <div class="metrics-row">
                                    <div class="metric-item bruto">
                                        <div class="metric-label">Bruto</div>
                                        <div class="metric-value bruto">
                                            R$ <?= number_format($brutoMesAtual, 2, ',', '.') ?>
                                        </div>
                                    </div>
                                    <div class="metric-item liquido">
                                        <div class="metric-label">Líquido</div>
                                        <div class="metric-value liquido">
                                            R$ <?= number_format($liquidoMesAtual, 2, ',', '.') ?>
                                        </div>
                                    </div>
                                    <div class="metric-item deducoes">
                                        <div class="metric-label">Deduções</div>
                                        <div class="metric-value deducoes">
                                            R$ <?= number_format($deducoesMesAtual, 2, ',', '.') ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="top-products">
                                    <div class="product-section">
                                        <h6><i class="bi bi-trophy"></i> Top 5 (UN)</h6>
                                        <?php if ($topProdutosMes): ?>
                                        <div class="product-list">
                                            <?php foreach ($topProdutosMes as $p): ?>
                                            <div class="product-item">
                                                <span
                                                    class="product-name"><?= htmlspecialchars($p['nome_produto']) ?></span>
                                                <span class="product-value"><?= $p['quantidade'] ?> un.</span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="empty-state">Nenhum produto vendido neste mês.</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="product-section">
                                        <h6><i class="bi bi-currency-dollar"></i> Top 5 (R$)</h6>
                                        <?php $totalBrutoMesCalc = 0; ?>
                                        <?php if ($topProdutosMes): ?>
                                        <div class="product-list">
                                            <?php foreach ($topProdutosMes as $p):
                                        $vt = $p['quantidade'] * $p['preco_venda'];
                                        $totalBrutoMesCalc += $vt;
                                    ?>
                                            <div class="product-item">
                                                <span
                                                    class="product-name"><?= htmlspecialchars($p['nome_produto']) ?></span>
                                                <span class="product-value">R$
                                                    <?= number_format($vt, 2, ',', '.') ?></span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="empty-state">Nenhum produto vendido neste mês.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!--Possibilidade de implementar -->
            <!-- Top 5 Clientes (Faturamento) 
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="chart-card" style="height:500px;">
                            <div class="card-header">
                                <h5>Top 5 Clientes (Faturamento)</h5>
                            </div>
                            <div class="card-body" style="height:300px; overflow-y:auto;">
                                <?php if (!empty($top5Cli)): ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($top5Cli as $cliente => $bruto): ?>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span><?= htmlspecialchars($cliente) ?></span>
                                        <span>R$ <?= number_format($bruto,2,',','.') ?></span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php else: ?>
                                <p class="text-center">Sem dados para este período.</p>
                                <?php endif; ?>
                            </div>
                            <div class="px-3 py-2 border-top d-flex justify-content-between"
                                style="font-size: 20px; margin-top: 30px;">
                                <small><strong>Total Bruto:</strong></small>
                                <small>R$ <?= number_format($totalBrutoCli,   2, ',', '.') ?></small>
                            </div>
                            <div class="px-3 py-2 d-flex justify-content-between" style="font-size: 20px;">
                                <small><strong>Total Líquido:</strong></small>
                                <small>R$ <?= number_format($totalLiquidoCli,2, ',', '.') ?></small>
                            </div>
                        </div>
                    </div>-->
        </div>

        <!-- Menu mobile -->
        <div class="bottom-nav d-block d-md-none d-flex justify-content-center align-items-center">
            <a href="teste.php"
                class="bottom-nav-item <?php echo in_array($action, ['list','create','edit','variacoes'])?'active':''; ?>">
                <i class="fas fa-box"></i>
                <span>Produtos</span>
            </a>
            <a href="../clientes/clientes.php" class="bottom-nav-item <?php echo ($action=='clientes')?'active':''; ?>">
                <i class="fas fa-users"></i>
                <span>Clientes</span>
            </a>
            <a href="../vendas/teste.php" class="bottom-nav-item <?php echo ($action=='vendas')?'active':''; ?>">
                <i class="fas fa-shopping-cart"></i>
                <span>Vendas</span>
            </a>
            <a href="../financeiro/teste.php"
                class="bottom-nav-item <?php echo ($action=='financeiro')?'active':''; ?>">
                <i class="fas fa-wallet"></i>
                <span>Financeiro</span>
            </a>
            <a href="#" id="desktopSidebarToggle" class="bottom-nav-item">
                <i class="fas fa-ellipsis-h"></i>
                <span>Mais</span>
            </a>
        </div>



        <style>
        /* Cards Dias/Semana/Mês */

        /* Reset e configurações base */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Container principal */
        .dashboard-container {
            background: #f7fafc;
            min-height: 100vh;
            padding: 20px;
        }

        /* Cards principais */
        .modern-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: none;
            transition: all 0.3s ease;
            overflow: hidden;
            min-height: 550px;

        }

        .modern-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        /* Headers dos cards */
        .card-header-daily {
            background: linear-gradient(135deg, #4299e1, #3182ce);
            color: white;
            border: none;
            padding: 20px;
            border-radius: 16px 16px 0 0;
        }

        .card-header-weekly {
            background: linear-gradient(135deg, #ed8936, #dd6b20);
            color: white;
            border: none;
            padding: 20px;
            border-radius: 16px 16px 0 0;
        }

        .card-header-monthly {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 20px;
            border-radius: 16px 16px 0 0;
        }

        .card-header h6 {
            font-weight: 600;
            font-size: 16px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Ícones dos headers */
        .header-icon {
            width: 24px;
            height: 24px;
            opacity: 0.9;
        }

        /* Formulários nos headers */
        .header-form {
            margin-top: 10px;
        }

        .header-form label {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 5px;
            display: block;
        }

        .header-form input {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            color: white;
            padding: 8px 12px;
            font-size: 14px;
            width: 100%;
        }

        .header-form input::placeholder {
            color: rgba(255, 255, 255, 0.7);
        }

        /* Corpo dos cards */
        .modern-card .card-body {
            padding: 4px 16px;
        }

        /* Faturamento principal */
        .main-revenue {
            text-align: center;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 10px;
        }

        .main-revenue-label {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
        }

        .main-revenue-value {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #4299e1, #3182ce);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Métricas em linha */
        .metrics-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin: 20px 0;
        }

        .metric-item {
            text-align: center;
            padding: 16px;
            background: #f7fafc;
            border-radius: 12px;
            border-left: 4px solid;
        }

        .metric-item.bruto {
            border-left-color: #4299e1;
        }

        .metric-item.liquido {
            border-left-color: #48bb78;
        }

        .metric-item.deducoes {
            border-left-color: #ed8936;
        }

        .metric-label {
            font-size: 12px;
            color: #718096;
            font-weight: 500;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .metric-value {
            font-size: 18px;
            font-weight: 700;
        }

        .metric-value.bruto {
            color: #4299e1;
        }

        .metric-value.liquido {
            color: #48bb78;
        }

        .metric-value.deducoes {
            color: #ed8936;
        }

        /* Seção de produtos top */
        .top-products {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .product-section h6 {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .product-list {
            background: #f7fafc;
            border-radius: 8px;
            width: 150px;
            max-height: 80px;
            overflow-y: auto;
            padding: 8px;
        }

        .product-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: white;
            border-radius: 6px;
            margin-bottom: 4px;
            font-size: 10px;
            transition: all 0.2s ease;
        }

        .product-item:hover {
            background: #edf2f7;
            transform: translateX(2px);
        }

        .product-item:last-child {
            margin-bottom: 0;
        }

        .product-name {
            font-weight: 500;
            color: #2d3748;
            flex: 1;
            margin-right: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .product-value {
            font-weight: 600;
            color: #4299e1;
        }

        /* Lista de vendas semanais */
        .weekly-sales-list {
            background: #f7fafc;
            border-radius: 8px;
            padding: 5px;
            margin: 16px 0;
            overflow-y: auto;
            max-height: 160px;
        }

        .weekly-sales-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 8px;
        }

        .weekly-sales-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            background: white;
            border-radius: 6px;
            margin-bottom: 4px;
            transition: all 0.2s ease;
        }

        .weekly-sales-item:hover {
            background: #edf2f7;
            transform: translateX(2px);
        }

        .weekly-sales-date {
            font-weight: 500;
            color: #2d3748;
        }

        .weekly-sales-value {
            font-weight: 600;
            color: #ed8936;
        }

        /* Scrollbar personalizada */
        .product-list::-webkit-scrollbar,
        .weekly-sales-list::-webkit-scrollbar {
            width: 4px;
        }

        .product-list::-webkit-scrollbar-track,
        .weekly-sales-list::-webkit-scrollbar-track {
            background: #e2e8f0;
            border-radius: 2px;
        }

        .product-list::-webkit-scrollbar-thumb,
        .weekly-sales-list::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 2px;
        }

        .product-list::-webkit-scrollbar-thumb:hover,
        .weekly-sales-list::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }

        /* Estados vazios */
        .empty-state {
            text-align: center;
            padding: 20px;
            color: #718096;
            font-size: 12px;
            font-style: italic;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .metrics-row {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .top-products {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .main-revenue-value {
                font-size: 24px;
            }

            .modern-card .card-body {
                padding: 16px;
            }

            .weekly-sales-header,
            .weekly-sales-item {
                font-size: 12px;
            }
        }

        @media (max-width: 576px) {
            .dashboard-container {
                padding: 10px;
            }

            .card-header-daily,
            .card-header-weekly,
            .card-header-monthly {
                padding: 16px;
            }

            .main-revenue-value {
                font-size: 20px;
            }

            .metric-value {
                font-size: 16px;
            }
        }

        /* Animações */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modern-card {
            animation: fadeInUp 0.6s ease-out;
        }

        .modern-card:nth-child(2) {
            animation-delay: 0.1s;
        }

        .modern-card:nth-child(3) {
            animation-delay: 0.2s;
        }

        /* Melhorias adicionais */
        .card-header i {
            font-size: 20px;
        }

        .list-group-flush {
            border-radius: 8px;
        }

        .border-top {
            border-top: 1px solid #e2e8f0 !important;
        }

        .border-end {
            border-right: 1px solid #e2e8f0 !important;
        }

        /* Ajustes para o layout existente */
        .row.g-2 {
            gap: 20px;
        }

        .col-lg-4 {
            flex: 1;
            min-width: 300px;
        }
        </style>

        <style>
        /* Mobile Menu */
        .mobile-bottom-nav,
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: var(--bottom-nav-height);
            background-color: #ffffff;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-around;
            align-items: center;
            z-index: 1001;
            padding: 0 10px;
        }

        .mobile-nav-item,
        .bottom-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            text-decoration: none;
            font-size: 10px;
            padding: 8px 0;
            flex: 1;
            transition: all 0.2s;
            position: relative;
        }

        .mobile-nav-item i,
        .bottom-nav-item i {
            font-size: 20px;
            margin-bottom: 4px;
            transition: all 0.2s;
        }

        .mobile-nav-item:hover,
        .mobile-nav-item:active,
        .mobile-nav-item.active,
        .bottom-nav-item:hover,
        .bottom-nav-item:active,
        .bottom-nav-item.active {
            color: var(--primary-color);
        }

        .mobile-nav-item:hover i,
        .mobile-nav-item:active i,
        .mobile-nav-item.active i,
        .bottom-nav-item:hover i,
        .bottom-nav-item:active i,
        .bottom-nav-item.active i {
            transform: translateY(-2px);
        }

        .mobile-nav-item::after,
        .bottom-nav-item::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 3px;
            background-color: var(--primary-color);
            transition: width 0.2s;
            border-radius: 3px 3px 0 0;
        }

        .mobile-nav-item:hover::after,
        .mobile-nav-item:active::after,
        .mobile-nav-item.active::after,
        .bottom-nav-item:hover::after,
        .bottom-nav-item:active::after,
        .bottom-nav-item.active::after {
            width: 40%;
        }

        body.dark-mode .bottom-nav {
            background-color: #1e1e1e;
            border-top: 1px solid #333;
        }

        body.dark-mode .bottom-nav-item {
            color: #adb5bd;
        }

        body.dark-mode .bottom-nav-item.active {
            color: var(--primary-color);
        }
        </style>

        <script>
        // Mobile Menu 
        const overlay = document.querySelector('.drawer-overlay');
        document.getElementById('desktopSidebarToggle')
            .addEventListener('click', e => {
                e.preventDefault();
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
            });

        // fechar ao clicar no overlay
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
        </script>

        <!-- Bootstrap JS Bundle -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js">
        </script>
        <!-- AOS - Animate On Scroll -->
        <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
        <script>
        // Inicializar AOS
        AOS.init({
            duration: 800,
            once: true
        });

        // Esconder loader quando a página estiver carregada
        window.addEventListener('load', function() {
            const loader = document.getElementById('pageLoader');
            if (loader) {
                loader.style.opacity = '0';
                setTimeout(() => {
                    loader.style.display = 'none';
                }, 500);
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Tente diferentes seletores que podem estar sendo usados
            const mobileMenuBtn = document.querySelector('.mobile-menu-btn') ||
                document.querySelector('[aria-label="Menu"]') ||
                document.querySelector('.navbar-toggler');

            if (mobileMenuBtn) {
                console.log('Menu button found:', mobileMenuBtn); // Para debug

                // Remova qualquer evento de clique existente para evitar duplicação
                mobileMenuBtn.removeEventListener('click', toggleDrawer);

                // Adicione o evento de clique
                mobileMenuBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    console.log('Menu button clicked'); // Para debug
                    toggleDrawer();
                });
            } else {
                console.error('Mobile menu button not found'); // Para debug
            }

            // Verificar se os elementos do drawer existem
            const drawer = document.getElementById('mobileDrawer');
            const overlay = document.getElementById('drawerOverlay');

            if (!drawer) console.error('Drawer element not found');
            if (!overlay) console.error('Overlay element not found');
        });
        // Adicione este código para garantir que o script seja executado mesmo após carregamento parcial
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            const mobileMenuBtn = document.querySelector('.mobile-menu-btn') ||
                document.querySelector('[aria-label="Menu"]') ||
                document.querySelector('.navbar-toggler');

            if (mobileMenuBtn) {
                mobileMenuBtn.onclick = function(e) {
                    e.preventDefault();
                    toggleDrawer();
                };
            }
        }

        // Dark Mode Toggle
        const btnDark = document.getElementById('btn-darkmode');
        const btnDarkMobile = document.getElementById('btn-darkmode-mobile');

        function toggleDarkMode() {
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            localStorage.setItem('darkMode', isDark ? 'true' : 'false');
            const icon = isDark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon"></i>';
            if (btnDark) btnDark.innerHTML = icon;
            if (btnDarkMobile) btnDarkMobile.innerHTML = icon;
        }

        if (btnDark) btnDark.addEventListener('click', toggleDarkMode);
        if (btnDarkMobile) btnDarkMobile.addEventListener('click', toggleDarkMode);

        if (localStorage.getItem('darkMode') === 'true') {
            document.body.classList.add('dark-mode');
            if (btnDark) btnDark.innerHTML = '<i class="bi bi-sun"></i>';
            if (btnDarkMobile) btnDarkMobile.innerHTML = '<i class="bi bi-sun"></i>';
        }
        /*

                // ========== GRÁFICO 1: Vendas Mensais ==========
                const ctxVendasMensais = document.getElementById('vendasMensais').getContext('2d');
                new Chart(ctxVendasMensais, {
                    type: 'bar',
                    data: {
                        labels: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set',
                            'Out',
                            'Nov',
                            'Dez'
                        ],
                        datasets: [{
                            label: 'Faturamento (R$)',
                            data: <?php echo json_encode(array_values($vendasPorMes)); ?>,
                            backgroundColor: 'rgba(78, 84, 233, 0.2)',
                            borderColor: 'rgba(78, 84, 233, 1)',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    borderDash: [2, 4]
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleFont: {
                                    size: 14,
                                    weight: 'bold'
                                },
                                bodyFont: {
                                    size: 13
                                },
                                padding: 12,
                                cornerRadius: 6,
                                callbacks: {
                                    label: function(context) {
                                        return 'R$ ' + context.parsed.y.toLocaleString(
                                            'pt-BR', {
                                                minimumFractionDigits: 2,
                                                maximumFractionDigits: 2
                                            });
                                    }
                                }
                            }
                        }
                    }
                });

                // ========== GRÁFICO 2: Vendas Últimos 7 Dias ==========
                const ctxVendasSemanais = document.getElementById('vendasSemanais').getContext('2d');
                new Chart(ctxVendasSemanais, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode(array_keys($vendasPorDia)); ?>,
                        datasets: [{
                            label: 'Faturamento Diário (R$)',
                            data: <?php echo json_encode(array_values($vendasPorDia)); ?>,
                            borderColor: 'rgba(255, 99, 132, 1)',
                            backgroundColor: 'rgba(255, 99, 132, 0.2)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: 'rgba(255, 99, 132, 1)',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    borderDash: [2, 4]
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleFont: {
                                    size: 14,
                                    weight: 'bold'
                                },
                                bodyFont: {
                                    size: 13
                                },
                                padding: 12,
                                cornerRadius: 6,
                                callbacks: {
                                    label: function(context) {
                                        return 'R$ ' + context.parsed.y.toLocaleString(
                                            'pt-BR', {
                                                minimumFractionDigits: 2,
                                                maximumFractionDigits: 2
                                            });
                                    }
                                }
                            }
                        }
                    }
                });

                // Isolando o comportamento do drawer
                (function() {
                    // Função para toggle do drawer
                    window.toggleDrawer = function() {
                        const drawer = document.getElementById('mobileDrawer');
                        const overlay = document.getElementById('drawerOverlay');

                        if (drawer && overlay) {
                            drawer.classList.toggle('show');
                            overlay.classList.toggle('show');
                        }
                    };

                    // Adicionar evento ao botão quando o DOM estiver pronto
                    function initMobileMenu() {
                        const mobileMenuBtn = document.querySelector('.mobile-menu-btn') ||
                            document.querySelector('[aria-label="Menu"]') ||
                            document.querySelector('.navbar-toggler');

                        if (mobileMenuBtn) {
                            mobileMenuBtn.addEventListener('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                window.toggleDrawer();
                                return false;
                            });
                        }
                    }

                    // Executar quando o DOM estiver pronto
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', initMobileMenu);
                    } else {
                        initMobileMenu();
                    }
                })();*/
        </script>



        </body>

</html>