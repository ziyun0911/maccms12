<?php
namespace app\admin\controller;
use think\facade\Db;
use app\common\util\Dir;
use app\common\util\Database as dbOper;

class Database extends Base
{
    var $_db_config;
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $group = input('group');
        if($group=='import'){
            $path = trim( $GLOBALS['config']['db']['backup_path'], '/').DS;
            if (!is_dir($path)) {
                Dir::create($path);
            }
            $flag = \FilesystemIterator::KEY_AS_FILENAME;
            $glob = new \FilesystemIterator($path,  $flag);

            $list = [];
            foreach ($glob as $name => $file) {
                if(preg_match('/^\d{8,8}-\d{6,6}-\d+\.sql(?:\.gz)?$/', $name)){
                    $name = sscanf($name, '%4s%2s%2s-%2s%2s%2s-%d');
                    $date = "{$name[0]}-{$name[1]}-{$name[2]}";
                    $time = "{$name[3]}:{$name[4]}:{$name[5]}";
                    $part = $name[6];

                    if(isset($list["{$date} {$time}"])){
                        $info = $list["{$date} {$time}"];
                        $info['part'] = max($info['part'], $part);
                        $info['size'] = $info['size'] + $file->getSize();
                    } else {
                        $info['part'] = $part;
                        $info['size'] = $file->getSize();
                    }

                    $extension        = strtoupper($file->getExtension());
                    $info['compress'] = ($extension === 'SQL') ? '无' : $extension;
                    $info['time']     = strtotime("{$date} {$time}");

                    $list["{$date} {$time}"] = $info;
                }
            }
        }
        else{
            $group='export';
            $list = Db::query("SHOW TABLE STATUS");
        }

