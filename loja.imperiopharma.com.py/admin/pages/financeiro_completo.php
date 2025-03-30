<?php
/**************************************************************
 * FINANCEIRO_TUDO_BR.PHP
 *
 * ARQUIVO ÚNICO, SUPER COMPLETO, HORÁRIO BRASÍLIA,
 * COM TODAS AS ABAS FUNCIONANDO E EXIBINDO TUDO.
 *
 * ABAS:
 *   1) Movimentação (intervalo)
 *   2) Caixa Diário
 *   3) Fechamentos (histórico)
 *   4) Relatórios (ex.: categorias, top produtos)
 *   5) Comparar Períodos
 *   6) Ranking (Produtos Orais, Injetáveis, Todos, Marcas)
 *   7) Análises Mensais (mês a mês)
 *   8) CRUD de Fechamentos (editar/remover)
 **************************************************************/

// ------------------------------------------------------------
// 1) CONEXÃO COM O BANCO + TIMEZONE BRASÍLIA
// ------------------------------------------------------------
$dbHost = 'localhost';
$dbName = 'imperiopharma_loja_db';
$dbUser = 'imperiopharma_loja_user';
$dbPass = 'Miguel22446688';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Forçar timezone no PHP e no MySQL
    date_default_timezone_set('America/Sao_Paulo');
    $pdo->exec("SET time_zone='-03:00'");

} catch (Exception $e) {
    die("Erro ao conectar ao BD: " . $e->getMessage());
}

// (Opcional) session_start(); // Se quiser checar se o admin está logado

// ------------------------------------------------------------
// 2) DEFINIR ACTION PELO GET
// ------------------------------------------------------------
$action = isset($_GET['action']) ? trim($_GET['action']) : 'movimentacao';

