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
