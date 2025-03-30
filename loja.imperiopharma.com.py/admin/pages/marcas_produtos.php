<?php
/**************************************************************
 * admin/pages/marcas_produtos.php
 *
 * GERENCIAMENTO DE MARCAS & PRODUTOS + EDIÇÃO EM MASSA
 *   - Possui abas:
 *     1) Marcas
 *     2) Produtos
 *     3) Edição em Massa (Produtos)
 *     4) Edição em Massa (Marcas)
 *     5) Edição em Massa de Valores (foca em price/promo/cost + lucro)
 *
 * Agora, na mesma aba de Edição em Massa (Produtos),
 * adicionamos a possibilidade de inserir vários produtos
 * com todos os campos completos (name, description, price, etc.).
 *
 * Tabelas:
 *   - brands: (id, slug, name, brand_type, banner, btn_image,
 *              stock, stock_message, sort_order, ...)
 *   - products: (id, brand_id, name, description, price,
 *                promo_price, cost, category, active, image_url, ...)
 **************************************************************/

// 1) Conexão PDO
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=imperiopharma_loja_db;charset=utf8mb4",
        "imperiopharma_loja_user",
        "Miguel22446688"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(Exception $e) {
    die("Erro ao conectar no BD: " . $e->getMessage());
}

// Qual aba? (tab=marcas|produtos|mass|mass_brands|mass_prices)
$tab = isset($_GET['tab']) ? trim($_GET['tab']) : 'marcas';

// (filtros de marcas e produtos, se precisar)
$filterNameMarca = isset($_GET['fname_marca']) ? trim($_GET['fname_marca']) : '';
$filterBrand     = isset($_GET['fbrand']) ? (int)$_GET['fbrand'] : 0;
$filterName      = isset($_GET['fname'])  ? trim($_GET['fname']) : '';

