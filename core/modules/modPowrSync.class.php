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

		// ID unique — à réserver sur https://wiki.dolibarr.org/index.php/List_of_modules_id
		$this->numero = 680001;

		$this->rights_class   = 'powrsync';
		$this->family         = 'products';
		$this->module_position = '90';
		$this->name           = preg_replace('/^mod/i', '', get_class($this));
		$this->description    = "Synchronisation des prix d'achat depuis POwR Connect";
		$this->descriptionlong = "Parcourt les produits Dolibarr ayant une référence fournisseur POwR Connect, ".
			"récupère les prix actuels par scraping authentifié et met à jour les prix d'achat si nécessaire.";
		$this->editor_name    = 'Votre Société';
		$this->editor_url     = 'https://votresociete.fr';
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
			'url'      => '/powrsync/powrsync.php',
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
			'url'      => '/powrsync/powrsync.php',
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
		$sql = array();
		// Chargement des tables SQL depuis le dossier sql/
		$result = $this->_load_tables('/powrsync/sql/');
		if ($result < 0) {
			return -1;
		}
		return $this->_init($sql, $options);
	}

	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
