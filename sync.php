<?php
/* Copyright (C) 2024 - Module PowrSync
 * Page de synchronisation des prix POwR Connect
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/tax.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
require_once dol_buildpath('/powrsync/class/powrconnectscraper.class.php', 0);

$langs->loadLangs(array('products', 'suppliers', 'powrsync@powrsync'));

if (!isModEnabled('powrsync')) {
	accessforbidden('Module PowrSync non activé');
}
if (!$user->hasRight('powrsync', 'synclog', 'read')) {
	accessforbidden();
}

$action            = GETPOST('action', 'aZ09');
$supplierPriceLineId = GETPOST('lineid', 'int');
$confirm           = GETPOST('confirm', 'alpha');
$sortfield = GETPOST('sortfield', 'aZ09comma') ? GETPOST('sortfield', 'aZ09comma') : 'p.ref';
$sortorder = GETPOST('sortorder', 'aZ09comma') ? GETPOST('sortorder', 'aZ09comma') : 'ASC';
$search_ref_product = trim(GETPOST('search_ref_product', 'alphanohtml'));
$search_ref_fourn = trim(GETPOST('search_ref_fourn', 'alphanohtml'));

$tempDir = !empty($conf->powrsync->dir_temp) ? $conf->powrsync->dir_temp : sys_get_temp_dir();
$scraper = new PowrConnectScraper($tempDir);
$fkSoc   = getDolGlobalInt('POWRSYNC_SUPPLIER_ID');
$email   = getDolGlobalString('POWRSYNC_LOGIN');
$pwd     = getDolGlobalString('POWRSYNC_PASSWORD');
$form = new Form($db);

$allowedSortFields = array('p.ref', 'p.label', 'pfp.ref_fourn', 'pfp.unitprice', 'lastlog.new_price', 'lastlog.datec', 'lastlog.status');
if (!in_array($sortfield, $allowedSortFields, true)) {
	$sortfield = 'p.ref';
}
$sortorder = (strtoupper($sortorder) === 'DESC') ? 'DESC' : 'ASC';
$param = '';
if ($search_ref_product !== '') {
	$param .= '&search_ref_product='.urlencode($search_ref_product);
}
if ($search_ref_fourn !== '') {
	$param .= '&search_ref_fourn='.urlencode($search_ref_fourn);
}

// =========================================================================
// ACTIONS
// =========================================================================

if ($user->hasRight('powrsync', 'synclog', 'write')) {
	// --- Synchronisation de TOUS les produits ---
	if ($action == 'syncall' && $confirm == 'yes') {
		$productsToSync = getProductsWithPowrRef($db, $fkSoc, 'p.ref', 'ASC', '', '', true);
		$updatedCount = 0;
		$errorMessages = array();

		if ($productsToSync === false) {
			setEventMessages($langs->trans('PowrSyncDbError').': '.$db->lasterror(), null, 'errors');
		} else {
			foreach ($productsToSync as $productToSync) {
				$syncStatus = syncOneSupplierProductPrice($db, $scraper, $productToSync, $fkSoc, $user, getDolGlobalString('POWRSYNC_LOGIN'), dol_decode(getDolGlobalString('POWRSYNC_PASSWORD')));
				if ($syncStatus > 0) {
					$updatedCount++;
				} elseif ($syncStatus < 0) {
					$errorMessages[] = $productToSync['ref_fourn'].' : '.$scraper->error;
				}
			}
			setEventMessages($langs->trans('PowrSyncDone', $updatedCount), $errorMessages, empty($errorMessages) ? 'mesgs' : 'warnings');
		}
		$scraper->close();
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}

	// --- Synchronisation d'UN seul produit (AJAX ou bouton) ---
	if ($action == 'syncone' && $supplierPriceLineId > 0) {
		// Récupérer exactement la ligne fournisseur ciblée
		$found = getProductWithPowrRefByLineId($db, $fkSoc, $supplierPriceLineId);

		if ($found === false) {
			setEventMessages($langs->trans('PowrSyncDbError').': '.$db->lasterror(), null, 'errors');
		} elseif (empty($found)) {
			setEventMessages($langs->trans('PowrSyncProductNotFound'), null, 'errors');
		} else {
			$ret2 = syncOneSupplierProductPrice($db, $scraper, $found, $fkSoc, $user, getDolGlobalString('POWRSYNC_LOGIN'), dol_decode(getDolGlobalString('POWRSYNC_PASSWORD')));
			if ($ret2 == 1) {
				setEventMessages($langs->trans('PowrSyncUpdated', $found['ref_product'], $found['ref_fourn']), null, 'mesgs');
			} elseif ($ret2 == 2) {
				setEventMessages($langs->trans('PowrSyncUpToDate', $found['ref_product']), null, 'warnings');
			} else {
				setEventMessages($scraper->error, null, 'errors');
			}
		}
		$scraper->close();
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
}

// =========================================================================
// VUE
// =========================================================================

$page = 0;
$limit = 0;
$total = 0;

llxHeader('', $langs->trans('PowrSyncTitle'));

//print load_fiche_titre($langs->trans('PowrSyncTitle'), '', 'price');
print_barre_liste($langs->trans('PowrSyncTitle'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $total, $limit, 'fa fa-sun', 0, '', '', $limit);


// Vérification de la configuration
$configOk = ($fkSoc > 0 && !empty($email) && !empty($pwd));
if (!$configOk) {
	print '<div class="error">';
	print img_picto('', 'warning', 'class="pictofixedwidth"');
	print ' '.$langs->trans('PowrSyncNotConfigured');
	print ' <a href="'.dol_buildpath('/powrsync/admin/setup.php', 1).'">'.$langs->trans('GoToSetup').'</a>';
	print '</div>';
	llxFooter();
	$db->close();
	exit;
}

// Confirmation avant sync global
if ($action == 'syncall') {
	$formconfirm = ''; // on gère manuellement
	print '<div class="warning" style="margin-bottom:15px;">';
	print '<p>'.$langs->trans('PowrSyncConfirmAll').'</p>';
	print '<a class="button" href="'.$_SERVER['PHP_SELF'].'?action=syncall&confirm=yes&token='.newToken().'">'.$langs->trans('Confirm').'</a> ';
	print '<a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Cancel').'</a>';
	print '</div>';
}

// Bouton de sync global
if ($user->hasRight('powrsync', 'synclog', 'write') && $action != 'syncall') {
	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=syncall&token='.newToken().'">';
	print img_picto('', 'refresh', 'class="pictofixedwidth"');
	print ' '.$langs->trans('SyncAllPrices');
	print '</a>';
	print '</div>';
}

// Liste des produits avec ref POwR Connect
$products = getProductsWithPowrRef($db, $fkSoc, $sortfield, $sortorder, $search_ref_product, $search_ref_fourn);

if ($products === false) {
	print '<div class="error">'.$langs->trans('PowrSyncDbError').': '.dol_escape_htmltag($db->lasterror()).'</div>';
	llxFooter();
	$db->close();
	exit;
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal noborder liste">';
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons('left').'</td>';
print '<td><input type="text" class="flat minwidth100" name="search_ref_product" value="'.dol_escape_htmltag($search_ref_product).'"></td>';
print '<td></td>';
print '<td><input type="text" class="flat minwidth100" name="search_ref_fourn" value="'.dol_escape_htmltag($search_ref_fourn).'"></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '</tr>';
print '<tr class="liste_titre">';
print '<td></td>';
print getTitleFieldOfList($langs->trans('ProductRef'), 0, $_SERVER['PHP_SELF'], 'p.ref', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('ProductLabel'), 0, $_SERVER['PHP_SELF'], 'p.label', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('PowrRef'), 0, $_SERVER['PHP_SELF'], 'pfp.ref_fourn', '', $param, '', $sortfield, $sortorder);
print '<td class="right">'.$langs->trans('CurrentBuyPrice').'</td>';
print getTitleFieldOfList($langs->trans('LastSyncPrice'), 0, $_SERVER['PHP_SELF'], 'lastlog.new_price', '', $param, 'class="right"', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('LastSync'), 0, $_SERVER['PHP_SELF'], 'lastlog.datec', '', $param, 'class="center"', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('Status'), 0, $_SERVER['PHP_SELF'], 'lastlog.status', '', $param, 'class="center"', $sortfield, $sortorder);
print '<td class="center">'.$langs->trans('Action').'</td>';
print '</tr>';

if (empty($products)) {
	print '<tr><td colspan="9" class="center opacitymedium">'.$langs->trans('PowrSyncNoProducts').'</td></tr>';
} else {
	foreach ($products as $prod) {
		$pid       = (int) $prod['fk_product'];
		$log       = (!empty($prod['last_datec']) || $prod['last_status'] !== null) ? array(
			'datec' => $prod['last_datec'],
			'old_price' => $prod['last_old_price'],
			'new_price' => $prod['last_new_price'],
			'status' => $prod['last_status'],
			'message' => $prod['last_message'],
		) : null;
		$statusClass = '';
		$statusLabel = '';
		$statusIcon  = '';

		if ($log) {
			if ($log['status'] == PowrConnectScraper::LOG_OK) {
				$statusClass = 'badge badge-status4';
				$statusLabel = $langs->trans('PowrLogUpdated');
				$statusIcon  = 'tick';
			} elseif ($log['status'] == PowrConnectScraper::LOG_UPTODATE) {
				$statusClass = 'badge badge-status1';
				$statusLabel = $langs->trans('PowrLogUpToDate');
				$statusIcon  = 'check';
			} else {
				$statusClass = 'badge badge-status8';
				$statusLabel = $langs->trans('PowrLogError');
				$statusIcon  = 'warning';
			}
		}

		print '<tr class="oddeven">';
		print '<td></td>';

		// Ref produit (lien fiche)
		print '<td>';
		print '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.$pid.'">'.dol_escape_htmltag($prod['ref_product']).'</a>';
		print '</td>';

		// Label
		print '<td>'.dol_escape_htmltag(dol_trunc($prod['label_product'], 50)).'</td>';

		// Ref POwR
		print '<td>';
		if (!empty($prod['supplier_url'])) {
			print '<a href="'.dol_escape_htmltag($prod['supplier_url']).'" target="_blank" rel="noopener">';
			print dol_escape_htmltag($prod['ref_fourn']);
			print ' '.img_picto('', 'external-link-alt', 'class="opacitymedium"');
			print '</a>';
		} else {
			print dol_escape_htmltag($prod['ref_fourn']);
		}
		print '</td>';

		// Prix actuel Dolibarr
		print '<td class="right"><b>'.price($prod['unitprice']).' €</b></td>';

		// Dernier prix scrapé
		print '<td class="right">';
		if ($log && $log['new_price'] !== null) {
			$diff = $log['new_price'] - $log['old_price'];
			print price($log['new_price']).' €';
			if (abs($diff) > 0.001) {
				$arrow = $diff > 0 ? '▲' : '▼';
				$cls   = $diff > 0 ? 'color:red' : 'color:green';
				print ' <span style="'.$cls.'">'.$arrow.' '.price(abs($diff)).' €</span>';
			}
		} else {
			print '<span class="opacitymedium">-</span>';
		}
		print '</td>';

		// Date dernière synchro
		print '<td class="center">';
		if ($log) {
			print dol_print_date($db->jdate($log['datec']), 'dayhour');
		} else {
			print '<span class="opacitymedium">'.$langs->trans('Never').'</span>';
		}
		print '</td>';

		// Statut badge
		print '<td class="center">';
		if ($log) {
			print '<span class="'.$statusClass.'">';
			print img_picto('', $statusIcon, 'class="pictofixedwidth"');
			print $statusLabel.'</span>';
			if ($log['status'] < 0 && !empty($log['message'])) {
				print '<br><small class="opacitymedium">'.dol_escape_htmltag(dol_trunc($log['message'], 60)).'</small>';
			}
		} else {
			print '<span class="opacitymedium">-</span>';
		}
		print '</td>';

		// Bouton sync individuel
		print '<td class="center">';
		if ($user->hasRight('powrsync', 'synclog', 'write')) {
			print '<a class="reposition butActionSmall" href="'.$_SERVER['PHP_SELF'].'?action=syncone&lineid='.(int) $prod['pfp_rowid'].'&token='.newToken().'">';
			print img_picto($langs->trans('Sync'), 'refresh');
			print '</a>';
		}
		print '</td>';

		print '</tr>';
	}
}

print '</table>';
print '</div>';
print '</form>';

print '<br><p class="opacitymedium center">';
print $langs->trans('PowrSyncProductCount', count($products));
print '</p>';

llxFooter();
$db->close();

// =========================================================================
// HELPER FUNCTION: syncable products
// =========================================================================

/**
 * Returns supplier product rows for POwR Connect sync.
 *
 * @param	DoliDB	$db
 * @param	int		$fkSoc
 * @param	string	$sortfield
 * @param	string	$sortorder
 * @param	string	$searchRefProduct
 * @param	string	$searchRefFourn
 * @param	bool	$requireSupplierUrl
 * @return	array|false
 */
