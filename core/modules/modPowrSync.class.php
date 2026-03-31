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
		$this->numero = 680001;

		$this->rights_class   = 'powrsync';
		$this->family         = 'Les Métiers du Bâtiment';
		$this->module_position = '90';
		$this->name           = preg_replace('/^mod/i', '', get_class($this));
		$this->description    = "Synchronisation des prix d'achat depuis POwR Connect";
		$this->descriptionlong = "Parcourt les produits Dolibarr ayant une référence fournisseur POwR Connect, ".
			"récupère les prix actuels par scraping authentifié et met à jour les prix d'achat si nécessaire.";
		$this->editor_name    = 'Les Métiers du Bâtiment';
		$this->editor_url     = 'https://lesmetiersdubatiment.fr';
		$this->version        = '1.0.0';
		$this->const_name     = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto          = 'object_powrsync@powrsync';

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
			),
		);

		// Droits
		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = $this->numero + $r + 1;
		$this->rights[$r][1] = 'Consulter les logs de synchronisation POwR Connect';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'synclog';
		$this->rights[$r][5] = 'read';
		$r++;

		$this->rights[$r][0] = $this->numero + $r + 1;
		$this->rights[$r][1] = 'Lancer une synchronisation POwR Connect';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'synclog';
		$this->rights[$r][5] = 'write';
		$r++;

		$this->rights[$r][0] = $this->numero + $r + 1;
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
			'url'      => '/powrsync/powrsync_log.php',
			'langs'    => 'powrsync@powrsync',
			'position' => 902,
			'enabled'  => 'isModEnabled("powrsync")',
			'perms'    => '$user->hasRight("powrsync", "synclog", "read")',
			'user'     => 2,
		);
	}

	public function init($options = '')
	{
		$result = $this->_init(array(), $options);
		if ($result < 0) {
			return -1;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		// Enable extrafield visibility by default
		dolibarr_set_const($this->db, 'SYNC_URL_SUPPLIER_EXTRAFIELD', '1', 'yesno', 0, '', 0);

		// Create extrafield "supplier_url" on supplier purchase prices if missing
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$existing = $extrafields->fetch_name_optionals_label('product_fournisseur_price');
		if (!isset($existing['supplier_url'])) {
			$extrafields->addExtraField(
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
				'powrsync@powrsync'                   // langfile
			);
		}

		// Ensure visibility is active for all entities
		$sql = "UPDATE ".MAIN_DB_PREFIX."extrafields";
		$sql .= " SET enabled = '(getDolGlobalInt(\"SYNC_URL_SUPPLIER_EXTRAFIELD\") == 1)', entity = 0";
		$sql .= " WHERE name = 'supplier_url'";
		$sql .= " AND elementtype = 'product_fournisseur_price'";
		$this->db->query($sql);

		return $result;
	}

	public function remove($options = '')
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_del_const($this->db, 'SYNC_URL_SUPPLIER_EXTRAFIELD', 0);

		$sql = array();
		return $this->_remove($sql, $options);
	}
}
