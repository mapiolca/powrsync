<?php
/* Copyright (C) 2024 - Module PowrSync
 * Page de synchronisation des prix POwR Connect
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
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

$action     = GETPOST('action', 'aZ09');
$productId  = GETPOST('productid', 'int');
$confirm    = GETPOST('confirm', 'alpha');

$tempDir = !empty($conf->powrsync->dir_temp) ? $conf->powrsync->dir_temp : sys_get_temp_dir();
$scraper = new PowrConnectScraper($tempDir);
$fkSoc   = getDolGlobalInt('POWRSYNC_SUPPLIER_ID');
$email   = getDolGlobalString('POWRSYNC_LOGIN');
$pwd     = getDolGlobalString('POWRSYNC_PASSWORD');

// =========================================================================
// ACTIONS
// =========================================================================

if ($user->hasRight('powrsync', 'synclog', 'write')) {
	// --- Synchronisation de TOUS les produits ---
	if ($action == 'syncall' && $confirm == 'yes') {
		$productsToSync = getProductsWithPowrRef($db, $fkSoc);
		$updatedCount = 0;
		$errorMessages = array();

		if ($productsToSync === false) {
			setEventMessages($langs->trans('PowrSyncDbError').': '.$db->lasterror(), null, 'errors');
		} else {
			$retLogin = $scraper->login(
				getDolGlobalString('POWRSYNC_LOGIN'),
				dol_decode(getDolGlobalString('POWRSYNC_PASSWORD'))
			);
			if ($retLogin < 0) {
				setEventMessages($scraper->error, null, 'errors');
			} else {
				foreach ($productsToSync as $productToSync) {
					$syncStatus = syncOneSupplierProductPrice($db, $scraper, $productToSync, $fkSoc, $user);
					if ($syncStatus > 0) {
						$updatedCount++;
					} elseif ($syncStatus < 0) {
						$errorMessages[] = $productToSync['ref_fourn'].' : '.$scraper->error;
					}
				}
				setEventMessages($langs->trans('PowrSyncDone', $updatedCount), $errorMessages, empty($errorMessages) ? 'mesgs' : 'warnings');
			}
		}
		$scraper->close();
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}

	// --- Synchronisation d'UN seul produit (AJAX ou bouton) ---
	if ($action == 'syncone' && $productId > 0) {
		// Récupérer la ligne prix de ce produit
		$products = getProductsWithPowrRef($db, $fkSoc);
		$found = array();
		foreach ($products as $p) {
			if ((int) $p['fk_product'] === $productId) {
				$found = $p;
				break;
			}
		}

		if (empty($found)) {
			setEventMessages($langs->trans('PowrSyncProductNotFound'), null, 'errors');
		} else {
			$ret2 = $scraper->login(
				getDolGlobalString('POWRSYNC_LOGIN'),
				dol_decode(getDolGlobalString('POWRSYNC_PASSWORD'))
			);
			if ($ret2 < 0) {
				setEventMessages($scraper->error, null, 'errors');
			} else {
				$ret2 = syncOneSupplierProductPrice($db, $scraper, $found, $fkSoc, $user);
				if ($ret2 == 1) {
					setEventMessages($langs->trans('PowrSyncUpdated', $found['ref_product'], $found['ref_fourn']), null, 'mesgs');
				} elseif ($ret2 == 2) {
					setEventMessages($langs->trans('PowrSyncUpToDate', $found['ref_product']), null, 'warnings');
				} else {
					setEventMessages($scraper->error, null, 'errors');
				}
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

llxHeader('', $langs->trans('PowrSyncTitle'), '');

print load_fiche_titre($langs->trans('PowrSyncTitle'), '', 'price');

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
$products = getProductsWithPowrRef($db, $fkSoc);

if ($products === false) {
	print '<div class="error">'.$langs->trans('PowrSyncDbError').': '.dol_escape_htmltag($db->lasterror()).'</div>';
	llxFooter();
	$db->close();
	exit;
}

// Récupérer les derniers logs pour chaque produit
$lastLogs = getLastLogsByProduct($db, $fkSoc);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('ProductRef').'</td>';
print '<td>'.$langs->trans('ProductLabel').'</td>';
print '<td>'.$langs->trans('PowrRef').'</td>';
print '<td class="right">'.$langs->trans('CurrentBuyPrice').'</td>';
print '<td class="right">'.$langs->trans('LastSyncPrice').'</td>';
print '<td class="center">'.$langs->trans('LastSync').'</td>';
print '<td class="center">'.$langs->trans('Status').'</td>';
print '<td class="center">'.$langs->trans('Action').'</td>';
print '</tr>';

if (empty($products)) {
	print '<tr><td colspan="8" class="center opacitymedium">'.$langs->trans('PowrSyncNoProducts').'</td></tr>';
} else {
	foreach ($products as $prod) {
		$pid       = (int) $prod['fk_product'];
		$log       = isset($lastLogs[$pid]) ? $lastLogs[$pid] : null;
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
			print '<a class="reposition butActionSmall" href="'.$_SERVER['PHP_SELF'].'?action=syncone&productid='.$pid.'&token='.newToken().'">';
			print img_picto($langs->trans('Sync'), 'refresh');
			print '</a>';
		}
		print '</td>';

		print '</tr>';
	}
}

print '</table>';
print '</div>';

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
 * @return	array|false
 */
