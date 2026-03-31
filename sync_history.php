<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file	sync_history.php
 * \ingroup	powrsync
 * \brief	Synchronization history list page.
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once dol_buildpath('/powrsync/class/powrconnectscraper.class.php', 0);

$langs->loadLangs(array('products', 'powrsync@powrsync'));

if (!isModEnabled('powrsync')) {
	accessforbidden();
}
if (!$user->hasRight('powrsync', 'synclog', 'read')) {
	accessforbidden();
}

$form = new Form($db);

$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma') ? GETPOST('sortfield', 'aZ09comma') : 'l.datec';
$sortorder = GETPOST('sortorder', 'aZ09comma') ? GETPOST('sortorder', 'aZ09comma') : 'DESC';
$page = max(0, GETPOSTINT('page'));
$offset = $limit * $page;

$search_ref_fourn = trim(GETPOST('search_ref_fourn', 'alphanohtml'));
$search_status = GETPOST('search_status', 'alpha');
if (GETPOST('button_removefilter', 'alpha')) {
	$search_ref_fourn = '';
	$search_status = '';
}

$allowedSortFields = array('l.datec', 'l.ref_product', 'l.ref_fourn', 'l.status');
if (!in_array($sortfield, $allowedSortFields, true)) {
	$sortfield = 'l.datec';
}
$sortorder = (strtoupper($sortorder) === 'ASC') ? 'ASC' : 'DESC';

$availableColumns = array();
$rescol = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."powrsync_log");
if ($rescol) {
	while ($objcol = $db->fetch_object($rescol)) {
		$availableColumns[$objcol->Field] = true;
	}
	$db->free($rescol);
}

$hasEntityColumn = !empty($availableColumns['entity']);
$hasRefProductColumn = !empty($availableColumns['ref_product']);
$hasRefFournColumn = !empty($availableColumns['ref_fourn']);

$sqlselect = "SELECT l.rowid, l.datec, l.fk_product, l.old_price, l.new_price, l.status, l.message";
$sqlselect .= ", ".($hasRefProductColumn ? "l.ref_product" : "p.ref")." AS ref_product";
$sqlselect .= ", ".($hasRefFournColumn ? "l.ref_fourn" : "''")." AS ref_fourn";

$sqlfrom = " FROM ".MAIN_DB_PREFIX."powrsync_log AS l";
$sqlfrom .= " LEFT JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = l.fk_product";
$sqlwhere = " WHERE 1 = 1";
if ($hasEntityColumn) {
	$sqlwhere .= " AND l.entity IN (".getEntity('powrsync').")";
}
if ($search_ref_fourn !== '' && $hasRefFournColumn) {
	$sqlwhere .= " AND l.ref_fourn LIKE '%".$db->escape($search_ref_fourn)."%'";
}
if ($search_status !== '') {
	if ((int) $search_status === PowrConnectScraper::LOG_OK) {
		$sqlwhere .= " AND (l.status IN (".PowrConnectScraper::LOG_OK.", 'updated', 'ok', 'success'))";
	} elseif ((int) $search_status === PowrConnectScraper::LOG_UPTODATE) {
		$sqlwhere .= " AND (l.status IN (".PowrConnectScraper::LOG_UPTODATE.", 'unchanged', 'uptodate'))";
	} elseif ((int) $search_status === PowrConnectScraper::LOG_ERROR) {
		$sqlwhere .= " AND (l.status IN (".PowrConnectScraper::LOG_ERROR.", 'error', 'failed'))";
	}
}

$sortFieldMap = array(
	'l.datec' => 'l.datec',
	'l.ref_product' => 'ref_product',
	'l.ref_fourn' => 'ref_fourn',
	'l.status' => 'l.status',
);
if (empty($sortFieldMap[$sortfield])) {
	$sortfield = 'l.datec';
}
$sql = $sqlselect;
$sql .= $sqlfrom;
$sql .= $sqlwhere;
$sql .= " ORDER BY ".$sortFieldMap[$sortfield]." ".$sortorder;
$sql .= $db->plimit($limit, $offset);

$resql = $db->query($sql);

$sqlcount = "SELECT COUNT(l.rowid) AS nb".$sqlfrom.$sqlwhere;
$rescount = $db->query($sqlcount);
$total = 0;
if ($rescount) {
	$objcount = $db->fetch_object($rescount);
	$total = (int) $objcount->nb;
}

$param = '';
if ($search_ref_fourn !== '') {
	$param .= '&search_ref_fourn='.urlencode($search_ref_fourn);
}
if ($search_status !== '') {
	$param .= '&search_status='.urlencode($search_status);
}

