<?php
/** Isolated module regression checks. Run with: php tests/release-check.php */
namespace ExternalModules {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    class ExternalModules {
        public static array $design = [];
        public static bool $super = false;
        public static string $username = 'designer';
        static function getUsername() { return self::$username; }
        static function hasDesignRights($pid) { return self::$super || (self::$design[$pid] ?? false); }
        static function requireDesignRights($pid) {
            if (!self::hasDesignRights($pid)) throw new \Exception('Design rights required');
        }
    }
    class AbstractExternalModule {
        public $framework;
        public array $settings = [];
        public array $queries = [];
        public array $rows = [];
        public array $updates = [];
        public int $affectedRows = 1;
        public $project;
        function __construct() { $this->framework = $this; }
        function isSuperUser() { return ExternalModules::$super; }
        function getSystemSetting($key) { return $this->settings['system'][$key] ?? null; }
        function setSystemSetting($key, $value) { $this->settings['system'][$key] = $value; }
        function getProjectSetting($key, $pid) { return $this->settings[$pid][$key] ?? null; }
        function setProjectSetting($key, $value, $pid) { $this->settings[$pid][$key] = $value; }
        function log($message, $values) {}
        function saveFile($path, $pid) { return 900; }
        function query($sql, $params) {
            $this->queries[] = [$sql, $params];
            if (str_starts_with($sql, 'SELECT project_id, status')) return new \TestRows([$this->project]);
            if (str_starts_with($sql, 'SELECT e.doc_id')) return new \TestRows([[
                'doc_id'=>2, 'project_id'=>20, 'doc_name'=>'test.png', 'delete_date'=>null,
                'date_deleted_server'=>null, '__SALT__'=>'test', 'owner_project_exists'=>20, 'project_deleted'=>null
            ]]);
            if (str_starts_with($sql, 'SELECT ')) return new \TestRows((str_contains($sql, '`redcap_metadata`') || str_contains($sql, '`redcap_metadata_temp`')) ? $this->rows : []);
            if (in_array($sql, ['START TRANSACTION', 'ROLLBACK', 'COMMIT'], true)) return new \TestRows([]);
            throw new \Exception('Unexpected SQL in isolated check: '.$sql);
        }
        function createQuery() { return new \TestUpdate($this); }
    }
}
namespace {
    use ExternalModules\ExternalModules as EM;
    use DE\RUB\SEG\LegacyURLFixerExternalModule\LegacyURLFixerExternalModule as Module;
    define('APP_PATH_WEBROOT_FULL', 'https://test.invalid/redcap/');
    class TestRows {
        function __construct(private array $rows) {}
        function fetch_assoc() { return array_shift($this->rows); }
    }
    class TestUpdate {
        public int $affected_rows = 0;
        private array $statement;
        function __construct(private $module) {}
        function add($sql, $params) { $this->statement = [$sql, $params]; }
        function execute() {
            $this->module->updates[] = $this->statement;
            $this->affected_rows = $this->module->affectedRows;
        }
    }
    class Files {
        static function docIdHash($id, $salt = null) { return 'current'; }
        static function docIdHashLegacy($id, $salt = null) { return 'legacy'; }
    }
    class REDCap {
        static function addFileToRepository(...$args) { return true; }
        static function copyFile(...$args) { throw new \Exception('Unexpected file copy'); }
    }
    require dirname(__DIR__) . '/LegacyURLFixerExternalModule.php';
    set_error_handler(function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    $checks = 0;
    function check($condition, $message) {
        global $checks;
        if (!$condition) throw new \Exception('FAIL: '.$message);
        $checks++;
    }
    function invoke($module, $method, ...$args) {
        return (new \ReflectionMethod(Module::class, $method))->invoke($module, ...$args);
    }
    function rejects($callback, $fragment) {
        try { $callback(); } catch (\Exception $e) {
            check(str_contains($e->getMessage(), $fragment), $e->getMessage());
            return;
        }
        throw new \Exception('Expected rejection: '.$fragment);
    }
    function ajax($module, $action, $payload = [], $pid = 10) {
        return $module->redcap_module_ajax($action, $payload, $pid, ...array_fill(0, 12, null));
    }
    function fixture($status = 0, $draft = 0) {
        $module = new Module;
        $module->project = ['project_id'=>10, 'status'=>$status, 'draft_mode'=>$draft, '__SALT__'=>'test'];
        // Provide the schema explicitly; schema discovery itself is outside these checks.
        $cache = new \ReflectionProperty(Module::class, 'tableColumnsCache');
        $schemas = [];
        foreach (invoke($module, 'getScanSurfaces', $module->project) as $surface) {
            $schemas[$surface['table']] = array_fill_keys(array_merge($surface['keys'], $surface['columns'], ['project_id','survey_id','email_sent','consent_id']), true);
        }
        $cache->setValue($module, $schemas);
        return $module;
    }
    function cacheCross($module, $item) {
        $key = invoke($module, 'crossProjectCacheKey');
        $module->setProjectSetting($key, json_encode([
            'id'=>'scan', 'project_id'=>10, 'username'=>EM::$username,
            'project_status'=>$module->project['status'], 'draft_mode'=>$module->project['draft_mode'], 'items'=>[$item]
        ]), 10);
    }
    $link = 'https://test.invalid/redcap/DataEntry/image_view.php?id=2&doc_id_hash=legacy';
    $item = ['id'=>0, 'surface'=>'active-metadata', 'column'=>'element_label',
        'keys'=>['project_id'=>10,'field_name'=>'description'], 'checksum'=>hash('sha256',$link),
        'url_checksum'=>hash('sha256',$link), 'url_index'=>0, 'doc_id'=>2, 'owner_project_id'=>20, 'url_count'=>1];

    // Permission outcomes supplied by the framework: both scan and apply must gate on host Design rights.
    foreach (['scan','apply','cross-project-scan','cross-project-apply'] as $action) {
        $m=fixture(); EM::$design=[];
        rejects(fn()=>ajax($m,$action), 'Design rights');
        check($m->queries===[] && $m->updates===[], 'Unauthorized request must not query project content');
    }
    $m=fixture(); EM::$design=[10=>true];
    check(ajax($m,'status')===null, 'Ordinary designer can access project status');
    foreach (['cross-project-control-center-status','cross-project-control-center-scan','control-center-status'] as $action) {
        rejects(fn()=>ajax($m,$action,[],null), 'super user');
    }
    EM::$super=true;
    check(count(ajax($m,'cross-project-control-center-status',[],null)['surfaces'])>0, 'Superuser can access inventory');
    EM::$super=false;
    foreach ([false,true] as $ownerDesign) {
        $m=fixture(); EM::$design=[10=>true,20=>$ownerDesign];
        $m->rows=[['project_id'=>10,'field_name'=>'description','element_label'=>$link]];
        $report=ajax($m,'cross-project-scan');
        check(count($report['rows'])===($ownerDesign?1:0), 'Source Design rights filter report rows');
    }

    // Production active dictionaries must never be writable; draft dictionaries are separate targets.
    foreach ([[0,0],[1,0],[1,1]] as [$status,$draft]) {
        $m=fixture($status,$draft);
        $surfaces=invoke($m,'getScanSurfaces',$m->project);
        $active=array_values(array_filter($surfaces,fn($s)=>$s['table']==='redcap_metadata'))[0];
        check((bool)($active['read_only']??false)===($status!==0), 'Production active dictionary is read-only');
        $drafts=array_values(array_filter($surfaces,fn($s)=>$s['table']==='redcap_metadata_temp'));
        check(count($drafts)===($status!==0 && $draft===1?1:0), 'Draft target only in production Draft Mode');
        if ($drafts) check(!($drafts[0]['read_only']??false), 'Draft dictionary is writable');
        if ($status!==0) {
            check(invoke($m,'applyItem',$active,$item,$m->project)['result']==='skipped-read-only', 'Legacy repair refuses active production dictionary');
            $prodItem=$item; $prodItem['surface']=$active['id']; cacheCross($m,$prodItem);
            check(ajax($m,'cross-project-apply',['scan_id'=>'scan','mode'=>'relocate','selected'=>[0]])['outcomes'][0]['result']==='skipped-read-only', 'Relocation refuses active production dictionary');
            check($m->updates===[], 'No production active dictionary writes');
        }
    }

    // A later Draft Mode or project-status change invalidates both repair caches.
    foreach (['status','draft_mode'] as $changed) {
        $m=fixture(); cacheCross($m,$item);
        $m->setProjectSetting('scan-cache',json_encode(['id'=>'scan','project_id'=>10,'project_status'=>0,'draft_mode'=>0,'items'=>[],'stats'=>[]]),10);
        $m->project[$changed]=1;
        rejects(fn()=>invoke($m,'getCrossProjectScan',$m->project,'scan'), 'state changed');
        rejects(fn()=>invoke($m,'applyCachedScan',$m->project,'scan'), 'state changed');
    }
    foreach (['relocate','public'] as $mode) {
        $m=fixture(); cacheCross($m,$item); $m->rows=[['element_label'=>'Edited since scan']];
        $result=ajax($m,'cross-project-apply',['scan_id'=>'scan','mode'=>$mode,'selected'=>[0]]);
        check($result['outcomes'][0]['result']==='skipped-changed', 'Stale content skipped before either copy mode');
        check($m->updates===[], 'Stale content never written');
        $m=fixture(); cacheCross($m,$item); $m->rows=[['element_label'=>$link]];
        EM::$design=[10=>true,20=>false];
        $result=ajax($m,'cross-project-apply',['scan_id'=>'scan','mode'=>$mode,'selected'=>[0]]);
        check($result['outcomes'][0]['result']==='skipped-changed-or-rights', 'Revoked source rights rechecked at apply');
        check($m->updates===[], 'No writes after source rights revoked');
    }
    EM::$design=[10=>true,20=>true];
    $m=fixture(); $surface=invoke($m,'getScanSurfaces',$m->project)[0];
    $m->rows=[['element_label'=>'Changed']];
    check(invoke($m,'applyItem',$surface,$item,$m->project)['result']==='skipped-changed', 'Legacy stale checksum rejected');
    check($m->updates===[], 'Legacy stale checksum causes no write');
    // A competing edit after the initial read makes the conditional update affect zero rows.
    foreach ([0,1] as $affected) {
        $m=fixture(); $m->affectedRows=$affected;
        check(invoke($m,'writeCrossProjectCell',$surface,$item,$m->project,$link,'replacement')===($affected===1), 'Cross-project conditional write detects competing edit');
        check(str_contains($m->updates[0][0],'CAST(`element_label` AS BINARY) = CAST(? AS BINARY)') && in_array($link,$m->updates[0][1],true), 'Conditional write includes scanned value');
        check(end($m->queries)[0]===($affected===1?'COMMIT':'ROLLBACK'), 'Conditional write commits or rolls back');
        $m=fixture(); $m->affectedRows=$affected; $m->rows=[['element_label'=>$link]];
        $project=$m->project+['allow_cross_project_edoc_repair'=>true];
        check(invoke($m,'applyItem',$surface,$item,$project)['result']===($affected===1?'updated':'skipped-changed'), 'Legacy conditional write detects competing edit');
        check(str_contains($m->updates[0][0],'CAST(`element_label` AS BINARY) = CAST(? AS BINARY)'), 'Legacy write compares exact bytes');
    }
    $m=fixture(1,1);
    $draft=array_values(array_filter(invoke($m,'getScanSurfaces',$m->project),fn($s)=>$s['table']==='redcap_metadata_temp'))[0];
    $m->rows=[['element_label'=>$link]];
    check(invoke($m,'applyItem',$draft,$item,$m->project+['allow_cross_project_edoc_repair'=>true])['result']==='updated', 'Legacy repair can update production draft');
    check(str_starts_with($m->updates[0][0],'UPDATE `redcap_metadata_temp`'), 'Draft repair writes only draft table');
    check(invoke($m,'writeCrossProjectCell',$draft,$item,$m->project,$link,'replacement'), 'Relocation can write draft cell');
    check(str_starts_with($m->updates[1][0],'UPDATE `redcap_metadata_temp`'), 'Relocation targets only draft table');
    $m=fixture(); cacheCross($m,$item);
    rejects(fn()=>invoke($m,'getCrossProjectScan',$m->project,'wrong-scan'), 'no longer available');
    EM::$username='another-designer';
    rejects(fn()=>invoke($m,'getCrossProjectScan',$m->project,'scan'), 'no longer available');
    echo "PASS: $checks isolated release checks (permissions, Draft Mode, stale content, conditional writes, scan ownership).\n";
}