// ------------------------------------------------------------
// 3) CRIAR UM MENU (OPCIONAL)
// ------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>Financeiro - Tudo e BR</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet">
  <style>
    body { background-color: #f8f9fa; }
    .active-link {
      background-color: #0d6efd !important;
      color: #fff !important;
      border-color: #0d6efd !important;
    }
    .container { margin-top: 1rem; }
  </style>
</head>
<body>

<div class="container">
  <h2 class="mb-3">Financeiro Completo - Horário Brasília</h2>

  <nav class="mb-4">
    <a href="?action=movimentacao"
       class="btn btn-outline-primary btn-sm me-2 <?=($action==='movimentacao'?'active-link':'')?>">
      Movimentação
    </a>
    <a href="?action=caixa"
       class="btn btn-outline-primary btn-sm me-2 <?=($action==='caixa'?'active-link':'')?>">
      Caixa Diário
    </a>
    <a href="?action=fechamentos"
       class="btn btn-outline-primary btn-sm me-2 <?=($action==='fechamentos'?'active-link':'')?>">
      Fechamentos
    </a>
    <a href="?action=relatorios"
       class="btn btn-outline-primary btn-sm me-2 <?=($action==='relatorios'?'active-link':'')?>">
      Relatórios
    </a>
    <a href="?action=comparar"
       class="btn btn-outline-primary btn-sm me-2 <?=($action==='comparar'?'active-link':'')?>">
      Comparar
    </a>
    <a href="?action=ranking"
       class="btn btn-outline-primary btn-sm me-2 <?=($action==='ranking'?'active-link':'')?>">
      Ranking
    </a>
    <a href="?action=mensal"
       class="btn btn-outline-primary btn-sm me-2 <?=($action==='mensal'?'active-link':'')?>">
      Mensal
    </a>
    <a href="?action=crud_fechamentos"
       class="btn btn-outline-primary btn-sm <?=($action==='crud_fechamentos'?'active-link':'')?>">
      Editar Fechamentos
    </a>
  </nav>

<?php
// ------------------------------------------------------------
// 4) SWITCH COM TODAS AS ABAS
// ------------------------------------------------------------
switch ($action) {

  // ====================================================
  // (A) MOVIMENTAÇÃO (INTERVALO)
  // ====================================================
  case 'movimentacao':
  default:
    ?>
    <div class="card mb-4">
      <div class="card-header bg-light">
        <h5 class="mb-0">Movimentação (Intervalo)</h5>
      </div>
      <div class="card-body">
      <?php
      $ini = isset($_GET['ini']) ? trim($_GET['ini']) : date('Y-m-01');
      $fim = isset($_GET['fim']) ? trim($_GET['fim']) : date('Y-m-d');
      ?>
      <form method="GET" class="row g-3 align-items-end mb-3">
        <input type="hidden" name="action" value="movimentacao">
        <div class="col-auto">
          <label for="ini" class="form-label mb-0"><strong>Data Inicial</strong></label>
          <input type="date" class="form-control form-control-sm" name="ini" id="ini"
                 value="<?=htmlspecialchars($ini)?>">
        </div>
        <div class="col-auto">
          <label for="fim" class="form-label mb-0"><strong>Data Final</strong></label>
          <input type="date" class="form-control form-control-sm" name="fim" id="fim"
                 value="<?=htmlspecialchars($fim)?>">
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        </div>
      </form>
      <?php
      if (
        preg_match('/^\d{4}-\d{2}-\d{2}$/',$ini) &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/',$fim)
      ) {
        try {
          $sql = "
            SELECT
              COUNT(*) AS totalPed,
              COALESCE(SUM(final_value),0) AS sumRev,
              COALESCE(SUM(cost_total),0)  AS sumCst
            FROM orders
            WHERE DATE(created_at) BETWEEN :i AND :f
              AND status <> 'CANCELADO'
          ";
          $st = $pdo->prepare($sql);
          $st->execute([':i'=>$ini, ':f'=>$fim]);
          $r = $st->fetch(PDO::FETCH_ASSOC);

          $nPed = (int)($r['totalPed']??0);
          $sRev = (float)($r['sumRev']??0);
          $sCst = (float)($r['sumCst']??0);
          $sLuc = $sRev - $sCst;
          ?>
          <div class="alert alert-info">
            <p><strong>Intervalo:</strong>
              <?= date('d/m/Y',strtotime($ini))?> até <?= date('d/m/Y',strtotime($fim))?>
            </p>
            <p><strong>Pedidos:</strong> <?=$nPed?></p>
            <p><strong>Receita:</strong> R$ <?=number_format($sRev,2,',','.')?></p>
            <p><strong>Custo:</strong> R$ <?=number_format($sCst,2,',','.')?></p>
            <p><strong>Lucro:</strong> R$ <?=number_format($sLuc,2,',','.')?></p>
          </div>
          <?php

          // listar pedidos
          $sql2 = "
            SELECT
              id, customer_name, status,
              final_value, cost_total,
              created_at
            FROM orders
            WHERE DATE(created_at) BETWEEN :i AND :f
              AND status <> 'CANCELADO'
            ORDER BY created_at ASC
          ";
          $st2=$pdo->prepare($sql2);
          $st2->execute([':i'=>$ini,':f'=>$fim]);
          $lst=$st2->fetchAll(PDO::FETCH_ASSOC);

          if(!$lst){
            echo "<p>Nenhum pedido no intervalo.</p>";
          } else {
            ?>
            <hr>
            <h6>Lista de Pedidos</h6>
            <div class="table-responsive">
              <table class="table table-bordered table-striped align-middle">
                <thead class="table-light">
                  <tr>
                    <th>ID</th>
                    <th>Cliente</th>
                    <th>Status</th>
                    <th>Data/Hora</th>
                    <th>Venda (R$)</th>
                    <th>Custo (R$)</th>
                    <th>Lucro (R$)</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                foreach($lst as $pp){
                  $fv=(float)$pp['final_value'];
                  $ct=(float)$pp['cost_total'];
                  $lc=$fv-$ct;
                  ?>
                  <tr>
                    <td><?=$pp['id']?></td>
                    <td><?=htmlspecialchars($pp['customer_name'])?></td>
                    <td><?=htmlspecialchars($pp['status'])?></td>
                    <td><?=date('d/m/Y H:i',strtotime($pp['created_at']))?></td>
                    <td><?=number_format($fv,2,',','.')?></td>
                    <td><?=number_format($ct,2,',','.')?></td>
                    <td><?=number_format($lc,2,',','.')?></td>
                  </tr>
                  <?php
                }
                ?>
                </tbody>
              </table>
            </div>
            <?php
          }

        } catch(Exception $e){
          echo "<div class='alert alert-danger'>Erro Movimentação: "
               . htmlspecialchars($e->getMessage())."</div>";
        }
      } else {
        echo "<div class='alert alert-warning'>Datas inválidas.</div>";
      }
      ?>
      </div>
    </div>
    <?php
    break;

  // ====================================================
  // (B) CAIXA DIÁRIO
  // ====================================================
  case 'caixa':
    ?>
    <div class="card mb-4">
      <div class="card-header bg-light">
        <h5 class="mb-0">Caixa Diário (Hoje)</h5>
      </div>
      <div class="card-body">
      <?php
      // se POST => fecharDia
      if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='fecharDia'){
        $hoje = date('Y-m-d'); // -03:00
        try {
          // ver se já tem
          $ck=$pdo->prepare("SELECT id FROM daily_closings WHERE closing_date=?");
          $ck->execute([$hoje]);
          $ex=$ck->fetch(PDO::FETCH_ASSOC);
          if($ex){
            echo "<div class='alert alert-info'>Dia $hoje já foi fechado (#{$ex['id']}).</div>";
          } else {
            $stS=$pdo->prepare("
              SELECT
                COUNT(*) AS qtd,
                COALESCE(SUM(final_value),0) AS sumRev,
                COALESCE(SUM(cost_total),0)  AS sumCst
              FROM orders
              WHERE DATE(created_at)=:d
                AND status<>'CANCELADO'
                AND closed=0
            ");
            $stS->execute([':d'=>$hoje]);
            $rx=$stS->fetch(PDO::FETCH_ASSOC);

            $qtd=(int)($rx['qtd']??0);
            $sR=(float)($rx['sumRev']??0);
            $sC=(float)($rx['sumCst']??0);
            $sL=$sR-$sC;

            $pdo->prepare("
              INSERT INTO daily_closings
                (closing_date,total_orders,total_revenue,total_cost,total_profit,created_at)
              VALUES
                (?,?,?,?,?,NOW())
            ")->execute([$hoje,$qtd,$sR,$sC,$sL]);

            $pdo->exec("
              UPDATE orders
              SET closed=1
              WHERE DATE(created_at)='$hoje'
                AND status<>'CANCELADO'
            ");
            echo "<div class='alert alert-success'>Fechamento do dia $hoje realizado!</div>";
          }
        } catch(Exception $e){
          echo "<div class='alert alert-danger'>Erro ao fechar dia: "
               . htmlspecialchars($e->getMessage())."</div>";
        }
      }

      // listar pedidos hoje
      $hoje=date('Y-m-d'); // -03
      try {
        $stH=$pdo->prepare("
          SELECT
            id, customer_name, final_value, cost_total,
            status, created_at
          FROM orders
          WHERE DATE(created_at)=:d
            AND status<>'CANCELADO'
            AND closed=0
          ORDER BY created_at ASC
        ");
        $stH->execute([':d'=>$hoje]);
        $lst=$stH->fetchAll(PDO::FETCH_ASSOC);

        $sumV=0; $sumC=0;
        foreach($lst as $p){
          $sumV+=(float)$p['final_value'];
          $sumC+=(float)$p['cost_total'];
        }
        $sumL=$sumV-$sumC;

        echo "<p><strong>Data de Hoje:</strong> ".date('d/m/Y')."</p>";
        echo "<p><strong>Pedidos Hoje (abertos):</strong> ".count($lst)."</p>";
        echo "<p><strong>Receita:</strong> R$ ".number_format($sumV,2,',','.')."</p>";
        echo "<p><strong>Custo:</strong> R$ ".number_format($sumC,2,',','.')."</p>";
        echo "<p><strong>Lucro:</strong> R$ ".number_format($sumL,2,',','.')."</p>";
        ?>
        <form method="POST" class="mt-3" onsubmit="return confirm('Fechar dia de hoje?');">
          <input type="hidden" name="acao" value="fecharDia">
          <button type="submit" class="btn btn-danger btn-sm">
            Fechar Dia (<?=date('d/m/Y')?>)
          </button>
        </form>
        <?php

        if(!$lst){
          echo "<p class='mt-3'>Nenhum pedido aberto hoje.</p>";
        } else {
          ?>
          <hr>
          <h6>Pedidos de Hoje</h6>
          <div class="table-responsive mt-2">
            <table class="table table-bordered table-striped align-middle">
              <thead class="table-light">
                <tr>
                  <th>ID</th>
                  <th>Cliente</th>
                  <th>Status</th>
                  <th>Data/Hora</th>
                  <th>Venda</th>
                  <th>Custo</th>
                  <th>Lucro</th>
                </tr>
              </thead>
              <tbody>
              <?php
              foreach($lst as $pp){
                $fv=(float)$pp['final_value'];
                $ct=(float)$pp['cost_total'];
                $lc=$fv-$ct;
                ?>
                <tr>
                  <td><?=$pp['id']?></td>
                  <td><?=htmlspecialchars($pp['customer_name'])?></td>
                  <td><?=htmlspecialchars($pp['status'])?></td>
                  <td><?=date('d/m/Y H:i',strtotime($pp['created_at']))?></td>
                  <td><?=number_format($fv,2,',','.')?></td>
                  <td><?=number_format($ct,2,',','.')?></td>
                  <td><?=number_format($lc,2,',','.')?></td>
                </tr>
                <?php
              }
              ?>
              </tbody>
            </table>
          </div>
          <?php
        }
      } catch(Exception $e){
        echo "<div class='alert alert-danger'>Erro ao listar: "
             . htmlspecialchars($e->getMessage())."</div>";
      }
      ?>
      </div>
    </div>
    <?php
    break;

  // ====================================================
  // (C) FECHAMENTOS (HISTÓRICO)
  // ====================================================
  case 'fechamentos':
    ?>
    <div class="card mb-4">
      <div class="card-header bg-light">
        <h5 class="mb-0">Histórico de Fechamentos</h5>
      </div>
      <div class="card-body">
      <?php
      $di=isset($_GET['di'])?trim($_GET['di']): date('Y-01-01');
      $df=isset($_GET['df'])?trim($_GET['df']): date('Y-m-d');
      ?>
      <form method="GET" class="row g-3 align-items-end mb-3">
        <input type="hidden" name="action" value="fechamentos">
        <div class="col-auto">
          <label for="di" class="form-label mb-0"><strong>Data Inicial</strong></label>
          <input type="date" class="form-control form-control-sm" name="di" value="<?=htmlspecialchars($di)?>">
        </div>
        <div class="col-auto">
          <label for="df" class="form-label mb-0"><strong>Data Final</strong></label>
          <input type="date" class="form-control form-control-sm" name="df" value="<?=htmlspecialchars($df)?>">
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        </div>
      </form>
      <?php
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$di) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$df)) {
        try {
          $stF=$pdo->prepare("
            SELECT *
            FROM daily_closings
            WHERE closing_date BETWEEN :i AND :f
            ORDER BY closing_date DESC
          ");
          $stF->execute([':i'=>$di,':f'=>$df]);
          $rows=$stF->fetchAll(PDO::FETCH_ASSOC);

          if(!$rows){
            echo "<p>Nenhum fechamento encontrado no período.</p>";
          } else {
            $sumO=0;$sumR=0;$sumC=0;$sumP=0;
            foreach($rows as $rw){
              $sumO+=(int)$rw['total_orders'];
              $sumR+=(float)$rw['total_revenue'];
              $sumC+=(float)$rw['total_cost'];
              $sumP+=(float)$rw['total_profit'];
            }
            ?>
            <div class="alert alert-info">
              <p><strong>Pedidos Somados:</strong> <?=$sumO?></p>
              <p><strong>Receita:</strong> R$ <?=number_format($sumR,2,',','.')?></p>
              <p><strong>Custo:</strong> R$ <?=number_format($sumC,2,',','.')?></p>
              <p><strong>Lucro:</strong> R$ <?=number_format($sumP,2,',','.')?></p>
            </div>
            <div class="table-responsive">
              <table class="table table-bordered table-striped align-middle">
                <thead class="table-light">
                  <tr>
                    <th>ID</th>
                    <th>Data</th>
                    <th>Pedidos</th>
                    <th>Receita</th>
                    <th>Custo</th>
                    <th>Lucro</th>
                    <th>Criado em</th>
                    <th>Atualizado em</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                foreach($rows as $fc){
                  ?>
                  <tr>
                    <td><?=$fc['id']?></td>
                    <td><?=$fc['closing_date']?></td>
                    <td><?=$fc['total_orders']?></td>
                    <td><?=number_format($fc['total_revenue'],2,',','.')?></td>
                    <td><?=number_format($fc['total_cost'],2,',','.')?></td>
                    <td><?=number_format($fc['total_profit'],2,',','.')?></td>
                    <td><?=$fc['created_at']?></td>
                    <td><?=$fc['updated_at']?></td>
                  </tr>
                  <?php
                }
                ?>
                </tbody>
              </table>
            </div>
            <?php
          }

        } catch(Exception $e){
          echo "<div class='alert alert-danger'>Erro Fechamentos: "
               . htmlspecialchars($e->getMessage())."</div>";
        }
      } else {
        echo "<div class='alert alert-warning'>Datas inválidas.</div>";
      }
      ?>
      </div>
    </div>
    <?php
    break;

  // ====================================================
  // (D) RELATÓRIOS
  // ====================================================
  case 'relatorios':
    ?>
    <div class="card mb-4">
      <div class="card-header bg-light">
        <h5 class="mb-0">Relatórios Avançados</h5>
      </div>
      <div class="card-body">
      <?php
      $d1=isset($_GET['d1'])?trim($_GET['d1']):date('Y-m-01');
      $d2=isset($_GET['d2'])?trim($_GET['d2']):date('Y-m-d');
      ?>
      <form method="GET" class="row g-3 align-items-end mb-3">
        <input type="hidden" name="action" value="relatorios">
        <div class="col-auto">
          <label for="d1" class="form-label mb-0"><strong>Data Inicial</strong></label>
          <input type="date" class="form-control form-control-sm" name="d1"
                 value="<?=htmlspecialchars($d1)?>">
        </div>
        <div class="col-auto">
          <label for="d2" class="form-label mb-0"><strong>Data Final</strong></label>
          <input type="date" class="form-control form-control-sm" name="d2"
                 value="<?=htmlspecialchars($d2)?>">
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        </div>
      </form>
      <?php
      if (
        preg_match('/^\d{4}-\d{2}-\d{2}$/',$d1) &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/',$d2)
      ) {
        try {
          // Categorias
          $sqlCat="
            SELECT
              p.category AS cat,
              SUM(oi.quantity) AS totalQty,
              SUM(oi.subtotal) AS totalSub
            FROM order_items oi
            JOIN orders o ON oi.order_id=o.id
            JOIN products p ON oi.product_id=p.id
            WHERE DATE(o.created_at) BETWEEN :i AND :f
              AND o.status<>'CANCELADO'
            GROUP BY p.category
            ORDER BY totalSub DESC
          ";
          $stC=$pdo->prepare($sqlCat);
          $stC->execute([':i'=>$d1,':f'=>$d2]);
          $cats=$stC->fetchAll(PDO::FETCH_ASSOC);

          ?>
          <div class="alert alert-info">
            <strong>Período:</strong>
            <?=date('d/m/Y',strtotime($d1))?> até <?=date('d/m/Y',strtotime($d2))?>
          </div>

          <h6>Vendas por Categoria</h6>
          <?php
          if(!$cats){
            echo "<p>Nenhuma venda por categoria.</p>";
          } else {
            ?>
            <div class="table-responsive mb-4">
              <table class="table table-bordered table-striped align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Categoria</th>
                    <th>Qtd Vendida</th>
                    <th>Faturado (R$)</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                foreach($cats as $c){
                  $cn=$c['cat']?:'SEM CATEGORIA';
                  ?>
                  <tr>
                    <td><?=htmlspecialchars($cn)?></td>
                    <td><?= (int)$c['totalQty']?></td>
                    <td><?= number_format($c['totalSub'],2,',','.')?></td>
                  </tr>
                  <?php
                }
                ?>
                </tbody>
              </table>
            </div>
            <?php
          }

          // Top 10 produtos
          $sqlTop="
            SELECT
              oi.product_name AS nome,
              SUM(oi.quantity) AS qtd,
              SUM(oi.subtotal) AS fat
            FROM order_items oi
            JOIN orders o ON oi.order_id=o.id
            WHERE DATE(o.created_at) BETWEEN :i AND :f
              AND o.status<>'CANCELADO'
            GROUP BY oi.product_id
            ORDER BY fat DESC
            LIMIT 10
          ";
          $stT=$pdo->prepare($sqlTop);
          $stT->execute([':i'=>$d1,':f'=>$d2]);
          $tops=$stT->fetchAll(PDO::FETCH_ASSOC);

          echo "<h6>Top 10 Produtos (Faturamento)</h6>";
          if(!$tops){
            echo "<p>Nenhum produto vendido nesse período.</p>";
          } else {
            ?>
            <div class="table-responsive">
              <table class="table table-bordered table-striped align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Produto</th>
                    <th>Qtd</th>
                    <th>Faturado (R$)</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                foreach($tops as $tp){
                  ?>
                  <tr>
                    <td><?=htmlspecialchars($tp['nome'])?></td>
                    <td><?=$tp['qtd']?></td>
                    <td><?=number_format($tp['fat'],2,',','.')?></td>
                  </tr>
                  <?php
                }
                ?>
                </tbody>
              </table>
            </div>
            <?php
          }

        } catch(Exception $e){
          echo "<div class='alert alert-danger'>Erro Relatórios: "
               . htmlspecialchars($e->getMessage())."</div>";
        }
      } else {
        echo "<div class='alert alert-warning'>Datas inválidas.</div>";
      }
      ?>
      </div>
    </div>
    <?php
    break;

  // ====================================================
  // (E) COMPARAR PERÍODOS
  // ====================================================
  case 'comparar':
    ?>
    <div class="card mb-4">
      <div class="card-header bg-light">
        <h5 class="mb-0">Comparar Períodos (A vs B)</h5>
      </div>
      <div class="card-body">
      <?php
      $ca1=isset($_GET['ca1'])?trim($_GET['ca1']):date('Y-m-01');
      $ca2=isset($_GET['ca2'])?trim($_GET['ca2']):date('Y-m-d');
      $cb1=isset($_GET['cb1'])?trim($_GET['cb1']):'';
      $cb2=isset($_GET['cb2'])?trim($_GET['cb2']):'';

      ?>
      <form method="GET" class="row g-3 align-items-end mb-3">
        <input type="hidden" name="action" value="comparar">
        <div class="col-12"><strong>Período A</strong></div>
        <div class="col-auto">
          <label for="ca1" class="form-label mb-0">De</label>
          <input type="date" class="form-control form-control-sm" name="ca1"
                 value="<?=htmlspecialchars($ca1)?>">
        </div>
        <div class="col-auto">
          <label for="ca2" class="form-label mb-0">Até</label>
          <input type="date" class="form-control form-control-sm" name="ca2"
                 value="<?=htmlspecialchars($ca2)?>">
        </div>

        <div class="col-12 mt-3"><strong>Período B</strong></div>
        <div class="col-auto">
          <label for="cb1" class="form-label mb-0">De</label>
          <input type="date" class="form-control form-control-sm" name="cb1"
                 value="<?=htmlspecialchars($cb1)?>">
        </div>
        <div class="col-auto">
          <label for="cb2" class="form-label mb-0">Até</label>
          <input type="date" class="form-control form-control-sm" name="cb2"
                 value="<?=htmlspecialchars($cb2)?>">
        </div>
        <div class="col-12 mt-3">
          <button type="submit" class="btn btn-primary btn-sm">Comparar</button>
        </div>
      </form>
      <?php
      function sumar($pdo,$start,$end){
        $sx="
          SELECT
            COUNT(*) AS ped,
            COALESCE(SUM(final_value),0) AS rev,
            COALESCE(SUM(cost_total),0)  AS cst
          FROM orders
          WHERE DATE(created_at) BETWEEN :i AND :f
            AND status<>'CANCELADO'
        ";
        $stx=$pdo->prepare($sx);
        $stx->execute([':i'=>$start,':f'=>$end]);
        $r=$stx->fetch(PDO::FETCH_ASSOC);
        $p=(int)($r['ped']??0);
        $rv=(float)($r['rev']??0);
        $cs=(float)($r['cst']??0);
        return [$p,$rv,$cs,($rv-$cs)];
      }

      if (
        preg_match('/^\d{4}-\d{2}-\d{2}$/',$ca1) &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/',$ca2) &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/',$cb1) &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/',$cb2)
      ) {
        try {
          list($pA,$rA,$cA,$lA)=sumar($pdo,$ca1,$ca2);
          list($pB,$rB,$cB,$lB)=sumar($pdo,$cb1,$cb2);
          ?>
          <div class="border p-3 mt-2" style="background:#fafafa;">
            <h6>Período A:
              <?=date('d/m/Y',strtotime($ca1))?> a <?=date('d/m/Y',strtotime($ca2))?>
            </h6>
            <p>
              Pedidos: <?=$pA?><br>
              Receita: R$ <?=number_format($rA,2,',','.')?><br>
              Custo:   R$ <?=number_format($cA,2,',','.')?><br>
              Lucro:   R$ <?=number_format($lA,2,',','.')?>
            </p>

            <h6>Período B:
              <?=date('d/m/Y',strtotime($cb1))?> a <?=date('d/m/Y',strtotime($cb2))?>
            </h6>
            <p>
              Pedidos: <?=$pB?><br>
              Receita: R$ <?=number_format($rB,2,',','.')?><br>
              Custo:   R$ <?=number_format($cB,2,',','.')?><br>
              Lucro:   R$ <?=number_format($lB,2,',','.')?>
            </p>
            <hr>
            <?php
            function varPct($a,$b){
              if($b==0){
                if($a==0)return '0%';
                return '∞%';
              }
              $pct=(( $a-$b )/$b)*100;
              return number_format($pct,1,',','.') . '%';
            }
            ?>
            <h6>Variação (A vs B)</h6>
            <p>
              Pedidos: <?= varPct($pA,$pB) ?><br>
              Receita: <?= varPct($rA,$rB) ?><br>
              Lucro:   <?= varPct($lA,$lB) ?>
            </p>
          </div>
          <?php

        } catch(Exception $e){
          echo "<div class='alert alert-danger'>Erro Comparação: "
               . htmlspecialchars($e->getMessage())."</div>";
        }
      } else {
        echo "<div class='alert alert-warning'>Datas inválidas.</div>";
      }
      ?>
      </div>
    </div>
    <?php
    break;

  // ====================================================
  // (F) RANKING (ORAL, INJETAVEL, TODOS, MARCAS)
  // ====================================================
  case 'ranking':
    ?>
    <div class="card mb-4">
      <div class="card-header bg-light">
        <h5 class="mb-0">Ranking (Orais, Injetáveis, Todos, Marcas)</h5>
      </div>
      <div class="card-body">
      <?php
      $rIni=isset($_GET['rIni'])?trim($_GET['rIni']):date('Y-m-01');
      $rFim=isset($_GET['rFim'])?trim($_GET['rFim']):date('Y-m-d');
      ?>
      <form method="GET" class="row g-3 align-items-end mb-3">
        <input type="hidden" name="action" value="ranking">
        <div class="col-auto">
          <label for="rIni" class="form-label mb-0"><strong>Data Inicial</strong></label>
          <input type="date" class="form-control form-control-sm" name="rIni"
                 value="<?=htmlspecialchars($rIni)?>">
        </div>
        <div class="col-auto">
          <label for="rFim" class="form-label mb-0"><strong>Data Final</strong></label>
          <input type="date" class="form-control form-control-sm" name="rFim"
                 value="<?=htmlspecialchars($rFim)?>">
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        </div>
      </form>
      <?php
      if (
        preg_match('/^\d{4}-\d{2}-\d{2}$/',$rIni) &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/',$rFim)
      ) {
        try {
          // A) PRODUTOS ORAIS
          $sqlOral="
            SELECT
              p.name AS nomeProduto,
              b.name AS nomeMarca,
              SUM(oi.quantity) AS totalVendidos,
              SUM(oi.subtotal) AS faturado
            FROM order_items oi
            JOIN orders o ON oi.order_id=o.id
            JOIN products p ON oi.product_id=p.id
            LEFT JOIN brands b ON p.brand_id=b.id
            WHERE o.status<>'CANCELADO'
              AND p.category='Oral'
              AND DATE(o.created_at) BETWEEN :ini AND :fim
            GROUP BY p.id
            ORDER BY totalVendidos DESC
            LIMIT 10
          ";
          $stOral=$pdo->prepare($sqlOral);
          $stOral->execute([':ini'=>$rIni,':fim'=>$rFim]);
          $oralRes=$stOral->fetchAll(PDO::FETCH_ASSOC);

          echo "<h6>Top Produtos Orais</h6>";
          if(!$oralRes){
            echo "<p>Nenhum produto oral vendido.</p>";
          } else {
            ?>
            <div class="table-responsive mb-4">
              <table class="table table-bordered table-striped align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Pos.</th>
                    <th>Produto</th>
                    <th>Marca</th>
                    <th>Qtd</th>
                    <th>Faturado (R$)</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                $pos=1;
                foreach($oralRes as $o){
                  ?>
                  <tr>
                    <td><?=$pos++?></td>
                    <td><?=htmlspecialchars($o['nomeProduto'])?></td>
                    <td><?=htmlspecialchars($o['nomeMarca']??'')?></td>
                    <td><?= (int)$o['totalVendidos']?></td>
                    <td><?= number_format($o['faturado'],2,',','.')?></td>
                  </tr>
                  <?php
                }
                ?>
                </tbody>
              </table>
            </div>
            <?php
          }

          // B) PRODUTOS INJETÁVEIS
          $sqlInj="
            SELECT
              p.name AS nomeProduto,
              b.name AS nomeMarca,
              SUM(oi.quantity) AS totalVendidos,
              SUM(oi.subtotal) AS faturado
            FROM order_items oi
            JOIN orders o ON oi.order_id=o.id
            JOIN products p ON oi.product_id=p.id
            LEFT JOIN brands b ON p.brand_id=b.id
            WHERE o.status<>'CANCELADO'
              AND p.category='Injetavel'
              AND DATE(o.created_at) BETWEEN :ini AND :fim
            GROUP BY p.id
            ORDER BY totalVendidos DESC
            LIMIT 10
          ";
          $stInj=$pdo->prepare($sqlInj);
          $stInj->execute([':ini'=>$rIni,':fim'=>$rFim]);
          $injRes=$stInj->fetchAll(PDO::FETCH_ASSOC);

          echo "<h6>Top Produtos Injetáveis</h6>";
          if(!$injRes){
            echo "<p>Nenhum produto injetável vendido.</p>";
          } else {
            ?>
            <div class="table-responsive mb-4">
              <table class="table table-bordered table-striped align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Pos.</th>
                    <th>Produto</th>
                    <th>Marca</th>
                    <th>Qtd</th>
                    <th>Faturado (R$)</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                $pos=1;
                foreach($injRes as $i){
                  ?>
                  <tr>
                    <td><?=$pos++?></td>
                    <td><?=htmlspecialchars($i['nomeProduto'])?></td>
                    <td><?=htmlspecialchars($i['nomeMarca']??'')?></td>
                    <td><?= (int)$i['totalVendidos']?></td>
                    <td><?= number_format($i['faturado'],2,',','.')?></td>
                  </tr>
                  <?php
                }
                ?>
                </tbody>
              </table>
            </div>
            <?php
          }

          // C) TODOS OS PRODUTOS
          $sqlTodos="
            SELECT
              p.name AS nomeProduto,
              b.name AS nomeMarca,
              SUM(oi.quantity) AS totalVendidos,
              SUM(oi.subtotal) AS faturado
            FROM order_items oi
            JOIN orders o ON oi.order_id=o.id
            JOIN products p ON oi.product_id=p.id
            LEFT JOIN brands b ON p.brand_id=b.id
            WHERE o.status<>'CANCELADO'
              AND DATE(o.created_at) BETWEEN :ini AND :fim
            GROUP BY p.id
            ORDER BY totalVendidos DESC
            LIMIT 10
          ";
          $stTodos=$pdo->prepare($sqlTodos);
          $stTodos->execute([':ini'=>$rIni,':fim'=>$rFim]);
          $todosRes=$stTodos->fetchAll(PDO::FETCH_ASSOC);

          echo "<h6>Top (Todos os Produtos)</h6>";
          if(!$todosRes){
            echo "<p>Nenhum produto vendido no período.</p>";
          } else {
            ?>
            <div class="table-responsive mb-4">
              <table class="table table-bordered table-striped align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Pos.</th>
                    <th>Produto</th>
                    <th>Marca</th>
                    <th>Qtd</th>
                    <th>Faturado (R$)</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                $pos=1;
                foreach($todosRes as $t){
                  ?>
                  <tr>
                    <td><?=$pos++?></td>
                    <td><?=htmlspecialchars($t['nomeProduto'])?></td>
                    <td><?=htmlspecialchars($t['nomeMarca']??'')?></td>
                    <td><?= (int)$t['totalVendidos']?></td>
                    <td><?= number_format($t['faturado'],2,',','.')?></td>
                  </tr>
                  <?php
                }
                ?>
                </tbody>
              </table>
            </div>
            <?php
          }

          // D) MARCAS
          $sqlMarcas="
            SELECT
              b.name AS nomeMarca,
              SUM(oi.quantity) AS totalVendidos,
              SUM(oi.subtotal) AS faturado
            FROM order_items oi
            JOIN orders o ON oi.order_id=o.id
            JOIN products p ON oi.product_id=p.id
            JOIN brands b ON p.brand_id=b.id
            WHERE o.status<>'CANCELADO'
              AND DATE(o.created_at) BETWEEN :ini AND :fim
            GROUP BY b.id
            ORDER BY totalVendidos DESC
            LIMIT 10
          ";
          $stMar=$pdo->prepare($sqlMarcas);
          $stMar->execute([':ini'=>$rIni,':fim'=>$rFim]);
          $marcasRes=$stMar->fetchAll(PDO::FETCH_ASSOC);

          echo "<h6>Top Marcas</h6>";
          if(!$marcasRes){
            echo "<p>Nenhuma marca vendida no período.</p>";
          } else {
            ?>
            <div class="table-responsive mb-4">
              <table class="table table-bordered table-striped align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Pos.</th>
                    <th>Marca</th>
                    <th>Qtd</th>
                    <th>Faturado (R$)</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                $pos=1;
                foreach($marcasRes as $m){
                  ?>
                  <tr>
                    <td><?=$pos++?></td>
                    <td><?=htmlspecialchars($m['nomeMarca'])?></td>
                    <td><?= (int)$m['totalVendidos']?></td>
                    <td><?= number_format($m['faturado'],2,',','.')?></td>
                  </tr>
                  <?php
                }
                ?>
                </tbody>
              </table>
            </div>
            <?php
          }

        } catch(Exception $e){
          echo "<div class='alert alert-danger'>Erro Ranking: "
               . htmlspecialchars($e->getMessage())."</div>";
        }
      } else {
        echo "<div class='alert alert-warning'>Datas inválidas.</div>";
      }
      ?>
      </div>
    </div>
    <?php
    break;

  // ====================================================
  // (G) ANÁLISES MENSAIS
  // ====================================================
  case 'mensal':
    ?>
    <div class="card mb-4">
      <div class="card-header bg-light">
        <h5 class="mb-0">Análises Mensais</h5>
      </div>
      <div class="card-body">
      <?php
      $ano = date('Y'); // -03
      $mesAtual = date('n');

      $results=[];
      for($m=1; $m<=$mesAtual;$m++){
        $ym = $ano.'-'.str_pad($m,2,'0',STR_PAD_LEFT);
        $results[$ym]=['ped'=>0,'rec'=>0,'cst'=>0,'luc'=>0];
      }

      try {
        $sqlM="
          SELECT
            DATE_FORMAT(created_at,'%Y-%m') AS anoMes,
            COUNT(*) AS qtd,
            COALESCE(SUM(final_value),0) AS sumRev,
            COALESCE(SUM(cost_total),0)  AS sumCst
          FROM orders
          WHERE YEAR(created_at)=:ano
            AND status<>'CANCELADO'
          GROUP BY anoMes
          ORDER BY anoMes ASC
        ";
        $stmM=$pdo->prepare($sqlM);
        $stmM->execute([':ano'=>$ano]);
        $rowsM=$stmM->fetchAll(PDO::FETCH_ASSOC);

        foreach($rowsM as $rm){
          $am=$rm['anoMes'];
          if(isset($results[$am])){
            $results[$am]['ped']=(int)$rm['qtd'];
            $results[$am]['rec']=(float)$rm['sumRev'];
            $results[$am]['cst']=(float)$rm['sumCst'];
            $results[$am]['luc']=$results[$am]['rec']-$results[$am]['cst'];
          }
        }

      } catch(Exception $e){
        echo "<div class='alert alert-danger'>Erro Mensal: "
             . htmlspecialchars($e->getMessage())."</div>";
      }

      echo "<p>Exibindo de Janeiro até ".date('F')." de $ano.</p>";
      ?>
      <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
          <thead class="table-light">
            <tr>
              <th>Mês</th>
              <th>Pedidos</th>
              <th>Receita</th>
              <th>Custo</th>
              <th>Lucro</th>
            </tr>
          </thead>
          <tbody>
          <?php
          foreach($results as $mes=>$val){
            $mN=(int)substr($mes,5,2);
            $nomeMes=date('F',mktime(0,0,0,$mN,1,$ano));
            ?>
            <tr>
              <td><?=$nomeMes?></td>
              <td><?=$val['ped']?></td>
              <td><?=number_format($val['rec'],2,',','.')?></td>
              <td><?=number_format($val['cst'],2,',','.')?></td>
              <td><?=number_format($val['luc'],2,',','.')?></td>
            </tr>
            <?php
          }
          ?>
          </tbody>
        </table>
      </div>
      </div>
    </div>
    <?php
    break;

  // ====================================================
  // (H) CRUD DE FECHAMENTOS (Editar/Remover)
  // ====================================================
  case 'crud_fechamentos':
    ?>
    <div class="card mb-4">
      <div class="card-header bg-light">
        <h5 class="mb-0">Editar/Remover Fechamentos</h5>
      </div>
      <div class="card-body">
      <?php
      if ($_SERVER['REQUEST_METHOD']==='POST'){
        $act=$_POST['act']??'';

        // Remover
        if($act==='del'){
          $fid=(int)($_POST['fid']??0);
          if($fid>0){
            try{
              $pdo->prepare("DELETE FROM daily_closings WHERE id=?")->execute([$fid]);
              echo "<div class='alert alert-success'>Fechamento #$fid removido!</div>";
            } catch(Exception $e){
              echo "<div class='alert alert-danger'>Erro remover: "
                   . htmlspecialchars($e->getMessage())."</div>";
            }
          }
        }
        // Editar
        elseif($act==='edit'){
          $fid=(int)($_POST['fid']??0);
          $dt=trim($_POST['closing_date']??'');
          $ord=(int)($_POST['total_orders']??0);
          $rev=(float)($_POST['total_revenue']??0);
          $cst=(float)($_POST['total_cost']??0);
          $luc=$rev-$cst;

          if($fid>0 && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dt)){
            try{
              $pdo->prepare("
                UPDATE daily_closings
                   SET closing_date=?,
                       total_orders=?,
                       total_revenue=?,
                       total_cost=?,
                       total_profit=?,
                       updated_at=NOW()
                 WHERE id=?
              ")->execute([$dt,$ord,$rev,$cst,$luc,$fid]);
              echo "<div class='alert alert-success'>Fechamento #$fid atualizado!</div>";
            } catch(Exception $e){
              echo "<div class='alert alert-danger'>Erro editar: "
                   . htmlspecialchars($e->getMessage())."</div>";
            }
          }
        }
      }

      // Listar
      try {
        $qF=$pdo->query("
          SELECT *
          FROM daily_closings
          ORDER BY closing_date DESC
          LIMIT 50
        ");
        $flist=$qF->fetchAll(PDO::FETCH_ASSOC);
      } catch(Exception $e){
        echo "<div class='alert alert-danger'>Erro: "
             . htmlspecialchars($e->getMessage())."</div>";
        $flist=[];
      }

      if(!$flist){
        echo "<p>Nenhum fechamento encontrado.</p>";
      } else {
        ?>
        <div class="table-responsive">
          <table class="table table-bordered table-striped align-middle">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Data</th>
                <th>Pedidos</th>
                <th>Receita</th>
                <th>Custo</th>
                <th>Lucro</th>
                <th>Ações</th>
              </tr>
            </thead>
            <tbody>
            <?php
            foreach($flist as $f){
              ?>
              <tr>
                <td><?=$f['id']?></td>
                <td><?=$f['closing_date']?></td>
                <td><?=$f['total_orders']?></td>
                <td><?=number_format($f['total_revenue'],2,',','.')?></td>
                <td><?=number_format($f['total_cost'],2,',','.')?></td>
                <td><?=number_format($f['total_profit'],2,',','.')?></td>
                <td>
                  <button type="button" class="btn btn-sm btn-primary"
                          onclick="abrirFormFech(
                            <?=$f['id']?>,
                            '<?=$f['closing_date']?>',
                            <?=$f['total_orders']?>,
                            <?=$f['total_revenue']?>,
                            <?=$f['total_cost']?>
                          )">
                    Editar
                  </button>
                  <form method="POST" class="d-inline"
                        onsubmit="return confirm('Remover fechamento #<?=$f['id']?>?');">
                    <input type="hidden" name="act" value="del">
                    <input type="hidden" name="fid" value="<?=$f['id']?>">
                    <button type="submit" class="btn btn-sm btn-danger">
                      Remover
                    </button>
                  </form>
                </td>
              </tr>
              <?php
            }
            ?>
            </tbody>
          </table>
        </div>
        <?php
      }
      ?>
      </div>
    </div>

    <!-- Modal Edição -->
    <div class="modal fade" id="modalEdicaoFech" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Editar Fechamento</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"
                    aria-label="Fechar"></button>
          </div>
          <div class="modal-body">
            <form method="POST">
              <input type="hidden" name="act" value="edit">
              <input type="hidden" name="fid" id="fechId">

              <div class="mb-2">
                <label class="form-label">Data (closing_date):</label>
                <input type="date" name="closing_date" class="form-control" id="fechData">
              </div>
              <div class="mb-2">
                <label class="form-label">Pedidos (total_orders):</label>
                <input type="number" name="total_orders" class="form-control" id="fechPed">
              </div>
              <div class="mb-2">
                <label class="form-label">Receita (total_revenue):</label>
                <input type="number" step="0.01" name="total_revenue"
                       class="form-control" id="fechRec">
              </div>
              <div class="mb-2">
                <label class="form-label">Custo (total_cost):</label>
                <input type="number" step="0.01" name="total_cost"
                       class="form-control" id="fechCst">
              </div>

              <div class="mt-3">
                <button type="submit" class="btn btn-success">Salvar</button>
                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">Cancelar</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <script>
      function abrirFormFech(id, dt, orders, rev, cst){
        document.getElementById('fechId').value   = id;
        document.getElementById('fechData').value = dt;
        document.getElementById('fechPed').value  = orders;
        document.getElementById('fechRec').value  = rev;
        document.getElementById('fechCst').value  = cst;

        let m = new bootstrap.Modal(document.getElementById('modalEdicaoFech'), {});
        m.show();
      }
    </script>
    <?php
    break;

} // fim do switch($action)

?>
</div><!-- /.container -->

<!-- Scripts Bootstrap -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
