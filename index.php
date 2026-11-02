<?php
require __DIR__ . '/config.php';
$pdo = db();
$page = $_GET['page'] ?? (empty($_SESSION['user_id']) ? 'login' : 'dashboard');
$allowed = ['login','dashboard','products','categories','pos','sales','reports','logout'];
if (!in_array($page, $allowed, true)) $page = 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'login') {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([trim((string)($_POST['username'] ?? ''))]);
            $user = $stmt->fetch();
            if ($user && password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['display_name'] = $user['display_name'];
                flash('Selamat datang, ' . $user['display_name'] . '!');
                redirect('index.php');
            }
            flash('Username atau password salah.', 'error');
            redirect('index.php?page=login');
        }
        require_login();
        if ($action === 'save_product') {
            $id = (int)($_POST['id'] ?? 0);
            $sku = strtoupper(trim((string)($_POST['sku'] ?? '')));
            $name = trim((string)($_POST['name'] ?? ''));
            $category = (int)($_POST['category_id'] ?? 0) ?: null;
            $price = (float)($_POST['price'] ?? 0);
            $stock = (int)($_POST['stock'] ?? 0);
            if ($sku === '' || $name === '' || $price < 0 || $stock < 0) throw new RuntimeException('Isi produk dengan benar. Harga dan stok tidak boleh negatif.');
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE products SET sku=?, name=?, category_id=?, price=?, stock=? WHERE id=?');
                $stmt->execute([$sku,$name,$category,$price,$stock,$id]);
                flash('Produk berhasil diperbarui.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO products (sku,name,category_id,price,stock) VALUES (?,?,?,?,?)');
                $stmt->execute([$sku,$name,$category,$price,$stock]);
                flash('Produk berhasil ditambahkan.');
            }
            redirect('index.php?page=products');
        }
        if ($action === 'delete_product') {
            $stmt = $pdo->prepare('DELETE FROM products WHERE id=?');
            $stmt->execute([(int)$_POST['id']]);
            flash('Produk berhasil dihapus.');
            redirect('index.php?page=products');
        }
        if ($action === 'save_category') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Nama kategori wajib diisi.');
            $stmt = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
            $stmt->execute([$name]);
            flash('Kategori berhasil ditambahkan.');
            redirect('index.php?page=categories');
        }
        if ($action === 'delete_category') {
            $stmt = $pdo->prepare('DELETE FROM categories WHERE id=?');
            $stmt->execute([(int)$_POST['id']]);
            flash('Kategori berhasil dihapus.');
            redirect('index.php?page=categories');
        }
        if ($action === 'checkout') {
            $quantities = $_POST['qty'] ?? [];
            if (!is_array($quantities)) throw new RuntimeException('Keranjang tidak valid.');
            $items = [];
            $total = 0.0;
            foreach ($quantities as $productId => $quantity) {
                $productId = (int)$productId;
                $quantity = (int)$quantity;
                if ($productId <= 0 || $quantity <= 0) continue;
                $stmt = $pdo->prepare('SELECT id,sku,name,price,stock FROM products WHERE id=?');
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
                if (!$product) throw new RuntimeException('Ada produk yang tidak ditemukan.');
                if ($quantity > (int)$product['stock']) throw new RuntimeException('Stok ' . $product['name'] . ' tidak mencukupi.');
                $line = (float)$product['price'] * $quantity;
                $total += $line;
                $items[] = ['product'=>$product, 'qty'=>$quantity, 'line'=>$line];
            }
            if (!$items) throw new RuntimeException('Pilih setidaknya satu produk.');
            $paid = (float)($_POST['paid'] ?? 0);
            if ($paid < $total) throw new RuntimeException('Uang pembayaran kurang dari total belanja.');
            $pdo->beginTransaction();
            $invoice = 'INV-' . date('ymd-His') . '-' . random_int(100,999);
            $stmt = $pdo->prepare('INSERT INTO sales (invoice_no,cashier_id,subtotal,paid,change_amount) VALUES (?,?,?,?,?)');
            $stmt->execute([$invoice,(int)$_SESSION['user_id'],$total,$paid,$paid-$total]);
            $saleId = (int)$pdo->lastInsertId();
            $insertItem = $pdo->prepare('INSERT INTO sale_items (sale_id,product_id,product_name,sku,qty,price,line_total) VALUES (?,?,?,?,?,?,?)');
            $updateStock = $pdo->prepare('UPDATE products SET stock=stock-? WHERE id=? AND stock>=?');
            foreach ($items as $item) {
                $p = $item['product'];
                $insertItem->execute([$saleId,$p['id'],$p['name'],$p['sku'],$item['qty'],$p['price'],$item['line']]);
                $updateStock->execute([$item['qty'],$p['id'],$item['qty']]);
                if ($updateStock->rowCount() !== 1) throw new RuntimeException('Stok berubah saat transaksi. Silakan ulangi.');
            }
            $pdo->commit();
            $_SESSION['last_sale_id'] = $saleId;
            flash('Transaksi berhasil disimpan. Stok sudah diperbarui.');
            redirect('index.php?page=sales&receipt=' . $saleId);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e instanceof PDOException && str_contains($e->getMessage(), 'Duplicate') ? 'Kode produk atau nama kategori sudah digunakan.' : $e->getMessage(), 'error');
        redirect('index.php?page=' . (in_array($action, ['save_product','delete_product']) ? 'products' : ($action === 'save_category' || $action === 'delete_category' ? 'categories' : 'pos')));
    }
}
if ($page === 'logout') {
    $_SESSION = [];
    session_destroy();
    redirect('index.php?page=login');
}
if ($page !== 'login') require_login();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$editProduct = null;
if ($page === 'products' && isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editProduct = $stmt->fetch() ?: null;
}
$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$stats = [
    'today' => (float)$pdo->query('SELECT COALESCE(SUM(subtotal),0) FROM sales WHERE DATE(created_at)=CURDATE()')->fetchColumn(),
    'count' => (int)$pdo->query('SELECT COUNT(*) FROM sales WHERE DATE(created_at)=CURDATE()')->fetchColumn(),
    'products' => (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'low' => (int)$pdo->query('SELECT COUNT(*) FROM products WHERE stock <= 5')->fetchColumn()
];
$products = [];
if ($page === 'products' || $page === 'pos') {
    $search = trim((string)($_GET['q'] ?? ''));
    if ($search !== '') {
        $stmt = $pdo->prepare('SELECT p.*, c.name category FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.name LIKE ? OR p.sku LIKE ? ORDER BY p.name');
        $stmt->execute(['%' . $search . '%','%' . $search . '%']);
        $products = $stmt->fetchAll();
    } else {
        $products = $pdo->query('SELECT p.*, c.name category FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.name')->fetchAll();
    }
}
$sales = [];
if ($page === 'sales' || $page === 'reports') {
    $sales = $pdo->query('SELECT s.*, u.display_name FROM sales s LEFT JOIN users u ON u.id=s.cashier_id ORDER BY s.id DESC LIMIT 100')->fetchAll();
}
$recentSales = $pdo->query('SELECT * FROM sales ORDER BY id DESC LIMIT 6')->fetchAll();
$receipt = null;
$receiptItems = [];
if ($page === 'sales' && isset($_GET['receipt'])) {
    $stmt = $pdo->prepare('SELECT s.*,u.display_name FROM sales s LEFT JOIN users u ON u.id=s.cashier_id WHERE s.id=?');
    $stmt->execute([(int)$_GET['receipt']]);
    $receipt = $stmt->fetch() ?: null;
    if ($receipt) {
        $stmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id=?');
        $stmt->execute([$receipt['id']]);
        $receiptItems = $stmt->fetchAll();
    }
}
$monthly = $pdo->query("SELECT DATE_FORMAT(created_at,'%Y-%m') month, SUM(subtotal) total, COUNT(*) transactions FROM sales GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY month DESC LIMIT 12")->fetchAll();

$brand = APP_NAME;
$theme = APP_THEME;
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($brand)?> · Point of Sale</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="theme-<?=h($theme)?> <?= $page === 'login' ? 'login-page' : '' ?>">
<?php if ($page === 'login'): ?>
<div class="login-shell">
  <div class="login-brand"><div class="brand-mark">✦</div><div><div class="brand-name"><?=h($brand)?></div><div class="brand-tag"><?=h(APP_TAGLINE)?></div></div></div>
  <div class="login-card"><div class="eyebrow">POINT OF SALE SYSTEM</div><h1>Selamat datang.</h1><p class="muted">Masuk untuk mengelola toko dan transaksi hari ini.</p>
  <?php if ($flash): ?><div class="alert <?=h($flash['type'])?>"><?=h($flash['message'])?></div><?php endif; ?>
  <form method="post" class="stack-form"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="login">
    <label>Username<input name="username" autocomplete="username" required placeholder="Masukkan username"></label>
    <label>Password<input type="password" name="password" autocomplete="current-password" required placeholder="Masukkan password"></label>
    <button class="btn primary full" type="submit">Masuk ke dashboard <span>→</span></button>
  </form><div class="demo-login"><strong>APK Web Kasir Purnomo Adji</strong><br> <code></code> · <code></code></div></div>
  <div class="login-foot">Dirancang untuk operasional toko yang lebih rapi dan efesien.</div>
</div>
<?php else: ?>
<div class="app-shell">
<aside class="sidebar">
  <a class="brand" href="index.php"><span class="brand-mark">✦</span><span><strong><?=h($brand)?></strong><small><?=h(APP_TAGLINE)?></small></span></a>
  <div class="nav-caption">MENU UTAMA</div>
  <nav>
    <a class="<?= $page==='dashboard'?'active':''?>" href="index.php"><span>▦</span>Dashboard</a>
    <a class="<?= $page==='pos'?'active':''?>" href="index.php?page=pos"><span>▣</span>Kasir / Transaksi</a>
    <a class="<?= $page==='products'?'active':''?>" href="index.php?page=products"><span>◈</span>Produk</a>
    <a class="<?= $page==='categories'?'active':''?>" href="index.php?page=categories"><span>▤</span>Kategori</a>
    <a class="<?= $page==='sales'?'active':''?>" href="index.php?page=sales"><span>↗</span>Riwayat transaksi</a>
    <a class="<?= $page==='reports'?'active':''?>" href="index.php?page=reports"><span>▥</span>Laporan</a>
  </nav>
  <div class="sidebar-bottom"><div class="status-dot"></div><div><strong>Sistem aktif</strong><small>Database terhubung</small></div></div>
</aside>
<main class="main-area">
<header class="topbar"><div><div class="eyebrow"><?=date('l, d F Y')?></div><h2><?=['dashboard'=>'Ringkasan dashboard','products'=>'Manajemen produk','categories'=>'Kategori produk','pos'=>'Transaksi kasir','sales'=>'Riwayat transaksi','reports'=>'Laporan penjualan'][$page] ?? 'Dashboard'?></h2></div><div class="user-chip"><div class="avatar"><?=h(strtoupper(substr($_SESSION['display_name'] ?? 'A',0,1)))?></div><div><strong><?=h($_SESSION['display_name'] ?? 'Admin')?></strong><small>Purnomo Adji</small></div><a class="logout" href="index.php?page=logout" title="Keluar">↗</a></div></header>
<?php if ($flash): ?><div class="alert <?=h($flash['type'])?>"><?=h($flash['message'])?></div><?php endif; ?>

<?php if ($page === 'dashboard'): ?>
<section class="welcome-panel"><div><div class="eyebrow">OVERVIEW</div><h1>Halo, <?=h($_SESSION['display_name'] ?? 'Admin')?> 👋</h1><p>Berikut ringkasan operasional tokomu hari ini.</p><a class="btn primary" href="index.php?page=pos">Mulai transaksi <span>→</span></a></div><div class="welcome-art"><div class="art-circle">✦</div><div class="art-label">SELL SMARTER<br><b>EVERY DAY</b></div></div></section>
<div class="stats-grid">
  <article class="stat-card"><div class="stat-top"><span>Penjualan hari ini</span><span class="stat-icon">↗</span></div><strong><?=rupiah($stats['today'])?></strong><small>Total omzet hari ini</small></article>
  <article class="stat-card"><div class="stat-top"><span>Transaksi hari ini</span><span class="stat-icon">▣</span></div><strong><?=number_format($stats['count'])?></strong><small>Transaksi berhasil</small></article>
  <article class="stat-card"><div class="stat-top"><span>Total produk</span><span class="stat-icon">◈</span></div><strong><?=number_format($stats['products'])?></strong><small>Produk terdaftar</small></article>
  <article class="stat-card"><div class="stat-top"><span>Stok menipis</span><span class="stat-icon warning">!</span></div><strong><?=number_format($stats['low'])?></strong><small>Stok 5 atau kurang</small></article>
</div>
<div class="section-heading"><div><h3>Transaksi terbaru</h3><p>Aktivitas penjualan terakhir</p></div><a class="text-link" href="index.php?page=sales">Lihat semua →</a></div>
<div class="table-card"><div class="table-wrap"><table><thead><tr><th>No. invoice</th><th>Tanggal</th><th>Total</th><th>Pembayaran</th><th></th></tr></thead><tbody>
<?php if (!$recentSales): ?><tr><td colspan="5" class="empty">Belum ada transaksi. Mulai transaksi pertamamu!</td></tr><?php else: foreach ($recentSales as $s): ?><tr><td><strong><?=h($s['invoice_no'])?></strong></td><td><?=h(date('d/m/Y H:i',strtotime($s['created_at'])))?></td><td><?=rupiah($s['subtotal'])?></td><td><span class="pill paid">Lunas</span></td><td><a class="row-link" href="index.php?page=sales&receipt=<?=(int)$s['id']?>">Detail ↗</a></td></tr><?php endforeach; endif; ?>
</tbody></table></div></div>

<?php elseif ($page === 'products'): ?>
<div class="content-grid"><section class="panel"><div class="panel-heading"><div><h3><?=$editProduct?'Edit produk':'Tambah produk'?></h3><p>Lengkapi informasi barang toko.</p></div></div>
<form method="post" class="stack-form"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="save_product"><input type="hidden" name="id" value="<?=(int)($editProduct['id']??0)?>">
<label>Kode produk / SKU<input name="sku" required maxlength="40" value="<?=h($editProduct['sku']??'')?>" placeholder="Contoh: BRG-001"></label>
<label>Nama produk<input name="name" required value="<?=h($editProduct['name']??'')?>" placeholder="Nama barang"></label>
<label>Kategori<select name="category_id"><option value="">Tanpa kategori</option><?php foreach($categories as $c):?><option value="<?=(int)$c['id']?>" <?=isset($editProduct['category_id'])&&(int)$editProduct['category_id']===(int)$c['id']?'selected':''?>><?=h($c['name'])?></option><?php endforeach;?></select></label>
<div class="form-row"><label>Harga (Rp)<input type="number" min="0" step="1" name="price" required value="<?=h($editProduct['price']??'0')?>"></label><label>Stok awal<input type="number" min="0" step="1" name="stock" required value="<?=h($editProduct['stock']??'0')?>"></label></div>
<button class="btn primary full" type="submit"><?=$editProduct?'Simpan perubahan':'Tambah produk'?></button><?php if($editProduct):?><a class="btn secondary full" href="index.php?page=products">Batal edit</a><?php endif;?></form></section>
<section class="panel panel-wide"><div class="panel-heading"><div><h3>Daftar produk</h3><p><?=count($products)?> produk ditampilkan</p></div><form class="search-form" method="get"><input type="hidden" name="page" value="products"><input name="q" value="<?=h($_GET['q']??'')?>" placeholder="Cari nama / SKU"><button class="btn secondary">Cari</button></form></div>
<div class="table-wrap"><table><thead><tr><th>Produk</th><th>Kategori</th><th>Harga</th><th>Stok</th><th>Aksi</th></tr></thead><tbody><?php if(!$products):?><tr><td colspan="5" class="empty">Belum ada produk. Tambahkan produk melalui formulir.</td></tr><?php else:foreach($products as $p):?><tr><td><strong><?=h($p['name'])?></strong><small class="cell-sub"><?=h($p['sku'])?></small></td><td><?=h($p['category']??'—')?></td><td><?=rupiah($p['price'])?></td><td><span class="pill <?=((int)$p['stock']<=5?'low':'')?>"><?=number_format($p['stock'])?></span></td><td class="actions"><a class="row-link" href="index.php?page=products&edit=<?=(int)$p['id']?>">Edit</a><form method="post" onsubmit="return confirm('Hapus produk ini?')"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" value="<?=(int)$p['id']?>"><button class="link-danger">Hapus</button></form></td></tr><?php endforeach;endif;?></tbody></table></div></section></div>

<?php elseif ($page === 'categories'): ?>
<div class="content-grid"><section class="panel"><div class="panel-heading"><div><h3>Tambah kategori</h3><p>Kelompokkan produk agar mudah dicari.</p></div></div><form method="post" class="stack-form"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="save_category"><label>Nama kategori<input name="name" required maxlength="80" placeholder="Contoh: Perlengkapan"></label><button class="btn primary full">Simpan kategori</button></form></section><section class="panel panel-wide"><div class="panel-heading"><div><h3>Daftar kategori</h3><p><?=count($categories)?> kategori tersedia</p></div></div><div class="category-grid"><?php foreach($categories as $c):?><div class="category-card"><span class="category-symbol">◈</span><strong><?=h($c['name'])?></strong><form method="post" onsubmit="return confirm('Hapus kategori? Produk di kategori ini tetap tersimpan.')"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><button class="link-danger">Hapus</button></form></div><?php endforeach;?></div></section></div>

<?php elseif ($page === 'pos'): ?>
<div class="pos-layout"><section class="panel"><div class="panel-heading"><div><h3>Pilih produk</h3><p>Masukkan jumlah barang yang dibeli.</p></div><form class="search-form" method="get"><input type="hidden" name="page" value="pos"><input name="q" value="<?=h($_GET['q']??'')?>" placeholder="Cari produk"><button class="btn secondary">Cari</button></form></div><div class="product-grid"><?php foreach($products as $p):?><article class="product-tile"><div class="product-symbol">◈</div><div class="product-meta"><small><?=h($p['sku'])?></small><h4><?=h($p['name'])?></h4><strong><?=rupiah($p['price'])?></strong><small class="<?=((int)$p['stock']<=5?'stock-low':'')?>">Stok: <?=number_format($p['stock'])?></small></div><label class="qty-label">Qty<input form="checkout-form" type="number" name="qty[<?=(int)$p['id']?>]" min="0" max="<?=(int)$p['stock']?>" value="0" <?=((int)$p['stock']<=0?'disabled':'')?>></label></article><?php endforeach;?><?php if(!$products):?><div class="empty">Belum ada produk. Tambahkan produk terlebih dahulu.</div><?php endif;?></div></section>
<section class="panel checkout-panel"><div class="panel-heading"><div><h3>Pembayaran</h3><p>Pastikan jumlah dan uang bayar benar.</p></div><span class="checkout-icon">▣</span></div><form id="checkout-form" method="post" class="stack-form" onsubmit="return confirm('Simpan transaksi ini?')"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="checkout"><div class="checkout-note">Pilih jumlah produk di panel sebelah kiri, lalu masukkan uang pembayaran.</div><label>Uang dibayarkan (Rp)<input type="number" min="0" step="1" name="paid" required placeholder="Contoh: 100000"></label><button class="btn primary full" type="submit">Proses pembayaran <span>→</span></button><p class="muted tiny">Total dihitung ulang di server untuk menjaga keakuratan transaksi.</p></form><a class="btn secondary full" href="index.php?page=products">Kelola produk</a></section></div>

<?php elseif ($page === 'sales'): ?>
<?php if($receipt):?><section class="panel receipt-panel"><div class="receipt-actions"><div><h3>Transaksi berhasil</h3><p>Struk pembayaran tersimpan.</p></div><button class="btn primary" onclick="window.print()">Cetak struk ↗</button></div><div class="receipt" id="receipt"><div class="receipt-brand"><div class="brand-mark">✦</div><h2><?=h($brand)?></h2><p><?=h(APP_TAGLINE)?></p></div><div class="receipt-divider"></div><div class="receipt-line"><span>Invoice</span><strong><?=h($receipt['invoice_no'])?></strong></div><div class="receipt-line"><span>Tanggal</span><strong><?=h(date('d/m/Y H:i:s',strtotime($receipt['created_at'])))?></strong></div><div class="receipt-line"><span>Kasir</span><strong><?=h($receipt['display_name']??'Admin')?></strong></div><div class="receipt-divider"></div><?php foreach($receiptItems as $it):?><div class="receipt-item"><div><strong><?=h($it['product_name'])?></strong><small><?=h($it['qty'])?> × <?=rupiah($it['price'])?></small></div><strong><?=rupiah($it['line_total'])?></strong></div><?php endforeach;?><div class="receipt-divider"></div><div class="receipt-line total"><span>TOTAL</span><strong><?=rupiah($receipt['subtotal'])?></strong></div><div class="receipt-line"><span>Bayar</span><strong><?=rupiah($receipt['paid'])?></strong></div><div class="receipt-line"><span>Kembalian</span><strong><?=rupiah($receipt['change_amount'])?></strong></div><div class="receipt-thanks">Terima kasih telah berbelanja ✦</div></div><a class="btn secondary" href="index.php?page=pos">Transaksi baru</a></section><?php endif;?>
<section class="panel"><div class="panel-heading"><div><h3>Semua transaksi</h3><p>Menampilkan maksimal 100 transaksi terakhir.</p></div></div><div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Tanggal</th><th>Kasir</th><th>Total</th><th>Bayar</th><th>Kembalian</th><th>Struk</th></tr></thead><tbody><?php if(!$sales):?><tr><td colspan="7" class="empty">Belum ada transaksi.</td></tr><?php else:foreach($sales as $s):?><tr><td><strong><?=h($s['invoice_no'])?></strong></td><td><?=h(date('d/m/Y H:i',strtotime($s['created_at'])))?></td><td><?=h($s['display_name']??'—')?></td><td><?=rupiah($s['subtotal'])?></td><td><?=rupiah($s['paid'])?></td><td><?=rupiah($s['change_amount'])?></td><td><a class="row-link" href="index.php?page=sales&receipt=<?=(int)$s['id']?>">Lihat ↗</a></td></tr><?php endforeach;endif;?></tbody></table></div></section>

<?php elseif ($page === 'reports'): ?>
<div class="stats-grid"><article class="stat-card"><div class="stat-top"><span>Total omzet tercatat</span><span class="stat-icon">↗</span></div><strong><?=rupiah($pdo->query('SELECT COALESCE(SUM(subtotal),0) FROM sales')->fetchColumn())?></strong><small>Seluruh transaksi</small></article><article class="stat-card"><div class="stat-top"><span>Jumlah transaksi</span><span class="stat-icon">▣</span></div><strong><?=number_format((int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn())?></strong><small>Semua waktu</small></article><article class="stat-card"><div class="stat-top"><span>Produk aktif</span><span class="stat-icon">◈</span></div><strong><?=number_format($stats['products'])?></strong><small>Terdaftar di katalog</small></article><article class="stat-card"><div class="stat-top"><span>Stok menipis</span><span class="stat-icon warning">!</span></div><strong><?=number_format($stats['low'])?></strong><small>Stok 5 atau kurang</small></article></div>
<section class="panel"><div class="panel-heading"><div><h3>Ringkasan per bulan</h3><p>Rekap omzet berdasarkan bulan transaksi.</p></div><button class="btn secondary no-print" onclick="window.print()">Cetak laporan</button></div><div class="table-wrap"><table><thead><tr><th>Bulan</th><th>Jumlah transaksi</th><th>Total omzet</th><th>Visual</th></tr></thead><tbody><?php if(!$monthly):?><tr><td colspan="4" class="empty">Belum ada data laporan.</td></tr><?php else:$max=max(array_map(fn($m)=>(float)$m['total'],$monthly));foreach($monthly as $m):?><tr><td><strong><?=h(date('F Y',strtotime($m['month'].'-01')))?></strong></td><td><?=number_format($m['transactions'])?></td><td><?=rupiah($m['total'])?></td><td><div class="bar-track"><div class="bar-fill" style="width:<?= $max > 0 ? max(2,((float)$m['total']/$max)*100) : 0 ?>%"></div></div></td></tr><?php endforeach;endif;?></tbody></table></div></section>
<?php endif; ?>
<footer class="app-footer"><?=h($brand)?> <span>•</span> Point of Sale <span>•</span> <?=date('Y')?></footer>
</main></div>
<?php endif; ?>
</body></html>