function getProductsWithPowrRef($db, $fkSoc, $sortfield = 'p.ref', $sortorder = 'ASC', $searchRefProduct = '', $searchRefFourn = '', $requireSupplierUrl = false)
{
	$sortFieldMap = array(
		'p.ref' => 'p.ref',
		'p.label' => 'p.label',
		'pfp.ref_fourn' => 'pfp.ref_fourn',
		'pfp.unitprice' => 'pfp.unitprice',
		'lastlog.new_price' => 'lastlog.new_price',
		'lastlog.datec' => 'lastlog.datec',
		'lastlog.status' => 'lastlog.status',
	);
	$sortfield = !empty($sortFieldMap[$sortfield]) ? $sortFieldMap[$sortfield] : 'p.ref';
	$sortorder = (strtoupper($sortorder) === 'DESC') ? 'DESC' : 'ASC';

	$sql = "SELECT pfp.rowid AS pfp_rowid, pfp.fk_product, p.ref AS ref_product, p.label AS label_product, pfp.ref_fourn, pfp.unitprice AS unitprice, pfp.quantity, ef.supplier_url";
	$sql .= ", lastlog.datec AS last_datec, lastlog.old_price AS last_old_price, lastlog.new_price AS last_new_price, lastlog.status AS last_status, lastlog.message AS last_message";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price AS pfp";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = pfp.fk_product";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_fournisseur_price_extrafields AS ef ON ef.fk_object = pfp.rowid";
	$sql .= " LEFT JOIN (";
	$sql .= "   SELECT l1.fk_product, l1.datec, l1.old_price, l1.new_price, l1.status, l1.message";
	$sql .= "   FROM ".MAIN_DB_PREFIX."powrsync_log AS l1";
	$sql .= "   INNER JOIN (SELECT fk_product, MAX(datec) AS maxdate FROM ".MAIN_DB_PREFIX."powrsync_log GROUP BY fk_product) AS l2 ON l2.fk_product = l1.fk_product AND l2.maxdate = l1.datec";
	$sql .= " ) AS lastlog ON lastlog.fk_product = pfp.fk_product";
	$sql .= " WHERE pfp.fk_soc = ".((int) $fkSoc);
	$sql .= " AND pfp.entity IN (".getEntity('product').")";
	$sql .= " AND pfp.status = 1";
	if ($requireSupplierUrl) {
		$sql .= " AND ef.supplier_url IS NOT NULL";
		$sql .= " AND TRIM(ef.supplier_url) <> ''";
	}
	if ($searchRefProduct !== '') {
		$sql .= " AND p.ref LIKE '%".$db->escape($searchRefProduct)."%'";
	}
	if ($searchRefFourn !== '') {
		$sql .= " AND pfp.ref_fourn LIKE '%".$db->escape($searchRefFourn)."%'";
	}
	$sql .= " ORDER BY ".$sortfield." ".$sortorder.", pfp.rowid ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		return false;
	}

	$result = array();
	while ($obj = $db->fetch_object($resql)) {
		$result[] = array(
			'pfp_rowid' => (int) $obj->pfp_rowid,
			'fk_product' => (int) $obj->fk_product,
			'ref_product' => $obj->ref_product,
			'label_product' => $obj->label_product,
			'ref_fourn' => $obj->ref_fourn,
			'unitprice' => (float) $obj->unitprice,
			'quantity' => (float) $obj->quantity,
			'supplier_url' => $obj->supplier_url,
			'last_datec' => $obj->last_datec,
			'last_old_price' => ($obj->last_old_price !== null ? (float) $obj->last_old_price : null),
			'last_new_price' => ($obj->last_new_price !== null ? (float) $obj->last_new_price : null),
			'last_status' => ($obj->last_status !== null ? (int) $obj->last_status : null),
			'last_message' => $obj->last_message,
		);
	}

	return $result;
}

