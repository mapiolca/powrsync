<?php
/* Copyright (C) 2024 Votre Société <contact@votresociete.fr>
 * Licence GNU GPL v3 ou ultérieure
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Descripteur du module PowrSync
 * Synchronisation des prix d'achat depuis POwR Connect
 */
class modPowrSync extends DolibarrModules
{
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;
		parent::__construct($db);

		// ID unique — à réserver sur https://wiki.dolibarr.org/index.php/List_of_modules_id
		$this->numero = 450013;

		$this->rights_class   = 'powrsync';
		$this->family         = 'Les Métiers du Bâtiment';
		$this->module_position = '90';
		$this->name           = preg_replace('/^mod/i', '', get_class($this));
		$this->description    = "Synchronisation des prix d'achat depuis POwR Connect";
		$this->descriptionlong = "Parcourt les produits Dolibarr ayant une référence fournisseur POwR Connect, ".
			"récupère les prix actuels par scraping authentifié et met à jour les prix d'achat si nécessaire.";
		$this->editor_name    = 'Les Métiers du Bâtiment';
		$this->editor_url     = 'https://lesmetiersdubatiment.fr';
		$this->editor_squarred_logo = 'lesmetiersdubatiment.png@powrsync';					// Must be image filename into the module/img directory followed with @modulename. Example: 'myimage.png@powrsync'

		$this->version        = '1.0.5';
		$this->const_name     = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto          = 'powrconnect@powrsync';

		$this->phpmin = array(8, 0);
		$this->need_dolibarr_version = array(20, 0);

		$this->dirs = array("/powrsync/temp");

		$this->config_page_url = array("setup.php@powrsync");

		$this->depends    = array('modProduct', 'modFournisseur');
		$this->requiredby = array();
		$this->conflictwith = array();

		// Constantes persistées (table llx_const)
		$this->const = array();

		// Cron job automatique (toutes les 24h)
		$this->cronjobs = array(
			0 => array(
				'label'         => 'PowrSyncSyncAllProducts',
				'jobtype'       => 'method',
				'class'         => '/powrsync/class/powrsync.class.php',
				'objectname'    => 'PowrSync',
				'method'        => 'syncAllProducts',
				'parameters'    => '',
				'comment'       => 'Synchronisation prix POwR Connect',
				'frequency'     => 24,
				'unitfrequency' => 3600,
				'status'        => 0,
				'test'          => 'isModEnabled("powrsync")',
				'priority'      => 50,
			),
		);

		// Droits
		$this->rights = array();
		$r = 0;

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'Consulter les logs de synchronisation POwR Connect';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'synclog';
		$this->rights[$r][5] = 'read';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'Lancer une synchronisation POwR Connect';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'synclog';
		$this->rights[$r][5] = 'write';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'Configurer le module POwR Connect';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'config';
		$this->rights[$r][5] = 'write';

