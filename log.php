<?php
/* Copyright (C) 2024 - Module PowrSync
 * Historique des synchronisations
 */

require '../../main.inc.php';
require_once dol_buildpath('/powrsync/class/powrconnectscraper.class.php', 0);

$langs->loadLangs(array('products', 'powrsync@powrsync'));

if (!isModEnabled('powrsync')) {
	accessforbidden();
}
if (!$user->hasRight('powrsync', 'sync', 'read')) {
	accessforbidden();
}

$limit      = GETPOST('limit', 'int') ?: $conf->liste_limit;
$sortfield  = GETPOST('sortfield', 'aZ09comma') ?: 'l.datec';
$sortorder  = GETPOST('sortorder', 'aZ09comma') ?: 'DESC';
$page       = max(0, GETPOST('page', 'int'));
$offset     = $limit * $page;
$filterRef  = GETPOST('ref_fourn', 'alpha');
$filterStat = GETPOST('status', 'int');

llxHeader('', $langs->trans('PowrSyncLog'), '');

print load_fiche_titre($langs->trans('PowrSyncLog'), '', 'price');

// Filtres
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<div class="directionmessage">';
print '<table class="centpercent">';
print '<tr>';
print '<td class="liste_titre">'.$langs->trans('PowrRef').'</td>';
print '<td><input type="text" name="ref_fourn" value="'.dol_escape_htmltag($filterRef).'" class="minwidth150"></td>';
print '<td class="liste_titre">'.$langs->trans('Status').'</td>';
print '<td>';
print '<select name="status" class="minwidth100">';
print '<option value="">'.$langs->trans('All').'</option>';
print '<option value="1"'.($filterStat == 1 ? ' selected' : '').'>'.$langs->trans('PowrLogUpdated').'</option>';
print '<option value="2"'.($filterStat == 2 ? ' selected' : '').'>'.$langs->trans('PowrLogUpToDate').'</option>';
print '<option value="-1"'.($filterStat == -1 ? ' selected' : '').'>'.$langs->trans('PowrLogError').'</option>';
print '</select>';
print '</td>';
print '<td><input type="submit" class="button" value="'.$langs->trans('Search').'"></td>';
print '<td><a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Reset').'</a></td>';
print '</tr>';
print '</table>';
print '</div>';
print '</form>';

// Requête
$sql = "SELECT l.rowid, l.datec, l.fk_product, l.ref_product, l.ref_fourn,";
$sql .= " l.old_price, l.new_price, l.status, l.message, u.login";
$sql .= " FROM ".MAIN_DB_PREFIX."powrsync_log l";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = l.fk_user_creat";
$sql .= " WHERE 1=1";
if (!empty($filterRef)) {
	$sql .= " AND l.ref_fourn LIKE '".$db->escape('%'.$filterRef.'%')."'";
}
if ($filterStat !== '' && is_numeric($filterStat)) {
	$sql .= " AND l.status = ".((int) $filterStat);
}
$sql .= " ORDER BY ".$db->sanitize($sortfield)." ".$db->sanitize($sortorder);
$sql .= $db->plimit($limit, $offset);

$resql = $db->query($sql);

// Comptage total
$sqlCount = preg_replace('/SELECT .+ FROM/s', 'SELECT COUNT(*) AS nb FROM', preg_replace('/ORDER BY.+/', '', $sql));
$resCount = $db->query($sqlCount);
$total    = 0;
if ($resCount) {
	$objCount = $db->fetch_object($resCount);
	$total    = (int) $objCount->nb;
}

// Pagination
print_barre_liste($langs->trans('PowrSyncLog'), $page, $_SERVER['PHP_SELF'], '&ref_fourn='.urlencode($filterRef).'&status='.$filterStat, $sortfield, $sortorder, '', $total, $limit, 'price');

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print getTitleFieldOfList($langs->trans('Date'), 0, $_SERVER['PHP_SELF'], 'l.datec', '', '&ref_fourn='.urlencode($filterRef), '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('ProductRef'), 0, $_SERVER['PHP_SELF'], 'l.ref_product', '', '&ref_fourn='.urlencode($filterRef), '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('PowrRef'), 0, $_SERVER['PHP_SELF'], 'l.ref_fourn', '', '&ref_fourn='.urlencode($filterRef), '', $sortfield, $sortorder);
print '<td class="right">'.$langs->trans('OldPrice').'</td>';
print '<td class="right">'.$langs->trans('NewPrice').'</td>';
print '<td class="right">'.$langs->trans('Variation').'</td>';
print '<td class="center">'.$langs->trans('Status').'</td>';
print '<td>'.$langs->trans('Message').'</td>';
print '<td>'.$langs->trans('User').'</td>';
print '</tr>';

if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$diff = ($obj->new_price !== null && $obj->old_price !== null)
			? ($obj->new_price - $obj->old_price)
			: null;

		// Badge statut
		if ($obj->status == PowrConnectScraper::LOG_OK) {
			$badge = '<span class="badge badge-status4">'.$langs->trans('PowrLogUpdated').'</span>';
		} elseif ($obj->status == PowrConnectScraper::LOG_UPTODATE) {
			$badge = '<span class="badge badge-status1">'.$langs->trans('PowrLogUpToDate').'</span>';
		} else {
			$badge = '<span class="badge badge-status8">'.$langs->trans('PowrLogError').'</span>';
		}

		print '<tr class="oddeven">';
		print '<td>'.dol_print_date($db->jdate($obj->datec), 'dayhour').'</td>';
		print '<td>';
		if ($obj->fk_product > 0) {
			print '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.$obj->fk_product.'">'.dol_escape_htmltag($obj->ref_product).'</a>';
		} else {
			print dol_escape_htmltag($obj->ref_product);
		}
		print '</td>';
		print '<td>'.dol_escape_htmltag($obj->ref_fourn).'</td>';
		print '<td class="right">'.($obj->old_price !== null ? price($obj->old_price).' €' : '-').'</td>';
		print '<td class="right">'.($obj->new_price !== null ? price($obj->new_price).' €' : '-').'</td>';
		print '<td class="right">';
		if ($diff !== null && abs($diff) > 0.001) {
			$arrow = $diff > 0 ? '▲' : '▼';
			$cls   = $diff > 0 ? 'color:red' : 'color:green';
			print '<span style="'.$cls.'">'.$arrow.' '.price(abs($diff)).' €</span>';
		} elseif ($diff !== null) {
			print '=';
		} else {
			print '-';
		}
		print '</td>';
		print '<td class="center">'.$badge.'</td>';
		print '<td><small>'.dol_escape_htmltag(dol_trunc($obj->message, 80)).'</small></td>';
		print '<td>'.dol_escape_htmltag($obj->login).'</td>';
		print '</tr>';
	}
	$db->free($resql);
} else {
	print '<tr><td colspan="9" class="center opacitymedium">'.$langs->trans('NoRecordFound').'</td></tr>';
}

print '</table>';
print '</div>';

llxFooter();
$db->close();
