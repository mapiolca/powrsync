<?php
/** Targeted schema/settings execution with a SQLite adapter; not a MySQL instance test. */
$coreRoot = $argv[1] ?? '';
if (!is_file($coreRoot.'/core/modules/DolibarrModules.class.php')) { throw new RuntimeException('Provide Dolibarr htdocs path'); }
define('DOL_DOCUMENT_ROOT', $coreRoot);
define('MAIN_DB_PREFIX', 'test_');
require __DIR__.'/../core/modules/modPowrSync.class.php';
class SchemaTestDb
{
	public $pdo;
	public $error = '';
	public function __construct() { $this->pdo = new PDO('sqlite::memory:'); }
	public function query($sql) {
		if (preg_match('/^SHOW COLUMNS FROM ([a-z_]+)$/', $sql, $match)) { $sql = "SELECT name AS Field FROM pragma_table_info('".$match[1]."')"; }
		if (strpos($sql, 'SHOW INDEX FROM ') === 0) { $sql = "SELECT name AS Key_name FROM sqlite_master WHERE type = 'index' AND name = 'idx_powrsync_log_entity'"; }
		try { return $this->pdo->query($sql); }
		catch (PDOException $error) { $this->error = $error->getMessage(); return false; }
	}
	public function fetch_object($result) { return $result->fetchObject(); }
	public function free($result) {}
	public function sanitize($value) { return $value; }
	public function lasterror() { return $this->error; }
}
function dolibarr_set_const($db, $name, $value, $type, $visible, $note, $entity) {
	$statement = $db->pdo->prepare('INSERT INTO test_const (name, value, entity) VALUES (?, ?, ?)');
	return $statement->execute(array($name, $value, $entity)) ? 1 : -1;
}
function img_picto($title, $picto, $attributes = '') { return ''; }
$conf = (object) array('entity' => 2);
$db = new SchemaTestDb();
$db->query('CREATE TABLE test_powrsync_log (rowid INTEGER PRIMARY KEY, fk_product INTEGER)');
$db->query('INSERT INTO test_powrsync_log VALUES (1, 42)');
$module = new modPowrSync($db);
$upgrade = new ReflectionMethod(modPowrSync::class, 'upgradeLogSchema');
foreach (array(1, 2) as $pass) {
	if ($upgrade->invoke($module) !== 1) { throw new RuntimeException($module->error); }
}
if ((int) $db->pdo->query('SELECT entity FROM test_powrsync_log WHERE rowid = 1')->fetchColumn() !== 0) {
	throw new RuntimeException('Migration invented ownership of legacy log');
}
$db->query('INSERT INTO test_powrsync_log (rowid, fk_product, entity) VALUES (2, 42, 2), (3, 42, 1)');
$rows = $db->pdo->query('SELECT rowid FROM test_powrsync_log WHERE entity = 2')->fetchAll(PDO::FETCH_COLUMN);
if ($rows !== array(2)) { throw new RuntimeException('Entity or quarantined log leaked'); }
$db->query('CREATE TABLE test_const (name TEXT, value TEXT, entity INTEGER, UNIQUE(name, entity))');
$initialize = new ReflectionMethod(modPowrSync::class, 'initSupplierUrlSetting');
foreach (array('0', '') as $value) {
	$db->query('DELETE FROM test_const');
	dolibarr_set_const($db, 'SYNC_URL_SUPPLIER_EXTRAFIELD', $value, '', 0, '', 0);
	foreach (array(1, 2) as $pass) {
		if ($initialize->invoke($module) !== 1) { throw new RuntimeException('Setting initialization failed'); }
	}
	$rows = $db->pdo->query('SELECT value FROM test_const WHERE entity = 2')->fetchAll(PDO::FETCH_COLUMN);
	if ($rows !== array($value)) { throw new RuntimeException('Configured zero/empty value was overwritten or duplicated'); }
}
$db->query('DELETE FROM test_const');
$initialize->invoke($module);
if ($db->pdo->query('SELECT value FROM test_const WHERE entity = 2')->fetchColumn() !== '1') { throw new RuntimeException('Missing setting not initialized'); }
echo "Schema replay, legacy quarantine, entity scope and zero/empty settings checks OK (SQLite adapter)\n";