//--------------------------------------------------------------------
// 1) AÇÕES PARA MARCAS
//--------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'marca') {
    $act = $_POST['act'] ?? '';

    // (a) Inserir Marca
    if ($act === 'add') {
        $slug      = trim($_POST['slug']        ?? '');
        $name      = trim($_POST['name']        ?? '');
        $brandType = trim($_POST['brand_type']  ?? '');
        $banner    = trim($_POST['banner']      ?? '');
        $btnImg    = trim($_POST['btn_image']   ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $stock     = (int)($_POST['stock']      ?? 1);
        $stockMsg  = trim($_POST['stock_message'] ?? '');

        if ($slug === '' || $name === '') {
            echo "<div class='alert alert-danger'>Slug e Nome são obrigatórios!</div>";
        } else {
            try {
                $sql = "
                  INSERT INTO brands
                    (slug, name, brand_type, banner, btn_image,
                     stock, stock_message, sort_order)
                  VALUES
                    (:slug, :nm, :bt, :ban, :btn, :st, :msg, :ord)
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':slug' => $slug,
                    ':nm'   => $name,
                    ':bt'   => $brandType,
                    ':ban'  => $banner,
                    ':btn'  => $btnImg,
                    ':st'   => $stock,
                    ':msg'  => $stockMsg,
                    ':ord'  => $sortOrder
                ]);
                echo "<div class='alert alert-success'>Marca inserida com sucesso!</div>";
            } catch (Exception $e) {
                echo "<div class='alert alert-danger'>Erro ao inserir marca: "
                     . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }

    // (b) Editar Marca
    elseif ($act === 'edit') {
        $id        = (int)($_POST['id'] ?? 0);
        $slug      = trim($_POST['slug']       ?? '');
        $name      = trim($_POST['name']       ?? '');
        $brandType = trim($_POST['brand_type'] ?? '');
        $banner    = trim($_POST['banner']     ?? '');
        $btnImg    = trim($_POST['btn_image']  ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $stock     = (int)($_POST['stock'] ?? 1);
        $stockMsg  = trim($_POST['stock_message'] ?? '');

        if ($id <= 0 || $slug === '' || $name === '') {
            echo "<div class='alert alert-danger'>Faltam dados (ID, slug, name)...</div>";
        } else {
            try {
                $sql = "
                  UPDATE brands
                     SET slug          = :sl,
                         name          = :nm,
                         brand_type    = :bt,
                         banner        = :ban,
                         btn_image     = :bimg,
                         stock         = :st,
                         stock_message = :smsg,
                         sort_order    = :sord
                   WHERE id = :id
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':sl'   => $slug,
                    ':nm'   => $name,
                    ':bt'   => $brandType,
                    ':ban'  => $banner,
                    ':bimg' => $btnImg,
                    ':st'   => $stock,
                    ':smsg' => $stockMsg,
                    ':sord' => $sortOrder,
                    ':id'   => $id
                ]);
                echo "<div class='alert alert-success'>Marca #$id editada!</div>";
            } catch (Exception $e) {
                echo "<div class='alert alert-danger'>Erro ao editar marca: "
                     . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }

    // (c) Excluir Marca
    elseif ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo "<div class='alert alert-danger'>ID inválido para excluir marca!</div>";
        } else {
            try {
                $pdo->prepare("DELETE FROM brands WHERE id=?")->execute([$id]);
                echo "<div class='alert alert-success'>Marca #$id excluída!</div>";
            } catch (Exception $e) {
                echo "<div class='alert alert-danger'>Erro ao excluir marca: "
                     . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }

    // (d) Edição em Massa de Marcas
    elseif ($act === 'mass_update_brands') {
        $massArr = $_POST['mass_brands'] ?? [];
        if (!is_array($massArr) || empty($massArr)) {
            echo "<div class='alert alert-danger'>Nenhuma marca para edição em massa!</div>";
        } else {
            $countUp = 0;
            try {
                $pdo->beginTransaction();
                foreach ($massArr as $mid => $flds) {
                    $mid = (int)$mid;
                    if ($mid > 0) {
                        $slug  = trim($flds['slug']  ?? '');
                        $nome  = trim($flds['name']  ?? '');
                        $btype = trim($flds['brand_type'] ?? '');
                        $stck  = (int)($flds['stock']       ?? 1);
                        $sord  = (int)($flds['sort_order']  ?? 0);
                        $smsg  = trim($flds['stock_message'] ?? '');

                        if ($slug !== '' && $nome !== '') {
                            $stmt = $pdo->prepare("
                              UPDATE brands
                                 SET slug          = :s,
                                     name          = :n,
                                     brand_type    = :t,
                                     stock         = :stk,
                                     sort_order    = :so,
                                     stock_message = :msg
                               WHERE id = :id
                            ");
                            $stmt->execute([
                                ':s'   => $slug,
                                ':n'   => $nome,
                                ':t'   => $btype,
                                ':stk' => $stck,
                                ':so'  => $sord,
                                ':msg' => $smsg,
                                ':id'  => $mid
                            ]);
                            $countUp++;
                        }
                    }
                }
                $pdo->commit();
                echo "<div class='alert alert-success'>
                       Edição em Massa de Marcas concluída! ($countUp atualizadas)
                      </div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<div class='alert alert-danger'>
                       Erro na edição em massa de marcas: ".htmlspecialchars($e->getMessage())."
                      </div>";
            }
        }
    }
}

//--------------------------------------------------------------------
// 2) AÇÕES PARA PRODUTOS
//--------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'produto') {
    $act = $_POST['act'] ?? '';

    // (a) Inserir Produto (formulário individual)
    if ($act === 'add') {
        $brandId  = (int)($_POST['brand_id'] ?? 0);
        $name     = trim($_POST['name']        ?? '');
        $descr    = trim($_POST['description'] ?? '');
        $price    = floatval($_POST['price']   ?? 0);
        $promo    = floatval($_POST['promo_price'] ?? 0);
        $cost     = floatval($_POST['cost']    ?? 0);
        $cat      = trim($_POST['category']    ?? '');
        $active   = isset($_POST['active'])    ? 1 : 0;
        $img      = trim($_POST['image_url']   ?? '');

        if ($name === '') {
            echo "<div class='alert alert-danger'>Nome do produto é obrigatório!</div>";
        } else {
            try {
                $stI = $pdo->prepare("
                  INSERT INTO products
                    (brand_id, name, description, price, promo_price, cost,
                     category, active, image_url)
                  VALUES
                    (:b, :n, :ds, :pr, :pm, :ct, :c, :a, :img)
                ");
                $stI->execute([
                    ':b'   => $brandId,
                    ':n'   => $name,
                    ':ds'  => $descr,
                    ':pr'  => $price,
                    ':pm'  => $promo,
                    ':ct'  => $cost,
                    ':c'   => $cat,
                    ':a'   => $active,
                    ':img' => $img
                ]);
                echo "<div class='alert alert-success'>Produto inserido!</div>";
            } catch (Exception $e) {
                echo "<div class='alert alert-danger'>Erro ao inserir produto: "
                     . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }

    // (b) Editar Produto
    elseif ($act === 'edit') {
        $id      = (int)($_POST['id'] ?? 0);
        $brandId = (int)($_POST['brand_id'] ?? 0);
        $name    = trim($_POST['name']        ?? '');
        $descr   = trim($_POST['description'] ?? '');
        $price   = floatval($_POST['price']   ?? 0);
        $promo   = floatval($_POST['promo_price'] ?? 0);
        $cost    = floatval($_POST['cost']    ?? 0);
        $cat     = trim($_POST['category']    ?? '');
        $active  = isset($_POST['active'])    ? 1 : 0;
        $img     = trim($_POST['image_url']   ?? '');

        if ($id <= 0 || $name === '') {
            echo "<div class='alert alert-danger'>Faltam dados (ID ou nome) para editar!</div>";
        } else {
            try {
                $stU = $pdo->prepare("
                  UPDATE products
                     SET brand_id    = :b,
                         name        = :n,
                         description = :ds,
                         price       = :pr,
                         promo_price = :pm,
                         cost        = :ct,
                         category    = :cat,
                         active      = :ac,
                         image_url   = :img
                   WHERE id = :id
                ");
                $stU->execute([
                    ':b'   => $brandId,
                    ':n'   => $name,
                    ':ds'  => $descr,
                    ':pr'  => $price,
                    ':pm'  => $promo,
                    ':ct'  => $cost,
                    ':cat' => $cat,
                    ':ac'  => $active,
                    ':img' => $img,
                    ':id'  => $id
                ]);
                echo "<div class='alert alert-success'>Produto #$id editado!</div>";
            } catch (Exception $e) {
                echo "<div class='alert alert-danger'>Erro ao editar produto: "
                     . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }

    // (c) Excluir Produto
    elseif ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo "<div class='alert alert-danger'>ID inválido para excluir produto!</div>";
        } else {
            try {
                $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
                echo "<div class='alert alert-success'>Produto #$id excluído!</div>";
            } catch (Exception $e) {
                echo "<div class='alert alert-danger'>Erro ao excluir produto: "
                     . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }

    // (d) Edição em Massa (Produtos) - Atualizar EXISTENTES
    elseif ($act === 'mass_update') {
        $massArr = $_POST['mass'] ?? [];
        if (!is_array($massArr) || empty($massArr)) {
            echo "<div class='alert alert-danger'>Nenhum produto para edição em massa!</div>";
        } else {
            $countUp = 0;
            try {
                $pdo->beginTransaction();
                foreach ($massArr as $pid => $flds) {
                    $pid = (int)$pid;
                    if ($pid > 0) {
                        $b  = (int)($flds['brand_id']     ?? 0);
                        $pr = floatval($flds['price']       ?? 0);
                        $pm = floatval($flds['promo_price'] ?? 0);
                        $ct = floatval($flds['cost']        ?? 0);
                        $ac = isset($flds['active'])        ? 1 : 0;

                        $pdo->prepare("
                          UPDATE products
                             SET brand_id    = ?,
                                 price       = ?,
                                 promo_price = ?,
                                 cost        = ?,
                                 active      = ?
                           WHERE id = ?
                        ")->execute([$b, $pr, $pm, $ct, $ac, $pid]);
                        $countUp++;
                    }
                }
                $pdo->commit();
                echo "<div class='alert alert-success'>
                       Edição em Massa concluída! ($countUp produtos atualizados)
                      </div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<div class='alert alert-danger'>Erro na edição em massa: "
                     . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }

    // (D.2) **Nova funcionalidade**: Inserir Múltiplos Produtos em Massa (com todos os campos)
    elseif ($act === 'mass_add') {
        $massNewArr = $_POST['mass_new'] ?? [];
        if (!is_array($massNewArr) || empty($massNewArr)) {
            echo "<div class='alert alert-danger'>Nenhum produto novo para inserir em massa.</div>";
        } else {
            $countInserted = 0;
            try {
                $pdo->beginTransaction();
                foreach ($massNewArr as $index => $flds) {
                    // Extrair cada campo
                    $brandId = (int)($flds['brand_id']    ?? 0);
                    $name    = trim($flds['name']         ?? '');
                    $descr   = trim($flds['description']  ?? '');
                    $price   = floatval($flds['price']     ?? 0);
                    $promo   = floatval($flds['promo_price'] ?? 0);
                    $cost    = floatval($flds['cost']      ?? 0);
                    $cat     = trim($flds['category']      ?? '');
                    $img     = trim($flds['image_url']     ?? '');
                    $active  = isset($flds['active'])       ? 1 : 0;

                    // Se não digitou nome, pula
                    if ($name === '') {
                        continue;
                    }

                    // Inserir
                    $sqlIns = $pdo->prepare("
                      INSERT INTO products
                        (brand_id, name, description, price, promo_price, cost,
                         category, active, image_url)
                      VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $sqlIns->execute([
                        $brandId,
                        $name,
                        $descr,
                        $price,
                        $promo,
                        $cost,
                        $cat,
                        $active,
                        $img
                    ]);
                    $countInserted++;
                }
                $pdo->commit();
                echo "<div class='alert alert-success'>
                        Inserção em Massa concluída! ($countInserted produtos novos inseridos)
                      </div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<div class='alert alert-danger'>
                        Erro ao inserir em massa: ".htmlspecialchars($e->getMessage())."
                      </div>";
            }
        }
    }

    // (e) Edição em Massa de Valores (promo, price, cost)
    elseif ($act === 'mass_update_prices') {
        $massVals = $_POST['mass_prices'] ?? [];
        if (!is_array($massVals) || empty($massVals)) {
            echo "<div class='alert alert-danger'>Nenhum produto para a aba de valores!</div>";
        } else {
            $countUp = 0;
            try {
                $pdo->beginTransaction();
                foreach ($massVals as $pid => $flds) {
                    $pid = (int)$pid;
                    if ($pid > 0) {
                        $pr = floatval($flds['price']       ?? 0);
                        $pm = floatval($flds['promo_price'] ?? 0);
                        $ct = floatval($flds['cost']        ?? 0);

                        // Atualiza price, promo_price e cost
                        $pdo->prepare("
                          UPDATE products
                             SET price       = ?,
                                 promo_price = ?,
                                 cost        = ?
                           WHERE id = ?
                        ")->execute([$pr, $pm, $ct, $pid]);

                        $countUp++;
                    }
                }
                $pdo->commit();
                echo "<div class='alert alert-success'>
                       Edição em Massa de Valores concluída! ($countUp atualizados)
                      </div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<div class='alert alert-danger'>Erro na edição em massa de valores: "
                     . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
}

//--------------------------------------------------------------------
// 3) CARREGAR LISTAS
//--------------------------------------------------------------------

// 3.1) Marcas (para listar e p/ selects)
$whereMarcas  = [];
$paramsMarcas = [];
if ($tab === 'mass_brands' && $filterNameMarca !== '') {
    $whereMarcas[] = "name LIKE :nm";
    $paramsMarcas[':nm'] = "%$filterNameMarca%";
}
$sqlMarcas = "SELECT * FROM brands ";
if (!empty($whereMarcas)) {
    $sqlMarcas .= " WHERE " . implode(' AND ', $whereMarcas);
}
$sqlMarcas .= " ORDER BY sort_order ASC, name ASC";

$listaMarcas = [];
try {
    $rM = $pdo->prepare($sqlMarcas);
    $rM->execute($paramsMarcas);
    $listaMarcas = $rM->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// 3.2) Para <select> de brand_id (usado em produtos)
$brandsSelect = [];
try {
    $rBsel = $pdo->query("SELECT id, name FROM brands ORDER BY sort_order ASC, name ASC");
    $brandsSelect = $rBsel->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// 3.3) Produtos (para Aba Produtos + Mass)
$where  = [];
$params = [];
if ($tab === 'mass') {
    if ($filterBrand > 0) {
        $where[] = "p.brand_id = :b";
        $params[':b'] = $filterBrand;
    }
    if ($filterName !== '') {
        $where[] = "p.name LIKE :n";
        $params[':n'] = "%$filterName%";
    }
}
$sqlProd = "
    SELECT p.*, b.name AS brand_name
      FROM products p
 LEFT JOIN brands b ON p.brand_id = b.id
";
if (!empty($where)) {
    $sqlProd .= " WHERE " . implode(" AND ", $where);
}
$sqlProd .= " ORDER BY p.id DESC";

$listaProdutos = [];
try {
    $stmP = $pdo->prepare($sqlProd);
    $stmP->execute($params);
    $listaProdutos = $stmP->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// 3.4) Produtos para a aba mass_prices
$whereVals  = [];
$paramsVals = [];
if ($tab === 'mass_prices') {
    if ($filterBrand > 0) {
        $whereVals[] = "p.brand_id = :b";
        $paramsVals[':b'] = $filterBrand;
    }
    if ($filterName !== '') {
        $whereVals[] = "p.name LIKE :n";
        $paramsVals[':n'] = "%$filterName%";
    }
}
$sqlProdVals = "
  SELECT p.*, b.name AS brand_name
    FROM products p
    LEFT JOIN brands b ON p.brand_id = b.id
";
if (!empty($whereVals)) {
    $sqlProdVals .= " WHERE " . implode(" AND ", $whereVals);
}
$sqlProdVals .= " ORDER BY p.id DESC";

$listaProdutosValores = [];
try {
    $stmV = $pdo->prepare($sqlProdVals);
    $stmV->execute($paramsVals);
    $listaProdutosValores = $stmV->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <title>Painel - Marcas & Produtos</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet">
  <style>
    .tab-nav { margin-bottom: 1rem; }
    .tab-btn { margin-right: 0.5rem; }
    .painel-card {
      padding: 1rem;
      background-color: #f8f9fa;
      border: 1px solid #ddd;
      border-radius: 4px;
      margin-bottom: 1rem;
    }
    .alert { margin: 0.75rem 0; }
    @media (max-width: 576px) {
      .tab-nav { flex-direction: column; }
      .tab-btn { margin-bottom: 0.5rem; }
    }
    /* Input "lucro" só leitura */
    .lucro-field {
      background-color: #f0f0f0;
      color: #333;
    }
  </style>
</head>
<body class="bg-light">

<div class="container-fluid">
  <h2 class="my-3">Gerenciar Marcas & Produtos</h2>

  <!-- Botões de Abas -->
  <div class="tab-nav d-flex flex-wrap">
    <button class="tab-btn btn btn-primary mb-2" data-tab="marcas">
      Marcas
    </button>
    <button class="tab-btn btn btn-primary mb-2" data-tab="produtos">
      Produtos
    </button>
    <button class="tab-btn btn btn-primary mb-2" data-tab="mass">
      Edição em Massa (Produtos)
    </button>
    <button class="tab-btn btn btn-primary mb-2" data-tab="mass_brands">
      Edição em Massa (Marcas)
    </button>
    <!-- ABA para editar valores (lucro) -->
    <button class="tab-btn btn btn-primary mb-2" data-tab="mass_prices">
      Edição de Valores (Lucro Real-Time)
    </button>
  </div>

  <div class="tab-content">

    <!-- ======================== ABA MARCAS ======================== -->
    <div class="tab-pane" id="tab-marcas" style="display:none;">
      <h3>Marcas</h3>
      <!-- (Conteúdo da Aba Marcas) -->
      <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Slug</th>
              <th>Nome</th>
              <th>Tipo</th>
              <th>Banner</th>
              <th>Btn Img</th>
              <th>Estoque</th>
              <th>Ordem</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($listaMarcas)): ?>
            <tr>
              <td colspan="9" class="text-center">Nenhuma marca cadastrada.</td>
            </tr>
          <?php else: ?>
            <?php foreach($listaMarcas as $m): ?>
              <tr>
                <td><?= $m['id'] ?></td>
                <td><?= htmlspecialchars($m['slug']) ?></td>
                <td><?= htmlspecialchars($m['name']) ?></td>
                <td><?= htmlspecialchars($m['brand_type'] ?? '') ?></td>
                <td>
                  <?php if(!empty($m['banner'])): ?>
                    <img src="<?= htmlspecialchars($m['banner']) ?>"
                         alt="Banner"
                         style="max-width:70px; max-height:40px;">
                  <?php endif; ?>
                </td>
                <td>
                  <?php if(!empty($m['btn_image'])): ?>
                    <img src="<?= htmlspecialchars($m['btn_image']) ?>"
                         alt="BtnImg"
                         style="max-width:70px;">
                  <?php endif; ?>
                </td>
                <td><?= (int)$m['stock'] ?></td>
                <td><?= (int)$m['sort_order'] ?></td>
                <td>
                  <button type="button"
                          class="btn btn-sm btn-primary me-1"
                          onclick="editMarca(
                            <?= $m['id'] ?>,
                            '<?= htmlspecialchars($m['slug'], ENT_QUOTES) ?>',
                            '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>',
                            '<?= htmlspecialchars($m['brand_type'] ?? '', ENT_QUOTES) ?>',
                            '<?= htmlspecialchars($m['banner'] ?? '', ENT_QUOTES) ?>',
                            '<?= htmlspecialchars($m['btn_image'] ?? '', ENT_QUOTES) ?>',
                            <?= (int)$m['stock'] ?>,
                            <?= (int)$m['sort_order'] ?>,
                            '<?= htmlspecialchars($m['stock_message'] ?? '', ENT_QUOTES) ?>'
                          )">
                    Editar
                  </button>
                  <form method="POST" class="d-inline"
                        onsubmit="return confirm('Excluir marca #<?= $m['id'] ?>?');">
                    <input type="hidden" name="action" value="marca">
                    <input type="hidden" name="act"    value="delete">
                    <input type="hidden" name="id"     value="<?= $m['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">
                      Excluir
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Form de Inserir/Editar Marcas -->
      <div class="painel-card" id="marcaFormContainer">
        <h4 id="marcaFormTitle">Inserir Nova Marca</h4>
        <form method="POST" id="formMarca">
          <input type="hidden" name="action" value="marca">
          <input type="hidden" name="act" value="add" id="marcaAct">
          <input type="hidden" name="id"  value=""   id="marcaId">

          <div class="mb-2">
            <label for="marcaSlug" class="form-label">Slug:</label>
            <input type="text"
                   name="slug"
                   id="marcaSlug"
                   class="form-control"
                   required />
          </div>

          <div class="mb-2">
            <label for="marcaName" class="form-label">Nome:</label>
            <input type="text"
                   name="name"
                   id="marcaName"
                   class="form-control"
                   required />
          </div>

          <div class="mb-2">
            <label for="marcaType" class="form-label">Tipo (brand_type):</label>
            <input type="text"
                   name="brand_type"
                   id="marcaType"
                   class="form-control" />
          </div>

          <div class="mb-2">
            <label for="marcaBanner" class="form-label">Banner (URL):</label>
            <input type="text"
                   name="banner"
                   id="marcaBanner"
                   class="form-control" />
          </div>

          <div class="mb-2">
            <label for="marcaBtn" class="form-label">Botão (btn_image):</label>
            <input type="text"
                   name="btn_image"
                   id="marcaBtn"
                   class="form-control" />
          </div>

          <div class="mb-2">
            <label for="marcaStock" class="form-label">Estoque (1,2,3,etc):</label>
            <input type="number"
                   name="stock"
                   id="marcaStock"
                   class="form-control"
                   value="1" />
          </div>

          <div class="mb-2">
            <label for="stockMessage" class="form-label">
              Mensagem Personalizada (stock_message):
            </label>
            <textarea name="stock_message"
                      id="stockMessage"
                      rows="2"
                      class="form-control"></textarea>
          </div>

          <div class="mb-2">
            <label for="marcaSort" class="form-label">Ordem (sort_order):</label>
            <input type="number"
                   name="sort_order"
                   id="marcaSort"
                   class="form-control"
                   placeholder="0 = padrão. Quanto menor, mais acima." />
          </div>

          <button type="submit" class="btn btn-primary">Salvar</button>
          <button type="button"
                  class="btn btn-secondary"
                  onclick="resetMarcaForm()">
            Cancelar
          </button>
        </form>
      </div>
    </div><!-- /#tab-marcas -->


    <!-- ======================== ABA PRODUTOS ======================== -->
    <div class="tab-pane" id="tab-produtos" style="display:none;">
      <h3>Produtos</h3>
      <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Marca</th>
              <th>Nome</th>
              <th>Preço</th>
              <th>Promo</th>
              <th>Custo</th>
              <th>Ativo?</th>
              <th>Imagem</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
          <?php if(empty($listaProdutos)): ?>
            <tr><td colspan="9" class="text-center">
              Nenhum produto cadastrado.
            </td></tr>
          <?php else: ?>
            <?php foreach($listaProdutos as $p): ?>
              <?php
                $promoVal = ($p['promo_price'] > 0)
                            ? number_format($p['promo_price'], 2, ',', '.')
                            : '--';
              ?>
              <tr>
                <td><?= $p['id'] ?></td>
                <td><?= htmlspecialchars($p['brand_name'] ?? '---') ?></td>
                <td><?= htmlspecialchars($p['name']) ?></td>
                <td>R$ <?= number_format($p['price'], 2, ',', '.') ?></td>
                <td><?= $promoVal ?></td>
                <td>R$ <?= number_format($p['cost'], 2, ',', '.') ?></td>
                <td><?= $p['active'] ? 'Sim' : 'Não' ?></td>
                <td>
                  <?php if(!empty($p['image_url'])): ?>
                    <img src="<?= htmlspecialchars($p['image_url']) ?>"
                         alt="ProdImg"
                         style="max-width:60px;">
                  <?php endif; ?>
                </td>
                <td>
                  <button type="button"
                          class="btn btn-sm btn-primary me-1"
                          onclick="editProd(
                            <?= $p['id'] ?>,
                            <?= (int)$p['brand_id'] ?>,
                            '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>',
                            '<?= htmlspecialchars($p['description'], ENT_QUOTES) ?>',
                            <?= floatval($p['price']) ?>,
                            <?= floatval($p['promo_price']) ?>,
                            <?= floatval($p['cost']) ?>,
                            '<?= htmlspecialchars($p['category'], ENT_QUOTES) ?>',
                            <?= (int)$p['active'] ?>,
                            '<?= htmlspecialchars($p['image_url'], ENT_QUOTES) ?>'
                          )">
                    Editar
                  </button>
                  <form method="POST" class="d-inline"
                        onsubmit="return confirm('Excluir produto #<?= $p['id'] ?>?');">
                    <input type="hidden" name="action" value="produto">
                    <input type="hidden" name="act"    value="delete">
                    <input type="hidden" name="id"     value="<?= $p['id'] ?>">
                    <button type="submit"
                            class="btn btn-sm btn-danger">
                      Excluir
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach;?>
          <?php endif;?>
          </tbody>
        </table>
      </div>

      <!-- Formulário de Inserir/Editar Produtos (individual) -->
      <div class="painel-card">
        <h4 id="prodTitleForm">Inserir Novo Produto</h4>
        <form method="POST" id="formProd">
          <input type="hidden" name="action" value="produto">
          <input type="hidden" name="act"    value="add" id="prodAct">
          <input type="hidden" name="id"     value=""   id="prodId">

          <div class="mb-2">
            <label for="prodBrand" class="form-label">Marca (brand_id):</label>
            <select name="brand_id" id="prodBrand" class="form-select">
              <option value="0">(Sem Marca)</option>
              <?php foreach($brandsSelect as $brs): ?>
                <option value="<?= $brs['id'] ?>">
                  <?= htmlspecialchars($brs['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-2">
            <label for="prodName" class="form-label">Nome do Produto:</label>
            <input type="text"
                   name="name"
                   id="prodName"
                   class="form-control"
                   required />
          </div>

          <div class="mb-2">
            <label for="prodDesc" class="form-label">Descrição:</label>
            <textarea name="description"
                      id="prodDesc"
                      rows="2"
                      class="form-control"></textarea>
          </div>

          <div class="mb-2">
            <label for="prodPrice" class="form-label">Preço (price):</label>
            <input type="number"
                   step="0.01"
                   name="price"
                   id="prodPrice"
                   class="form-control" />
          </div>

          <div class="mb-2">
            <label for="prodPromo" class="form-label">Preço Promo (promo_price):</label>
            <input type="number"
                   step="0.01"
                   name="promo_price"
                   id="prodPromo"
                   class="form-control" />
          </div>

          <div class="mb-2">
            <label for="prodCost" class="form-label">Custo (cost):</label>
            <input type="number"
                   step="0.01"
                   name="cost"
                   id="prodCost"
                   class="form-control" />
          </div>

          <div class="mb-2">
            <label for="prodCat" class="form-label">Categoria:</label>
            <input type="text"
                   name="category"
                   id="prodCat"
                   class="form-control" />
          </div>

          <div class="form-check mb-2">
            <input type="checkbox"
                   name="active"
                   id="prodActive"
                   class="form-check-input"
                   value="1" />
            <label for="prodActive" class="form-check-label">
              Produto Ativo?
            </label>
          </div>

          <div class="mb-2">
            <label for="prodImg" class="form-label">Imagem (URL):</label>
            <input type="text"
                   name="image_url"
                   id="prodImg"
                   class="form-control" />
          </div>

          <button type="submit" class="btn btn-primary">Salvar</button>
          <button type="button"
                  class="btn btn-secondary"
                  onclick="resetProdForm()">
            Cancelar
          </button>
        </form>
      </div>
    </div><!-- /#tab-produtos -->


    <!-- ======================== ABA MASS PRODUTOS ======================== -->
    <div class="tab-pane" id="tab-mass" style="display:none;">
      <h3>Edição em Massa de Produtos</h3>

      <!-- FILTRO (para buscar produtos existentes) -->
      <form method="GET" class="mb-2">
        <input type="hidden" name="page" value="marcas_produtos">
        <input type="hidden" name="tab"  value="mass">
        <div class="d-flex flex-wrap align-items-center mb-2">
          <label class="me-2">Filtrar por Marca:</label>
          <select name="fbrand" class="form-select d-inline w-auto me-2">
            <option value="0">(Todas)</option>
            <?php foreach($brandsSelect as $bb):
              $sel = ($filterBrand == $bb['id']) ? 'selected' : '';
            ?>
              <option value="<?= $bb['id'] ?>" <?= $sel ?>>
                <?= htmlspecialchars($bb['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <label class="me-2">Nome contém:</label>
          <input type="text"
                 name="fname"
                 value="<?= htmlspecialchars($filterName) ?>"
                 class="form-control d-inline w-auto me-2" />
          <button type="submit" class="btn btn-primary btn-sm">
            Filtrar
          </button>
        </div>
      </form>

      <!-- FORM ATUALIZAR PRODUTOS EXISTENTES -->
      <form method="POST"
            onsubmit="return confirm('Salvar alterações em massa?');"
            class="mb-4">
        <input type="hidden" name="action" value="produto">
        <input type="hidden" name="act"    value="mass_update">

        <div class="table-responsive">
          <table class="table table-bordered table-striped align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Marca</th>
                <th>Preço</th>
                <th>Promo</th>
                <th>Custo</th>
                <th>Ativo?</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($listaProdutos)): ?>
              <tr><td colspan="7" class="text-center">
                Nenhum produto encontrado.
              </td></tr>
            <?php else: ?>
              <?php foreach($listaProdutos as $pp): ?>
                <?php $pid = (int)$pp['id']; ?>
                <tr>
                  <td><?= $pid ?></td>
                  <td><?= htmlspecialchars($pp['name']) ?></td>
                  <td>
                    <select name="mass[<?= $pid ?>][brand_id]"
                            class="form-select">
                      <option value="0">(Sem Marca)</option>
                      <?php foreach($brandsSelect as $bb):
                        $selected = ($bb['id'] == $pp['brand_id']) ? 'selected' : '';
                      ?>
                        <option value="<?= $bb['id'] ?>" <?= $selected ?>>
                          <?= htmlspecialchars($bb['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td>
                    <input type="number"
                           step="0.01"
                           name="mass[<?= $pid ?>][price]"
                           value="<?= number_format($pp['price'], 2, '.', '') ?>"
                           class="form-control"
                           style="width:80px;" />
                  </td>
                  <td>
                    <input type="number"
                           step="0.01"
                           name="mass[<?= $pid ?>][promo_price]"
                           value="<?= number_format($pp['promo_price'], 2, '.', '') ?>"
                           class="form-control"
                           style="width:80px;" />
                  </td>
                  <td>
                    <input type="number"
                           step="0.01"
                           name="mass[<?= $pid ?>][cost]"
                           value="<?= number_format($pp['cost'], 2, '.', '') ?>"
                           class="form-control"
                           style="width:80px;" />
                  </td>
                  <td>
                    <input type="checkbox"
                           name="mass[<?= $pid ?>][active]"
                           value="1"
                           <?= ($pp['active'] ? 'checked' : '') ?> />
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if (!empty($listaProdutos)): ?>
          <button type="submit" class="btn btn-success mt-2">
            Salvar Alterações em Massa (Produtos)
          </button>
        <?php endif; ?>
      </form>

      <!-- FORM INSERIR NOVOS PRODUTOS EM MASSA (com descrição, image_url etc.) -->
      <h4>Inserir Novos Produtos em Massa</h4>
      <p>Preencha abaixo todos os campos para cada produto que deseja incluir:</p>
      <form method="POST"
            onsubmit="return confirm('Inserir esses produtos novos em massa?');">
        <input type="hidden" name="action" value="produto">
        <input type="hidden" name="act"    value="mass_add">

        <div class="table-responsive">
          <table class="table table-bordered align-middle">
            <thead>
              <tr>
                <th>Marca</th>
                <th>Nome</th>
                <th>Descrição</th>
                <th>Preço</th>
                <th>Promo</th>
                <th>Custo</th>
                <th>Categoria</th>
                <th>Imagem URL</th>
                <th>Ativo?</th>
              </tr>
            </thead>
            <tbody>
            <?php for($i=0; $i<5; $i++): ?>
              <tr>
                <td>
                  <select name="mass_new[<?= $i ?>][brand_id]"
                          class="form-select">
                    <option value="0">(Sem Marca)</option>
                    <?php foreach($brandsSelect as $bb): ?>
                      <option value="<?= $bb['id'] ?>">
                        <?= htmlspecialchars($bb['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <input type="text"
                         name="mass_new[<?= $i ?>][name]"
                         class="form-control"
                         placeholder="Nome" />
                </td>
                <td>
                  <textarea name="mass_new[<?= $i ?>][description]"
                            rows="2"
                            class="form-control"
                            placeholder="Descrição"></textarea>
                </td>
                <td>
                  <input type="number"
                         step="0.01"
                         name="mass_new[<?= $i ?>][price]"
                         class="form-control"
                         style="width:80px;"
                         placeholder="Ex: 100.00" />
                </td>
                <td>
                  <input type="number"
                         step="0.01"
                         name="mass_new[<?= $i ?>][promo_price]"
                         class="form-control"
                         style="width:80px;"
                         placeholder="Promo" />
                </td>
                <td>
                  <input type="number"
                         step="0.01"
                         name="mass_new[<?= $i ?>][cost]"
                         class="form-control"
                         style="width:80px;"
                         placeholder="Custo" />
                </td>
                <td>
                  <input type="text"
                         name="mass_new[<?= $i ?>][category]"
                         class="form-control"
                         placeholder="Categoria" />
                </td>
                <td>
                  <input type="text"
                         name="mass_new[<?= $i ?>][image_url]"
                         class="form-control"
                         placeholder="URL da Imagem" />
                </td>
                <td class="text-center">
                  <input type="checkbox"
                         name="mass_new[<?= $i ?>][active]"
                         value="1" />
                </td>
              </tr>
            <?php endfor; ?>
            </tbody>
          </table>
        </div>

        <button type="submit" class="btn btn-primary">
          Inserir Novos Produtos
        </button>
      </form>
    </div><!-- /#tab-mass -->


    <!-- ======================== ABA MASS MARCAS ======================== -->
    <div class="tab-pane" id="tab-mass_brands" style="display:none;">
      <h3>Edição em Massa de Marcas</h3>
      <form method="GET" class="mb-2">
        <input type="hidden" name="page" value="marcas_produtos">
        <input type="hidden" name="tab"  value="mass_brands">
        <div class="d-flex flex-wrap align-items-center mb-2">
          <label class="me-2">Nome contém:</label>
          <input type="text"
                 name="fname_marca"
                 value="<?= htmlspecialchars($filterNameMarca) ?>"
                 class="form-control d-inline w-auto me-2" />
          <button type="submit" class="btn btn-primary btn-sm">
            Filtrar
          </button>
        </div>
      </form>

      <form method="POST"
            onsubmit="return confirm('Salvar alterações em massa nas marcas?');"
            class="mb-3">
        <input type="hidden" name="action" value="marca">
        <input type="hidden" name="act"    value="mass_update_brands">

        <div class="table-responsive">
          <table class="table table-bordered table-striped align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Slug</th>
                <th>Nome</th>
                <th>Tipo</th>
                <th>Estoque</th>
                <th>Ordem</th>
                <th>Mensagem de Estoque</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($listaMarcas)): ?>
              <tr>
                <td colspan="7" class="text-center">
                  Nenhuma marca encontrada.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach($listaMarcas as $m): ?>
                <?php $mid = (int)$m['id']; ?>
                <tr>
                  <td><?= $mid ?></td>
                  <td>
                    <input type="text"
                           name="mass_brands[<?= $mid ?>][slug]"
                           value="<?= htmlspecialchars($m['slug']) ?>"
                           class="form-control"
                           style="min-width:100px;" />
                  </td>
                  <td>
                    <input type="text"
                           name="mass_brands[<?= $mid ?>][name]"
                           value="<?= htmlspecialchars($m['name']) ?>"
                           class="form-control"
                           style="min-width:130px;" />
                  </td>
                  <td>
                    <input type="text"
                           name="mass_brands[<?= $mid ?>][brand_type]"
                           value="<?= htmlspecialchars($m['brand_type'] ?? '') ?>"
                           class="form-control"
                           style="min-width:80px;" />
                  </td>
                  <td>
                    <input type="number"
                           name="mass_brands[<?= $mid ?>][stock]"
                           value="<?= (int)$m['stock'] ?>"
                           class="form-control"
                           style="width:70px;" />
                  </td>
                  <td>
                    <input type="number"
                           name="mass_brands[<?= $mid ?>][sort_order]"
                           value="<?= (int)$m['sort_order'] ?>"
                           class="form-control"
                           style="width:70px;" />
                  </td>
                  <td>
                    <textarea name="mass_brands[<?= $mid ?>][stock_message]"
                              rows="2"
                              class="form-control"
                              style="min-width:150px;"><?= htmlspecialchars($m['stock_message'] ?? '') ?></textarea>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if (!empty($listaMarcas)): ?>
          <button type="submit" class="btn btn-success mt-2">
            Salvar Alterações em Massa (Marcas)
          </button>
        <?php endif; ?>
      </form>
    </div><!-- /#tab-mass_brands -->


    <!-- ======================== ABA MASS PRICES (NOVA) ======================== -->
    <div class="tab-pane" id="tab-mass_prices" style="display:none;">
      <h3>Edição de Valores (Lucro em Tempo Real)</h3>

      <!-- Filtro (igual mass) -->
      <form method="GET" class="mb-2">
        <input type="hidden" name="page" value="marcas_produtos">
        <input type="hidden" name="tab"  value="mass_prices">
        <div class="d-flex flex-wrap align-items-center mb-2">
          <label class="me-2">Filtrar por Marca:</label>
          <select name="fbrand" class="form-select d-inline w-auto me-2">
            <option value="0">(Todas)</option>
            <?php foreach($brandsSelect as $bb):
              $sel = ($filterBrand == $bb['id']) ? 'selected' : '';
            ?>
              <option value="<?= $bb['id'] ?>" <?= $sel ?>>
                <?= htmlspecialchars($bb['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <label class="me-2">Nome contém:</label>
          <input type="text"
                 name="fname"
                 value="<?= htmlspecialchars($filterName) ?>"
                 class="form-control d-inline w-auto me-2" />
          <button type="submit" class="btn btn-primary btn-sm">
            Filtrar
          </button>
        </div>
      </form>

      <!-- Formulário MASS UPDATE PRICES (price/promo/cost) -->
      <form method="POST"
            onsubmit="return confirm('Salvar alterações de valores em massa?');"
            class="mb-3">
        <input type="hidden" name="action" value="produto">
        <input type="hidden" name="act"    value="mass_update_prices">

        <div class="table-responsive">
          <table class="table table-bordered table-striped align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Nome do Produto</th>
                <th>Marca</th>
                <th>Preço</th>
                <th>Promo</th>
                <th>Custo</th>
                <th>Lucro (Auto)</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($listaProdutosValores)): ?>
              <tr><td colspan="7" class="text-center">
                Nenhum produto encontrado.
              </td></tr>
            <?php else: ?>
              <?php foreach($listaProdutosValores as $pp): ?>
                <?php $pid = (int)$pp['id']; ?>
                <tr>
                  <td><?= $pid ?></td>
                  <td><?= htmlspecialchars($pp['name']) ?></td>
                  <td><?= htmlspecialchars($pp['brand_name'] ?? '') ?></td>
                  <td>
                    <input type="number"
                           step="0.01"
                           name="mass_prices[<?= $pid ?>][price]"
                           value="<?= number_format($pp['price'], 2, '.', '') ?>"
                           class="form-control price-field"
                           data-pid="<?= $pid ?>"
                           style="width:80px;" />
                  </td>
                  <td>
                    <input type="number"
                           step="0.01"
                           name="mass_prices[<?= $pid ?>][promo_price]"
                           value="<?= number_format($pp['promo_price'], 2, '.', '') ?>"
                           class="form-control promo-field"
                           data-pid="<?= $pid ?>"
                           style="width:80px;" />
                  </td>
                  <td>
                    <input type="number"
                           step="0.01"
                           name="mass_prices[<?= $pid ?>][cost]"
                           value="<?= number_format($pp['cost'], 2, '.', '') ?>"
                           class="form-control cost-field"
                           data-pid="<?= $pid ?>"
                           style="width:80px;" />
                  </td>
                  <!-- Lucro (não salva no BD, só exibe) -->
                  <td>
                    <input type="text"
                           class="form-control lucro-field"
                           data-pid="<?= $pid ?>"
                           style="width:80px;"
                           value="0.00"
                           readonly />
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if (!empty($listaProdutosValores)): ?>
          <button type="submit" class="btn btn-success mt-2">
            Salvar Alterações (Valores)
          </button>
        <?php endif; ?>
      </form>
    </div><!-- /#tab-mass_prices -->

  </div><!-- /.tab-content -->
</div><!-- /.container-fluid -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
/**
 * Gerencia as abas (Marcas, Produtos, Mass, Mass_Brands, Mass_Prices).
 */
(function() {
  'use strict';

  const tabBtns     = document.querySelectorAll('.tab-btn');
  const tabMarcas   = document.getElementById('tab-marcas');
  const tabProds    = document.getElementById('tab-produtos');
  const tabMass     = document.getElementById('tab-mass');
  const tabMassBr   = document.getElementById('tab-mass_brands');
  const tabMassVals = document.getElementById('tab-mass_prices');

  function showTab(t) {
    tabMarcas.style.display   = (t === 'marcas')      ? 'block' : 'none';
    tabProds.style.display    = (t === 'produtos')    ? 'block' : 'none';
    tabMass.style.display     = (t === 'mass')        ? 'block' : 'none';
    tabMassBr.style.display   = (t === 'mass_brands') ? 'block' : 'none';
    tabMassVals.style.display = (t === 'mass_prices') ? 'block' : 'none';
  }

  tabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const t = btn.getAttribute('data-tab');
      const url = new URL(window.location);
      url.searchParams.set('tab', t);
      history.replaceState(null, '', url.toString());
      showTab(t);
    });
  });

  // Verifica qual aba deve ficar ativa pelo ?tab=xxx
  const urlParams = new URLSearchParams(window.location.search);
  const defTab = urlParams.get('tab') || 'marcas';
  showTab(defTab);
})();

/**
 * Editar Marca (carrega dados no form e rola até lá)
 */
function editMarca(
  id,
  slug,
  name,
  brandType,
  banner,
  btnImg,
  stock,
  sortOrder,
  stockMsg
) {
  document.getElementById('marcaFormTitle').textContent = "Editar Marca #" + id;
  document.getElementById('marcaAct').value = "edit";
  document.getElementById('marcaId').value  = id;

  document.getElementById('marcaSlug').value  = slug;
  document.getElementById('marcaName').value  = name;
  document.getElementById('marcaType').value  = brandType;
  document.getElementById('marcaBanner').value= banner;
  document.getElementById('marcaBtn').value   = btnImg;
  document.getElementById('marcaStock').value = stock;
  document.getElementById('marcaSort').value  = sortOrder;

  // stock_message
  const smgEl = document.getElementById('stockMessage');
  if (smgEl) {
    smgEl.value = stockMsg;
  }

  // Muda pra aba Marcas
  document.querySelector('.tab-btn[data-tab="marcas"]').click();

  setTimeout(() => {
    const formEl = document.getElementById('formMarca');
    if (formEl) {
      formEl.scrollIntoView({ behavior: 'smooth' });
    }
  }, 120);
}

/**
 * Resetar Form Marca
 */
function resetMarcaForm() {
  document.getElementById('marcaFormTitle').textContent = "Inserir Nova Marca";
  document.getElementById('marcaAct').value   = "add";
  document.getElementById('marcaId').value    = "";
  document.getElementById('marcaSlug').value  = "";
  document.getElementById('marcaName').value  = "";
  document.getElementById('marcaType').value  = "";
  document.getElementById('marcaBanner').value= "";
  document.getElementById('marcaBtn').value   = "";
  document.getElementById('marcaStock').value = "1";
  document.getElementById('marcaSort').value  = "0";

  // limpa stockMessage
  const smgEl = document.getElementById('stockMessage');
  if (smgEl) smgEl.value = "";
}

/**
 * Editar Produto (carrega dados no form e rola até lá)
 */
function editProd(id, brandId, nome, descr, price, promo, cost, categ, active, img) {
  document.getElementById('prodTitleForm').textContent = "Editar Produto #" + id;
  document.getElementById('prodAct').value = "edit";
  document.getElementById('prodId').value  = id;

  document.getElementById('prodBrand').value   = brandId;
  document.getElementById('prodName').value    = nome;
  document.getElementById('prodDesc').value    = descr;
  document.getElementById('prodPrice').value   = price;
  document.getElementById('prodPromo').value   = promo;
  document.getElementById('prodCost').value    = cost;
  document.getElementById('prodCat').value     = categ;
  document.getElementById('prodActive').checked= (active === 1);
  document.getElementById('prodImg').value     = img;

  document.querySelector('.tab-btn[data-tab="produtos"]').click();
  setTimeout(() => {
    const formEl = document.getElementById('formProd');
    if (formEl) {
      formEl.scrollIntoView({ behavior: 'smooth' });
    }
  }, 120);
}

/**
 * Resetar Form Produto
 */
function resetProdForm() {
  document.getElementById('prodTitleForm').textContent = "Inserir Novo Produto";
  document.getElementById('prodAct').value = "add";
  document.getElementById('prodId').value  = "";
  document.getElementById('prodBrand').value= "0";
  document.getElementById('prodName').value = "";
  document.getElementById('prodDesc').value = "";
  document.getElementById('prodPrice').value= "";
  document.getElementById('prodPromo').value= "";
  document.getElementById('prodCost').value = "";
  document.getElementById('prodCat').value  = "";
  document.getElementById('prodActive').checked = false;
  document.getElementById('prodImg').value  = "";
}

/**
 * LÓGICA DE LUCRO EM TEMPO REAL (aba "mass_prices")
 * - price-field, promo-field, cost-field => recalcular Lucro
 */
document.addEventListener('DOMContentLoaded', function(){
  const priceFields = document.querySelectorAll('.price-field');
  const promoFields = document.querySelectorAll('.promo-field');
  const costFields  = document.querySelectorAll('.cost-field');

  function recalcularLucro(pid) {
    const priceEl = document.querySelector(`.price-field[data-pid="${pid}"]`);
    const promoEl = document.querySelector(`.promo-field[data-pid="${pid}"]`);
    const costEl  = document.querySelector(`.cost-field[data-pid="${pid}"]`);
    const lucroEl = document.querySelector(`.lucro-field[data-pid="${pid}"]`);

    if (!priceEl || !costEl || !lucroEl) return;

    const priceVal = parseFloat(priceEl.value)  || 0;
    const promoVal = promoEl ? (parseFloat(promoEl.value) || 0) : 0;
    const costVal  = parseFloat(costEl.value)   || 0;

    // Se houver promo > 0, calcule lucro em cima da promo
    const baseVenda = (promoVal > 0) ? promoVal : priceVal;
    const lucro = baseVenda - costVal;

    // Exibe 2 casas
    lucroEl.value = lucro.toFixed(2);
  }

  priceFields.forEach(el => {
    el.addEventListener('input', () => {
      recalcularLucro(el.dataset.pid);
    });
    recalcularLucro(el.dataset.pid);
  });
  promoFields.forEach(el => {
    el.addEventListener('input', () => {
      recalcularLucro(el.dataset.pid);
    });
    recalcularLucro(el.dataset.pid);
  });
  costFields.forEach(el => {
    el.addEventListener('input', () => {
      recalcularLucro(el.dataset.pid);
    });
    recalcularLucro(el.dataset.pid);
  });
});
</script>

</body>
</html>
