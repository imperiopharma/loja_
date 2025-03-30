<?php
/**************************************************************
 * admin/pages/dashboard.php
 *
 * PÁGINA INICIAL (HOME) DO PAINEL ADMINISTRATIVO
 * - Métricas Históricas (Pedidos, Vendas, Custos, etc.)
 * - Métricas do Dia (Hoje)
 * - Pedidos Pendentes
 * - Cupons Ativos
 * - Acesso Rápido (links)
 **************************************************************/

// Verifique se a variável $pdo já está definida em config.php.
// Se não estiver, inclua a conexão manualmente:
// require_once __DIR__ . '/../inc/config.php';

// 1) MÉTRICAS HISTÓRICAS
$totalPedidos     = 0;
$somaVendas       = 0.0; // SUM(final_value) (receita total)
$somaCusto        = 0.0; // SUM(cost_total)  (custo total)
$lucroEstimado    = 0.0;
$totalProdutos    = 0;
$totalMarcas      = 0;
$totalClientes    = 0;
$totalFechamentos = 0;

try {
    // Pedidos (tabela `orders`)
    $stmtP = $pdo->query("
        SELECT 
          COUNT(*) AS c,
          COALESCE(SUM(final_value), 0) AS sumFinal,
          COALESCE(SUM(cost_total), 0)  AS sumCost
        FROM orders
    ");
    $rowP = $stmtP->fetch(PDO::FETCH_ASSOC);

    $totalPedidos  = (int)($rowP['c'] ?? 0);
    $somaVendas    = (float)($rowP['sumFinal'] ?? 0);
    $somaCusto     = (float)($rowP['sumCost']  ?? 0);
    $lucroEstimado = $somaVendas - $somaCusto;

    // Produtos
    $stmtProd = $pdo->query("SELECT COUNT(*) AS c FROM products");
    $rowProd  = $stmtProd->fetch(PDO::FETCH_ASSOC);
    $totalProdutos = (int)($rowProd['c'] ?? 0);

    // Marcas
    $stmtB = $pdo->query("SELECT COUNT(*) AS c FROM brands");
    $rowB  = $stmtB->fetch(PDO::FETCH_ASSOC);
    $totalMarcas = (int)($rowB['c'] ?? 0);

    // Clientes
    $stmtCli = $pdo->query("SELECT COUNT(*) AS c FROM customers");
    $rowCli  = $stmtCli->fetch(PDO::FETCH_ASSOC);
    $totalClientes = (int)($rowCli['c'] ?? 0);

    // Fechamentos (tabela `daily_closings`, se existir)
    $stmtF = $pdo->query("SELECT COUNT(*) AS c FROM daily_closings");
    $rowF  = $stmtF->fetch(PDO::FETCH_ASSOC);
    $totalFechamentos = (int)($rowF['c'] ?? 0);

} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Erro ao buscar métricas históricas: "
         . htmlspecialchars($e->getMessage()) . "</div>";
}

// 2) MÉTRICAS DO DIA (Hoje)
$hojePed    = 0;
$hojeVendas = 0.0;
$hojeCusto  = 0.0;
$hojeLucro  = 0.0;

try {
    $stmtHoje = $pdo->query("
        SELECT
          COUNT(*) AS c,
          COALESCE(SUM(final_value), 0) AS sumFinal,
          COALESCE(SUM(cost_total), 0) AS sumCost
        FROM orders
        WHERE DATE(created_at) = CURDATE()
    ");
    $rowH = $stmtHoje->fetch(PDO::FETCH_ASSOC);

    $hojePed    = (int)($rowH['c'] ?? 0);
    $hojeVendas = (float)($rowH['sumFinal'] ?? 0);
    $hojeCusto  = (float)($rowH['sumCost']  ?? 0);
    $hojeLucro  = $hojeVendas - $hojeCusto;

} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Erro ao buscar métricas do dia: "
         . htmlspecialchars($e->getMessage()) . "</div>";
}