/**
 * Returns one supplier product row for POwR Connect sync.
 *
 * @param	DoliDB	$db
 * @param	int		$fkSoc
 * @param	int		$lineId
 * @return	array|false
 */
function getProductWithPowrRefByLineId($db, $fkSoc, $lineId)
{
	$sql = "SELECT pfp.rowid AS pfp_rowid, pfp.fk_product, p.ref AS ref_product, p.label AS label_product, pfp.ref_fourn, pfp.unitprice AS unitprice, pfp.quantity, ef.supplier_url";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price AS pfp";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = pfp.fk_product";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_fournisseur_price_extrafields AS ef ON ef.fk_object = pfp.rowid";
	$sql .= " WHERE pfp.rowid = ".((int) $lineId);
	$sql .= " AND pfp.fk_soc = ".((int) $fkSoc);
	$sql .= " AND pfp.entity IN (".getEntity('product').")";
	$sql .= " AND pfp.status = 1";

	$resql = $db->query($sql);
	if (!$resql) {
		return false;
	}

	$obj = $db->fetch_object($resql);
	if (empty($obj)) {
		return array();
	}

	return array(
		'pfp_rowid' => (int) $obj->pfp_rowid,
		'fk_product' => (int) $obj->fk_product,
		'ref_product' => $obj->ref_product,
		'label_product' => $obj->label_product,
		'ref_fourn' => $obj->ref_fourn,
		'unitprice' => (float) $obj->unitprice,
		'quantity' => (float) $obj->quantity,
		'supplier_url' => $obj->supplier_url,
	);
}

