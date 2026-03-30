<?php
/* Copyright (C) 2024 - Module PowrSync
 * Page de configuration
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/cron/class/cronjob.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

$langs->loadLangs(array('admin', 'powrsync@powrsync'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// =========================================================================
// ACTIONS
// =========================================================================

if ($action == 'update') {
	$login       = GETPOST('POWRSYNC_LOGIN', 'email');
	$password    = GETPOST('POWRSYNC_PASSWORD', 'password');
	$supplierId  = GETPOST('POWRSYNC_SUPPLIER_ID', 'int');
	$delayMs     = GETPOST('POWRSYNC_DELAY_MS', 'int');

	dolibarr_set_const($db, 'POWRSYNC_LOGIN', $login, 'chaine', 0, '', $conf->entity);

	// Ne mettre à jour le mot de passe que s'il est saisi (évite d'effacer par erreur)
	if (!empty($password)) {
		dolibarr_set_const($db, 'POWRSYNC_PASSWORD', dol_encode($password), 'chaine', 0, '', $conf->entity);
	}

	dolibarr_set_const($db, 'POWRSYNC_SUPPLIER_ID', (int) $supplierId, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'POWRSYNC_DELAY_MS', max(500, (int) $delayMs), 'chaine', 0, '', $conf->entity);

	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// =========================================================================
// VUE
// =========================================================================
$title = $langs->trans('ModuleSetup', 'PowrSync');
$helpurl = '';

llxHeader('', $title, $helpurl, '', 0, 0, '', '', '', 'mod-timesheetweek page-admin');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

// Configuration header
$head = powrsyncAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, 'powrsync@powrsync');

// Récupérer la liste des fournisseurs pour le select
$sql = "SELECT s.rowid, s.nom FROM ".MAIN_DB_PREFIX."societe s";
$sql .= " WHERE s.fournisseur = 1 AND s.status = 1";
$sql .= " ORDER BY s.nom ASC";
$resql = $db->query($sql);
$suppliers = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$suppliers[$obj->rowid] = $obj->nom;
	}
}

$currentLogin      = getDolGlobalString('POWRSYNC_LOGIN');
$currentSupplierId = getDolGlobalInt('POWRSYNC_SUPPLIER_ID');
$currentDelayMs    = getDolGlobalInt('POWRSYNC_DELAY_MS') ?: 1000;
$hasPassword       = !empty(getDolGlobalString('POWRSYNC_PASSWORD'));

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print dol_get_fiche_head(array(), '', '', -1);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="3">'.$langs->trans('PowrConnectCredentials').'</td>';
print '</tr>';

// Email
print '<tr class="oddeven">';
print '<td class="titlefield">'.$langs->trans('PowrConnectEmail').'<span class="fieldrequired"> *</span></td>';
print '<td>';
print '<input type="email" name="POWRSYNC_LOGIN" class="minwidth300" value="'.dol_escape_htmltag($currentLogin).'" autocomplete="off">';
print '</td>';
print '<td class="opacitymedium">'.$langs->trans('PowrConnectEmailHelp').'</td>';
print '</tr>';

// Mot de passe
print '<tr class="oddeven">';
print '<td>'.$langs->trans('PowrConnectPassword').'<span class="fieldrequired"> *</span></td>';
print '<td>';
print '<input type="password" name="POWRSYNC_PASSWORD" class="minwidth300" value="" autocomplete="new-password" placeholder="'.($hasPassword ? '(mot de passe enregistré - laisser vide pour ne pas changer)' : '').'">';
print '</td>';
print '<td class="opacitymedium">'.$langs->trans('PowrConnectPasswordHelp').'</td>';
print '</tr>';

print '<tr class="liste_titre">';
print '<td colspan="3">'.$langs->trans('PowrConnectMapping').'</td>';
print '</tr>';

// Fournisseur Dolibarr correspondant à POwR Connect
print '<tr class="oddeven">';
print '<td>'.$langs->trans('PowrConnectSupplier').'<span class="fieldrequired"> *</span></td>';
print '<td>';
print '<select name="POWRSYNC_SUPPLIER_ID" class="minwidth300">';
print '<option value="0">-- '.$langs->trans('SelectSupplier').' --</option>';
foreach ($suppliers as $id => $nom) {
	$sel = ($id == $currentSupplierId) ? ' selected' : '';
	print '<option value="'.$id.'"'.$sel.'>'.dol_escape_htmltag($nom).'</option>';
}
print '</select>';
print '</td>';
print '<td class="opacitymedium">'.$langs->trans('PowrConnectSupplierHelp').'</td>';
print '</tr>';

print '<tr class="liste_titre">';
print '<td colspan="3">'.$langs->trans('PowrSyncAdvanced').'</td>';
print '</tr>';

// Délai entre requêtes
print '<tr class="oddeven">';
print '<td>'.$langs->trans('PowrSyncDelay').'</td>';
print '<td>';
print '<input type="number" name="POWRSYNC_DELAY_MS" value="'.((int) $currentDelayMs).'" min="500" max="10000" step="100"> ms';
print '</td>';
print '<td class="opacitymedium">'.$langs->trans('PowrSyncDelayHelp').'</td>';
print '</tr>';

print '</table>';
print dol_get_fiche_end();

print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
print '</div>';

print '</form>';

// Info sur la configuration actuelle
if ($currentSupplierId > 0 && !empty($currentLogin)) {
	print '<br>';
	print '<div class="info">';
	print img_picto('', 'info', 'class="pictofixedwidth"');
	print ' '.$langs->trans('PowrSyncConfigOk', $currentLogin);
	print '</div>';
}

llxFooter();
$db->close();