llxHeader('', $langs->trans('PowrSyncLog'));
//print load_fiche_titre($langs->trans('PowrSyncLog'), '', 'title_generic');

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print_barre_liste($langs->trans('PowrSyncLog'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $total, $limit, 'clock', 0, '', '', $limit);
print_barre_liste($langs->trans('PowrSyncLog'), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'clock', 0, $newcardbutton, '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">';

print '<tr class="liste_titre_filter">';
print '<td></td>';
print '<td></td>';
print '<td>';
if ($hasRefFournColumn) {
	print '<input type="text" class="flat minwidth100" name="search_ref_fourn" value="'.dol_escape_htmltag($search_ref_fourn).'">';
} else {
	print '<span class="opacitymedium">-</span>';
}
print '</td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td class="center">';
print $form->selectarray('search_status', array(
	'' => $langs->trans('All'),
	PowrConnectScraper::LOG_OK => $langs->trans('PowrLogUpdated'),
	PowrConnectScraper::LOG_UPTODATE => $langs->trans('PowrLogUpToDate'),
	PowrConnectScraper::LOG_ERROR => $langs->trans('PowrLogError'),
), $search_status, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150');
print '</td>';
print '<td class="right">';
print '<button class="button small" type="submit" name="button_search">'.$langs->trans('Search').'</button> ';
print '<button class="button button-cancel small" type="submit" name="button_removefilter" value="x">'.$langs->trans('RemoveFilter').'</button>';
print '</td>';
print '</tr>';

print '<tr class="liste_titre">';
print getTitleFieldOfList($langs->trans('Date'), 0, $_SERVER['PHP_SELF'], 'l.datec', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('ProductRef'), 0, $_SERVER['PHP_SELF'], 'l.ref_product', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('PowrRef'), 0, $_SERVER['PHP_SELF'], 'l.ref_fourn', '', $param, '', $sortfield, $sortorder);
print '<td class="right">'.$langs->trans('OldPrice').'</td>';
print '<td class="right">'.$langs->trans('NewPrice').'</td>';
print '<td class="right">'.$langs->trans('Variation').'</td>';
print getTitleFieldOfList($langs->trans('Status'), 0, $_SERVER['PHP_SELF'], 'l.status', '', $param, 'class="center"', $sortfield, $sortorder);
print '<td>'.$langs->trans('Message').'</td>';
print '</tr>';

if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$oldPrice = ($obj->old_price !== null ? (float) $obj->old_price : null);
		$newPrice = ($obj->new_price !== null ? (float) $obj->new_price : null);
		$diff = ($oldPrice !== null && $newPrice !== null) ? ($newPrice - $oldPrice) : null;
		$statusRaw = is_string($obj->status) ? strtolower((string) $obj->status) : (string) $obj->status;
		$statusNum = is_numeric($obj->status) ? (int) $obj->status : null;

		$badge = '<span class="badge badge-status8">'.$langs->trans('PowrLogError').'</span>';
		if ($statusNum === PowrConnectScraper::LOG_OK || in_array($statusRaw, array('updated', 'ok', 'success'), true)) {
			$badge = '<span class="badge badge-status4">'.$langs->trans('PowrLogUpdated').'</span>';
		} elseif ($statusNum === PowrConnectScraper::LOG_UPTODATE || in_array($statusRaw, array('unchanged', 'uptodate'), true)) {
			$badge = '<span class="badge badge-status1">'.$langs->trans('PowrLogUpToDate').'</span>';
		}

		print '<tr class="oddeven">';
		print '<td>'.dol_print_date($db->jdate($obj->datec), 'dayhour').'</td>';
		print '<td>';
		if ((int) $obj->fk_product > 0) {
			print '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.(int) $obj->fk_product.'">'.dol_escape_htmltag($obj->ref_product).'</a>';
		} else {
			print dol_escape_htmltag($obj->ref_product);
		}
		print '</td>';
		print '<td>'.dol_escape_htmltag($obj->ref_fourn).'</td>';
		print '<td class="right">'.($oldPrice !== null ? price($oldPrice) : '-').'</td>';
		print '<td class="right">'.($newPrice !== null ? price($newPrice) : '-').'</td>';
		print '<td class="right">';
		if ($diff !== null && abs($diff) > 0.001) {
			$arrow = ($diff > 0 ? '▲' : '▼');
			$style = ($diff > 0 ? 'color: var(--colortextdanger);' : 'color: var(--colortextsuccess);');
			print '<span style="'.$style.'">'.$arrow.' '.price(abs($diff)).'</span>';
		} elseif ($diff !== null) {
			print '=';
		} else {
			print '-';
		}
			print '</td>';
			print '<td class="center">'.$badge.'</td>';
			print '<td><span class="small">'.dol_escape_htmltag(dol_trunc($obj->message, 120)).'</span></td>';
			print '</tr>';
		}
		$db->free($resql);
	} else {
		print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">'.dol_escape_htmltag($db->lasterror()).'</span></td></tr>';
	}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