// =========================================================================
// FONCTION UTILITAIRE : derniers logs par produit
// =========================================================================

/**
 * Retourne le dernier log de synchro pour chaque fk_product
 *
 * @param  DoliDB $db
 * @param  int    $fkSoc  (non utilisé directement mais pourrait filtrer)
 * @return array          [fk_product => log_row]
 */
function getLastLogsByProduct($db, $fkSoc)
{
	$sql = "SELECT l.fk_product, l.datec, l.old_price, l.new_price, l.status, l.message";
	$sql .= " FROM ".MAIN_DB_PREFIX."powrsync_log l";
	$sql .= " INNER JOIN (";
	$sql .= "   SELECT fk_product, MAX(datec) AS maxdate FROM ".MAIN_DB_PREFIX."powrsync_log GROUP BY fk_product";
	$sql .= " ) last ON l.fk_product = last.fk_product AND l.datec = last.maxdate";

	$resql = $db->query($sql);
	$result = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$result[(int) $obj->fk_product] = array(
				'datec'     => $obj->datec,
				'old_price' => $obj->old_price,
				'new_price' => $obj->new_price,
				'status'    => (int) $obj->status,
				'message'   => $obj->message,
			);
		}
	}
	return $result;
}

/**
 * Returns the default VAT rate for a supplier according to its country.
 *
 * @param	DoliDB	$db
 * @param	int		$supplierId
 * @return	float
 */
