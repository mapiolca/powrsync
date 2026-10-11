<?php
/**
 * Compatibilité déclarée et limites de validation PowrSync.
 */

// EN: Attempt to load Dolibarr bootstrap from common locations.
// FR: Tente de charger l'amorçage Dolibarr depuis les emplacements courants.
$res = 0;
if (!$res && file_exists(__DIR__.'/../main.inc.php')) {
	$res = require_once __DIR__.'/../main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../main.inc.php')) {
	$res = require_once __DIR__.'/../../main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../../main.inc.php')) {
	$res = require_once __DIR__.'/../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/powrsync/lib/powrsync.lib.php');
dol_include_once('/powrsync/core/modules/modPowrSync.class.php');

// EN: Load admin and module translations for the compatibility page.
// FR: Charge les traductions d'administration et du module pour la page Compatibilité.
$langs->loadLangs(array('admin', 'powrsync@powrsync'));

// EN: Only Dolibarr administrators can display the compatibility page.
// FR: Seuls les administrateurs Dolibarr peuvent afficher la page Compatibilité.
if (empty($user->admin)) {
	accessforbidden();
}

$moduleDescriptor = new modPowrSync($db);
$langs->load('other');
llxHeader('', $langs->trans('Compatibility'));
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?search_keyword=powrsync">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('Compatibility'), $linkback, 'info');
print dol_get_fiche_head(powrsyncAdminPrepareHead(), 'compatibility', $langs->trans('PowrSyncTitle'), -1, $moduleDescriptor->picto);
$information = array(
	'PowrSyncDetectedPhp' => PHP_VERSION,
	'PowrSyncDetectedDolibarr' => DOL_VERSION,
	'PowrSyncMinimumPhp' => implode('.', $moduleDescriptor->phpmin),
	'PowrSyncMinimumDolibarr' => implode('.', $moduleDescriptor->need_dolibarr_version),
	'PowrSyncTargetVersions' => '20, 21, 22, 23, 24, 25',
);
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
foreach ($information as $label => $value) {
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($label).'</td><td>'.dol_escape_htmltag($value).'</td></tr>';
}
print '</table></div>';
if (version_compare(PHP_VERSION, '8.0', '<') || version_compare(DOL_VERSION, '20.0', '<')) {
	print '<div class="warning">'.$langs->trans('PowrSyncUnsupportedEnvironment').'</div>';
}
print '<p class="opacitymedium">'.$langs->trans('PowrSyncCompatibilityScope').'</p>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Dolibarr</th><th>'.$langs->trans('Status').'</th></tr>';
foreach (array(20, 21, 22, 23, 24, 25) as $major) {
	print '<tr class="oddeven"><td>'.((int) $major).'</td><td>'.$langs->trans('PowrSyncNotIntegrationTested').'</td></tr>';
}
print '</table>';
print dol_get_fiche_end();
llxFooter();
$db->close();