		// Menus (sous le menu Produits)
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=products',
			'type'     => 'left',
			'titre'    => 'POwR Connect Sync',
			'prefix' => img_picto('', 'object_powrconnect@powrsync', 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'products',
			'leftmenu' => 'powrsync',
			'url'      => '/powrsync/sync.php',
			'langs'    => 'powrsync@powrsync',
			'position' => 900,
			'enabled'  => 'isModEnabled("powrsync")',
			'perms'    => '$user->hasRight("powrsync", "synclog", "read")',
			'target'   => '',
			'user'     => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=powrsync',
			'type'     => 'left',
			'titre'    => 'Tableau de bord',
			'mainmenu' => 'products',
			'leftmenu' => 'powrsync_dashboard',
			'url'      => '/powrsync/sync.php',
			'langs'    => 'powrsync@powrsync',
			'position' => 901,
			'enabled'  => 'isModEnabled("powrsync")',
			'perms'    => '$user->hasRight("powrsync", "synclog", "read")',
			'user'     => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=powrsync',
			'type'     => 'left',
			'titre'    => 'Historique des syncs',
			'mainmenu' => 'products',
			'leftmenu' => 'powrsync_log',
			'url'      => '/powrsync/sync_history.php',
			'langs'    => 'powrsync@powrsync',
			'position' => 902,
			'enabled'  => 'isModEnabled("powrsync")',
			'perms'    => '$user->hasRight("powrsync", "synclog", "read")',
			'user'     => 2,
		);
	}

	public function init($options = '')
	{
		$result = $this->migrateLegacyPermissionIds();
		if ($result < 0) {
			return -1;
		}

		// EN: Create synchronization log table if missing at module activation.
		// FR: Crée la table de logs de synchronisation si elle n'existe pas à l'activation du module.
		$sql = "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."powrsync_log (";
		$sql .= " rowid INTEGER AUTO_INCREMENT PRIMARY KEY,";
		$sql .= " entity INTEGER NOT NULL DEFAULT 1, ref_fourn VARCHAR(255) NULL, fk_product INTEGER NOT NULL,";
		$sql .= " datec DATETIME NOT NULL,";
		$sql .= " old_price DOUBLE(24,8) NULL,";
		$sql .= " new_price DOUBLE(24,8) NULL,";
		$sql .= " status VARCHAR(20) NOT NULL,";
		$sql .= " message TEXT NULL,";
		$sql .= " INDEX idx_powrsync_log_entity (entity), INDEX idx_powrsync_log_fk_product (fk_product),";
		$sql .= " INDEX idx_powrsync_log_datec (datec),";
		$sql .= " INDEX idx_powrsync_log_status (status)";
		$sql .= ") ENGINE=innodb";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		if ($this->upgradeLogSchema() < 0 || $this->initSupplierUrlSetting() < 0) {
			return -1;
		}

		// Create extrafield "supplier_url" on supplier purchase prices if missing
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('product_fournisseur_price');
		if (!isset($extrafields->attributes['product_fournisseur_price']['label']['supplier_url'])) {
			$result = $extrafields->addExtraField(
				'supplier_url',                     // attrname
				'PowrSyncSupplierUrlLabel',         // label
				'url',                               // type
				100,                                  // pos
				'',                                   // size
				'product_fournisseur_price',          // elementtype
				0,                                    // unique
				0,                                    // required
				'',                                   // default
				'',                                   // param
				1,                                    // alwayseditable
				'',                                   // perms
				1,                                    // list
				'PowrSyncSupplierUrlTooltip',         // help
				'',                                   // computed
				0,                                    // entity (all entities)
				'powrsync@powrsync',                  // langfile
				'(getDolGlobalInt("SYNC_URL_SUPPLIER_EXTRAFIELD") == 1)' // enabled
			);
			if ($result < 0) {
				$this->error = $extrafields->error;
				return -1;
			}
		}

		return $this->_init(array(), $options);
	}

	/**
	 * Migrate permissions granted before the 1.0.3 numbering fix.
	 *
	 * @return int 1 if OK, -1 on error
	 */
	protected function migrateLegacyPermissionIds()
	{
		$permissionMap = array(
			$this->numero + 1 => $this->numero * 100 + 1,
			$this->numero + 2 => $this->numero * 100 + 2,
			$this->numero + 3 => $this->numero * 100 + 3,
		);

		$this->db->begin();

		foreach ($permissionMap as $oldId => $newId) {
			$oldId = (int) $oldId;
			$newId = (int) $newId;
			$rightsClass = $this->db->escape($this->rights_class);
			$legacyIdIsNotOwnedByAnotherModule = "NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."rights_def AS rd WHERE rd.id = ".$oldId." AND rd.module <> '".$rightsClass."')";

			$sql = "INSERT IGNORE INTO ".MAIN_DB_PREFIX."user_rights (entity, fk_user, fk_id)";
			$sql .= " SELECT ur.entity, ur.fk_user, ".$newId;
			$sql .= " FROM ".MAIN_DB_PREFIX."user_rights AS ur";
			$sql .= " WHERE ur.fk_id = ".$oldId;
			$sql .= " AND ".$legacyIdIsNotOwnedByAnotherModule;
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}

			$sql = "DELETE FROM ".MAIN_DB_PREFIX."user_rights";
			$sql .= " WHERE fk_id = ".$oldId;
			$sql .= " AND ".$legacyIdIsNotOwnedByAnotherModule;
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}

			$sql = "INSERT IGNORE INTO ".MAIN_DB_PREFIX."usergroup_rights (entity, fk_usergroup, fk_id)";
			$sql .= " SELECT ugr.entity, ugr.fk_usergroup, ".$newId;
			$sql .= " FROM ".MAIN_DB_PREFIX."usergroup_rights AS ugr";
			$sql .= " WHERE ugr.fk_id = ".$oldId;
			$sql .= " AND ".$legacyIdIsNotOwnedByAnotherModule;
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}

			$sql = "DELETE FROM ".MAIN_DB_PREFIX."usergroup_rights";
			$sql .= " WHERE fk_id = ".$oldId;
			$sql .= " AND ".$legacyIdIsNotOwnedByAnotherModule;
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}

			$sql = "DELETE FROM ".MAIN_DB_PREFIX."rights_def";
			$sql .= " WHERE id = ".$oldId;
			$sql .= " AND module = '".$rightsClass."'";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Add missing log metadata without inventing ownership of historical entries.
	 * Existing rows without entity remain quarantined at entity 0.
	 *
	 * @return int
	 */
	protected function upgradeLogSchema()
	{
		$resql = $this->db->query('SHOW COLUMNS FROM '.MAIN_DB_PREFIX.'powrsync_log');
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$columns = array();
		while (is_object($column = $this->db->fetch_object($resql))) {
			$columns[$column->Field] = true;
		}
		foreach (array('entity' => 'INTEGER NOT NULL DEFAULT 0', 'ref_fourn' => 'VARCHAR(255) NULL') as $name => $definition) {
			if (!isset($columns[$name]) && !$this->db->query('ALTER TABLE '.MAIN_DB_PREFIX.'powrsync_log ADD COLUMN '.$name.' '.$definition)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		$resql = $this->db->query("SHOW INDEX FROM ".MAIN_DB_PREFIX."powrsync_log WHERE Key_name = 'idx_powrsync_log_entity'");
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if (!is_object($this->db->fetch_object($resql)) && !$this->db->query('CREATE INDEX idx_powrsync_log_entity ON '.MAIN_DB_PREFIX.'powrsync_log (entity)')) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Initialize entity setting only when absent, inheriting any legacy global value.
	 *
	 * @return int
	 */
	protected function initSupplierUrlSetting()
	{
		global $conf;
		$sql = "SELECT entity, value FROM ".MAIN_DB_PREFIX."const WHERE name = 'SYNC_URL_SUPPLIER_EXTRAFIELD'";
		$sql .= ' AND entity IN (0, '.((int) $conf->entity).') ORDER BY entity DESC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$value = '1';
		while (is_object($constant = $this->db->fetch_object($resql))) {
			if ((int) $constant->entity === (int) $conf->entity) {
				return 1;
			}
			$value = (string) $constant->value;
		}
		return dolibarr_set_const($this->db, 'SYNC_URL_SUPPLIER_EXTRAFIELD', $value, 'yesno', 0, '', (int) $conf->entity);
	}

	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