function getSupplierDefaultVatRate($db, $supplierId)
{
	static $vatCache = array();

	$supplierId = (int) $supplierId;
	if ($supplierId <= 0) {
		return 0.0;
	}

	if (isset($vatCache[$supplierId])) {
		return (float) $vatCache[$supplierId];
	}

	$configuredVatRaw = getDolGlobalString('POWRSYNC_DEFAULT_VAT_RATE');
	if (trim((string) $configuredVatRaw) === '') {
		return null;
	}

	$vatCache[$supplierId] = (float) price2num($configuredVatRaw);

	return (float) $vatCache[$supplierId];
}

/**
 * Synchronize one supplier product price with POwR Connect.
 *
 * @param	DoliDB				$db
 * @param	PowrConnectScraper	$scraper
 * @param	array				$productRow
 * @param	int					$fkSoc
 * @param	User				$user
 * @param	string				$login
 * @param	string				$password
 * @return	int
 */
function syncOneSupplierProductPrice($db, $scraper, $productRow, $fkSoc, $user, $login, $password)
{
	global $langs;

	$powrRef = $productRow['ref_fourn'];
	$url = !empty($productRow['supplier_url']) ? $productRow['supplier_url'] : '';
	$productId = (int) $productRow['fk_product'];
	$currentPrice = isset($productRow['unitprice']) ? (float) $productRow['unitprice'] : null;

	if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
		$scraper->error = 'URL fournisseur non valide pour '.$powrRef;
		insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_ERROR, $currentPrice, null, $scraper->error);
		return -1;
	}

	$newPrice = $scraper->testConnectionAndGetPrice($login, $password, $url, $powrRef);
	if ($newPrice === false) {
		insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_ERROR, $currentPrice, null, $scraper->error);
		return -1;
	}

	if (abs($newPrice - $currentPrice) <= 0.001) {
		insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_UPTODATE, $currentPrice, $newPrice, '');
		return 2;
	}

	$productFournisseur = new ProductFournisseur($db);
	$priceLineId = !empty($productRow['pfp_rowid']) ? (int) $productRow['pfp_rowid'] : 0;
	$qty = max(1, (float) $productRow['quantity']);
	$vatTx = getSupplierDefaultVatRate($db, $fkSoc);
	if ($vatTx === null) {
		$scraper->error = $langs->trans('PowrSyncDefaultVatRateRequired');
		insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_ERROR, $currentPrice, null, $scraper->error);
		return -1;
	}

	// EN: Always set context ids before update to avoid fallback delete/insert with fk_product=0/fk_soc=0.
	// FR: Toujours renseigner les IDs de contexte avant update pour éviter le fallback delete/insert avec fk_product=0/fk_soc=0.
	$productFournisseur->id = $productId;
	$productFournisseur->fk_product = $productId;
	$productFournisseur->fourn_id = (int) $fkSoc;
	$productFournisseur->ref_fourn = $powrRef;
	$productFournisseur->fourn_qty = $qty;
	if ($priceLineId > 0) {
		$productFournisseur->product_fourn_price_id = $priceLineId;
	}

	$fetchResult = -1;
	if ($priceLineId > 0) {
		$fetchResult = $productFournisseur->fetch_product_fournisseur_price($priceLineId);
	}
	if ($fetchResult < 0) {
		$productFournisseur->fk_product = $productId;
		$productFournisseur->fourn_id = (int) $fkSoc;
		$productFournisseur->ref_fourn = $powrRef;
		$productFournisseur->fourn_qty = $qty;
	}

	$res = $productFournisseur->update_buyprice(
		$qty,
		(float) $newPrice,
		$user,
		'HT',
		(int) $fkSoc,
		$vatTx,
		$powrRef,
		0,
		0,
		0,
		0
	);

	if ($res < 0) {
		$scraper->error = !empty($productFournisseur->error) ? $productFournisseur->error : $db->lasterror();
		insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_ERROR, $currentPrice, $newPrice, $scraper->error);
		return -1;
	}

	$forceVatResult = forceSupplierPriceVatRate($db, $priceLineId, $productId, $fkSoc, $powrRef, $qty, $vatTx);
	if ($forceVatResult < 0) {
		$scraper->error = $db->lasterror();
		insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_ERROR, $currentPrice, $newPrice, $scraper->error);
		return -1;
	}

	insertPowrSyncLog($db, $productRow, $user, PowrConnectScraper::LOG_OK, $currentPrice, $newPrice, '');

	return 1;
}