        $storage = $this->getUploadStorageInfo();
        $this->assign('list',$list);
        $this->assign('upload_storage_ready', $storage['ready']);
        $this->assign('upload_storage_mode', $storage['mode_name']);
        $this->assign('title',lang('admin/database/title'));
        return $this->fetch('admin@database/'.$group);
    }

    public function export($ids = '', $start = 0)
    {
        if ($this->request->isPost()) {
            if (empty($ids)) {
                return $this->error(lang('admin/database/select_export_table'));
            }

            if (!is_array($ids)) {
                $tables[] = $ids;
            } else {
                $tables = $ids;
            }
            $have_admin = false;
            $admin_table='';
            foreach($tables as $k=>$v){
                if(strpos($v,'_admin')!==false){
                    $have_admin=true;
                    $admin_table = $v;
                    unset($tables[$k]);
                }
            }
            if($have_admin){
                $tables[] = $admin_table;
            }

            $config = array(
                'path'     => $GLOBALS['config']['db']['backup_path'] .DS,
                'part'     => $GLOBALS['config']['db']['part_size'] ,
                'compress' => $GLOBALS['config']['db']['compress'] ,
                'level'    => $GLOBALS['config']['db']['compress_level'] ,
            );

            $lock = "{$config['path']}backup.lock";
            if(is_file($lock)){
                return $this->error(lang('admin/database/lock_check'));
            } else {
                if (!is_dir($config['path'])) {
                    Dir::create($config['path'], 0755, true);
                }
                file_put_contents($lock, $this->request->time());
            }

            $file = [
                'name' => date('Ymd-His', $this->request->time()),
                'part' => 1,
            ];

            $uploadStorage = input('upload_storage/d', 0);

            $database = new dbOper($file, $config);
            if($database->create() !== false) {
                foreach ($tables as $table) {
                    $start = $database->backup($table, $start);
                    while (0 !== $start) {
                        if (false === $start) {
                            @unlink($lock);
                            return $this->error(lang('admin/database/backup_err'));
                        }
                        $start = $database->backup($table, $start[0]);
                    }
                }
                unlink($lock);
            } else {
                @unlink($lock);
                return $this->error(lang('admin/database/backup_err'));
            }

            if ($uploadStorage) {
                $uploadRes = $this->uploadBackupByName($file['name']);
                if ($uploadRes['code'] !== 1) {
                    return $this->success(lang('admin/database/backup_ok') . ' ' . $uploadRes['msg']);
                }
                return $this->success(lang('admin/database/backup_ok_uploaded'));
            }
            return $this->success(lang('admin/database/backup_ok'));
        }
        return $this->error(lang('admin/database/backup_err'));
    }

    public function uploadStorage($id = '')
    {
        $id = intval($id);
        if ($id <= 0) {
            return $this->error(lang('admin/database/select_file'));
        }
        $name = date('Ymd-His', $id);
        if (!preg_match('/^\d{8}-\d{6}$/', $name)) {
            return $this->error(lang('admin/database/select_file'));
        }
        $res = $this->uploadBackupByName($name);
        if ($res['code'] !== 1) {
            return $this->error($res['msg']);
        }
        return $this->success(lang('admin/database/upload_storage_ok'));
    }

    public function import($id = '')
    {
        if (empty($id)) {
            return $this->error(lang('admin/database/select_file'));
        }

        $name  = date('Ymd-His', $id) . '-*.sql*';
        $path  = trim( $GLOBALS['config']['db']['backup_path'] , '/').DS.$name;
        $files = glob($path);
        $list  = array();
        foreach($files as $name){
            $basename = basename($name);
            $match    = sscanf($basename, '%4s%2s%2s-%2s%2s%2s-%d');
            $gz       = preg_match('/^\d{8,8}-\d{6,6}-\d+\.sql.gz$/', $basename);
            $list[$match[6]] = array($match[6], $name, $gz);
        }
        ksort($list);

        $last = end($list);
        if(count($list) === $last[0]){
            foreach ($list as $item) {
                $config = [
                    'path'     => trim($GLOBALS['config']['db']['backup_path'], '/').DS,
                    'compress' => $item[2]
                ];
                $database = new dbOper($item, $config);
                $start = $database->import(0);
                while (0 !== $start) {
                    if (false === $start) {
                        return $this->error(lang('admin/database/import_err'));
                    }
                    $start = $database->import($start[0]);
                }
            }
            return $this->success(lang('admin/database/import_ok'));
        }
        return $this->error(lang('admin/database/file_damage'));
    }

    public function optimize($ids = '')
    {
        if (empty($ids)) {
            return $this->error(lang('admin/database/select_optimize_table'));
        }

        if (!is_array($ids)) {
            $table[] = $ids;
        } else {
            $table = $ids;
        }

        foreach ($table as $t) {
            if (!$this->isValidTable($t)) {
                return $this->error('Table is invalid.');
            }
        }

        $tables = implode('`,`', $table);
        $res = Db::query("OPTIMIZE TABLE `{$tables}`");
        if ($res) {
            return $this->success(lang('admin/database/optimize_ok'));
        }
        return $this->error(lang('admin/database/optimize_err'));
    }

    public function repair($ids = '')
    {
        if (empty($ids)) {
            return $this->error(lang('admin/database/select_repair_table'));
        }

        if (!is_array($ids)) {
            $table[] = $ids;
        } else {
            $table = $ids;
        }

        foreach ($table as $t) {
            if (!$this->isValidTable($t)) {
                return $this->error('Table is invalid.');
            }
        }

        $tables = implode('`,`', $table);
        $res = Db::query("REPAIR TABLE `{$tables}`");
        if ($res) {
            return $this->success(lang('admin/database/repair_ok'));
        }
        return $this->error(lang('admin/database/repair_ok'));
    }

    public function del($id = '')
    {
        if (empty($id)) {
            return $this->error(lang('admin/database/select_del_file'));
        }

        $name  = date('Ymd-His', $id) . '-*.sql*';
        $path = trim($GLOBALS['config']['db']['backup_path']).DS.$name;
        array_map("unlink", glob($path));
        if(count(glob($path)) && glob($path)){
            return $this->error(lang('del_err'));
        }
        return $this->success(lang('del_ok'));
    }

    public function sql()
    {
        if($this->request->isPost()){
            $param=input();
            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($param)){
                return $this->error($validate->getError());
            }

            $sql = trim($param['sql']);

            if(!empty($sql)){
                $forbidden_keywords = ['into dumpfile', 'into outfile', 'char(', 'load_file'];
                foreach ($forbidden_keywords as $keyword) {
                    if (stripos($sql, $keyword) !== false) {
                        return $this->error(lang('format_err'));
                    }
                }
                $sql = str_replace('{pre}',config('database.connections.mysql.prefix'),$sql);
                if(
                    strtolower(substr($sql,0,6))=="select" || 
                    stripos($sql, ' outfile') !== false
                ){
                }
                else{
                    Db::execute($sql);
                }
            }
            return $this->success(lang('run_ok'));
        }
        $this->assign('title', lang('menu/database_sql'));
        return $this->fetch('admin@database/sql');
    }

    public function columns()
    {
        $param = input();
        $table = $param['table'];
        if (!empty($table) && !$this->isValidTable($table)) {
            return $this->error('Table is invalid.');
        }
        if (!empty($table)) {
            $list = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            return $this->success(lang('obtain_ok'),null, $list);
        }
        return $this->error(lang('param_err'));
    }

    public function rep()
    {
        if($this->request->isPost()){
            $param = input();
            $table = isset($param['table']) ? $param['table'] : '';
            $field = isset($param['field']) ? $param['field'] : '';
            $findstr = isset($param['findstr']) ? $param['findstr'] : '';
            $tostr = isset($param['tostr']) ? $param['tostr'] : '';
            $where = isset($param['where']) ? $param['where'] : '';

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($param)){
                return $this->error($validate->getError());
            }
            if ($table === '' || !$this->isValidTable($table)) {
                return $this->error('Table is invalid.');
            }
            if ($field === '' || $findstr === '' || $tostr === '') {
                return $this->error(lang('param_err'));
            }
            if (!$this->isValidField($table, $field)) {
                return $this->error('Column is invalid.');
            }
            $whereSql = $this->sanitizeRepWhereClause($where);
            if ($whereSql === false) {
                return $this->error('WHERE clause is invalid.');
            }
            $tq = '`' . str_replace('`', '``', $table) . '`';
            $fq = '`' . str_replace('`', '``', $field) . '`';
            $sql = 'UPDATE ' . $tq . ' SET ' . $fq . '=REPLACE(' . $fq . ', ?, ?) WHERE 1=1' . $whereSql;
            Db::execute($sql, [$findstr, $tostr]);
            return $this->success(lang('run_ok'));
        }
        $list = Db::query("SHOW TABLE STATUS");
        $this->assign('list',$list);
        $this->assign('title', lang('menu/database_rep'));
        return $this->fetch('admin@database/rep');
    }

    private function isValidTable($table) {
        $list = Db::query("SHOW TABLE STATUS");
        foreach ($list as $table_raw) {
            if ($table_raw['Name'] == $table) {
                return true;
            }
        }
        return false;
    }

    private function getUploadStorageInfo()
    {
        $config = (array)config('maccms.upload');
        $mode = isset($config['mode']) ? strtolower((string)$config['mode']) : 'local';
        $s3 = isset($config['api']['s3']) && is_array($config['api']['s3']) ? $config['api']['s3'] : [];
        $ready = ($mode === 's3'
            && !empty($s3['bucket'])
            && !empty($s3['accesskey'])
            && !empty($s3['secretkey']));
        return [
            'ready' => $ready,
            'mode' => 's3',
            'mode_name' => 'S3/MinIO/OSS',
            'config' => $config,
        ];
    }

    private function uploadBackupByName($backupName)
    {
        if (!is_string($backupName) || !preg_match('/^\d{8}-\d{6}$/', $backupName)) {
            return ['code' => 0, 'msg' => lang('admin/database/select_file')];
        }

        $storage = $this->getUploadStorageInfo();
        if (!$storage['ready']) {
            return ['code' => 0, 'msg' => lang('admin/database/upload_storage_not_ready')];
        }

        $path = rtrim(str_replace(['/', '\\'], DS, $GLOBALS['config']['db']['backup_path']), DS) . DS;
        $files = glob($path . $backupName . '-*.sql*');
        if (empty($files)) {
            return ['code' => 0, 'msg' => lang('admin/database/select_file')];
        }

        $uploadConfig = $storage['config'];
        $uploadConfig['mode'] = 's3';
        $uploadConfig['keep_local'] = 1;
        $uploadConfig['acl'] = false;

        $cp = 'app\\common\\extend\\upload\\S3';
        if (!class_exists($cp)) {
            return ['code' => 0, 'msg' => lang('admin/upload/not_find_extend')];
        }

        try {
            $driver = new $cp($uploadConfig);
            foreach ($files as $absFile) {
                $base = basename($absFile);
                if (!preg_match('/^\d{8}-\d{6}-\d+\.sql(?:\.gz)?$/', $base)) {
                    return ['code' => 0, 'msg' => lang('admin/database/upload_storage_fail')];
                }
                $rel = $this->toUploadRelativePath($absFile);
                if ($rel === '' || strpos($rel, '..') !== false) {
                    return ['code' => 0, 'msg' => lang('admin/database/upload_storage_fail')];
                }
                $url = $driver->submit($rel);
                if (!$this->isRemoteUploadResult($url, $rel)) {
                    return ['code' => 0, 'msg' => lang('admin/database/upload_storage_fail')];
                }
                if (!is_file($absFile) && !is_file(ROOT_PATH . $rel)) {
                    return ['code' => 0, 'msg' => lang('admin/database/upload_storage_fail')];
                }
            }
        } catch (\Throwable $e) {
            return ['code' => 0, 'msg' => lang('admin/database/upload_storage_fail')];
        }

        return ['code' => 1, 'msg' => 'ok'];
    }

    private function isRemoteUploadResult($url, $rel)
    {
        if (!is_string($url) || $url === '' || $url === $rel) {
            return false;
        }
        return (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0);
    }

    private function toUploadRelativePath($file)
    {
        $file = str_replace('\\', '/', $file);
        $root = str_replace('\\', '/', rtrim(ROOT_PATH, '/\\'));
        if (strpos($file, $root . '/') === 0) {
            return ltrim(substr($file, strlen($root)), '/');
        }
        $real = realpath($file);
        $rootReal = realpath(ROOT_PATH);
        if ($real && $rootReal) {
            $real = str_replace('\\', '/', $real);
            $rootReal = str_replace('\\', '/', $rootReal);
            if (strpos($real, $rootReal . '/') === 0) {
                return ltrim(substr($real, strlen($rootReal)), '/');
            }
        }
        return '';
    }

    private function isValidField($table, $field)
    {
        if (!is_string($field) || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
            return false;
        }
        $list = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $row) {
            if (!empty($row['Field']) && $row['Field'] === $field) {
                return true;
            }
        }
        return false;
    }

    private function sanitizeRepWhereClause($where)
    {
        $where = trim((string)$where);
        if ($where === '') {
            return '';
        }
        if (strlen($where) > 500) {
            return false;
        }
        $norm = preg_replace('/\s+/', ' ', strtolower($where));
        $blocked = [
            ';', '--', '/*', '*/', ' union ', ' select ', ' insert ', ' update ', ' delete ',
            ' drop ', ' create ', ' alter ', ' grant ', ' revoke ', ' exec ', ' execute ',
            'sleep(', 'benchmark(', 'load_file', 'outfile', 'dumpfile', ' information_schema',
            ' xor ', ' or 1', ' or true',
        ];
        foreach ($blocked as $b) {
            if (strpos($norm, $b) !== false) {
                return false;
            }
        }
        if (strncmp($norm, 'and ', 4) !== 0) {
            return false;
        }

        return ' ' . $where;
    }
}