function getProductsWithPowrRef($db, $fkSoc)
{
	$sql = "SELECT pfp.fk_product, p.ref AS ref_product, p.label AS label_product, pfp.ref_fourn, pfp.unitprice AS unitprice, pfp.quantity, ef.supplier_url";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price AS pfp";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = pfp.fk_product";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_fournisseur_price_extrafields AS ef ON ef.fk_object = pfp.rowid";
	$sql .= " WHERE pfp.fk_soc = ".((int) $fkSoc);
	$sql .= " AND pfp.entity IN (".getEntity('product').")";
	$sql .= " AND pfp.status = 1";
	$sql .= " ORDER BY p.ref ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		return false;
	}

	$result = array();
	while ($obj = $db->fetch_object($resql)) {
		$result[] = array(
			'fk_product' => (int) $obj->fk_product,
			'ref_product' => $obj->ref_product,
			'label_product' => $obj->label_product,
			'ref_fourn' => $obj->ref_fourn,
			'unitprice' => (float) $obj->unitprice,
			'quantity' => (float) $obj->quantity,
			'supplier_url' => $obj->supplier_url,
		);
	}

	return $result;
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
 * Synchronize one supplier product price with POwR Connect.
 *
 * @param	DoliDB				$db
 * @param	PowrConnectScraper	$scraper
 * @param	array				$productRow
 * @param	int					$fkSoc
 * @param	User				$user
 * @return	int
 */
function syncOneSupplierProductPrice($db, $scraper, $productRow, $fkSoc, $user)
{
	$powrRef = $productRow['ref_fourn'];
	$url = !empty($productRow['supplier_url']) ? $productRow['supplier_url'] : '';

	if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
		$scraper->error = 'URL fournisseur non valide pour '.$powrRef;
		return -1;
	}

	$newPrice = $scraper->getPrice($powrRef, $url);
	if ($newPrice === false) {
		return -1;
	}

	$currentPrice = (float) $productRow['unitprice'];
	if (abs($newPrice - $currentPrice) <= 0.001) {
		return 2;
	}

	$productFournisseur = new ProductFournisseur($db);
	$res = $productFournisseur->update_buyprice(
		max(1, (float) $productRow['quantity']),
		(float) $newPrice,
		$user,
		'HT',
		(int) $fkSoc,
		0,
		$powrRef,
		0,
		0,
		0,
		0
	);

	if ($res < 0) {
		$scraper->error = !empty($productFournisseur->error) ? $productFournisseur->error : $db->lasterror();
		return -1;
	}

	return 1;
}