/**
 * Force VAT rate value on supplier price row after update.
 *
 * @param	DoliDB	$db
 * @param	int		$priceLineId
 * @param	int		$productId
 * @param	int		$fkSoc
 * @param	string	$powrRef
 * @param	float	$qty
 * @param	float	$vatTx
 * @return	int
 */
function forceSupplierPriceVatRate($db, $priceLineId, $productId, $fkSoc, $powrRef, $qty, $vatTx)
{
	$priceLineId = (int) $priceLineId;
	if ($priceLineId <= 0) {
		$sqlFind = "SELECT pfp.rowid";
		$sqlFind .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price AS pfp";
		$sqlFind .= " WHERE pfp.fk_product = ".((int) $productId);
		$sqlFind .= " AND pfp.fk_soc = ".((int) $fkSoc);
		$sqlFind .= " AND pfp.ref_fourn = '".$db->escape($powrRef)."'";
		$sqlFind .= " AND pfp.quantity = ".price2num($qty);
		$sqlFind .= " ORDER BY pfp.rowid DESC";
		$sqlFind .= " LIMIT 1";

		$resqlFind = $db->query($sqlFind);
		if (!$resqlFind) {
			return -1;
		}
		$objFind = $db->fetch_object($resqlFind);
		$priceLineId = !empty($objFind) ? (int) $objFind->rowid : 0;
		if ($resqlFind) {
			$db->free($resqlFind);
		}
	}

	if ($priceLineId <= 0) {
		return -1;
	}

	$sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."product_fournisseur_price";
	$sqlUpdate .= " SET tva_tx = ".price2num($vatTx);
	$sqlUpdate .= " WHERE rowid = ".$priceLineId;

	return $db->query($sqlUpdate) ? 1 : -1;
}

