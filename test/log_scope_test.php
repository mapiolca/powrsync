<?php
/** Execute module log reads and access guards using a SQLite adapter. */
require __DIR__.'/schema_settings_test.php';
require __DIR__.'/../class/powrsync.class.php';
function isModEnabled($module) { return true; }
function getEntity($element) { return '1,2'; }
$user = new class {
	public $admin = 1;
	public $socid = 0;
	public $allowed = true;
	public function hasRight(...$right) { return $this->allowed; }
};
$db->query('CREATE TABLE test_product (rowid INTEGER, entity INTEGER, ref TEXT, label TEXT)');
$db->query("INSERT INTO test_product VALUES (42, 1, 'shared-product', 'Shared')");
foreach (array('old_price REAL', 'new_price REAL', 'status TEXT', 'message TEXT', 'datec TEXT') as $column) {
	$db->query('ALTER TABLE test_powrsync_log ADD COLUMN '.$column);
}
$reflection = new ReflectionClass(PowrSync::class);
$module = $reflection->newInstanceWithoutConstructor();
$module->db = $db;
$rows = $module->getLogs();
if (count($rows) !== 1 || (int) $rows[0]->rowid !== 2) {
	throw new RuntimeException('Log read leaked foreign or quarantined records for a shared product');
}
$user->allowed = false;
if ($module->getLogs() !== array()) { throw new RuntimeException('Admin bypassed log permission'); }
if ($module->syncAllProducts() !== -1) { throw new RuntimeException('Admin bypassed synchronization permission'); }
$db->query('DROP TABLE test_powrsync_log');
$db->query('CREATE TABLE test_powrsync_log (rowid INTEGER, fk_product INTEGER)');
$module = $reflection->newInstanceWithoutConstructor();
$module->db = $db;
$user->allowed = true;
if ($module->getLogs() !== array() || $module->error !== 'PowrSync log schema must be upgraded') {
	throw new RuntimeException('Legacy schema read did not fail closed');
}
echo "Module log entity scope, admin permissions and legacy-schema refusal checks OK (SQLite adapter)\n";

// Exercise both actual log writers with the same test database.
$loggingDb = new class extends SchemaTestDb {
	public function escape($value) { return str_replace("'", "''", $value); }
};
$loggingDb->pdo = $db->pdo;
$db = $loggingDb;
$module->db = $db;
$logMethod = new ReflectionMethod(PowrSync::class, 'logSync');
$logMethod->invoke($module, 42, 'recipe', 1.23, 1.23, 'unchanged', '');

// The cron must stop before configuration reads, login, or price updates.
// Those APIs are deliberately not provided by this harness.
if ($module->syncAllProducts() !== -1 || $module->output !== 'PowrSync log schema must be upgraded') {
	throw new RuntimeException('Legacy schema synchronization did not fail before side effects');
}
$source = file_get_contents(__DIR__.'/../sync.php');
$start = strpos($source, 'function syncOneSupplierProductPrice(');
$end = strpos($source, 'function forceSupplierPriceVatRate(', $start);
$functionSource = substr($source, $start, $end - $start);
$functionSource = substr($functionSource, 0, strrpos($functionSource, '}') + 1);
eval($functionSource);
eval(substr($source, strpos($source, 'function insertPowrSyncLog(')));
insertPowrSyncLog($db, array('fk_product' => 42), $user, 1, 1.23, 1.23, '');
$langs = new class { public function trans($key) { return $key; } };
$scraper = new class {
	public $error = '';
	public $calls = 0;
	public function testConnectionAndGetPrice(...$args) { $this->calls++; throw new RuntimeException('supplier reached'); }
};
if (syncOneSupplierProductPrice($db, $scraper, array(), 1, $user, '', '') !== -1 || $scraper->calls !== 0) {
	throw new RuntimeException('Individual synchronization contacted supplier with legacy schema');
}
if ((int) $db->pdo->query('SELECT COUNT(*) FROM test_powrsync_log')->fetchColumn() !== 0) {
	throw new RuntimeException('Legacy schema refusal inserted a log');
}
$db->query('DROP TABLE test_powrsync_log');
if (PowrSync::isLogSchemaReady($db)) { throw new RuntimeException('Missing table accepted'); }
$db->query('CREATE TABLE test_powrsync_log (rowid INTEGER, fk_product INTEGER)');
if ($upgrade->invoke(new modPowrSync($db)) !== 1 || !PowrSync::isLogSchemaReady($db)) {
	throw new RuntimeException('Schema guard did not allow migrated table');
}
echo "Cron and individual legacy-schema refusal before side effects, missing table refusal and migration recovery OK (simulated)\n";

try {
	syncOneSupplierProductPrice($db, $scraper, array('ref_fourn' => 'recipe', 'supplier_url' => 'https://example.com/recipe', 'fk_product' => 42, 'unitprice' => 1.23), 1, $user, '', '');
	throw new RuntimeException('Migrated schema still blocked supplier access');
} catch (RuntimeException $error) {
	if ($error->getMessage() !== 'supplier reached' || $scraper->calls !== 1) { throw $error; }
}
echo "Individual synchronization resumes at supplier boundary after migration (supplier simulated)\n";

$logMethod->invoke($module, 42, 'recipe', 1.23, 1.23, 'unchanged', '');
insertPowrSyncLog($db, array('fk_product' => 42, 'ref_fourn' => 'recipe'), $user, 1, 1.23, 1.23, '');
$owners = $db->pdo->query('SELECT entity FROM test_powrsync_log')->fetchAll(PDO::FETCH_COLUMN);
if ($owners !== array(2, 2)) { throw new RuntimeException('New logs lost executing entity after migration'); }
echo "Both actual log writers refuse legacy schema and preserve executing entity after migration (SQLite adapter)\n";