// 3) PEDIDOS PENDENTES
$pendentesCount = 0;
try {
    $stmtPend = $pdo->query("
        SELECT COUNT(*) AS c
        FROM orders
        WHERE status = 'PENDENTE'
    ");
    $rowPend = $stmtPend->fetch(PDO::FETCH_ASSOC);
    $pendentesCount = (int)($rowPend['c'] ?? 0);

} catch (Exception $e) {
    // ignora se quiser
}

// 4) CUPONS ATIVOS (opcional)
$cuponsAtivos = [];
try {
    $stmtCup = $pdo->query("
        SELECT id, code, discount_type, discount_value, max_discount
        FROM coupons
        WHERE active = 1
          AND (valid_until IS NULL OR valid_until > NOW())
        ORDER BY id DESC
        LIMIT 5
    ");
    $cuponsAtivos = $stmtCup->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $cuponsAtivos = [];
}

// 5) EXIBIÇÃO DO DASHBOARD
?>
<h2 class="mb-4">Dashboard Avançado - Império Pharma</h2>

<!-- MÉTRICAS HISTÓRICAS -->
<div class="row row-cols-1 row-cols-sm-2 row-cols-md-4 g-3 mb-4">
  <div class="col">
    <div class="card border-secondary">
      <div class="card-body">
        <h5 class="card-title">Pedidos (Total)</h5>
        <p class="card-text display-6"><?= $totalPedidos ?></p>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card border-success">
      <div class="card-body">
        <h5 class="card-title">Receita Total</h5>
        <p class="card-text display-6">R$ <?= number_format($somaVendas, 2, ',', '.') ?></p>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card border-danger">
      <div class="card-body">
        <h5 class="card-title">Custo Total</h5>
        <p class="card-text display-6">R$ <?= number_format($somaCusto, 2, ',', '.') ?></p>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card border-info">
      <div class="card-body">
        <h5 class="card-title">Lucro Estimado</h5>
        <p class="card-text display-6">R$ <?= number_format($lucroEstimado, 2, ',', '.') ?></p>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card border-secondary">
      <div class="card-body">
        <h5 class="card-title">Produtos</h5>
        <p class="card-text display-6"><?= $totalProdutos ?></p>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card border-secondary">
      <div class="card-body">
        <h5 class="card-title">Marcas</h5>
        <p class="card-text display-6"><?= $totalMarcas ?></p>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card border-secondary">
      <div class="card-body">
        <h5 class="card-title">Clientes</h5>
        <p class="card-text display-6"><?= $totalClientes ?></p>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card border-secondary">
      <div class="card-body">
        <h5 class="card-title">Fechamentos</h5>
        <p class="card-text display-6"><?= $totalFechamentos ?></p>
      </div>
    </div>
  </div>
</div>

<!-- MÉTRICAS DO DIA -->
<h4 class="mt-5 mb-3">Métricas do Dia (<?= date('d/m/Y') ?>)</h4>
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
  <div class="col">
    <div class="card border-primary">
      <div class="card-body">
        <h6 class="card-title">Pedidos Hoje</h6>
        <p class="card-text fs-4"><?= $hojePed ?></p>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card border-success">
      <div class="card-body">
        <h6 class="card-title">Receita Hoje</h6>
        <p class="card-text fs-4">R$ <?= number_format($hojeVendas, 2, ',', '.') ?></p>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card border-danger">
      <div class="card-body">
        <h6 class="card-title">Custo Hoje</h6>
        <p class="card-text fs-4">R$ <?= number_format($hojeCusto, 2, ',', '.') ?></p>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card border-info">
      <div class="card-body">
        <h6 class="card-title">Lucro Hoje</h6>
        <p class="card-text fs-4">R$ <?= number_format($hojeLucro, 2, ',', '.') ?></p>
      </div>
    </div>
  </div>
</div>

<!-- PEDIDOS PENDENTES -->
<?php if ($pendentesCount > 0): ?>
  <div class="alert alert-warning d-flex align-items-center" role="alert">
    <div>
      <strong><?= $pendentesCount ?> pedidos</strong> estão com status <em>PENDENTE</em>.
      <a href="index.php?page=pedidos&status=PENDENTE"
         class="btn btn-sm btn-outline-danger ms-3">
        Ver agora
      </a>
    </div>
  </div>
<?php endif; ?>

<!-- CUPONS ATIVOS -->
<div class="card mb-4">
  <div class="card-header bg-light">
    <h5 class="mb-0">Cupons Ativos</h5>
  </div>
  <div class="card-body">
    <?php if (empty($cuponsAtivos)): ?>
      <p class="text-muted">Nenhum cupom ativo no momento.</p>
    <?php else: ?>
      <ul class="list-group">
        <?php foreach ($cuponsAtivos as $cup): ?>
          <li class="list-group-item">
            <strong><?= htmlspecialchars($cup['code']) ?></strong>
            (<?= $cup['discount_type'] ?> =
              <?php
                if ($cup['discount_type'] === 'FIXO') {
                    echo 'R$ ' . number_format($cup['discount_value'], 2, ',', '.');
                } else {
                    echo number_format($cup['discount_value'], 2, ',', '.') . '%';
                }
              ?>
            )
            <?php if (!empty($cup['max_discount'])): ?>
              - máx R$ <?= number_format($cup['max_discount'], 2, ',', '.') ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <div class="mt-3">
      <a href="index.php?page=cupons" class="btn btn-primary btn-sm">Gerenciar Cupons</a>
    </div>
  </div>
</div>

<!-- LINKS DE ACESSO RÁPIDO -->
<h4 class="mt-5 mb-3">Acesso Rápido</h4>
<div class="row row-cols-1 row-cols-sm-2 row-cols-md-4 g-3">
  <div class="col">
    <div class="card h-100" style="cursor:pointer;" onclick="location.href='index.php?page=pedidos'">
      <div class="card-body">
        <h5 class="card-title">Ver Pedidos</h5>
        <p class="card-text text-muted">Lista de todos os pedidos</p>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card h-100" style="cursor:pointer;" onclick="location.href='index.php?page=marcas_produtos'">
      <div class="card-body">
        <h5 class="card-title">Marcas &amp; Produtos</h5>
        <p class="card-text text-muted">Gerenciar catálogos</p>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card h-100" style="cursor:pointer;" onclick="location.href='index.php?page=financeiro'">
      <div class="card-body">
        <h5 class="card-title">Financeiro</h5>
        <p class="card-text text-muted">Movimentação, Caixa e Relatórios</p>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card h-100" style="cursor:pointer;" onclick="location.href='index.php?page=usuarios'">
      <div class="card-body">
        <h5 class="card-title">Usuários</h5>
        <p class="card-text text-muted">Clientes e Permissões</p>
      </div>
    </div>
  </div>
</div>