/**
 * Save one synchronization log row in llx_powrsync_log.
 *
 * @param	DoliDB	$db
 * @param	array	$productRow
 * @param	User	$user
 * @param	int		$status
 * @param	float	$oldPrice
 * @param	float	$newPrice
 * @param	string	$message
 * @return	void
 */
function insertPowrSyncLog($db, $productRow, $user, $status, $oldPrice, $newPrice, $message)
{
	static $availableColumns = null;
	if ($availableColumns === null) {
		$availableColumns = array();
		$resql = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."powrsync_log");
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$availableColumns[$obj->Field] = true;
			}
			$db->free($resql);
		}
	}

	if (empty($availableColumns)) {
		return;
	}

	$fields = array();
	$values = array();

	if (!empty($availableColumns['fk_product'])) {
		$fields[] = 'fk_product';
		$values[] = (int) $productRow['fk_product'];
	}
	if (!empty($availableColumns['ref_product'])) {
		$fields[] = 'ref_product';
		$values[] = "'".$db->escape($productRow['ref_product'])."'";
	}
	if (!empty($availableColumns['ref_fourn'])) {
		$fields[] = 'ref_fourn';
		$values[] = "'".$db->escape($productRow['ref_fourn'])."'";
	}
	if (!empty($availableColumns['old_price'])) {
		$fields[] = 'old_price';
		$values[] = ($oldPrice !== null ? (float) price2num($oldPrice) : 'NULL');
	}
	if (!empty($availableColumns['new_price'])) {
		$fields[] = 'new_price';
		$values[] = ($newPrice !== null ? (float) price2num($newPrice) : 'NULL');
	}
	if (!empty($availableColumns['status'])) {
		$fields[] = 'status';
		$values[] = (int) $status;
	}
	if (!empty($availableColumns['sync_status'])) {
		$fields[] = 'sync_status';
		$values[] = "'".$db->escape((string) $status)."'";
	}
	if (!empty($availableColumns['message'])) {
		$fields[] = 'message';
		$values[] = "'".$db->escape($message)."'";
	}
	if (!empty($availableColumns['fk_user_creat'])) {
		$fields[] = 'fk_user_creat';
		$values[] = (int) $user->id;
	}
	if (!empty($availableColumns['entity'])) {
		$fields[] = 'entity';
		$values[] = (int) $GLOBALS['conf']->entity;
	}
	if (!empty($availableColumns['datec'])) {
		$fields[] = 'datec';
		$values[] = "'".$db->idate(dol_now())."'";
	}

	if (empty($fields)) {
		return;
	}

	$sql = "INSERT INTO ".MAIN_DB_PREFIX."powrsync_log (".implode(', ', $fields).")";
	$sql .= " VALUES (".implode(', ', $values).")";
	$db->query($sql);
}
