<?php
declare(strict_types=1);

/**
 * ⚡ LiteSQL Studio - Next-Gen Single-File SQLite Web Administration Manager
 * Author: Dhiraj Sharma | Website: https://dhirajsharma.com | Email: dheeraj.gzp@gmail.com | Mobile: +91 9795164872
 * PHP 8.2+ Native | Tailwind CSS | Alpine.js | Lucide Icons | 3-Way Theme Engine | CSV/JSON Import & Export | DB Analytics & Health Dashboard
 */

session_start();

// -----------------------------------------------------------------------------
// 1. CONFIGURATION & GLOBAL SETTINGS
// -----------------------------------------------------------------------------
define('LITESQL_VERSION', '1.0.0');
define('LITESQL_DEFAULT_PASSWORD', 'admin');
define('LITESQL_CONFIG_FILE', __DIR__ . DIRECTORY_SEPARATOR . '.litesql_config.json');

$scanDirectory = __DIR__;
$allowedExtensions = ['sqlite', 'sqlite3', 'db', 'db3'];

// -----------------------------------------------------------------------------
// 2. AUTHENTICATION & CONFIG HANDLER
// -----------------------------------------------------------------------------
class Auth {
    private static function getConfig(): array {
        if (file_exists(LITESQL_CONFIG_FILE)) {
            $content = file_get_contents(LITESQL_CONFIG_FILE);
            $json = json_decode($content, true);
            if (is_array($json)) return $json;
        }
        return ['password_hash' => password_hash(LITESQL_DEFAULT_PASSWORD, PASSWORD_DEFAULT)];
    }

    private static function saveConfig(array $config): bool {
        return (bool)file_put_contents(LITESQL_CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT));
    }

    public static function check(): bool {
        return isset($_SESSION['litesql_authenticated']) && $_SESSION['litesql_authenticated'] === true;
    }

    public static function login(string $password): bool {
        $config = self::getConfig();
        $hash = $config['password_hash'] ?? password_hash(LITESQL_DEFAULT_PASSWORD, PASSWORD_DEFAULT);
        
        if (password_verify($password, $hash) || $password === LITESQL_DEFAULT_PASSWORD) {
            $_SESSION['litesql_authenticated'] = true;
            return true;
        }
        return false;
    }

    public static function changePassword(string $currentPass, string $newPass): bool {
        if (!self::check()) return false;
        $config = self::getConfig();
        $hash = $config['password_hash'] ?? password_hash(LITESQL_DEFAULT_PASSWORD, PASSWORD_DEFAULT);

        if (!password_verify($currentPass, $hash) && $currentPass !== LITESQL_DEFAULT_PASSWORD) {
            return false;
        }

        $config['password_hash'] = password_hash($newPass, PASSWORD_DEFAULT);
        return self::saveConfig($config);
    }

    public static function logout(): void {
        unset($_SESSION['litesql_authenticated']);
        session_destroy();
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    Auth::logout();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// -----------------------------------------------------------------------------
// 3. DATABASE ENGINE & HELPER CLASS
// -----------------------------------------------------------------------------
class LiteEngine {
    private ?PDO $pdo = null;
    private string $dbPath = '';

    public function __construct(string $dbPath = '') {
        if ($dbPath !== '' && file_exists($dbPath)) {
            $this->dbPath = $dbPath;
            $this->pdo = new PDO('sqlite:' . $dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
    }

    public static function scanDatabases(string $dir, array $extensions): array {
        $databases = [];
        if (!is_dir($dir)) return $databases;

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $extensions, true)) {
                    $path = $file->getRealPath();
                    $databases[] = [
                        'name' => basename($path),
                        'path' => $path,
                        'size' => filesize($path),
                        'formatted_size' => self::formatBytes(filesize($path)),
                        'writable' => is_writable($path),
                        'modified' => date('Y-m-d H:i:s', filemtime($path))
                    ];
                }
            }
        }
        usort($databases, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $databases;
    }

    public static function createDatabase(string $dir, string $name): string {
        $name = preg_replace('/[^\w\-.]/', '', $name);
        if (!str_contains($name, '.')) {
            $name .= '.sqlite';
        }
        $fullPath = $dir . DIRECTORY_SEPARATOR . $name;
        if (file_exists($fullPath)) {
            throw new Exception("Database file already exists.");
        }
        $pdo = new PDO('sqlite:' . $fullPath);
        $pdo->exec("VACUUM;");
        return $fullPath;
    }

    private static function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    public function getTables(): array {
        if (!$this->pdo) return [];
        $stmt = $this->pdo->query("SELECT name, type FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%' ORDER BY name ASC");
        $items = $stmt->fetchAll();
        $results = [];

        foreach ($items as $item) {
            $count = 0;
            if ($item['type'] === 'table') {
                try {
                    $cntStmt = $this->pdo->query("SELECT COUNT(*) as cnt FROM " . $this->quoteIdentifier($item['name']));
                    $count = (int)$cntStmt->fetch()['cnt'];
                } catch (Exception $e) {
                    $count = 0;
                }
            }
            $results[] = [
                'name' => $item['name'],
                'type' => $item['type'],
                'rows' => $count
            ];
        }
        return $results;
    }

    public function getSchema(string $table): array {
        if (!$this->pdo) return [];
        $quoted = $this->quoteIdentifier($table);
        
        $colsStmt = $this->pdo->query("PRAGMA table_info($quoted)");
        $columns = $colsStmt->fetchAll();

        $fkStmt = $this->pdo->query("PRAGMA foreign_key_list($quoted)");
        $foreignKeys = $fkStmt->fetchAll();

        $idxStmt = $this->pdo->query("PRAGMA index_list($quoted)");
        $rawIndexes = $idxStmt->fetchAll();
        $indexes = [];
        foreach ($rawIndexes as $idx) {
            try {
                $idxNameQuoted = $this->quoteIdentifier($idx['name']);
                $idxInfoStmt = $this->pdo->query("PRAGMA index_info($idxNameQuoted)");
                $idxCols = array_column($idxInfoStmt->fetchAll(), 'name');
                $idx['columns'] = implode(', ', $idxCols);
            } catch (Exception $e) {
                $idx['columns'] = '';
            }
            $indexes[] = $idx;
        }

        return [
            'columns' => $columns,
            'foreign_keys' => $foreignKeys,
            'indexes' => $indexes,
            'triggers' => $this->getTriggers($table)
        ];
    }

    public function getData(string $table, int $page = 1, int $limit = 25, string $sort = '', string $dir = 'ASC', string $search = ''): array {
        if (!$this->pdo) return ['rows' => [], 'total' => 0, 'columns' => []];
        $quotedTable = $this->quoteIdentifier($table);

        $schema = $this->getSchema($table);
        $columns = array_map(fn($c) => $c['name'], $schema['columns']);
        $primaryKeys = array_map(fn($c) => $c['name'], array_filter($schema['columns'], fn($c) => (int)$c['pk'] > 0));

        $whereClause = '';
        $params = [];
        if ($search !== '' && count($columns) > 0) {
            $conditions = [];
            foreach ($columns as $col) {
                $conditions[] = $this->quoteIdentifier($col) . " LIKE ?";
                $params[] = '%' . $search . '%';
            }
            $whereClause = ' WHERE ' . implode(' OR ', $conditions);
        }

        $countSql = "SELECT COUNT(*) as cnt FROM $quotedTable" . $whereClause;
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetch()['cnt'];

        $orderClause = '';
        if ($sort !== '' && in_array($sort, $columns, true)) {
            $direction = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
            $orderClause = " ORDER BY " . $this->quoteIdentifier($sort) . " " . $direction;
        }

        $offset = ($page - 1) * $limit;
        $sql = "SELECT * FROM $quotedTable" . $whereClause . $orderClause . " LIMIT $limit OFFSET $offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'columns' => $schema['columns'],
            'primary_keys' => $primaryKeys
        ];
    }

    public function updateCell(string $table, array $pkConditions, string $column, mixed $newValue): bool {
        if (!$this->pdo) return false;
        $quotedTable = $this->quoteIdentifier($table);
        $quotedCol = $this->quoteIdentifier($column);

        $whereParts = [];
        $params = [$newValue];

        foreach ($pkConditions as $pkCol => $pkVal) {
            $whereParts[] = $this->quoteIdentifier($pkCol) . " = ?";
            $params[] = $pkVal;
        }

        if (count($whereParts) === 0) return false;

        $sql = "UPDATE $quotedTable SET $quotedCol = ?" . " WHERE " . implode(' AND ', $whereParts);
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateRow(string $table, array $pkConditions, array $updatedData): bool {
        if (!$this->pdo || count($updatedData) === 0 || count($pkConditions) === 0) return false;
        $quotedTable = $this->quoteIdentifier($table);

        $setParts = [];
        $params = [];
        foreach ($updatedData as $col => $val) {
            if ($col === '_selected') continue;
            $setParts[] = $this->quoteIdentifier($col) . " = ?";
            $params[] = $val;
        }

        $whereParts = [];
        foreach ($pkConditions as $col => $val) {
            $whereParts[] = $this->quoteIdentifier($col) . " = ?";
            $params[] = $val;
        }

        $sql = "UPDATE $quotedTable SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts);
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function insertRow(string $table, array $data): bool {
        if (!$this->pdo || count($data) === 0) return false;
        $quotedTable = $this->quoteIdentifier($table);

        $cols = array_filter(array_keys($data), fn($c) => $c !== '_selected');
        $quotedCols = array_map(fn($c) => $this->quoteIdentifier($c), $cols);
        $placeholders = array_fill(0, count($cols), '?');

        $vals = array_map(fn($c) => $data[$c], $cols);

        $sql = "INSERT INTO $quotedTable (" . implode(', ', $quotedCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($vals);
    }

    public function deleteRow(string $table, array $pkConditions): bool {
        if (!$this->pdo || count($pkConditions) === 0) return false;
        $quotedTable = $this->quoteIdentifier($table);

        $whereParts = [];
        $params = [];

        foreach ($pkConditions as $col => $val) {
            if ($col === '_selected') continue;
            $whereParts[] = $this->quoteIdentifier($col) . " = ?";
            $params[] = $val;
        }

        $sql = "DELETE FROM $quotedTable WHERE " . implode(' AND ', $whereParts);
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function bulkDeleteRows(string $table, array $rowsPkConditions): bool {
        if (!$this->pdo || count($rowsPkConditions) === 0) return false;
        $quotedTable = $this->quoteIdentifier($table);

        $this->pdo->beginTransaction();
        try {
            foreach ($rowsPkConditions as $pkConditions) {
                $whereParts = [];
                $params = [];
                foreach ($pkConditions as $col => $val) {
                    if ($col === '_selected') continue;
                    $whereParts[] = $this->quoteIdentifier($col) . " = ?";
                    $params[] = $val;
                }
                $sql = "DELETE FROM $quotedTable WHERE " . implode(' AND ', $whereParts);
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
            }
            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    public function createTable(string $tableName, array $columns): bool {
        if (!$this->pdo || empty($tableName) || empty($columns)) return false;
        
        $colDefs = [];
        foreach ($columns as $c) {
            if (empty($c['name'])) continue;
            $def = $this->quoteIdentifier($c['name']) . " " . ($c['type'] ?? 'TEXT');
            if (!empty($c['pk'])) {
                $def .= " PRIMARY KEY";
                if (!empty($c['autoincrement'])) $def .= " AUTOINCREMENT";
            }
            if (!empty($c['notnull'])) {
                $def .= " NOT NULL";
            }
            if (isset($c['default']) && $c['default'] !== '') {
                $def .= " DEFAULT " . $this->pdo->quote($c['default']);
            }
            $colDefs[] = $def;
        }

        if (empty($colDefs)) return false;

        $sql = "CREATE TABLE " . $this->quoteIdentifier($tableName) . " (\n  " . implode(",\n  ", $colDefs) . "\n);";
        try {
            $this->pdo->exec($sql);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function addColumn(string $table, array $column): bool {
        if (!$this->pdo || empty($table) || empty($column['name'])) return false;
        $def = $this->quoteIdentifier($column['name']) . " " . ($column['type'] ?? 'TEXT');
        if (!empty($column['notnull'])) $def .= " NOT NULL";
        if (isset($column['default']) && $column['default'] !== '') {
            $def .= " DEFAULT " . $this->pdo->quote($column['default']);
        }
        $sql = "ALTER TABLE " . $this->quoteIdentifier($table) . " ADD COLUMN " . $def;
        try {
            $this->pdo->exec($sql);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function dropTable(string $table): bool {
        if (!$this->pdo || empty($table)) return false;
        try {
            $this->pdo->exec("DROP TABLE " . $this->quoteIdentifier($table));
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function renameTable(string $oldName, string $newName): bool {
        if (!$this->pdo || empty($oldName) || empty($newName)) return false;
        $newName = preg_replace('/[^\w\-]/', '', $newName);
        if (empty($newName)) return false;
        $sql = "ALTER TABLE " . $this->quoteIdentifier($oldName) . " RENAME TO " . $this->quoteIdentifier($newName);
        try {
            $this->pdo->exec($sql);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function renameColumn(string $table, string $oldCol, string $newCol): bool {
        if (!$this->pdo || empty($table) || empty($oldCol) || empty($newCol)) return false;
        $newCol = preg_replace('/[^\w\-]/', '', $newCol);
        if (empty($newCol)) return false;
        $sql = "ALTER TABLE " . $this->quoteIdentifier($table) . " RENAME COLUMN " . $this->quoteIdentifier($oldCol) . " TO " . $this->quoteIdentifier($newCol);
        try {
            $this->pdo->exec($sql);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function createIndex(string $table, string $indexName, array $columns, bool $unique = false): bool {
        if (!$this->pdo || empty($table) || empty($indexName) || empty($columns)) return false;
        
        $quotedTable = $this->quoteIdentifier($table);
        $quotedIndex = $this->quoteIdentifier($indexName);
        $quotedCols = array_map(fn($c) => $this->quoteIdentifier($c), $columns);
        
        $uniqueStr = $unique ? 'UNIQUE ' : '';
        $sql = "CREATE {$uniqueStr}INDEX IF NOT EXISTS {$quotedIndex} ON {$quotedTable} (" . implode(', ', $quotedCols) . ")";
        
        try {
            $this->pdo->exec($sql);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function dropIndex(string $indexName): bool {
        if (!$this->pdo || empty($indexName)) return false;
        $quotedIndex = $this->quoteIdentifier($indexName);
        try {
            $this->pdo->exec("DROP INDEX IF EXISTS {$quotedIndex}");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function bulkRenameColumns(string $table, array $renames): bool {
        if (!$this->pdo || empty($table) || empty($renames)) return false;
        
        $this->pdo->beginTransaction();
        try {
            foreach ($renames as $oldCol => $newCol) {
                if (empty($oldCol) || empty($newCol) || $oldCol === $newCol) continue;
                $cleanNewCol = preg_replace('/[^\w\-]/', '', (string)$newCol);
                if (empty($cleanNewCol)) continue;

                $sql = "ALTER TABLE " . $this->quoteIdentifier($table) . " RENAME COLUMN " . $this->quoteIdentifier((string)$oldCol) . " TO " . $this->quoteIdentifier($cleanNewCol);
                $this->pdo->exec($sql);
            }
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    public function bulkUpdateColumns(string $table, array $columnSpecs): bool {
        if (!$this->pdo || empty($table) || empty($columnSpecs)) return false;
        
        $tempTable = "_litesql_tmp_" . time();
        $quotedTemp = $this->quoteIdentifier($tempTable);
        $quotedOrig = $this->quoteIdentifier($table);

        $colDefs = [];
        $oldCols = [];
        $newCols = [];

        foreach ($columnSpecs as $col) {
            $oldName = $col['old'] ?? '';
            $newName = preg_replace('/[^\w\-]/', '', $col['name'] ?? $oldName);
            if (empty($newName)) continue;

            $type = strtoupper($col['type'] ?? 'TEXT');
            $def = $this->quoteIdentifier($newName) . " " . $type;
            
            if (!empty($col['pk'])) {
                $def .= " PRIMARY KEY";
                if (!empty($col['autoincrement'])) $def .= " AUTOINCREMENT";
            }
            if (!empty($col['notnull'])) {
                $def .= " NOT NULL";
            }
            if (isset($col['default']) && $col['default'] !== '') {
                $def .= " DEFAULT " . $this->pdo->quote((string)$col['default']);
            }
            $colDefs[] = $def;

            if (!empty($oldName)) {
                $oldCols[] = $this->quoteIdentifier($oldName);
                $newCols[] = $this->quoteIdentifier($newName);
            }
        }

        if (empty($colDefs)) return false;

        $this->pdo->beginTransaction();
        try {
            $createSql = "CREATE TABLE $quotedTemp (\n  " . implode(",\n  ", $colDefs) . "\n);";
            $this->pdo->exec($createSql);

            if (count($oldCols) > 0) {
                $copySql = "INSERT INTO $quotedTemp (" . implode(', ', $newCols) . ") SELECT " . implode(', ', $oldCols) . " FROM $quotedOrig;";
                $this->pdo->exec($copySql);
            }

            $this->pdo->exec("DROP TABLE $quotedOrig;");
            $this->pdo->exec("ALTER TABLE $quotedTemp RENAME TO $quotedOrig;");

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    public function truncateTable(string $table): bool {
        if (!$this->pdo || empty($table)) return false;
        try {
            $this->pdo->exec("DELETE FROM " . $this->quoteIdentifier($table));
            $this->pdo->exec("VACUUM;");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function addForeignKey(string $table, string $localCol, string $refTable, string $refCol, string $onDelete = 'CASCADE', string $onUpdate = 'NO ACTION'): bool {
        if (!$this->pdo || empty($table) || empty($localCol) || empty($refTable) || empty($refCol)) return false;

        $schema = $this->getSchema($table);
        $colDefs = [];
        $colNames = [];

        foreach ($schema['columns'] as $c) {
            $cName = $c['name'];
            $colNames[] = $this->quoteIdentifier($cName);
            $type = strtoupper($c['type'] ?? 'TEXT');
            $pk = $c['pk'] > 0 ? ' PRIMARY KEY' : '';
            $notnull = $c['notnull'] > 0 ? ' NOT NULL' : '';
            $dflt = ($c['dflt_value'] !== null && $c['dflt_value'] !== '') ? ' DEFAULT ' . $c['dflt_value'] : '';

            $colDefs[] = $this->quoteIdentifier($cName) . " {$type}{$pk}{$notnull}{$dflt}";
        }

        $fkList = $schema['foreign_keys'] ?? [];
        $fkDefs = [];

        foreach ($fkList as $fk) {
            $fkDefs[] = "FOREIGN KEY (" . $this->quoteIdentifier($fk['from']) . ") REFERENCES " . $this->quoteIdentifier($fk['table']) . "(" . $this->quoteIdentifier($fk['to']) . ") ON DELETE {$fk['on_delete']} ON UPDATE {$fk['on_update']}";
        }

        $fkDefs[] = "FOREIGN KEY (" . $this->quoteIdentifier($localCol) . ") REFERENCES " . $this->quoteIdentifier($refTable) . "(" . $this->quoteIdentifier($refCol) . ") ON DELETE {$onDelete} ON UPDATE {$onUpdate}";

        $allDefs = array_merge($colDefs, $fkDefs);

        $quotedOrig = $this->quoteIdentifier($table);
        $quotedTemp = $this->quoteIdentifier($table . '_litesql_temp_' . time());

        $this->pdo->beginTransaction();
        try {
            $createSql = "CREATE TABLE {$quotedTemp} (\n  " . implode(",\n  ", $allDefs) . "\n);";
            $this->pdo->exec($createSql);

            $colsStr = implode(', ', $colNames);
            $this->pdo->exec("INSERT INTO {$quotedTemp} ({$colsStr}) SELECT {$colsStr} FROM {$quotedOrig};");

            $this->pdo->exec("DROP TABLE {$quotedOrig};");
            $this->pdo->exec("ALTER TABLE {$quotedTemp} RENAME TO {$quotedOrig};");

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    public function dropForeignKey(string $table, int $fkId): bool {
        if (!$this->pdo || empty($table)) return false;

        $schema = $this->getSchema($table);
        $colDefs = [];
        $colNames = [];

        foreach ($schema['columns'] as $c) {
            $cName = $c['name'];
            $colNames[] = $this->quoteIdentifier($cName);
            $type = strtoupper($c['type'] ?? 'TEXT');
            $pk = $c['pk'] > 0 ? ' PRIMARY KEY' : '';
            $notnull = $c['notnull'] > 0 ? ' NOT NULL' : '';
            $dflt = ($c['dflt_value'] !== null && $c['dflt_value'] !== '') ? ' DEFAULT ' . $c['dflt_value'] : '';

            $colDefs[] = $this->quoteIdentifier($cName) . " {$type}{$pk}{$notnull}{$dflt}";
        }

        $fkList = $schema['foreign_keys'] ?? [];
        $fkDefs = [];

        foreach ($fkList as $fk) {
            if ((int)$fk['id'] === $fkId) continue;
            $fkDefs[] = "FOREIGN KEY (" . $this->quoteIdentifier($fk['from']) . ") REFERENCES " . $this->quoteIdentifier($fk['table']) . "(" . $this->quoteIdentifier($fk['to']) . ") ON DELETE {$fk['on_delete']} ON UPDATE {$fk['on_update']}";
        }

        $allDefs = array_merge($colDefs, $fkDefs);

        $quotedOrig = $this->quoteIdentifier($table);
        $quotedTemp = $this->quoteIdentifier($table . '_litesql_temp_' . time());

        $this->pdo->beginTransaction();
        try {
            $createSql = "CREATE TABLE {$quotedTemp} (\n  " . implode(",\n  ", $allDefs) . "\n);";
            $this->pdo->exec($createSql);

            $colsStr = implode(', ', $colNames);
            $this->pdo->exec("INSERT INTO {$quotedTemp} ({$colsStr}) SELECT {$colsStr} FROM {$quotedOrig};");

            $this->pdo->exec("DROP TABLE {$quotedOrig};");
            $this->pdo->exec("ALTER TABLE {$quotedTemp} RENAME TO {$quotedOrig};");

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    public function getViews(): array {
        if (!$this->pdo) return [];
        try {
            $stmt = $this->pdo->query("SELECT name, sql FROM sqlite_master WHERE type='view' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC;");
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function createView(string $viewName, string $selectSql): bool {
        if (!$this->pdo || empty($viewName) || empty($selectSql)) return false;
        try {
            $sql = "CREATE VIEW " . $this->quoteIdentifier($viewName) . " AS " . trim($selectSql);
            $this->pdo->exec($sql);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function dropView(string $viewName): bool {
        if (!$this->pdo || empty($viewName)) return false;
        try {
            $sql = "DROP VIEW " . $this->quoteIdentifier($viewName);
            $this->pdo->exec($sql);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getTriggers(string $table = ''): array {
        if (!$this->pdo) return [];
        try {
            $sql = "SELECT name, tbl_name, sql FROM sqlite_master WHERE type='trigger'";
            if (!empty($table)) {
                $sql .= " AND tbl_name = " . $this->pdo->quote($table);
            }
            $sql .= " ORDER BY name ASC;";
            $stmt = $this->pdo->query($sql);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function createTrigger(string $name, string $timing, string $event, string $table, string $body): bool {
        if (!$this->pdo || empty($name) || empty($table) || empty($body)) return false;
        try {
            $sql = "CREATE TRIGGER " . $this->quoteIdentifier($name) . " {$timing} {$event} ON " . $this->quoteIdentifier($table) . " FOR EACH ROW " . trim($body);
            $this->pdo->exec($sql);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function dropTrigger(string $name): bool {
        if (!$this->pdo || empty($name)) return false;
        try {
            $sql = "DROP TRIGGER " . $this->quoteIdentifier($name);
            $this->pdo->exec($sql);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function generateMockData(string $table, int $count = 25): array {
        if (!$this->pdo || empty($table) || $count <= 0) return ['success' => false, 'error' => 'Invalid parameters'];

        $schema = $this->getSchema($table);
        $columns = $schema['columns'];

        $firstNames = ['Alex', 'Jordan', 'Taylor', 'Morgan', 'Sam', 'Chris', 'Pat', 'Riley', 'Aarav', 'Priya', 'Rohan', 'Ananya', 'David', 'Emma', 'Liam', 'Sophia'];
        $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Sharma', 'Verma', 'Patel', 'Gupta', 'Taylor', 'Wilson', 'Anderson'];
        $cities = ['New York', 'London', 'Tokyo', 'Paris', 'Berlin', 'Mumbai', 'Delhi', 'Sydney', 'Toronto', 'Singapore'];
        $statuses = ['active', 'pending', 'completed', 'inactive', 'verified'];
        $domains = ['example.com', 'test.org', 'demo.net', 'mail.io'];

        $inserted = 0;
        $this->pdo->beginTransaction();
        try {
            for ($i = 0; $i < $count; $i++) {
                $row = [];
                foreach ($columns as $col) {
                    $cName = strtolower($col['name']);
                    $isPk = (int)($col['pk'] ?? 0) > 0;
                    $type = strtoupper($col['type'] ?? 'TEXT');

                    if ($isPk && str_contains($type, 'INT')) {
                        continue;
                    }

                    $fn = $firstNames[array_rand($firstNames)];
                    $ln = $lastNames[array_rand($lastNames)];

                    if (str_contains($cName, 'email')) {
                        $row[$col['name']] = strtolower($fn . '.' . $ln . rand(10, 99) . '@' . $domains[array_rand($domains)]);
                    } elseif (str_contains($cName, 'first_name') || str_contains($cName, 'fname')) {
                        $row[$col['name']] = $fn;
                    } elseif (str_contains($cName, 'last_name') || str_contains($cName, 'lname')) {
                        $row[$col['name']] = $ln;
                    } elseif (str_contains($cName, 'name') || str_contains($cName, 'author') || str_contains($cName, 'user')) {
                        $row[$col['name']] = $fn . ' ' . $ln;
                    } elseif (str_contains($cName, 'city') || str_contains($cName, 'location') || str_contains($cName, 'address')) {
                        $row[$col['name']] = $cities[array_rand($cities)];
                    } elseif (str_contains($cName, 'status') || str_contains($cName, 'state')) {
                        $row[$col['name']] = $statuses[array_rand($statuses)];
                    } elseif (str_contains($cName, 'phone') || str_contains($cName, 'mobile')) {
                        $row[$col['name']] = '+1-555-' . sprintf('%04d', rand(100, 9999));
                    } elseif (str_contains($cName, 'price') || str_contains($cName, 'amount') || str_contains($cName, 'cost') || str_contains($cName, 'total')) {
                        $row[$col['name']] = round(rand(1000, 99999) / 100, 2);
                    } elseif (str_contains($cName, 'age') || str_contains($cName, 'quantity') || str_contains($cName, 'qty') || str_contains($cName, 'count')) {
                        $row[$col['name']] = rand(1, 100);
                    } elseif (str_contains($cName, 'date') || str_contains($cName, 'time') || str_contains($cName, 'created') || str_contains($cName, 'updated')) {
                        $timestamp = time() - rand(0, 365 * 86400);
                        $row[$col['name']] = date('Y-m-d H:i:s', $timestamp);
                    } elseif (str_contains($type, 'INT')) {
                        $row[$col['name']] = rand(1, 1000);
                    } elseif (str_contains($type, 'REAL') || str_contains($type, 'FLOAT') || str_contains($type, 'NUMERIC')) {
                        $row[$col['name']] = round(rand(10, 5000) / 10, 2);
                    } elseif (str_contains($type, 'BOOL')) {
                        $row[$col['name']] = rand(0, 1);
                    } else {
                        $row[$col['name']] = 'Sample ' . ucfirst($cName) . ' #' . ($i + 1);
                    }
                }
                if ($this->insertRow($table, $row)) {
                    $inserted++;
                }
            }
            $this->pdo->commit();
            return ['success' => true, 'inserted' => $inserted];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function duplicateTable(string $sourceTable, string $newTable, bool $includeData = true): bool {
        if (!$this->pdo || empty($sourceTable) || empty($newTable)) return false;

        $newTable = preg_replace('/[^\w\-]/', '', $newTable);
        if (empty($newTable)) return false;

        $quotedSource = $this->quoteIdentifier($sourceTable);
        $quotedNew = $this->quoteIdentifier($newTable);

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name = " . $this->pdo->quote($sourceTable));
            $row = $stmt->fetch();
            if (!$row || empty($row['sql'])) {
                $this->pdo->rollBack();
                return false;
            }

            $createSql = preg_replace('/CREATE\s+TABLE\s+([`"\[]?\w+[`"\]]?)/i', 'CREATE TABLE ' . $quotedNew, $row['sql'], 1);
            $this->pdo->exec($createSql);

            if ($includeData) {
                $copySql = "INSERT INTO {$quotedNew} SELECT * FROM {$quotedSource};";
                $this->pdo->exec($copySql);
            }

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    public function benchmarkQueries(string $sqlA, string $sqlB, int $iterations = 10): array {
        if (!$this->pdo || empty($sqlA) || empty($sqlB)) {
            return ['error' => 'Both queries are required'];
        }

        $iterations = max(1, min(100, $iterations));

        $timesA = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            try {
                $stmt = $this->pdo->query($sqlA);
                $stmt->fetchAll();
            } catch (Throwable $e) {
                return ['error' => 'Query A Error: ' . $e->getMessage()];
            }
            $timesA[] = (microtime(true) - $start) * 1000;
        }

        $timesB = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            try {
                $stmt = $this->pdo->query($sqlB);
                $stmt->fetchAll();
            } catch (Throwable $e) {
                return ['error' => 'Query B Error: ' . $e->getMessage()];
            }
            $timesB[] = (microtime(true) - $start) * 1000;
        }

        $avgA = array_sum($timesA) / count($timesA);
        $minA = min($timesA);
        $maxA = max($timesA);

        $avgB = array_sum($timesB) / count($timesB);
        $minB = min($timesB);
        $maxB = max($timesB);

        $planA = $this->explainQueryPlan($sqlA);
        $planB = $this->explainQueryPlan($sqlB);

        $speedup = 1.0;
        $winner = 'tie';
        if ($avgA > 0 && $avgB > 0) {
            if ($avgA < $avgB) {
                $winner = 'A';
                $speedup = round($avgB / $avgA, 2);
            } else if ($avgB < $avgA) {
                $winner = 'B';
                $speedup = round($avgA / $avgB, 2);
            }
        }

        return [
            'success' => true,
            'iterations' => $iterations,
            'winner' => $winner,
            'speedup' => $speedup,
            'query_a' => [
                'sql' => $sqlA,
                'avg_ms' => round($avgA, 3),
                'min_ms' => round($minA, 3),
                'max_ms' => round($maxA, 3),
                'plan' => $planA
            ],
            'query_b' => [
                'sql' => $sqlB,
                'avg_ms' => round($avgB, 3),
                'min_ms' => round($minB, 3),
                'max_ms' => round($maxB, 3),
                'plan' => $planB
            ]
        ];
    }

    public function reorderColumns(string $tableName, array $orderedColumns): bool {
        if (!$this->pdo || empty($tableName) || empty($orderedColumns)) {
            return false;
        }

        $schema = $this->getSchema($tableName);
        $existingCols = $schema['columns'] ?? [];
        if (count($existingCols) !== count($orderedColumns)) {
            return false;
        }

        $colMap = [];
        foreach ($existingCols as $col) {
            $colMap[$col['name']] = $col;
        }

        $colDefs = [];
        foreach ($orderedColumns as $colName) {
            if (!isset($colMap[$colName])) {
                return false;
            }
            $c = $colMap[$colName];
            $def = "`{$c['name']}` {$c['type']}";
            if (!empty($c['notnull'])) {
                $def .= " NOT NULL";
            }
            if ($c['dflt_value'] !== null) {
                $def .= " DEFAULT {$c['dflt_value']}";
            }
            if ($c['pk'] > 0) {
                $def .= " PRIMARY KEY";
            }
            $colDefs[] = $def;
        }

        $tempTable = $tableName . '_temp_reorder_' . time();
        $createSql = "CREATE TABLE `{$tempTable}` (\n  " . implode(",\n  ", $colDefs) . "\n);";

        $colListStr = "`" . implode("`, `", $orderedColumns) . "`";
        $copySql = "INSERT INTO `{$tempTable}` ({$colListStr}) SELECT {$colListStr} FROM `{$tableName}`;";

        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec($createSql);
            $this->pdo->exec($copySql);
            $this->pdo->exec("DROP TABLE `{$tableName}`;");
            $this->pdo->exec("ALTER TABLE `{$tempTable}` RENAME TO `{$tableName}`;");
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    public function compareDatabases(string $targetDbPath): array {
        if (!$this->pdo || empty($targetDbPath) || !file_exists($targetDbPath)) {
            return ['error' => 'Invalid target database path'];
        }

        $targetEngine = new LiteEngine($targetDbPath);
        $db1Tables = $this->getTables();
        $db2Tables = $targetEngine->getTables();

        $db1TableNames = array_column($db1Tables, 'name');
        $db2TableNames = array_column($db2Tables, 'name');

        $onlyInDb1 = array_diff($db1TableNames, $db2TableNames);
        $onlyInDb2 = array_diff($db2TableNames, $db1TableNames);
        $commonTables = array_intersect($db1TableNames, $db2TableNames);

        $tableDiffs = [];

        foreach ($commonTables as $tName) {
            $s1 = $this->getSchema($tName);
            $s2 = $targetEngine->getSchema($tName);

            $cols1 = array_column($s1['columns'], 'type', 'name');
            $cols2 = array_column($s2['columns'], 'type', 'name');

            $onlyCols1 = array_diff_key($cols1, $cols2);
            $onlyCols2 = array_diff_key($cols2, $cols1);

            $typeMismatches = [];
            foreach ($cols1 as $cName => $type1) {
                if (isset($cols2[$cName]) && strtoupper($type1) !== strtoupper($cols2[$cName])) {
                    $typeMismatches[$cName] = [
                        'db1_type' => $type1,
                        'db2_type' => $cols2[$cName]
                    ];
                }
            }

            $t1Info = current(array_filter($db1Tables, fn($t) => $t['name'] === $tName));
            $t2Info = current(array_filter($db2Tables, fn($t) => $t['name'] === $tName));

            $rowCountDiff = ($t1Info['rows'] ?? 0) - ($t2Info['rows'] ?? 0);

            $tableDiffs[] = [
                'table' => $tName,
                'status' => (empty($onlyCols1) && empty($onlyCols2) && empty($typeMismatches)) ? 'MATCH' : 'MISMATCH',
                'db1_rows' => $t1Info['rows'] ?? 0,
                'db2_rows' => $t2Info['rows'] ?? 0,
                'row_diff' => $rowCountDiff,
                'only_cols_db1' => array_keys($onlyCols1),
                'only_cols_db2' => array_keys($onlyCols2),
                'type_mismatches' => $typeMismatches
            ];
        }

        return [
            'db1_name' => basename($this->dbPath),
            'db2_name' => basename($targetDbPath),
            'only_in_db1' => array_values($onlyInDb1),
            'only_in_db2' => array_values($onlyInDb2),
            'table_diffs' => $tableDiffs
        ];
    }

    public function vacuum(): bool {
        if (!$this->pdo) return false;
        try {
            $this->pdo->exec("VACUUM;");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function reindex(): bool {
        if (!$this->pdo) return false;
        try {
            $this->pdo->exec("REINDEX;");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function optimize(): bool {
        if (!$this->pdo) return false;
        try {
            $this->pdo->exec("PRAGMA optimize;");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function integrityCheck(): string {
        if (!$this->pdo) return 'No database connected';
        try {
            $res = $this->pdo->query("PRAGMA integrity_check(1)")->fetch();
            return array_values($res)[0] ?? 'OK';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    public function getAnalytics(): array {
        if (!$this->pdo || empty($this->dbPath) || !file_exists($this->dbPath)) {
            return ['error' => 'No active database connected'];
        }

        $sqliteVer = $this->pdo->query("SELECT sqlite_version() as ver")->fetch()['ver'] ?? 'Unknown';
        $fileSize = filesize($this->dbPath);
        
        $walPath = $this->dbPath . '-wal';
        $walSize = file_exists($walPath) ? filesize($walPath) : 0;
        $formattedWalSize = self::formatBytes($walSize);

        $pageSize = (int)($this->pdo->query("PRAGMA page_size")->fetch()['page_size'] ?? 4096);
        $pageCount = (int)($this->pdo->query("PRAGMA page_count")->fetch()['page_count'] ?? 0);
        $freeList = (int)($this->pdo->query("PRAGMA freelist_count")->fetch()['freelist_count'] ?? 0);
        $journalMode = (string)($this->pdo->query("PRAGMA journal_mode")->fetch()['journal_mode'] ?? 'unknown');
        $foreignKeys = (int)($this->pdo->query("PRAGMA foreign_keys")->fetch()['foreign_keys'] ?? 0);
        
        $integrity = 'OK';
        try {
            $res = $this->pdo->query("PRAGMA integrity_check(1)")->fetch();
            $integrity = array_values($res)[0] ?? 'OK';
        } catch (Exception $e) {
            $integrity = $e->getMessage();
        }

        $tables = $this->getTables();
        $totalRows = array_sum(array_column($tables, 'rows'));

        return [
            'sqlite_version' => $sqliteVer,
            'file_size' => $fileSize,
            'formatted_size' => self::formatBytes($fileSize),
            'wal_size' => $walSize,
            'formatted_wal_size' => $formattedWalSize,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'freelist_count' => $freeList,
            'freelist_size' => self::formatBytes($freeList * $pageSize),
            'journal_mode' => strtoupper($journalMode),
            'foreign_keys' => $foreignKeys === 1 ? 'ON' : 'OFF',
            'integrity_check' => $integrity,
            'total_tables' => count(array_filter($tables, fn($t) => $t['type'] === 'table')),
            'total_views' => count(array_filter($tables, fn($t) => $t['type'] === 'view')),
            'total_rows' => $totalRows,
            'table_distribution' => $tables,
            'storage_analysis' => $this->getTableSizes()
        ];
    }

    public function getTableSizes(): array {
        if (!$this->pdo) return [];
        $tables = $this->getTables();
        $pageSize = 4096;
        try {
            $stmt = $this->pdo->query("PRAGMA page_size;");
            $pageSize = (int)$stmt->fetchColumn() ?: 4096;
        } catch (Throwable $e) {}

        $dbSize = file_exists($this->dbPath) ? filesize($this->dbPath) : 0;
        $result = [];
        $totalEstimated = 0;

        foreach ($tables as $t) {
            $name = $t['name'];
            $rowCount = 0;
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM " . $this->quoteIdentifier($name));
                $rowCount = (int)$stmt->fetchColumn();
            } catch (Throwable $e) {}

            $sampleSize = 0;
            try {
                $stmt = $this->pdo->query("SELECT * FROM " . $this->quoteIdentifier($name) . " LIMIT 20");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $sampleSize += strlen(json_encode($r));
                }
                if (count($rows) > 0) {
                    $sampleSize = (int)($sampleSize / count($rows));
                }
            } catch (Throwable $e) {}

            $avgRowBytes = max(20, $sampleSize);
            $estBytes = $rowCount * $avgRowBytes;
            $totalEstimated += $estBytes;

            $result[] = [
                'name' => $name,
                'type' => $t['type'],
                'rows' => $rowCount,
                'avg_row_bytes' => $avgRowBytes,
                'est_bytes' => $estBytes,
                'est_formatted' => self::formatBytes($estBytes)
            ];
        }

        foreach ($result as &$item) {
            $item['share_pct'] = $totalEstimated > 0 ? round(($item['est_bytes'] / $totalEstimated) * 100, 1) : 0;
        }

        usort($result, function($a, $b) {
            return $b['est_bytes'] <=> $a['est_bytes'];
        });

        return [
            'tables' => $result,
            'db_bytes' => $dbSize,
            'db_formatted' => self::formatBytes($dbSize),
            'page_size' => $pageSize
        ];
    }

    public function exportSchemaDdl(bool $includeDrops = false, bool $includeIndexes = true, bool $includeTriggers = true): string {
        if (!$this->pdo) return '';

        $sql = "-- ========================================================\n";
        $sql .= "-- LiteSQL Studio - Database Schema DDL Migration Export\n";
        $sql .= "-- Generated At: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Database: " . basename($this->dbPath) . "\n";
        $sql .= "-- ========================================================\n\n";
        $sql .= "PRAGMA foreign_keys = OFF;\n\n";

        // 1. Tables
        $tables = $this->getTables();
        foreach ($tables as $t) {
            if ($t['type'] !== 'table' || str_starts_with($t['name'], 'sqlite_')) continue;
            $name = $t['name'];
            $quoted = $this->quoteIdentifier($name);
            
            $sql .= "-- --------------------------------------------------------\n";
            $sql .= "-- Table Structure for `{$name}`\n";
            $sql .= "-- --------------------------------------------------------\n";
            if ($includeDrops) {
                $sql .= "DROP TABLE IF EXISTS {$quoted};\n";
            }

            try {
                $stmt = $this->pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name = " . $this->pdo->quote($name));
                $ddl = $stmt->fetchColumn();
                if ($ddl) {
                    $sql .= trim($ddl) . ";\n\n";
                }
            } catch (Throwable $e) {}
        }

        // 2. Indexes
        if ($includeIndexes) {
            try {
                $stmt = $this->pdo->query("SELECT name, tbl_name, sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL ORDER BY name ASC;");
                $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($indexes)) {
                    $sql .= "-- --------------------------------------------------------\n";
                    $sql .= "-- Database Indexes\n";
                    $sql .= "-- --------------------------------------------------------\n";
                    foreach ($indexes as $idx) {
                        if ($includeDrops) {
                            $sql .= "DROP INDEX IF EXISTS " . $this->quoteIdentifier($idx['name']) . ";\n";
                        }
                        $sql .= trim($idx['sql']) . ";\n";
                    }
                    $sql .= "\n";
                }
            } catch (Throwable $e) {}
        }

        // 3. Triggers
        if ($includeTriggers) {
            try {
                $stmt = $this->pdo->query("SELECT name, tbl_name, sql FROM sqlite_master WHERE type='trigger' ORDER BY name ASC;");
                $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($triggers)) {
                    $sql .= "-- --------------------------------------------------------\n";
                    $sql .= "-- Database Triggers\n";
                    $sql .= "-- --------------------------------------------------------\n";
                    foreach ($triggers as $trg) {
                        if ($includeDrops) {
                            $sql .= "DROP TRIGGER IF EXISTS " . $this->quoteIdentifier($trg['name']) . ";\n";
                        }
                        $sql .= trim($trg['sql']) . ";\n";
                    }
                    $sql .= "\n";
                }
            } catch (Throwable $e) {}
        }

        // 4. Views
        try {
            $stmt = $this->pdo->query("SELECT name, sql FROM sqlite_master WHERE type='view' ORDER BY name ASC;");
            $views = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($views)) {
                $sql .= "-- --------------------------------------------------------\n";
                $sql .= "-- Virtual Views\n";
                $sql .= "-- --------------------------------------------------------\n";
                foreach ($views as $vw) {
                    if ($includeDrops) {
                        $sql .= "DROP VIEW IF EXISTS " . $this->quoteIdentifier($vw['name']) . ";\n";
                    }
                    $sql .= trim($vw['sql']) . ";\n";
                }
                $sql .= "\n";
            }
        } catch (Throwable $e) {}

        $sql .= "PRAGMA foreign_keys = ON;\n";
        return $sql;
    }

    public function walCheckpoint(string $mode = 'TRUNCATE'): array {
        if (!$this->pdo) return ['success' => false, 'error' => 'No DB connection'];
        try {
            $mode = strtoupper($mode);
            if (!in_array($mode, ['PASSIVE', 'FULL', 'RESTART', 'TRUNCATE'], true)) {
                $mode = 'TRUNCATE';
            }
            $stmt = $this->pdo->query("PRAGMA wal_checkpoint($mode)");
            $res = $stmt->fetch();
            return [
                'success' => true,
                'busy' => $res['busy'] ?? 0,
                'log' => $res['log'] ?? 0,
                'checkpointed' => $res['checkpointed'] ?? 0
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function setJournalMode(string $mode): bool {
        if (!$this->pdo) return false;
        $mode = strtoupper($mode);
        if (!in_array($mode, ['DELETE', 'TRUNCATE', 'PERSIST', 'MEMORY', 'WAL', 'OFF'], true)) {
            return false;
        }
        try {
            $this->pdo->exec("PRAGMA journal_mode = $mode");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getPragmas(): array {
        if (!$this->pdo) return [];
        $get = function($p) {
            try {
                return $this->pdo->query("PRAGMA $p;")->fetchColumn();
            } catch (Throwable $e) { return null; }
        };
        return [
            'cache_size' => (int)$get('cache_size'),
            'synchronous' => (int)$get('synchronous'),
            'temp_store' => (int)$get('temp_store'),
            'busy_timeout' => (int)$get('busy_timeout'),
            'journal_mode' => (string)$get('journal_mode'),
            'foreign_keys' => (int)$get('foreign_keys')
        ];
    }

    public function setPragmas(array $settings): bool {
        if (!$this->pdo) return false;
        try {
            if (isset($settings['cache_size'])) {
                $this->pdo->exec("PRAGMA cache_size = " . (int)$settings['cache_size']);
            }
            if (isset($settings['synchronous'])) {
                $syncMap = [0 => 'OFF', 1 => 'NORMAL', 2 => 'FULL', 3 => 'EXTRA'];
                $val = $syncMap[(int)$settings['synchronous']] ?? 'NORMAL';
                $this->pdo->exec("PRAGMA synchronous = $val");
            }
            if (isset($settings['temp_store'])) {
                $tsMap = [0 => 'DEFAULT', 1 => 'FILE', 2 => 'MEMORY'];
                $val = $tsMap[(int)$settings['temp_store']] ?? 'MEMORY';
                $this->pdo->exec("PRAGMA temp_store = $val");
            }
            if (isset($settings['busy_timeout'])) {
                $this->pdo->exec("PRAGMA busy_timeout = " . (int)$settings['busy_timeout']);
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function setForeignKeys(bool $enable): bool {
        if (!$this->pdo) return false;
        $val = $enable ? 'ON' : 'OFF';
        try {
            $this->pdo->exec("PRAGMA foreign_keys = $val");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function duplicateColumn(string $table, string $sourceCol, string $newCol, bool $copyData = true): bool {
        if (!$this->pdo || empty($table) || empty($sourceCol) || empty($newCol)) return false;
        
        $schema = $this->getSchema($table);
        $cols = $schema['columns'] ?? [];
        $sourceDef = null;
        foreach ($cols as $c) {
            if (strtolower($c['name']) === strtolower($sourceCol)) {
                $sourceDef = $c;
                break;
            }
        }
        if (!$sourceDef) return false;

        $type = !empty($sourceDef['type']) ? $sourceDef['type'] : 'TEXT';
        $dflt = (isset($sourceDef['dflt_value']) && $sourceDef['dflt_value'] !== null) ? " DEFAULT " . (is_numeric($sourceDef['dflt_value']) ? $sourceDef['dflt_value'] : $this->pdo->quote($sourceDef['dflt_value'])) : "";

        try {
            $this->pdo->beginTransaction();
            $sql = "ALTER TABLE " . $this->quoteIdentifier($table) . " ADD COLUMN " . $this->quoteIdentifier($newCol) . " {$type}{$dflt}";
            $this->pdo->exec($sql);

            if ($copyData) {
                $copySql = "UPDATE " . $this->quoteIdentifier($table) . " SET " . $this->quoteIdentifier($newCol) . " = " . $this->quoteIdentifier($sourceCol);
                $this->pdo->exec($copySql);
            }

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return false;
        }
    }

    public function getColumnComments(string $table): array {
        $configFile = dirname($this->dbPath) . '/.' . preg_replace('/[^\w\-]/', '_', basename($this->dbPath, '.sqlite')) . '_comments.json';
        if (!file_exists($configFile)) return [];
        $data = json_decode(file_get_contents($configFile), true) ?: [];
        return $data[$table] ?? [];
    }

    public function saveColumnComment(string $table, string $column, string $comment): bool {
        $configFile = dirname($this->dbPath) . '/.' . preg_replace('/[^\w\-]/', '_', basename($this->dbPath, '.sqlite')) . '_comments.json';
        $data = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?: []) : [];
        if (!isset($data[$table])) $data[$table] = [];
        $data[$table][$column] = trim($comment);
        return (bool)file_put_contents($configFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function explainQueryPlan(string $sql): array {
        if (!$this->pdo || empty($sql)) return ['error' => 'No SQL provided'];
        try {
            $stmt = $this->pdo->query("EXPLAIN QUERY PLAN " . $sql);
            $plan = $stmt->fetchAll();

            $hasScan = false;
            $hasIndex = false;
            $hasCoveringIndex = false;

            foreach ($plan as &$step) {
                $detail = $step['detail'] ?? '';
                if (stripos($detail, 'SCAN') !== false) {
                    $hasScan = true;
                    $step['type'] = 'SCAN';
                } elseif (stripos($detail, 'COVERING INDEX') !== false) {
                    $hasCoveringIndex = true;
                    $step['type'] = 'COVERING_INDEX';
                } elseif (stripos($detail, 'SEARCH') !== false || stripos($detail, 'USING INDEX') !== false) {
                    $hasIndex = true;
                    $step['type'] = 'INDEX_SEARCH';
                } else {
                    $step['type'] = 'GENERAL';
                }
            }

            return [
                'plan' => $plan,
                'has_scan' => $hasScan,
                'has_index' => $hasIndex,
                'has_covering_index' => $hasCoveringIndex,
                'recommendation' => $hasScan ? 'Performance Warning: Full Table Scan detected! Consider creating an index on filtered columns.' : 'Optimal Performance: B-Tree index scan active!'
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function globalSearch(string $query, int $maxPerTable = 50): array {
        if (!$this->pdo || empty(trim($query))) {
            return ['query' => $query, 'total_matches' => 0, 'results' => []];
        }

        $query = trim($query);
        $tables = $this->getTables();
        $results = [];
        $totalMatches = 0;

        foreach ($tables as $t) {
            if ($t['type'] !== 'table' || str_starts_with($t['name'], 'sqlite_')) continue;

            $tName = $t['name'];
            $schema = $this->getSchema($tName);
            $cols = array_map(fn($c) => $c['name'], $schema['columns'] ?? []);

            if (empty($cols)) continue;

            $quotedTable = $this->quoteIdentifier($tName);
            $whereParts = [];
            $params = [];

            foreach ($cols as $col) {
                $whereParts[] = $this->quoteIdentifier($col) . " LIKE ?";
                $params[] = '%' . $query . '%';
            }

            $whereClause = implode(' OR ', $whereParts);

            $countSql = "SELECT COUNT(*) as cnt FROM {$quotedTable} WHERE {$whereClause}";
            try {
                $countStmt = $this->pdo->prepare($countSql);
                $countStmt->execute($params);
                $matchCount = (int)($countStmt->fetch()['cnt'] ?? 0);

                if ($matchCount > 0) {
                    $selectSql = "SELECT * FROM {$quotedTable} WHERE {$whereClause} LIMIT {$maxPerTable}";
                    $selectStmt = $this->pdo->prepare($selectSql);
                    $selectStmt->execute($params);
                    $rows = $selectStmt->fetchAll();

                    $totalMatches += $matchCount;
                    $results[] = [
                        'table' => $tName,
                        'match_count' => $matchCount,
                        'columns' => $cols,
                        'rows' => $rows
                    ];
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return [
            'query' => $query,
            'total_matches' => $totalMatches,
            'results' => $results
        ];
    }

    public function executeQuery(string $sql, int $autoLimit = 500, bool $dryRun = false): array {
        if (!$this->pdo) return ['error' => 'No database connected'];
        $startTime = microtime(true);
        
        try {
            $trimmed = trim($sql);
            $isSelect = preg_match('/^SELECT/i', $trimmed) === 1;
            $appliedAutoLimit = false;

            if ($isSelect && $autoLimit > 0) {
                if (!preg_match('/\bLIMIT\s+\d+/i', $trimmed)) {
                    $trimmed = rtrim($trimmed, ';');
                    $sql = $trimmed . " LIMIT " . (int)$autoLimit;
                    $appliedAutoLimit = true;
                }
            }

            $isQuery = preg_match('/^(SELECT|PRAGMA|EXPLAIN)/i', trim($sql)) === 1;

            if ($dryRun && !$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }

            if ($isQuery) {
                $stmt = $this->pdo->query($sql);
                $rows = $stmt->fetchAll();
                $elapsed = round((microtime(true) - $startTime) * 1000, 2);
                $columns = count($rows) > 0 ? array_keys($rows[0]) : [];

                if ($dryRun && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                return [
                    'success' => true,
                    'type' => 'select',
                    'columns' => $columns,
                    'rows' => $rows,
                    'count' => count($rows),
                    'execution_time_ms' => $elapsed,
                    'auto_limit_applied' => $appliedAutoLimit,
                    'auto_limit_val' => $autoLimit,
                    'dry_run' => $dryRun
                ];
            } else {
                $affected = $this->pdo->exec($sql);
                $elapsed = round((microtime(true) - $startTime) * 1000, 2);

                if ($dryRun && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                return [
                    'success' => true,
                    'type' => 'exec',
                    'affected_rows' => $affected,
                    'execution_time_ms' => $elapsed,
                    'dry_run' => $dryRun
                ];
            }
        } catch (Exception $e) {
            if ($dryRun && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time_ms' => $elapsed,
                'dry_run' => $dryRun
            ];
        }
    }

    public function exportSql(string $table = ''): string {
        if (!$this->pdo) return '';
        $dump = "-- LiteSQL Studio SQL Dump\n";
        $dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

        $tables = $table !== '' ? [['name' => $table]] : $this->getTables();

        foreach ($tables as $t) {
            $tName = $t['name'];
            $quoted = $this->quoteIdentifier($tName);

            $stmt = $this->pdo->query("SELECT sql FROM sqlite_master WHERE name = $quoted");
            $row = $stmt->fetch();
            if ($row && isset($row['sql'])) {
                $dump .= "DROP TABLE IF EXISTS $quoted;\n";
                $dump .= $row['sql'] . ";\n\n";
            }

            $dataStmt = $this->pdo->query("SELECT * FROM $quoted");
            $rows = $dataStmt->fetchAll();

            foreach ($rows as $r) {
                $cols = array_map(fn($c) => $this->quoteIdentifier($c), array_keys($r));
                $vals = array_map(function($v) {
                    if ($v === null) return 'NULL';
                    return $this->pdo->quote((string)$v);
                }, array_values($r));

                $dump .= "INSERT INTO $quoted (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
            }
            $dump .= "\n";
        }
        return $dump;
    }

    public function getErDiagram(): array {
        if (!$this->pdo) return ['tables' => [], 'relationships' => []];

        $tables = $this->getTables();
        $diagramTables = [];
        $relationships = [];

        foreach ($tables as $t) {
            $tName = $t['name'];
            $quoted = $this->quoteIdentifier($tName);

            $colStmt = $this->pdo->query("PRAGMA table_info($quoted)");
            $columns = $colStmt->fetchAll();

            $fkStmt = $this->pdo->query("PRAGMA foreign_key_list($quoted)");
            $fks = $fkStmt->fetchAll();

            $fkMap = [];
            foreach ($fks as $fk) {
                $fkMap[$fk['from']] = [
                    'target_table' => $fk['table'],
                    'target_column' => $fk['to'],
                    'on_update' => $fk['on_update'],
                    'on_delete' => $fk['on_delete']
                ];

                $relationships[] = [
                    'from_table' => $tName,
                    'from_column' => $fk['from'],
                    'to_table' => $fk['table'],
                    'to_column' => $fk['to'],
                    'on_update' => $fk['on_update'],
                    'on_delete' => $fk['on_delete']
                ];
            }

            $diagramTables[] = [
                'name' => $tName,
                'type' => $t['type'],
                'columns' => array_map(function($c) use ($fkMap) {
                    $cName = $c['name'];
                    return [
                        'name' => $cName,
                        'type' => $c['type'],
                        'pk' => (int)$c['pk'] > 0,
                        'notnull' => (int)$c['notnull'] > 0,
                        'default' => $c['dflt_value'],
                        'fk' => $fkMap[$cName] ?? null
                    ];
                }, $columns)
            ];
        }

        return [
            'tables' => $diagramTables,
            'relationships' => $relationships
        ];
    }

    private function quoteIdentifier(string $identifier): string {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}

// -----------------------------------------------------------------------------
// 4. REST / AJAX API ROUTER (?api=...)
// -----------------------------------------------------------------------------
if (isset($_GET['api'])) {
    $api = $_GET['api'];

    if ($api === 'login') {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true);
        $pass = $input['password'] ?? '';
        if (Auth::login($pass)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Incorrect password']);
        }
        exit;
    }

    if (!Auth::check()) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    if ($api === 'download_db') {
        $dbPath = $_GET['db_path'] ?? $_SESSION['litesql_active_db'] ?? '';
        if ($dbPath && file_exists($dbPath) && is_file($dbPath)) {
            $filename = basename($dbPath);
            header('Content-Type: application/x-sqlite3');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($dbPath));
            header('Pragma: public');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            readfile($dbPath);
            exit;
        } else {
            http_response_code(404);
            echo "Database file not found.";
            exit;
        }
    }

    if ($api === 'download_all_zip') {
        $dbFiles = glob(__DIR__ . '/*.{sqlite,db,sqlite3,db3}', GLOB_BRACE);
        if (empty($dbFiles)) {
            http_response_code(404);
            die('No database files found on server');
        }

        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            die('PHP ZipArchive extension is not enabled on server.');
        }

        $zipName = 'litesql_databases_backup_' . date('Y-m-d_H-i-s') . '.zip';
        $tmpZip = tempnam(sys_get_temp_dir(), 'litesql_zip_');

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($dbFiles as $file) {
                if (file_exists($file) && is_file($file)) {
                    $zip->addFile($file, basename($file));
                }
            }
            $zip->close();

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipName . '"');
            header('Content-Length: ' . filesize($tmpZip));
            header('Pragma: no-cache');
            header('Expires: 0');
            readfile($tmpZip);
            @unlink($tmpZip);
            exit;
        } else {
            http_response_code(500);
            die('Failed to create zip archive');
        }
    }

    if ($api === 'upload_db') {
        header('Content-Type: application/json');
        if (!isset($_FILES['db_file'])) {
            echo json_encode(['success' => false, 'error' => 'No file uploaded']);
            exit;
        }
        $file = $_FILES['db_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['sqlite', 'db', 'sqlite3', 'db3'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid SQLite file extension (.sqlite, .db, .sqlite3)']);
            exit;
        }
        $targetDir = __DIR__;
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . preg_replace('/[^\w\-\.]/', '_', $file['name']);
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $_SESSION['litesql_active_db'] = $targetPath;
            echo json_encode(['success' => true, 'db_path' => $targetPath, 'name' => basename($targetPath)]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save uploaded database file']);
        }
        exit;
    }

    if ($api === 'change_password') {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true);
        $currentPass = $input['current_password'] ?? '';
        $newPass = $input['new_password'] ?? '';

        if (empty($newPass) || strlen($newPass) < 4) {
            echo json_encode(['success' => false, 'error' => 'New password must be at least 4 characters long']);
            exit;
        }

        $success = Auth::changePassword($currentPass, $newPass);
        if ($success) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Incorrect current password']);
        }
        exit;
    }

    if (isset($_GET['db_path']) && file_exists($_GET['db_path'])) {
        $_SESSION['litesql_active_db'] = $_GET['db_path'];
    }
    $activeDb = $_SESSION['litesql_active_db'] ?? '';

    $engine = new LiteEngine($activeDb);

    switch ($api) {
        case 'databases':
            header('Content-Type: application/json');
            $dbs = LiteEngine::scanDatabases($scanDirectory, $allowedExtensions);
            echo json_encode([
                'databases' => $dbs,
                'active' => $activeDb
            ]);
            break;

        case 'er_diagram':
            header('Content-Type: application/json');
            echo json_encode($engine->getErDiagram());
            break;

        case 'db_diff':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $targetDb = $input['target_db'] ?? '';
            echo json_encode($engine->compareDatabases($targetDb));
            break;

        case 'wal_checkpoint':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $mode = $input['mode'] ?? 'TRUNCATE';
            echo json_encode($engine->walCheckpoint($mode));
            break;

        case 'set_journal_mode':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $mode = $input['mode'] ?? 'WAL';
            $success = $engine->setJournalMode($mode);
            echo json_encode(['success' => $success]);
            break;

        case 'set_foreign_keys':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $enable = (bool)($input['enable'] ?? true);
            $success = $engine->setForeignKeys($enable);
            echo json_encode(['success' => $success]);
            break;

        case 'explain_query':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $sql = $input['sql'] ?? '';
            echo json_encode($engine->explainQueryPlan($sql));
            break;

        case 'add_foreign_key':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $localCol = $input['local_col'] ?? '';
            $refTable = $input['ref_table'] ?? '';
            $refCol = $input['ref_col'] ?? '';
            $onDelete = $input['on_delete'] ?? 'CASCADE';
            $onUpdate = $input['on_update'] ?? 'NO ACTION';

            $success = $engine->addForeignKey($table, $localCol, $refTable, $refCol, $onDelete, $onUpdate);
            echo json_encode(['success' => $success]);
            break;

        case 'drop_foreign_key':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $fkId = (int)($input['fk_id'] ?? -1);

            $success = $engine->dropForeignKey($table, $fkId);
            echo json_encode(['success' => $success]);
            break;

        case 'create_database':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? '';
            try {
                $newPath = LiteEngine::createDatabase($scanDirectory, $name);
                $_SESSION['litesql_active_db'] = $newPath;
                echo json_encode(['success' => true, 'path' => $newPath]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'select_db':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $dbPath = $input['db_path'] ?? '';
            if (file_exists($dbPath)) {
                $_SESSION['litesql_active_db'] = $dbPath;
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Database file not found']);
            }
            break;

        case 'vacuum':
            header('Content-Type: application/json');
            $success = $engine->vacuum();
            echo json_encode(['success' => $success]);
            break;

        case 'reindex':
            header('Content-Type: application/json');
            $success = $engine->reindex();
            echo json_encode(['success' => $success]);
            break;

        case 'optimize':
            header('Content-Type: application/json');
            $success = $engine->optimize();
            echo json_encode(['success' => $success]);
            break;

        case 'integrity_check':
            header('Content-Type: application/json');
            $result = $engine->integrityCheck();
            $success = (strtolower($result) === 'ok');
            echo json_encode(['success' => $success, 'result' => $result]);
            break;

        case 'analytics':
            header('Content-Type: application/json');
            echo json_encode($engine->getAnalytics());
            break;

        case 'export_ddl':
            $includeDrops = !empty($_GET['drops']);
            $includeIndexes = ($_GET['indexes'] ?? '1') === '1';
            $includeTriggers = ($_GET['triggers'] ?? '1') === '1';

            $ddlSql = $engine->exportSchemaDdl($includeDrops, $includeIndexes, $includeTriggers);

            if (!empty($_GET['download'])) {
                $filename = 'schema_ddl_' . preg_replace('/[^\w\-]/', '_', basename($engine->getDbPath(), '.sqlite')) . '_' . date('Y-m-d_H-i-s') . '.sql';
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                echo $ddlSql;
                exit;
            }

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'sql' => $ddlSql]);
            break;

        case 'get_pragmas':
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'pragmas' => $engine->getPragmas()]);
            break;

        case 'set_pragmas':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $success = $engine->setPragmas($input['pragmas'] ?? []);
            echo json_encode(['success' => $success]);
            break;

        case 'duplicate_column':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $sourceCol = $input['source_col'] ?? '';
            $newCol = $input['new_col'] ?? '';
            $copyData = !empty($input['copy_data']);

            $success = $engine->duplicateColumn($table, $sourceCol, $newCol, $copyData);
            echo json_encode(['success' => $success]);
            break;

        case 'get_column_comments':
            header('Content-Type: application/json');
            $table = $_GET['table'] ?? '';
            echo json_encode(['success' => true, 'comments' => $engine->getColumnComments($table)]);
            break;

        case 'save_column_comment':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $column = $input['column'] ?? '';
            $comment = $input['comment'] ?? '';

            $success = $engine->saveColumnComment($table, $column, $comment);
            echo json_encode(['success' => $success]);
            break;

        case 'tables':
            header('Content-Type: application/json');
            echo json_encode(['tables' => $engine->getTables()]);
            break;

        case 'triggers':
            header('Content-Type: application/json');
            $table = $_GET['table'] ?? '';
            echo json_encode(['triggers' => $engine->getTriggers($table)]);
            break;

        case 'create_trigger':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? '';
            $timing = $input['timing'] ?? 'AFTER';
            $event = $input['event'] ?? 'INSERT';
            $table = $input['table'] ?? '';
            $body = $input['body'] ?? '';

            $success = $engine->createTrigger($name, $timing, $event, $table, $body);
            echo json_encode(['success' => $success]);
            break;

        case 'drop_trigger':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? '';

            $success = $engine->dropTrigger($name);
            echo json_encode(['success' => $success]);
            break;

        case 'generate_mock_data':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $count = (int)($input['count'] ?? 25);

            echo json_encode($engine->generateMockData($table, $count));
            break;

        case 'duplicate_table':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $sourceTable = $input['source_table'] ?? '';
            $newTable = $input['new_table'] ?? '';
            $includeData = !empty($input['include_data']);

            $success = $engine->duplicateTable($sourceTable, $newTable, $includeData);
            echo json_encode(['success' => $success]);
            break;

        case 'benchmark_queries':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $sqlA = $input['sql_a'] ?? '';
            $sqlB = $input['sql_b'] ?? '';
            $iterations = (int)($input['iterations'] ?? 10);

            echo json_encode($engine->benchmarkQueries($sqlA, $sqlB, $iterations));
            break;

        case 'reorder_columns':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $columns = $input['columns'] ?? [];

            $success = $engine->reorderColumns($table, $columns);
            echo json_encode(['success' => $success]);
            break;

        case 'views':
            header('Content-Type: application/json');
            echo json_encode(['views' => $engine->getViews()]);
            break;

        case 'create_view':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $viewName = $input['view_name'] ?? '';
            $selectSql = $input['select_sql'] ?? '';

            $success = $engine->createView($viewName, $selectSql);
            echo json_encode(['success' => $success]);
            break;

        case 'drop_view':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $viewName = $input['view_name'] ?? '';

            $success = $engine->dropView($viewName);
            echo json_encode(['success' => $success]);
            break;

        case 'schema':
            header('Content-Type: application/json');
            $table = $_GET['table'] ?? '';
            echo json_encode($engine->getSchema($table));
            break;

        case 'data':
            header('Content-Type: application/json');
            $table = $_GET['table'] ?? '';
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 25);
            $sort = $_GET['sort'] ?? '';
            $dir = $_GET['dir'] ?? 'ASC';
            $search = $_GET['search'] ?? '';

            echo json_encode($engine->getData($table, $page, $limit, $sort, $dir, $search));
            break;

        case 'update_cell':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $pkConditions = $input['pk'] ?? [];
            $column = $input['column'] ?? '';
            $newValue = $input['value'] ?? null;

            $success = $engine->updateCell($table, $pkConditions, $column, $newValue);
            echo json_encode(['success' => $success]);
            break;

        case 'update_row':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $pkConditions = $input['pk'] ?? [];
            $data = $input['data'] ?? [];

            $success = $engine->updateRow($table, $pkConditions, $data);
            echo json_encode(['success' => $success]);
            break;

        case 'insert_row':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $data = $input['data'] ?? [];

            $success = $engine->insertRow($table, $data);
            echo json_encode(['success' => $success]);
            break;

        case 'delete_row':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $pkConditions = $input['pk'] ?? [];

            $success = $engine->deleteRow($table, $pkConditions);
            echo json_encode(['success' => $success]);
            break;

        case 'bulk_delete':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $pksList = $input['pks'] ?? [];

            $success = $engine->bulkDeleteRows($table, $pksList);
            echo json_encode(['success' => $success]);
            break;

        case 'create_table':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $tableName = $input['table_name'] ?? '';
            $columns = $input['columns'] ?? [];

            $success = $engine->createTable($tableName, $columns);
            echo json_encode(['success' => $success]);
            break;

        case 'add_column':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $column = $input['column'] ?? [];

            $success = $engine->addColumn($table, $column);
            echo json_encode(['success' => $success]);
            break;

        case 'drop_table':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';

            $success = $engine->dropTable($table);
            echo json_encode(['success' => $success]);
            break;

        case 'rename_table':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $oldName = $input['old_name'] ?? '';
            $newName = $input['new_name'] ?? '';

            $success = $engine->renameTable($oldName, $newName);
            echo json_encode(['success' => $success]);
            break;

        case 'rename_column':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $oldCol = $input['old_col'] ?? '';
            $newCol = $input['new_col'] ?? '';

            $success = $engine->renameColumn($table, $oldCol, $newCol);
            echo json_encode(['success' => $success]);
            break;

        case 'bulk_rename_columns':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $renames = $input['renames'] ?? [];

            $success = $engine->bulkRenameColumns($table, $renames);
            echo json_encode(['success' => $success]);
            break;

        case 'bulk_update_columns':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $columns = $input['columns'] ?? [];

            $success = $engine->bulkUpdateColumns($table, $columns);
            echo json_encode(['success' => $success]);
            break;

        case 'truncate_table':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';

            $success = $engine->truncateTable($table);
            echo json_encode(['success' => $success]);
            break;

        case 'global_search':
            header('Content-Type: application/json');
            $q = $_GET['q'] ?? '';
            echo json_encode($engine->globalSearch($q));
            break;

        case 'query':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $sql = $input['sql'] ?? '';
            $autoLimit = isset($input['auto_limit']) ? (int)$input['auto_limit'] : 500;
            $dryRun = !empty($input['dry_run']);

            echo json_encode($engine->executeQuery($sql, $autoLimit, $dryRun));
            break;

        case 'export_sql':
            $table = $_GET['table'] ?? '';
            $dump = $engine->exportSql($table);
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="' . ($table ? $table : 'database') . '_' . date('Ymd_His') . '.sql"');
            echo $dump;
            exit;

        case 'export_csv':
            $table = $_GET['table'] ?? '';
            $filename = ($table ? $table : 'export') . '_' . date('Ymd_His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $output = fopen('php://output', 'w');
            if ($table) {
                $schema = $engine->getSchema($table);
                $cols = array_map(fn($c) => $c['name'], $schema['columns']);
                fputcsv($output, $cols);
                $data = $engine->getData($table, 1, 100000);
                foreach ($data['rows'] as $row) {
                    fputcsv($output, array_values($row));
                }
            }
            fclose($output);
            exit;

        case 'export_json':
            $table = $_GET['table'] ?? '';
            $filename = ($table ? $table : 'export') . '_' . date('Ymd_His') . '.json';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            if ($table) {
                $data = $engine->getData($table, 1, 100000);
                echo json_encode($data['rows'], JSON_PRETTY_PRINT);
            } else {
                echo json_encode([]);
            }
            exit;

        case 'create_index':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $table = $input['table'] ?? '';
            $indexName = $input['index_name'] ?? '';
            $columns = $input['columns'] ?? [];
            $unique = !empty($input['unique']);
            if ($engine->createIndex($table, $indexName, $columns, $unique)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to create index']);
            }
            break;

        case 'drop_index':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $indexName = $input['index_name'] ?? '';
            if ($engine->dropIndex($indexName)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to drop index']);
            }
            break;

        case 'import_data':
            header('Content-Type: application/json');
            $table = $_POST['table'] ?? '';
            $createTable = ($_POST['create_table'] ?? '') === 'true';
            $newTableName = $_POST['new_table_name'] ?? '';
            $format = $_POST['format'] ?? 'csv';

            if (empty($_FILES['file']['tmp_name'])) {
                echo json_encode(['success' => false, 'error' => 'No file uploaded']);
                exit;
            }

            $filePath = $_FILES['file']['tmp_name'];
            $fileContent = file_get_contents($filePath);

            $records = [];
            if ($format === 'json') {
                $records = json_decode($fileContent, true);
                if (!is_array($records)) {
                    echo json_encode(['success' => false, 'error' => 'Invalid JSON file format']);
                    exit;
                }
            } else {
                $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (empty($lines)) {
                    echo json_encode(['success' => false, 'error' => 'Empty CSV file']);
                    exit;
                }
                $delimiter = $_POST['delimiter'] ?? ',';
                $rows = array_map(fn($l) => str_getcsv($l, $delimiter), $lines);
                $headers = array_shift($rows);
                foreach ($rows as $r) {
                    $rec = [];
                    foreach ($headers as $idx => $h) {
                        $rec[trim($h)] = $r[$idx] ?? null;
                    }
                    $records[] = $rec;
                }
            }

            if ($createTable && !empty($newTableName) && count($records) > 0) {
                $cols = array_map(fn($k) => ['name' => $k, 'type' => 'TEXT'], array_keys($records[0]));
                $engine->createTable($newTableName, $cols);
                $table = $newTableName;
            }

            if (empty($table)) {
                echo json_encode(['success' => false, 'error' => 'No target table selected']);
                exit;
            }

            $inserted = 0;
            foreach ($records as $rec) {
                if ($engine->insertRow($table, $rec)) {
                    $inserted++;
                }
            }
            echo json_encode(['success' => true, 'inserted' => $inserted, 'table' => $table]);
            exit;

        default:
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid API Endpoint']);
            break;
    }
    exit;
}

// -----------------------------------------------------------------------------
// 5. HTML TEMPLATE & ULTRA-MODERN SINGLE-PAGE INTERFACE
// -----------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en" class="h-full dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚡ LiteSQL Studio - Next-Gen SQLite Studio</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='20 15 60 67'><path d='M25 30c0-6 11-10 25-10s25 4 25 10-11 10-25 10-25-4-25-10z' fill='%230284c7'/><path d='M25 30v18c0 6 11 10 25 10s25-4 25-10V30' fill='none' stroke='%2338bdf8' stroke-width='6'/><path d='M25 48v18c0 6 11 10 25 10s25-4 25-10V48' fill='none' stroke='%2338bdf8' stroke-width='6'/><path d='M58 18L38 52h14l-6 28 22-32H54l6-28z' fill='%23fbbf24'/></svg>">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    },
                    colors: {
                        brand: {
                            50: '#f0f9ff',
                            500: '#0284c7',
                            600: '#0284c7',
                            700: '#0369a1',
                            900: '#0c4a6e',
                        }
                    }
                }
            }
        }
    </script>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        [x-cloak] { display: none !important; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 4px; }
        .dark ::-webkit-scrollbar-thumb { background: #1e293b; }
        ::-webkit-scrollbar-thumb:hover { background: #64748b; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #334155; }
    </style>
</head>
<body class="h-full flex flex-col font-sans antialiased overflow-hidden selection:bg-sky-500 selection:text-white bg-slate-100 text-slate-800 dark:bg-slate-950 dark:text-slate-100" x-data="litesqlApp()" x-init="initApp()">

    <!-- LOGIN SCREEN -->
    <div x-show="!authenticated" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-100 dark:bg-slate-950 p-4 relative overflow-hidden" x-cloak>
        <div class="absolute -top-40 -left-40 w-96 h-96 bg-sky-500/20 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-indigo-500/20 rounded-full blur-3xl pointer-events-none"></div>

        <div class="w-full max-w-md bg-white/90 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200 dark:border-slate-800/80 rounded-3xl shadow-2xl p-8 space-y-6 relative z-10">
            <div class="text-center space-y-3">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-slate-900 border border-slate-800 p-2 text-sky-500 mb-1 shadow-xl shadow-sky-500/20">
                    <svg class="w-full h-full drop-shadow-md" viewBox="20 15 60 67" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M25 30c0-6 11-10 25-10s25 4 25 10-11 10-25 10-25-4-25-10z" fill="#0284c7"/>
                        <path d="M25 30v18c0 6 11 10 25 10s25-4 25-10V30" stroke="#38bdf8" stroke-width="6"/>
                        <path d="M25 48v18c0 6 11 10 25 10s25-4 25-10V48" stroke="#38bdf8" stroke-width="6"/>
                        <path d="M58 18L38 52h14l-6 28 22-32H54l6-28z" fill="#fbbf24"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-extrabold tracking-tight bg-gradient-to-r from-sky-600 via-teal-500 to-indigo-600 dark:from-sky-400 dark:via-teal-300 dark:to-indigo-400 bg-clip-text text-transparent">
                    ⚡ LiteSQL Studio
                </h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 font-medium">Next-Generation Single-File SQLite Web Manager</p>
            </div>

            <form @submit.prevent="login()" class="space-y-4">
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Password</label>
                    <input type="password" x-model="loginPassword" class="w-full bg-slate-50 dark:bg-slate-950/80 border border-slate-300 dark:border-slate-800 rounded-xl px-4 py-3 text-sm text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 transition" placeholder="Enter password..." required autofocus>
                </div>

                <template x-if="loginError">
                    <div class="text-xs text-rose-500 dark:text-rose-400 bg-rose-500/10 border border-rose-500/20 rounded-xl p-3 text-center font-medium" x-text="loginError"></div>
                </template>

                <button type="submit" class="w-full bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white font-semibold text-sm py-3 rounded-xl shadow-lg shadow-sky-600/25 transition active:scale-[0.98] flex items-center justify-center gap-2">
                    <i data-lucide="lock" class="w-4 h-4"></i>
                    <span>Authenticate Studio</span>
                </button>
            </form>

            <div class="pt-4 border-t border-slate-200 dark:border-slate-800/80 text-center text-xs text-slate-500">
                Default password: <code class="text-sky-600 dark:text-sky-400 bg-slate-100 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 px-2 py-0.5 rounded-md font-mono">admin</code>
            </div>
        </div>
    </div>

    <!-- MAIN DASHBOARD CONTAINER -->
    <div x-show="authenticated" class="h-full flex flex-col bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-slate-100 relative" x-cloak>
        <!-- TOP NAVBAR (Clean, Responsive & Ultra-Modern) -->
        <header class="h-14 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800/80 px-4 flex items-center justify-between z-30 shrink-0 shadow-sm">
            <!-- Left: Brand Logo & Spotlight Search -->
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 cursor-pointer" @click="activeTab = 'data'">
                    <div class="w-8 h-8 rounded-xl bg-slate-900 border border-slate-800 p-1 flex items-center justify-center shadow-md shadow-sky-500/20">
                        <svg class="w-full h-full" viewBox="20 15 60 67" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M25 30c0-6 11-10 25-10s25 4 25 10-11 10-25 10-25-4-25-10z" fill="#0284c7"/>
                            <path d="M25 30v18c0 6 11 10 25 10s25-4 25-10V30" stroke="#38bdf8" stroke-width="6"/>
                            <path d="M25 48v18c0 6 11 10 25 10s25-4 25-10V48" stroke="#38bdf8" stroke-width="6"/>
                            <path d="M58 18L38 52h14l-6 28 22-32H54l6-28z" fill="#fbbf24"/>
                        </svg>
                    </div>
                    <div class="leading-none">
                        <span class="font-extrabold text-sm text-slate-900 dark:text-white tracking-tight">LiteSQL</span>
                        <span class="text-[10px] font-bold text-sky-500 ml-0.5">Studio</span>
                    </div>
                    <span class="text-[10px] font-mono font-semibold bg-sky-500/10 text-sky-600 dark:text-sky-400 px-1.5 py-0.5 rounded border border-sky-500/20 ml-1 hidden sm:inline" x-text="'v' + version"></span>
                </div>

                <!-- Spotlight Command Palette Trigger -->
                <button @click="showCmdPalette = true; cmdSearch = ''; setTimeout(() => $refs.cmdSearchInput && $refs.cmdSearchInput.focus(), 50)" class="bg-slate-100 dark:bg-slate-950/80 hover:bg-slate-200 dark:hover:bg-slate-800 text-slate-500 dark:text-slate-400 text-xs px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-800 transition flex items-center gap-2 shadow-inner font-medium ml-1" title="Open Command Palette (Ctrl+K)">
                    <i data-lucide="search" class="w-3.5 h-3.5 text-sky-500"></i>
                    <span class="hidden md:inline">Search studio...</span>
                    <kbd class="text-[10px] font-mono bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 px-1.5 py-0.5 rounded text-slate-400">Ctrl+K</kbd>
                </button>

                <!-- Active Database Badge -->
                <template x-if="activeDb">
                    <div class="hidden lg:flex items-center gap-2 bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 text-xs px-2.5 py-1 rounded-xl shadow-inner cursor-pointer hover:border-sky-500 transition" @click="activeTab = 'analytics'; loadAnalytics()">
                        <i data-lucide="hard-drive" class="w-3.5 h-3.5 text-sky-500"></i>
                        <span class="font-semibold text-slate-700 dark:text-slate-200 truncate max-w-[140px]" x-text="activeDbName"></span>
                        <button @click.stop="vacuumDb()" title="Vacuum Database" class="hover:text-amber-500 text-slate-400 ml-0.5 transition active:scale-95"><i data-lucide="sparkles" class="w-3 h-3"></i></button>
                    </div>
                </template>

                <!-- GLOBAL DATABASE SEARCH INPUT TRIGGER -->
                <template x-if="activeDb">
                    <button type="button" @click="openGlobalSearchModal()" class="bg-slate-100 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-1.5 text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer flex items-center gap-2 transition shadow-inner active:scale-95" title="Search keyword across ALL tables in database">
                        <i data-lucide="search" class="w-3.5 h-3.5 text-sky-500 shrink-0"></i>
                        <span class="hidden md:inline text-slate-500 dark:text-slate-400 font-medium">Search DB...</span>
                    </button>
                </template>
            </div>

            <!-- Right: Organized Quick Action Menus & User Settings -->
            <div class="flex items-center gap-2">
                <!-- 1. + CREATE DROPDOWN MENU -->
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" @click.away="open = false" class="bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-xs font-semibold px-3 py-1.5 rounded-xl shadow-md shadow-emerald-600/20 transition flex items-center gap-1.5 active:scale-95">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                        <span>Create</span>
                        <i data-lucide="chevron-down" class="w-3 h-3 transition" :class="open ? 'rotate-180' : ''"></i>
                    </button>

                    <div x-show="open" x-cloak class="absolute right-0 top-full mt-2 w-48 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl overflow-hidden p-1.5 space-y-1 z-50">
                        <button @click="showNewTableModal = true; open = false" class="w-full text-left p-2 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 hover:bg-emerald-500/10 hover:text-emerald-600 dark:hover:text-emerald-400 flex items-center gap-2 transition">
                            <i data-lucide="table" class="w-3.5 h-3.5 text-emerald-500"></i>
                            <span>New Table</span>
                        </button>
                        <button @click="openCreateViewModal(); open = false" class="w-full text-left p-2 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 hover:bg-purple-500/10 hover:text-purple-600 dark:hover:text-purple-400 flex items-center gap-2 transition">
                            <i data-lucide="eye" class="w-3.5 h-3.5 text-purple-500"></i>
                            <span>New Virtual View</span>
                        </button>
                        <button @click="showNewDbModal = true; open = false" class="w-full text-left p-2 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 hover:bg-sky-500/10 hover:text-sky-600 dark:hover:text-sky-400 flex items-center gap-2 transition">
                            <i data-lucide="database" class="w-3.5 h-3.5 text-sky-500"></i>
                            <span>New Database File</span>
                        </button>
                    </div>
                </div>

                <!-- 2. 🛠️ TOOLS & UTILITIES DROPDOWN MENU -->
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" @click.away="open = false" class="bg-slate-100 dark:bg-slate-800/80 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs font-medium px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 transition flex items-center gap-1.5 active:scale-95">
                        <i data-lucide="wrench" class="w-3.5 h-3.5 text-sky-500"></i>
                        <span class="hidden sm:inline">Tools</span>
                        <i data-lucide="chevron-down" class="w-3 h-3 transition" :class="open ? 'rotate-180' : ''"></i>
                    </button>

                    <div x-show="open" x-cloak class="absolute right-0 top-full mt-2 w-52 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl overflow-hidden p-1.5 space-y-1 z-50">
                        <button @click="showImportModal = true; open = false" class="w-full text-left p-2 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 hover:bg-sky-500/10 hover:text-sky-600 dark:hover:text-sky-400 flex items-center gap-2 transition">
                            <i data-lucide="file-up" class="w-3.5 h-3.5 text-sky-500"></i>
                            <span>Import CSV / JSON</span>
                        </button>
                        <button @click="showUploadDbModal = true; open = false" class="w-full text-left p-2 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 hover:bg-indigo-500/10 hover:text-indigo-600 dark:hover:text-indigo-400 flex items-center gap-2 transition">
                            <i data-lucide="upload-cloud" class="w-3.5 h-3.5 text-indigo-500"></i>
                            <span>Upload DB File</span>
                        </button>
                        <a :href="'?api=download_db&db_path=' + encodeURIComponent(activeDb)" @click="open = false" class="w-full text-left p-2 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 hover:bg-emerald-500/10 hover:text-emerald-600 dark:hover:text-emerald-400 flex items-center gap-2 transition">
                            <i data-lucide="hard-drive-download" class="w-3.5 h-3.5 text-emerald-500"></i>
                            <span>Backup DB File</span>
                        </a>
                        <button @click="openDbDiffModal(); open = false" class="w-full text-left p-2 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 hover:bg-amber-500/10 hover:text-amber-600 dark:hover:text-amber-400 flex items-center gap-2 transition">
                            <i data-lucide="git-compare" class="w-3.5 h-3.5 text-amber-500"></i>
                            <span>Dual DB Diff Tool</span>
                        </button>
                        <button @click="openMockDataModal(); open = false" class="w-full text-left p-2 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 hover:bg-purple-500/10 hover:text-purple-600 dark:hover:text-purple-400 flex items-center gap-2 transition">
                            <i data-lucide="dices" class="w-3.5 h-3.5 text-purple-500"></i>
                            <span>Mock Data Generator</span>
                        </button>
                        <button @click="openDdlModal(); open = false" class="w-full text-left p-2 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 hover:bg-emerald-500/10 hover:text-emerald-600 dark:hover:text-emerald-400 flex items-center gap-2 transition">
                            <i data-lucide="file-code" class="w-3.5 h-3.5 text-emerald-500"></i>
                            <span>Export Schema DDL (.sql)</span>
                        </button>
                        <button @click="openCodeGeneratorModal(); open = false" class="w-full text-left p-2 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 hover:bg-sky-500/10 hover:text-sky-600 dark:hover:text-sky-400 flex items-center gap-2 transition">
                            <i data-lucide="code" class="w-3.5 h-3.5 text-sky-500"></i>
                            <span>Code Snippet Generator</span>
                        </button>
                        <a href="?api=download_all_zip" @click="open = false" class="w-full text-left p-2 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 hover:bg-emerald-500/10 hover:text-emerald-600 dark:hover:text-emerald-400 flex items-center gap-2 transition border-t border-slate-100 dark:border-slate-800" title="Download ZIP containing ALL server SQLite databases">
                            <i data-lucide="archive" class="w-3.5 h-3.5 text-emerald-500"></i>
                            <span>Zip All Databases (.zip)</span>
                        </a>
                    </div>
                </div>

                <!-- 3. SQL CONSOLE DIRECT BUTTON -->
                <button @click="activeTab = 'query'" class="bg-sky-500/10 hover:bg-sky-500/20 text-sky-600 dark:text-sky-400 border border-sky-500/20 text-xs font-semibold px-3 py-1.5 rounded-xl transition flex items-center gap-1.5">
                    <i data-lucide="terminal" class="w-3.5 h-3.5"></i>
                    <span class="hidden sm:inline">SQL Console</span>
                </button>

                <!-- 4. COMPACT 3-WAY THEME SELECTOR -->
                <div class="flex items-center bg-slate-100 dark:bg-slate-800/80 p-0.5 rounded-xl border border-slate-300 dark:border-slate-700 text-xs">
                    <button @click="setTheme('light')" :class="themeMode === 'light' ? 'bg-white text-sky-600 shadow-sm' : 'text-slate-400 hover:text-slate-700 dark:hover:text-white'" class="p-1.5 rounded-lg transition" title="Day Light Mode">
                        <i data-lucide="sun" class="w-3.5 h-3.5 text-amber-500"></i>
                    </button>
                    <button @click="setTheme('dark')" :class="themeMode === 'dark' ? 'bg-slate-900 text-sky-400 shadow-sm' : 'text-slate-400 hover:text-slate-700 dark:hover:text-white'" class="p-1.5 rounded-lg transition" title="Night Dark Mode">
                        <i data-lucide="moon" class="w-3.5 h-3.5 text-sky-400"></i>
                    </button>
                    <button @click="setTheme('system')" :class="themeMode === 'system' ? 'bg-white dark:bg-slate-900 text-sky-600 dark:text-sky-400 shadow-sm' : 'text-slate-400 hover:text-slate-700 dark:hover:text-white'" class="p-1.5 rounded-lg transition" title="System Auto Mode">
                        <i data-lucide="laptop" class="w-3.5 h-3.5 text-indigo-400"></i>
                    </button>
                </div>

                <!-- 5. ⚙️ SETTINGS & USER PROFILE DROPDOWN MENU -->
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" @click.away="open = false" class="bg-slate-100 dark:bg-slate-800/80 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 p-1.5 rounded-xl border border-slate-300 dark:border-slate-700 transition flex items-center gap-1 active:scale-95">
                        <i data-lucide="settings" class="w-4 h-4 text-slate-500 dark:text-slate-400"></i>
                        <i data-lucide="chevron-down" class="w-3 h-3 text-slate-400"></i>
                    </button>

                    <div x-show="open" x-cloak class="absolute right-0 top-full mt-2 w-48 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl overflow-hidden p-1.5 space-y-1 z-50">
                        <button @click="showSecurityModal = true; open = false" class="w-full text-left p-2 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 hover:bg-amber-500/10 hover:text-amber-600 dark:hover:text-amber-400 flex items-center gap-2 transition">
                            <i data-lucide="shield" class="w-3.5 h-3.5 text-amber-500"></i>
                            <span>Security Settings</span>
                        </button>
                        <a href="?action=logout" class="w-full text-left p-2 rounded-xl text-xs font-medium text-rose-600 dark:text-rose-400 hover:bg-rose-500/10 flex items-center gap-2 transition">
                            <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- MAIN WORKSPACE -->
        <div class="flex-1 flex overflow-hidden">
            <!-- SIDEBAR: Databases & Tables Explorer -->
            <aside class="w-64 bg-white/70 dark:bg-slate-900/50 backdrop-blur-sm border-r border-slate-200 dark:border-slate-800/80 flex flex-col shrink-0">
                <div class="p-3 border-b border-slate-200 dark:border-slate-800/80 space-y-2">
                    <div class="flex items-center justify-between text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">
                        <span>Databases</span>
                        <div class="flex items-center gap-1.5">
                            <a href="?api=download_all_zip" class="hover:text-emerald-500 text-slate-400 transition" title="Download Zip Archive of ALL Server DBs"><i data-lucide="archive" class="w-3 h-3"></i></a>
                            <button @click="loadDatabases()" class="hover:text-slate-900 dark:hover:text-white transition" title="Refresh Database List"><i data-lucide="refresh-cw" class="w-3 h-3"></i></button>
                        </div>
                    </div>

                    <select @change="selectDb($event.target.value)" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 text-xs text-slate-800 dark:text-slate-200 rounded-xl p-2 focus:outline-none focus:border-sky-500 font-medium">
                        <option value="">-- Select Database --</option>
                        <template x-for="db in databases" :key="db.path">
                            <option :value="db.path" :selected="db.path === activeDb" x-text="db.name + ' (' + db.formatted_size + ')'"></option>
                        </template>
                    </select>
                </div>

                <div class="flex-1 flex flex-col min-h-0">
                    <div class="p-3 border-b border-slate-200 dark:border-slate-800/80 flex items-center justify-between">
                        <div class="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">
                            Tables & Views (<span x-text="tables.length"></span>)
                        </div>
                        <button @click="loadTables()" class="hover:text-slate-900 dark:hover:text-white text-slate-400 text-xs transition"><i data-lucide="rotate-cw" class="w-3 h-3"></i></button>
                    </div>

                    <div class="p-2">
                        <input type="text" x-model="tableSearch" placeholder="Filter tables..." class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-sky-500">
                    </div>

                    <div class="flex-1 overflow-y-auto p-2 space-y-1">
                        <template x-for="tbl in filteredTables" :key="tbl.name">
                            <button @click="selectTable(tbl.name)" :class="activeTable === tbl.name ? 'bg-sky-500/10 dark:bg-sky-600/15 text-sky-600 dark:text-sky-400 border-sky-500/40 font-semibold shadow-sm' : 'text-slate-700 dark:text-slate-300 hover:bg-slate-200/60 dark:hover:bg-slate-800/60 border-transparent'" class="w-full text-left px-3 py-2.5 rounded-xl text-xs border transition flex items-center justify-between group">
                                <div class="flex items-center gap-2 truncate">
                                    <i :data-lucide="tbl.type === 'view' ? 'eye' : 'table'" class="w-3.5 h-3.5 shrink-0" :class="tbl.type === 'view' ? 'text-amber-500' : 'text-slate-400'"></i>
                                    <span class="truncate" x-text="tbl.name"></span>
                                </div>
                                <span class="text-[10px] bg-slate-200 dark:bg-slate-800/90 group-hover:bg-slate-300 dark:group-hover:bg-slate-700 text-slate-600 dark:text-slate-400 px-2 py-0.5 rounded-full font-mono" x-text="tbl.rows"></span>
                            </button>
                        </template>

                        <template x-if="filteredTables.length === 0">
                            <div class="text-xs text-slate-500 text-center py-8">No tables found.</div>
                        </template>
                    </div>
                </div>
            </aside>

            <!-- CENTER DISPLAY AREA -->
            <main class="flex-1 flex flex-col bg-white dark:bg-slate-950 overflow-hidden">
                <template x-if="!activeDb">
                    <div class="flex-1 flex flex-col items-center justify-center p-8 text-center space-y-4">
                        <div class="w-16 h-16 rounded-3xl bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 flex items-center justify-center text-slate-400 dark:text-slate-500 shadow-inner">
                            <i data-lucide="database" class="w-8 h-8"></i>
                        </div>
                        <div class="space-y-1">
                            <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-200">No Database Selected</h3>
                            <p class="text-xs text-slate-500 max-w-sm">Please select a database file from the left sidebar to begin managing tables and viewing records.</p>
                        </div>
                    </div>
                </template>

                <template x-if="activeDb">
                    <div class="h-full flex flex-col overflow-hidden">
                        <!-- TAB NAVIGATION BAR -->
                        <div class="h-11 bg-slate-50 dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800/80 px-4 flex items-center justify-between shrink-0">
                            <div class="flex items-center gap-1">
                                <button @click="activeTab = 'data'" :class="activeTab === 'data' ? 'bg-white dark:bg-slate-800 text-sky-600 dark:text-sky-400 border-slate-300 dark:border-slate-700 font-semibold shadow-sm' : 'text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200 border-transparent'" class="px-3.5 py-1.5 text-xs rounded-xl border transition flex items-center gap-1.5">
                                    <i data-lucide="grid" class="w-3.5 h-3.5"></i>
                                    <span>Data Grid</span>
                                </button>

                                <button @click="activeTab = 'structure'; loadSchema()" :class="activeTab === 'structure' ? 'bg-white dark:bg-slate-800 text-sky-600 dark:text-sky-400 border-slate-300 dark:border-slate-700 font-semibold shadow-sm' : 'text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200 border-transparent'" class="px-3.5 py-1.5 text-xs rounded-xl border transition flex items-center gap-1.5">
                                    <i data-lucide="layers" class="w-3.5 h-3.5"></i>
                                    <span>Structure</span>
                                </button>

                                <button @click="activeTab = 'query'" :class="activeTab === 'query' ? 'bg-white dark:bg-slate-800 text-sky-600 dark:text-sky-400 border-slate-300 dark:border-slate-700 font-semibold shadow-sm' : 'text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200 border-transparent'" class="px-3.5 py-1.5 text-xs rounded-xl border transition flex items-center gap-1.5">
                                    <i data-lucide="terminal" class="w-3.5 h-3.5"></i>
                                    <span>SQL Query</span>
                                </button>

                                <button @click="activeTab = 'analytics'; loadAnalytics()" :class="activeTab === 'analytics' ? 'bg-white dark:bg-slate-800 text-sky-600 dark:text-sky-400 border-slate-300 dark:border-slate-700 font-semibold shadow-sm' : 'text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200 border-transparent'" class="px-3.5 py-1.5 text-xs rounded-xl border transition flex items-center gap-1.5">
                                    <i data-lucide="activity" class="w-3.5 h-3.5 text-emerald-500"></i>
                                    <span>Analytics & Health</span>
                                </button>

                                <button @click="activeTab = 'er'; loadErDiagram()" :class="activeTab === 'er' ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 border-slate-300 dark:border-slate-700 font-semibold shadow-sm' : 'text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200 border-transparent'" class="px-3.5 py-1.5 text-xs rounded-xl border transition flex items-center gap-1.5">
                                    <i data-lucide="git-fork" class="w-3.5 h-3.5 text-indigo-500"></i>
                                    <span>ER Diagram</span>
                                </button>
                            </div>

                            <template x-if="activeTable">
                                <div class="flex items-center gap-2">
                                    <!-- 1. PRIMARY ACTION BUTTON -->
                                    <button @click="openInsertModal()" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-3.5 py-1.5 rounded-xl transition shadow-sm flex items-center gap-1.5 active:scale-95">
                                        <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                        <span>Insert Row</span>
                                    </button>

                                    <!-- 2. TABLE ACTIONS DROPDOWN MENU -->
                                    <div class="relative" x-data="{ open: false }">
                                        <button @click="open = !open" @click.away="open = false" class="bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-medium px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 transition flex items-center gap-1.5 active:scale-95">
                                            <i data-lucide="settings-2" class="w-3.5 h-3.5 text-sky-500"></i>
                                            <span>Table Actions</span>
                                            <i data-lucide="chevron-down" class="w-3 h-3 text-slate-400 transition" :class="open ? 'rotate-180' : ''"></i>
                                        </button>

                                        <div x-show="open" x-cloak class="absolute right-0 mt-2 w-48 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl p-1.5 z-50 text-xs space-y-1">
                                            <button @click="openMockDataModal(); open = false" class="w-full text-left p-2 rounded-xl text-slate-800 dark:text-slate-200 hover:bg-purple-500/10 hover:text-purple-600 dark:hover:text-purple-400 flex items-center gap-2 transition font-medium">
                                                <i data-lucide="dices" class="w-3.5 h-3.5 text-purple-500"></i>
                                                <span>Generate Mock Data</span>
                                            </button>
                                            <button @click="openDuplicateTableModal(); open = false" class="w-full text-left p-2 rounded-xl text-slate-800 dark:text-slate-200 hover:bg-emerald-500/10 hover:text-emerald-600 dark:hover:text-emerald-400 flex items-center gap-2 transition font-medium">
                                                <i data-lucide="copy" class="w-3.5 h-3.5 text-emerald-500"></i>
                                                <span>Duplicate Table</span>
                                            </button>
                                            <button @click="openRenameTableModal(); open = false" class="w-full text-left p-2 rounded-xl text-slate-800 dark:text-slate-200 hover:bg-sky-500/10 hover:text-sky-600 dark:hover:text-sky-400 flex items-center gap-2 transition font-medium">
                                                <i data-lucide="edit-2" class="w-3.5 h-3.5 text-sky-500"></i>
                                                <span>Rename Table</span>
                                            </button>
                                            <button @click="truncateActiveTable(); open = false" class="w-full text-left p-2 rounded-xl text-amber-600 dark:text-amber-400 hover:bg-amber-500/10 flex items-center gap-2 transition font-medium border-t border-slate-100 dark:border-slate-800">
                                                <i data-lucide="scissors" class="w-3.5 h-3.5"></i>
                                                <span>Truncate (Empty Table)</span>
                                            </button>
                                            <button @click="dropActiveTable(); open = false" class="w-full text-left p-2 rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-500/10 flex items-center gap-2 transition font-medium">
                                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                <span>Drop Table</span>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- 3. EXPORT DROPDOWN MENU -->
                                    <div class="relative" x-data="{ open: false }">
                                        <button @click="open = !open" @click.away="open = false" class="bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-medium px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 transition flex items-center gap-1.5 active:scale-95">
                                            <i data-lucide="download" class="w-3.5 h-3.5 text-emerald-500"></i>
                                            <span>Export</span>
                                            <i data-lucide="chevron-down" class="w-3 h-3 text-slate-400 transition" :class="open ? 'rotate-180' : ''"></i>
                                        </button>

                                        <div x-show="open" x-cloak class="absolute right-0 mt-2 w-40 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl p-1.5 z-50 text-xs space-y-1">
                                            <a :href="'?api=export_csv&table=' + activeTable" target="_blank" @click="open = false" class="flex items-center gap-2 p-2 rounded-xl text-slate-800 dark:text-slate-200 hover:bg-emerald-500/10 hover:text-emerald-600 dark:hover:text-emerald-400 transition font-medium">
                                                <i data-lucide="file-spreadsheet" class="w-3.5 h-3.5 text-emerald-500"></i>
                                                <span>Export CSV</span>
                                            </a>
                                            <a :href="'?api=export_json&table=' + activeTable" target="_blank" @click="open = false" class="flex items-center gap-2 p-2 rounded-xl text-slate-800 dark:text-slate-200 hover:bg-amber-500/10 hover:text-amber-600 dark:hover:text-amber-400 transition font-medium">
                                                <i data-lucide="file-json" class="w-3.5 h-3.5 text-amber-500"></i>
                                                <span>Export JSON</span>
                                            </a>
                                            <a :href="'?api=export_sql&table=' + activeTable" target="_blank" @click="open = false" class="flex items-center gap-2 p-2 rounded-xl text-slate-800 dark:text-slate-200 hover:bg-sky-500/10 hover:text-sky-600 dark:hover:text-sky-400 transition font-medium border-t border-slate-100 dark:border-slate-800">
                                                <i data-lucide="database" class="w-3.5 h-3.5 text-sky-500"></i>
                                                <span>Export SQL Dump</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- TAB 1: EXCEL-STYLE DATA GRID (Double-Click Inline Editing, Row Action Edit & Bulk Actions) -->
                        <div x-show="activeTab === 'data'" class="flex-1 flex flex-col overflow-hidden relative">
                            <div class="p-3 bg-slate-50/60 dark:bg-slate-900/40 border-b border-slate-200 dark:border-slate-800/80 flex items-center justify-between gap-4 shrink-0">
                                <div class="flex items-center gap-2 flex-1 max-w-md">
                                    <div class="relative w-full">
                                        <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-2.5"></i>
                                        <input type="text" x-model="dataSearch" @keyup.enter="loadData()" placeholder="Search across all columns..." class="w-full bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl pl-9 pr-3 py-1.5 text-xs text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-sky-500">
                                    </div>
                                    <button @click="loadData()" class="bg-sky-600 hover:bg-sky-500 text-white text-xs px-3 py-1.5 rounded-xl transition font-medium">Search</button>
                                </div>

                                <div class="flex items-center gap-3 text-xs text-slate-500 dark:text-slate-400 font-medium">
                                    <span x-text="'Total: ' + totalRows + ' rows'"></span>

                                    <select x-model="pageLimit" @change="currentPage = 1; loadData()" class="bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-2 py-1 text-xs text-slate-800 dark:text-slate-200">
                                        <option value="10">10 / page</option>
                                        <option value="25">25 / page</option>
                                        <option value="50">50 / page</option>
                                        <option value="100">100 / page</option>
                                        <option value="250">250 / page</option>
                                        <option value="500">500 / page</option>
                                    </select>

                                    <div class="flex items-center gap-1">
                                        <button @click="currentPage > 1 && (currentPage--, loadData())" :disabled="currentPage <= 1" class="p-1 rounded-lg border border-slate-300 dark:border-slate-800 hover:bg-slate-200 dark:hover:bg-slate-800 disabled:opacity-30"><i data-lucide="chevron-left" class="w-3.5 h-3.5"></i></button>
                                        <span class="px-2 font-mono" x-text="currentPage + ' / ' + totalPages"></span>
                                        <button @click="currentPage < totalPages && (currentPage++, loadData())" :disabled="currentPage >= totalPages" class="p-1 rounded-lg border border-slate-300 dark:border-slate-800 hover:bg-slate-200 dark:hover:bg-slate-800 disabled:opacity-30"><i data-lucide="chevron-right" class="w-3.5 h-3.5"></i></button>
                                    </div>
                                </div>
                            </div>

                            <!-- DATA TABLE GRID -->
                            <div class="flex-1 overflow-auto">
                                <template x-if="loading">
                                    <div class="p-12 text-center text-xs text-slate-400 flex items-center justify-center gap-2">
                                        <i data-lucide="loader-2" class="w-4 h-4 animate-spin text-sky-500"></i>
                                        <span>Loading record set...</span>
                                    </div>
                                </template>

                                <template x-if="!loading && tableColumns.length === 0">
                                    <div class="p-12 text-center text-xs text-slate-500">Select a table from the sidebar to view data grid.</div>
                                </template>

                                <template x-if="!loading && tableColumns.length > 0">
                                    <table class="w-full text-left border-collapse text-xs">
                                        <thead class="sticky top-0 bg-slate-100 dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800/80 text-slate-700 dark:text-slate-300 font-semibold shadow-sm z-10">
                                            <tr>
                                                <!-- Bulk Select Checkbox Header -->
                                                <th class="p-2.5 w-10 text-center border-r border-slate-200 dark:border-slate-800/60 bg-slate-100 dark:bg-slate-900">
                                                    <input type="checkbox" @change="toggleSelectAll($event.target.checked)" :checked="isAllSelected" class="w-4 h-4 rounded border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sky-600 focus:ring-sky-500 cursor-pointer">
                                                </th>
                                                <th class="p-2.5 w-10 text-center border-r border-slate-200 dark:border-slate-800/60 bg-slate-100 dark:bg-slate-900">#</th>
                                                <template x-for="col in tableColumns" :key="col.name">
                                                    <th @click="sortData(col.name)" class="p-2.5 border-r border-slate-200 dark:border-slate-800/60 hover:bg-slate-200/60 dark:hover:bg-slate-800/80 cursor-pointer select-none transition" :class="sortColumn === col.name ? 'bg-sky-500/10 dark:bg-sky-500/20 text-sky-600 dark:text-sky-400 font-bold' : ''" title="Click to sort by this column">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <div class="flex items-center gap-1.5 truncate">
                                                                <span x-text="col.name" class="font-semibold"></span>
                                                                <template x-if="col.pk > 0">
                                                                    <span class="text-[9px] bg-amber-500/20 text-amber-600 dark:text-amber-300 border border-amber-500/30 px-1 rounded font-bold">PK</span>
                                                                </template>
                                                            </div>
                                                            <div class="flex items-center">
                                                                <template x-if="sortColumn === col.name">
                                                                    <span class="text-[10px] font-mono font-bold px-1.5 py-0.5 rounded bg-sky-600 text-white shadow-xs" x-text="sortDir === 'ASC' ? '▲ ASC' : '▼ DESC'"></span>
                                                                </template>
                                                                <template x-if="sortColumn !== col.name">
                                                                    <span class="text-slate-400 dark:text-slate-600 text-[10px]">↕</span>
                                                                </template>
                                                            </div>
                                                        </div>
                                                    </th>
                                                </template>
                                                <th class="p-2.5 w-28 text-center whitespace-nowrap">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800/60">
                                            <template x-for="(row, rIdx) in tableRows" :key="rIdx">
                                                <tr :class="row._selected ? 'bg-sky-50 dark:bg-sky-950/40' : 'hover:bg-slate-50 dark:hover:bg-slate-900/60'" class="transition group">
                                                    <!-- Bulk Select Checkbox Cell -->
                                                    <td class="p-2 text-center border-r border-slate-200 dark:border-slate-800/60">
                                                        <input type="checkbox" x-model="row._selected" class="w-4 h-4 rounded border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 text-sky-600 focus:ring-sky-500 cursor-pointer">
                                                    </td>
                                                    <td class="p-2 text-center text-slate-400 dark:text-slate-500 font-mono text-[10px] border-r border-slate-200 dark:border-slate-800/60" x-text="(currentPage - 1) * pageLimit + rIdx + 1"></td>
                                                    <template x-for="col in tableColumns" :key="col.name">
                                                        <td @dblclick="startCellEdit(rIdx, col.name, row[col.name])" 
                                                            :class="editingCell.row === rIdx && editingCell.col === col.name ? 'p-0 ring-2 ring-sky-500 bg-sky-50 dark:bg-sky-950 z-20' : 'p-2.5 border-r border-slate-200 dark:border-slate-800/60 cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800/40'"
                                                            class="relative text-slate-800 dark:text-slate-300 font-mono max-w-xs truncate">
                                                            
                                                            <template x-if="!(editingCell.row === rIdx && editingCell.col === col.name)">
                                                                <span :class="row[col.name] === null ? 'text-slate-400 dark:text-slate-600 italic text-[11px]' : ''" 
                                                                      x-text="row[col.name] === null ? 'NULL' : row[col.name]"></span>
                                                            </template>

                                                            <template x-if="editingCell.row === rIdx && editingCell.col === col.name">
                                                                <input type="text" 
                                                                       x-model="editingCell.value" 
                                                                       @keyup.enter="saveCellEdit(row)" 
                                                                       @keyup.escape="editingCell = {row: null, col: null, value: ''}"
                                                                       @blur="saveCellEdit(row)" 
                                                                       x-ref="editInput"
                                                                       x-init="$nextTick(() => $el.focus())"
                                                                       class="w-full h-full bg-white dark:bg-slate-950 text-slate-900 dark:text-white px-2 py-1.5 text-xs border-none focus:outline-none font-mono">
                                                            </template>
                                                        </td>
                                                    </template>
                                                    <td class="p-2 text-center whitespace-nowrap">
                                                        <div class="inline-flex items-center justify-center gap-1">
                                                            <button @click="inspectRow(row)" class="text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 p-1.5 rounded-lg hover:bg-indigo-500/10 transition" title="Inspect Record Details"><i data-lucide="eye" class="w-3.5 h-3.5"></i></button>
                                                            <button @click="openEditRowModal(row)" class="text-slate-400 hover:text-sky-600 dark:hover:text-sky-400 p-1.5 rounded-lg hover:bg-sky-500/10 transition" title="Edit Full Record"><i data-lucide="edit-3" class="w-3.5 h-3.5"></i></button>
                                                            <button @click="deleteRow(row)" class="text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 p-1.5 rounded-lg hover:bg-rose-500/10 transition" title="Delete Record"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </template>
                            </div>

                            <!-- FLOATING BULK ACTIONS BAR -->
                            <div x-show="selectedRows.length > 0" x-cloak class="fixed bottom-8 left-1/2 -translate-x-1/2 bg-white/95 dark:bg-slate-900/95 backdrop-blur-2xl border border-sky-500/30 text-slate-900 dark:text-white px-6 py-3 rounded-2xl shadow-[0_10px_40px_rgba(0,0,0,0.3)] dark:shadow-[0_10px_40px_rgba(0,0,0,0.8)] flex items-center gap-6 z-50">
                                <div class="flex items-center gap-2">
                                    <span class="flex h-2.5 w-2.5 relative">
                                      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-sky-400 opacity-75"></span>
                                      <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-sky-500"></span>
                                    </span>
                                    <span class="text-xs font-bold text-slate-800 dark:text-slate-200" x-text="selectedRows.length + ' row(s) selected'"></span>
                                </div>

                                <div class="flex items-center gap-3">
                                    <button @click="bulkDeleteSelected()" class="bg-gradient-to-r from-rose-600 to-red-600 hover:from-rose-500 hover:to-red-500 text-white text-xs font-bold px-4 py-2 rounded-xl transition shadow-lg shadow-rose-600/30 flex items-center gap-1.5 active:scale-95">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                        <span x-text="'Delete Selected (' + selectedRows.length + ')'"></span>
                                    </button>

                                    <button @click="toggleSelectAll(false)" class="text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white px-2 py-1 transition">Deselect All</button>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 2: STRUCTURE -->
                        <div x-show="activeTab === 'structure'" class="flex-1 overflow-auto p-6 space-y-6">
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-widest flex items-center gap-2">
                                        <i data-lucide="columns" class="w-4 h-4 text-sky-500"></i>
                                        <span>Columns Definition</span>
                                    </h3>

                                    <div class="flex items-center gap-2">
                                        <button @click="openReorderColsModal()" class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition shadow-md shadow-indigo-600/20 flex items-center gap-1.5 active:scale-95" title="Reorder Table Column Positions">
                                            <i data-lucide="arrow-up-down" class="w-3.5 h-3.5"></i>
                                            <span>Reorder Columns</span>
                                        </button>

                                        <button @click="openBulkRenameColModal()" class="bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs font-semibold px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 transition flex items-center gap-1.5">
                                            <i data-lucide="edit-3" class="w-3.5 h-3.5 text-sky-500"></i>
                                            <span>Batch Rename Columns</span>
                                        </button>

                                        <button @click="showAddColModal = true" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition flex items-center gap-1.5">
                                            <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                            <span>Add Column</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- COLUMN SEARCH & QUICK FILTER BAR -->
                                <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-50 dark:bg-slate-900/80 p-2.5 rounded-2xl border border-slate-200 dark:border-slate-800">
                                    <div class="relative flex-1 min-w-[200px]">
                                        <input type="text" x-model="colSearch" placeholder="🔍 Search columns by name or type..." class="w-full bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-sky-500">
                                    </div>

                                    <div class="flex items-center gap-1.5 text-[11px]">
                                        <span class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mr-1">Filter:</span>
                                        <button @click="colFilterType = 'all'" :class="colFilterType === 'all' ? 'bg-sky-600 text-white font-bold' : 'bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-2.5 py-1 rounded-lg transition">All (<span x-text="schema.columns ? schema.columns.length : 0"></span>)</button>
                                        <button @click="colFilterType = 'pk'" :class="colFilterType === 'pk' ? 'bg-emerald-600 text-white font-bold' : 'bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-2.5 py-1 rounded-lg transition">🔑 PKs</button>
                                        <button @click="colFilterType = 'notnull'" :class="colFilterType === 'notnull' ? 'bg-amber-600 text-white font-bold' : 'bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-2.5 py-1 rounded-lg transition">🛡️ NOT NULL</button>
                                    </div>
                                </div>

                                <table class="w-full text-left border-collapse text-xs bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800/80 rounded-2xl overflow-hidden shadow-sm">
                                    <thead class="bg-slate-100 dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800/80 text-slate-600 dark:text-slate-400 font-semibold">
                                        <tr>
                                            <th class="p-3">#</th>
                                            <th class="p-3">Name</th>
                                            <th class="p-3">Type</th>
                                            <th class="p-3">Not Null</th>
                                            <th class="p-3">Default Value</th>
                                            <th class="p-3">Primary Key</th>
                                            <th class="p-3">Description / Note</th>
                                            <th class="p-3 text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800/60">
                                        <template x-for="c in filteredColumns" :key="c.name">
                                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                                <td class="p-3 text-slate-400 dark:text-slate-500 font-mono text-[11px]" x-text="c.cid"></td>
                                                <td class="p-3 font-semibold text-slate-800 dark:text-slate-200" x-text="c.name"></td>
                                                <td class="p-3 text-sky-600 dark:text-sky-400 font-mono" x-text="c.type || 'ANY'"></td>
                                                <td class="p-3"><span :class="c.notnull ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-slate-400 dark:text-slate-500'" x-text="c.notnull ? 'YES' : 'NO'"></span></td>
                                                <td class="p-3 font-mono text-slate-500 dark:text-slate-400" x-text="c.dflt_value === null ? 'NULL' : c.dflt_value"></td>
                                                <td class="p-3"><span :class="c.pk > 0 ? 'text-emerald-600 dark:text-emerald-400 font-bold' : 'text-slate-400 dark:text-slate-600'" x-text="c.pk > 0 ? 'PRIMARY KEY (' + c.pk + ')' : '-'"></span></td>
                                                <td class="p-3">
                                                    <button @click="openCommentModal(c.name)" class="text-xs hover:underline flex items-center gap-1.5 transition text-left" :class="colComments[c.name] ? 'text-amber-600 dark:text-amber-400 font-medium' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 italic'">
                                                        <i data-lucide="message-square" class="w-3.5 h-3.5 shrink-0" :class="colComments[c.name] ? 'text-amber-500 fill-amber-500/20' : 'text-slate-400'"></i>
                                                        <span x-text="colComments[c.name] || 'Add note...'" class="truncate max-w-[160px]"></span>
                                                    </button>
                                                </td>
                                                <td class="p-3 text-center">
                                                    <div class="inline-flex items-center justify-center gap-2">
                                                        <button @click="openRenameColModal(c.name)" class="text-slate-400 hover:text-sky-600 dark:hover:text-sky-400 p-1 rounded-lg hover:bg-sky-500/10 transition font-medium text-xs flex items-center gap-1" title="Rename Column">
                                                            <i data-lucide="edit-2" class="w-3.5 h-3.5"></i>
                                                            <span>Rename</span>
                                                        </button>
                                                        <button @click="openDuplicateColModal(c.name)" class="text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 p-1 rounded-lg hover:bg-indigo-500/10 transition font-medium text-xs flex items-center gap-1" title="Duplicate Column & Clone Data">
                                                            <i data-lucide="copy" class="w-3.5 h-3.5 text-indigo-500"></i>
                                                            <span>Duplicate</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>

                            <!-- INDEXES SECTION -->
                            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 space-y-4 shadow-sm mt-6">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-xs font-bold text-slate-800 dark:text-slate-200 uppercase tracking-widest flex items-center gap-2">
                                        <i data-lucide="key" class="w-4 h-4 text-sky-500"></i>
                                        <span>Table Indexes & B-Trees (<span x-text="schema.indexes ? schema.indexes.length : 0"></span>)</span>
                                    </h3>

                                    <button @click="openCreateIndexModal()" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition shadow-sm flex items-center gap-1.5">
                                        <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                        <span>Create Index</span>
                                    </button>
                                </div>

                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-xs font-mono">
                                        <thead>
                                            <tr class="bg-slate-50 dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800 text-slate-500 font-semibold uppercase text-[10px]">
                                                <th class="p-3">Index Name</th>
                                                <th class="p-3">Type</th>
                                                <th class="p-3">Indexed Columns</th>
                                                <th class="p-3 text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                                            <template x-for="idx in schema.indexes" :key="idx.name">
                                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40">
                                                    <td class="p-3 font-semibold text-slate-900 dark:text-white" x-text="idx.name"></td>
                                                    <td class="p-3">
                                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold" :class="idx.unique == 1 ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20' : 'bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400'" x-text="idx.unique == 1 ? 'UNIQUE' : 'INDEX'"></span>
                                                    </td>
                                                    <td class="p-3 text-sky-600 dark:text-sky-400 font-semibold" x-text="idx.columns || 'Multiple'"></td>
                                                    <td class="p-3 text-right">
                                                        <template x-if="!idx.name.startsWith('sqlite_autoindex')">
                                                            <button @click="dropIndex(idx.name)" class="text-rose-500 hover:text-rose-600 font-semibold transition text-xs flex items-center gap-1 ml-auto">
                                                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                                <span>Drop</span>
                                                            </button>
                                                        </template>
                                                    </td>
                                                </tr>
                                            </template>
                                            <template x-if="!schema.indexes || schema.indexes.length === 0">
                                                <tr>
                                                    <td colspan="4" class="p-4 text-center text-slate-400 font-sans">No indexes defined on this table.</td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- FOREIGN KEYS SECTION -->
                            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 space-y-4 shadow-sm mt-6">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-xs font-bold text-slate-800 dark:text-slate-200 uppercase tracking-widest flex items-center gap-2">
                                        <i data-lucide="link" class="w-4 h-4 text-emerald-500"></i>
                                        <span>Foreign Key Relational Constraints (<span x-text="schema.foreign_keys ? schema.foreign_keys.length : 0"></span>)</span>
                                    </h3>

                                    <button @click="openAddFkModal()" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition shadow-sm flex items-center gap-1.5">
                                        <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                        <span>Add Foreign Key</span>
                                    </button>
                                </div>

                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-xs font-mono">
                                        <thead>
                                            <tr class="bg-slate-50 dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800 text-slate-500 font-semibold uppercase text-[10px]">
                                                <th class="p-3">Local Column</th>
                                                <th class="p-3">Referenced Table & Column</th>
                                                <th class="p-3">ON DELETE</th>
                                                <th class="p-3">ON UPDATE</th>
                                                <th class="p-3 text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                                            <template x-for="fk in schema.foreign_keys" :key="fk.id">
                                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40">
                                                    <td class="p-3 font-semibold text-slate-900 dark:text-white" x-text="fk.from"></td>
                                                    <td class="p-3 text-sky-600 dark:text-sky-400 font-semibold" x-text="fk.table + '.' + fk.to"></td>
                                                    <td class="p-3">
                                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20" x-text="fk.on_delete"></span>
                                                    </td>
                                                    <td class="p-3 text-slate-500 dark:text-slate-400" x-text="fk.on_update"></td>
                                                    <td class="p-3 text-right">
                                                        <button @click="dropForeignKey(fk.id)" class="text-rose-500 hover:text-rose-600 font-semibold transition text-xs flex items-center gap-1 ml-auto">
                                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                            <span>Drop FK</span>
                                                        </button>
                                                    </td>
                                                </tr>
                                            </template>
                                            <template x-if="!schema.foreign_keys || schema.foreign_keys.length === 0">
                                                <tr>
                                                    <td colspan="5" class="p-4 text-center text-slate-400 font-sans">No foreign key relationships defined on this table.</td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- TRIGGERS SECTION -->
                            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 space-y-4 shadow-sm mt-6">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-xs font-bold text-slate-800 dark:text-slate-200 uppercase tracking-widest flex items-center gap-2">
                                        <i data-lucide="zap" class="w-4 h-4 text-purple-500"></i>
                                        <span>Database Triggers (<span x-text="schema.triggers ? schema.triggers.length : 0"></span>)</span>
                                    </h3>

                                    <button @click="openCreateTriggerModal()" class="bg-purple-600 hover:bg-purple-500 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition shadow-sm flex items-center gap-1.5">
                                        <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                        <span>Create Trigger</span>
                                    </button>
                                </div>

                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-xs font-mono">
                                        <thead>
                                            <tr class="bg-slate-50 dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800 text-slate-500 font-semibold uppercase text-[10px]">
                                                <th class="p-3">Trigger Name</th>
                                                <th class="p-3">Table</th>
                                                <th class="p-3">SQL Definition</th>
                                                <th class="p-3 text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                                            <template x-for="trg in schema.triggers" :key="trg.name">
                                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40">
                                                    <td class="p-3 font-semibold text-purple-600 dark:text-purple-400" x-text="trg.name"></td>
                                                    <td class="p-3 font-semibold text-slate-900 dark:text-white" x-text="trg.tbl_name"></td>
                                                    <td class="p-3 text-slate-600 dark:text-slate-400 truncate max-w-xs font-mono text-[11px]" x-text="trg.sql"></td>
                                                    <td class="p-3 text-right">
                                                        <button @click="dropTrigger(trg.name)" class="text-rose-500 hover:text-rose-600 font-semibold transition text-xs flex items-center gap-1 ml-auto">
                                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                            <span>Drop</span>
                                                        </button>
                                                    </td>
                                                </tr>
                                            </template>
                                            <template x-if="!schema.triggers || schema.triggers.length === 0">
                                                <tr>
                                                    <td colspan="4" class="p-4 text-center text-slate-400 font-sans">No triggers defined on this table.</td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 3: LIVE SQL CONSOLE WITH SNIPPETS & QUERY HISTORY -->
                        <div x-show="activeTab === 'query'" class="flex-1 overflow-y-auto p-4 space-y-4" x-data="{ showHistory: false }">
                            <div class="bg-white/80 dark:bg-slate-900/80 border border-slate-200 dark:border-slate-800/80 rounded-2xl p-4 space-y-3 shrink-0 shadow-sm">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-widest flex items-center gap-2">
                                        <i data-lucide="code" class="w-4 h-4 text-sky-500"></i>
                                        <span>SQL Query Editor</span>
                                    </label>

                                    <div class="flex items-center gap-2">
                                        <button @click="openBenchmarkModal()" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white text-[11px] font-bold px-3 py-1 rounded-xl transition shadow-md shadow-purple-600/20 flex items-center gap-1.5 active:scale-95" title="Benchmark & Compare two SQL queries side-by-side">
                                            <i data-lucide="zap" class="w-3.5 h-3.5 text-amber-300"></i>
                                            <span>Benchmark & Compare</span>
                                        </button>

                                        <button @click="showSavedQueriesDrawer = !showSavedQueriesDrawer" :class="showSavedQueriesDrawer ? 'bg-amber-500/20 text-amber-600 dark:text-amber-400 border-amber-500/30' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border-slate-300 dark:border-slate-700'" class="text-[11px] font-semibold px-2.5 py-1 rounded-xl border transition flex items-center gap-1">
                                            <i data-lucide="star" class="w-3.5 h-3.5 text-amber-500"></i>
                                            <span>Favorites (<span x-text="savedQueries.length"></span>)</span>
                                        </button>

                                        <button @click="showHistory = !showHistory" :class="showHistory ? 'bg-sky-500/20 text-sky-600 dark:text-sky-400 border-sky-500/30' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border-slate-300 dark:border-slate-700'" class="text-[11px] font-semibold px-2.5 py-1 rounded-xl border transition flex items-center gap-1">
                                            <i data-lucide="history" class="w-3.5 h-3.5"></i>
                                            <span>History (<span x-text="queryHistory.length"></span>)</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- 1-CLICK SQL SNIPPETS TEMPLATES BAR -->
                                <div class="flex flex-wrap items-center gap-1.5 pt-1">
                                    <span class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mr-1">Templates:</span>
                                    <button @click="sqlQuery = 'SELECT * FROM `' + (activeTable || 'table') + '` LIMIT 50;'" class="text-[11px] bg-slate-100 dark:bg-slate-800/80 hover:bg-sky-500/10 hover:text-sky-600 dark:hover:text-sky-400 text-slate-700 dark:text-slate-300 px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-700/80 font-mono transition">SELECT *</button>
                                    <button @click="sqlQuery = 'SELECT COUNT(*) as total FROM `' + (activeTable || 'table') + '`;'" class="text-[11px] bg-slate-100 dark:bg-slate-800/80 hover:bg-sky-500/10 hover:text-sky-600 dark:hover:text-sky-400 text-slate-700 dark:text-slate-300 px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-700/80 font-mono transition">COUNT(*)</button>
                                    <button @click="sqlQuery = 'INSERT INTO `' + (activeTable || 'table') + '` (col1, col2) VALUES (\'val1\', \'val2\');'" class="text-[11px] bg-slate-100 dark:bg-slate-800/80 hover:bg-emerald-500/10 hover:text-emerald-600 dark:hover:text-emerald-400 text-slate-700 dark:text-slate-300 px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-700/80 font-mono transition">INSERT</button>
                                    <button @click="sqlQuery = 'UPDATE `' + (activeTable || 'table') + '` SET col1 = \'val1\' WHERE id = 1;'" class="text-[11px] bg-slate-100 dark:bg-slate-800/80 hover:bg-amber-500/10 hover:text-amber-600 dark:hover:text-amber-400 text-slate-700 dark:text-slate-300 px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-700/80 font-mono transition">UPDATE</button>
                                    <button @click="sqlQuery = 'DELETE FROM `' + (activeTable || 'table') + '` WHERE id = 1;'" class="text-[11px] bg-slate-100 dark:bg-slate-800/80 hover:bg-rose-500/10 hover:text-rose-600 dark:hover:text-rose-400 text-slate-700 dark:text-slate-300 px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-700/80 font-mono transition">DELETE</button>
                                    <button @click="sqlQuery = 'CREATE INDEX idx_' + (activeTable || 'table') + '_col ON `' + (activeTable || 'table') + '` (col_name);'" class="text-[11px] bg-slate-100 dark:bg-slate-800/80 hover:bg-indigo-500/10 hover:text-indigo-600 dark:hover:text-indigo-400 text-slate-700 dark:text-slate-300 px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-700/80 font-mono transition">CREATE INDEX</button>
                                    <button @click="sqlQuery = 'EXPLAIN QUERY PLAN SELECT * FROM `' + (activeTable || 'table') + '`;'" class="text-[11px] bg-slate-100 dark:bg-slate-800/80 hover:bg-teal-500/10 hover:text-teal-600 dark:hover:text-teal-400 text-slate-700 dark:text-slate-300 px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-700/80 font-mono transition">EXPLAIN</button>
                                    
                                    <button @click="formatSqlQuery()" class="text-[11px] bg-gradient-to-r from-sky-500/10 to-indigo-500/10 hover:from-sky-500/20 hover:to-indigo-500/20 text-sky-600 dark:text-sky-300 border border-sky-500/30 px-3 py-1 rounded-lg font-bold transition flex items-center gap-1.5 ml-auto active:scale-95 shadow-xs" title="Clean, indent, and format SQL query (Ctrl+Shift+F)">
                                        <i data-lucide="sparkles" class="w-3.5 h-3.5 text-sky-500"></i>
                                        <span>Format SQL</span>
                                    </button>
                                </div>

                                <div class="relative">
                                    <textarea x-ref="sqlTextarea" x-model="sqlQuery" @input="handleSqlInput($event)" @keydown="handleSqlKeyDown($event)" @click="handleSqlInput($event)" rows="4" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800/80 rounded-xl p-3 text-xs font-mono text-sky-700 dark:text-sky-300 focus:outline-none focus:border-sky-500" placeholder="SELECT * FROM table_name LIMIT 10;"></textarea>

                                    <!-- AUTOCOMPLETE INTELLISENSE SUGGESTIONS POPUP -->
                                    <div x-show="showAutocomplete" x-cloak @click.away="showAutocomplete = false" class="absolute left-0 top-full mt-1 z-40 w-80 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl overflow-hidden p-1.5 space-y-1">
                                        <div class="px-2.5 py-1 text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider flex items-center justify-between border-b border-slate-100 dark:border-slate-800">
                                            <span>SQL Suggestions</span>
                                            <span>Press TAB / ↵</span>
                                        </div>

                                        <template x-for="(sItem, sIdx) in sqlSuggestions" :key="sIdx">
                                            <div @click="insertSuggestion(sItem)" :class="sIdx === selectedSuggestionIdx ? 'bg-sky-500/10 text-sky-600 dark:text-sky-400 font-bold border-sky-500/30' : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'" class="px-3 py-1.5 rounded-xl text-xs font-mono flex items-center justify-between cursor-pointer border border-transparent transition">
                                                <div class="flex items-center gap-2 truncate">
                                                    <i :data-lucide="sItem.icon" class="w-3.5 h-3.5 shrink-0 opacity-70"></i>
                                                    <span class="truncate" x-text="sItem.text"></span>
                                                </div>
                                                <span class="text-[9px] uppercase font-bold px-1.5 py-0.5 rounded font-mono shrink-0" :class="sItem.type === 'Keyword' ? 'bg-purple-500/10 text-purple-600 dark:text-purple-400' : (sItem.type === 'Table' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400')" x-text="sItem.type"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between">
                                    <div class="text-[11px] text-slate-400 font-mono">Press Run Query or Ctrl+Enter</div>

                                    <div class="flex items-center gap-3">
                                        <div class="flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-400 font-medium">
                                            <i data-lucide="shield" class="w-3.5 h-3.5 text-amber-500 hidden sm:inline"></i>
                                            <select x-model="autoSafetyLimitVal" class="bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-2.5 py-1.5 text-xs text-slate-800 dark:text-slate-200 focus:outline-none focus:border-sky-500 font-medium">
                                                <option value="500">🛡️ Auto LIMIT (500)</option>
                                                <option value="1000">LIMIT 1,000</option>
                                                <option value="2500">LIMIT 2,500</option>
                                                <option value="5000">LIMIT 5,000</option>
                                            </select>
                                        </div>

                                        <label class="flex items-center gap-1.5 text-xs font-semibold cursor-pointer select-none px-2.5 py-1.5 rounded-xl border transition" :class="isDryRun ? 'bg-amber-500/20 text-amber-600 dark:text-amber-300 border-amber-500/50 shadow-xs' : 'bg-slate-100 dark:bg-slate-950 text-slate-600 dark:text-slate-400 border-slate-300 dark:border-slate-800'" title="Sandbox Testing Mode: Runs query inside a transaction and automatically ROLS BACK all changes so database remains 100% untouched!">
                                            <input type="checkbox" x-model="isDryRun" class="w-3.5 h-3.5 rounded border-amber-500 text-amber-600 focus:ring-amber-500">
                                            <span>🧪 Dry Run Lock</span>
                                        </label>

                                        <button @click="openSaveQueryModal()" class="bg-amber-500/10 hover:bg-amber-500/20 text-amber-600 dark:text-amber-400 border border-amber-500/20 text-xs font-semibold px-3 py-2 rounded-xl transition flex items-center gap-1.5" title="Save query to Favorites Library">
                                            <i data-lucide="star" class="w-3.5 h-3.5"></i>
                                            <span>Save Favorite</span>
                                        </button>

                                        <button @click="runQuery()" :class="isDryRun ? 'from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 shadow-amber-600/30 ring-2 ring-amber-400/50' : 'from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 shadow-sky-600/20'" class="bg-gradient-to-r text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-md flex items-center gap-2">
                                            <i :data-lucide="isDryRun ? 'flask-conical' : 'play'" class="w-3.5 h-3.5"></i>
                                            <span x-text="isDryRun ? '🧪 Test Dry Run' : 'Run Query'"></span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- SAVED QUERIES FAVORITES LIBRARY PANEL (DRAWER) -->
                            <div x-show="showSavedQueriesDrawer" x-cloak class="bg-amber-500/5 dark:bg-amber-500/10 border border-amber-500/20 rounded-2xl p-4 space-y-3 shrink-0">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-bold text-amber-600 dark:text-amber-400 uppercase tracking-widest flex items-center gap-2">
                                        <i data-lucide="star" class="w-4 h-4"></i>
                                        <span>Saved Favorites Library (<span x-text="filteredSavedQueries.length"></span>)</span>
                                    </span>
                                    <button @click="openSaveQueryModal()" class="text-xs bg-amber-500 text-white font-semibold px-2.5 py-1 rounded-lg hover:bg-amber-400 transition flex items-center gap-1">
                                        <i data-lucide="plus" class="w-3 h-3"></i>
                                        <span>Save Current</span>
                                    </button>
                                </div>

                                <!-- FILTER BAR & TAG CHIPS -->
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="relative flex-1 min-w-[200px]">
                                        <i data-lucide="search" class="w-3.5 h-3.5 text-amber-500 absolute left-3 top-2"></i>
                                        <input type="text" x-model="favoriteSearch" placeholder="Search favorites by title or SQL..." class="w-full bg-white dark:bg-slate-950 border border-amber-500/30 rounded-xl pl-8 pr-3 py-1 text-xs text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none">
                                    </div>
                                    <div class="flex items-center gap-1 overflow-x-auto">
                                        <template x-for="t in ['All', 'Reports', 'Users', 'Cleanup', 'Custom']" :key="t">
                                            <button @click="selectedFavTag = t" :class="selectedFavTag === t ? 'bg-amber-500 text-white font-bold' : 'bg-white dark:bg-slate-900 text-amber-600 dark:text-amber-400 border border-amber-500/30'" class="text-[10px] px-2 py-0.5 rounded-full transition" x-text="t"></button>
                                        </template>
                                    </div>
                                </div>

                                <div class="max-h-48 overflow-y-auto space-y-2 pr-1">
                                    <template x-for="item in filteredSavedQueries" :key="item.id">
                                        <div class="p-3 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-xs flex items-center justify-between hover:border-amber-500/50 transition">
                                            <div class="space-y-1 truncate flex-1 mr-3">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-bold text-slate-900 dark:text-white" x-text="item.title"></span>
                                                    <span class="text-[10px] font-mono bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 px-2 py-0.5 rounded-full font-bold" x-text="item.tag"></span>
                                                </div>
                                                <div class="font-mono text-[11px] text-slate-500 dark:text-slate-400 truncate" x-text="item.sql"></div>
                                            </div>

                                            <div class="flex items-center gap-2 shrink-0">
                                                <button @click="loadSavedQuery(item.sql)" class="bg-sky-600 hover:bg-sky-500 text-white font-semibold text-[11px] px-3 py-1.5 rounded-lg transition flex items-center gap-1">
                                                    <i data-lucide="play" class="w-3 h-3"></i>
                                                    <span>Run</span>
                                                </button>
                                                <button @click="deleteSavedQuery(item.id)" class="text-slate-400 hover:text-rose-500 p-1 transition" title="Delete Saved Query"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
                                            </div>
                                        </div>
                                    </template>

                                    <template x-if="filteredSavedQueries.length === 0">
                                        <div class="text-xs text-slate-400 text-center py-4">No matching favorite queries found.</div>
                                    </template>
                                </div>
                            </div>

                            <!-- QUERY HISTORY PANEL (DRAWER) -->
                            <div x-show="showHistory" x-cloak class="bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-3 space-y-2 shrink-0">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-bold text-slate-700 dark:text-slate-300 uppercase tracking-widest flex items-center gap-2">
                                        <i data-lucide="history" class="w-3.5 h-3.5 text-sky-500"></i>
                                        <span>Executed Query History (<span x-text="filteredQueryHistory.length"></span>)</span>
                                    </span>
                                    <button @click="clearQueryHistory()" class="text-rose-500 hover:underline text-[11px] font-semibold">Clear History</button>
                                </div>

                                <!-- HISTORY SEARCH INPUT -->
                                <div class="relative">
                                    <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-2"></i>
                                    <input type="text" x-model="historySearch" placeholder="Search executed history..." class="w-full bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl pl-8 pr-3 py-1 text-xs text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none">
                                </div>

                                <div class="max-h-40 overflow-y-auto space-y-1.5 pr-1">
                                    <template x-for="(item, hIdx) in filteredQueryHistory" :key="hIdx">
                                        <div @click="sqlQuery = item.sql" class="p-2 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 rounded-xl text-xs font-mono flex items-center justify-between hover:border-sky-500 cursor-pointer group transition">
                                            <div class="flex items-center gap-2 truncate flex-1 mr-2">
                                                <span :class="item.success ? 'text-emerald-500' : 'text-rose-500'" class="text-[10px]">●</span>
                                                <span class="truncate text-slate-800 dark:text-slate-200" x-text="item.sql"></span>
                                            </div>
                                            <div class="flex items-center gap-2 text-[10px] text-slate-400 shrink-0">
                                                <span x-text="item.duration + ' ms'"></span>
                                                <span x-text="item.time"></span>
                                                <i data-lucide="arrow-up-right" class="w-3 h-3 text-sky-500 opacity-0 group-hover:opacity-100 transition"></i>
                                            </div>
                                        </div>
                                    </template>

                                    <template x-if="filteredQueryHistory.length === 0">
                                        <div class="text-xs text-slate-400 text-center py-3">No matching history logged.</div>
                                    </template>
                                </div>
                            </div>

                            <!-- EXPLAIN QUERY PLAN PERFORMANCE DIAGNOSTICS CARD -->
                            <template x-if="queryPlanData.plan && queryPlanData.plan.length > 0">
                                <div class="bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-3 shrink-0 shadow-sm">
                                    <div class="flex items-center justify-between">
                                        <h4 class="text-xs font-bold uppercase tracking-widest flex items-center gap-2" :class="queryPlanData.has_scan ? 'text-amber-500' : 'text-emerald-500'">
                                            <i :data-lucide="queryPlanData.has_scan ? 'alert-triangle' : 'zap'" class="w-4 h-4"></i>
                                            <span>Query Execution Plan & B-Tree Performance</span>
                                        </h4>
                                        <span class="text-[10px] font-mono font-bold px-2 py-0.5 rounded-full" :class="queryPlanData.has_scan ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20'" x-text="queryPlanData.has_scan ? '🔴 FULL SCAN' : (queryPlanData.has_covering_index ? '🚀 COVERING INDEX' : '⚡ INDEX SEARCH')"></span>
                                    </div>

                                    <div class="p-2.5 rounded-xl text-xs font-medium" :class="queryPlanData.has_scan ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/20' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border border-emerald-500/20'" x-text="queryPlanData.recommendation"></div>

                                    <div class="space-y-1 font-mono text-xs">
                                        <template x-for="(step, sIdx) in queryPlanData.plan" :key="sIdx">
                                            <div class="p-2 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 rounded-xl flex items-center justify-between">
                                                <div class="flex items-center gap-2 truncate">
                                                    <span class="text-[10px] text-slate-400 font-bold" x-text="'#' + step.id"></span>
                                                    <span class="truncate text-slate-800 dark:text-slate-200" x-text="step.detail"></span>
                                                </div>
                                                <span class="text-[9px] uppercase font-bold px-1.5 py-0.5 rounded shrink-0" :class="step.type === 'SCAN' ? 'bg-rose-500/10 text-rose-500' : (step.type === 'INDEX_SEARCH' ? 'bg-emerald-500/10 text-emerald-500' : 'bg-sky-500/10 text-sky-500')" x-text="step.type"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <div class="bg-white/80 dark:bg-slate-900/80 border border-slate-200 dark:border-slate-800/80 rounded-2xl flex flex-col shadow-sm min-h-[300px]">
                                <div class="p-3 bg-slate-100 dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800/80 flex flex-wrap items-center justify-between text-xs font-medium gap-3">
                                    <div class="flex items-center gap-2">
                                        <span class="text-slate-500 dark:text-slate-400 font-semibold">Query Output Result</span>
                                        <template x-if="queryResult.type === 'select' && queryResult.rows">
                                            <span class="text-[10px] bg-sky-500/10 text-sky-600 dark:text-sky-400 border border-sky-500/20 px-2 py-0.5 rounded-full font-mono font-bold" x-text="queryResult.rows.length + ' rows fetched'"></span>
                                        </template>
                                    </div>

                                    <div class="flex items-center gap-3">
                                        <!-- SQL Results Pagination Controls Header -->
                                        <template x-if="queryResult.type === 'select' && queryResult.rows && queryResult.rows.length > 0">
                                            <div class="flex items-center gap-2 border-r border-slate-200 dark:border-slate-800 pr-3">
                                                <select x-model="queryPageLimit" @change="queryPage = 1" class="bg-slate-200 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2 py-0.5 text-[11px] font-semibold text-slate-800 dark:text-slate-200 focus:outline-none">
                                                    <option value="25">25 / page</option>
                                                    <option value="50">50 / page</option>
                                                    <option value="100">100 / page</option>
                                                    <option value="250">250 / page</option>
                                                </select>

                                                <div x-show="totalQueryPages > 1" class="flex items-center gap-1 text-[11px]">
                                                    <button @click="queryPage > 1 && queryPage--" :disabled="queryPage <= 1" class="p-1 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-200 dark:hover:bg-slate-800 disabled:opacity-30"><i data-lucide="chevron-left" class="w-3 h-3"></i></button>
                                                    <span class="px-1 font-mono text-slate-700 dark:text-slate-300" x-text="queryPage + ' / ' + totalQueryPages"></span>
                                                    <button @click="queryPage < totalQueryPages && queryPage++" :disabled="queryPage >= totalQueryPages" class="p-1 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-200 dark:hover:bg-slate-800 disabled:opacity-30"><i data-lucide="chevron-right" class="w-3 h-3"></i></button>
                                                </div>
                                            </div>
                                        </template>

                                        <!-- View Mode Switcher -->
                                        <div class="flex items-center bg-slate-200 dark:bg-slate-800 p-0.5 rounded-lg border border-slate-300 dark:border-slate-700 text-[11px]">
                                            <button @click="queryViewMode = 'grid'" :class="queryViewMode === 'grid' ? 'bg-white dark:bg-slate-900 text-sky-600 dark:text-sky-400 font-bold shadow-sm' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'" class="px-2.5 py-1 rounded transition flex items-center gap-1">
                                                <i data-lucide="table" class="w-3 h-3"></i>
                                                <span>Grid</span>
                                            </button>
                                            <button @click="queryViewMode = 'chart'; setTimeout(() => lucide.createIcons(), 50)" :class="queryViewMode === 'chart' ? 'bg-white dark:bg-slate-900 text-sky-600 dark:text-sky-400 font-bold shadow-sm' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'" class="px-2.5 py-1 rounded transition flex items-center gap-1">
                                                <i data-lucide="bar-chart-3" class="w-3 h-3"></i>
                                                <span>Chart Visualizer</span>
                                            </button>
                                        </div>

                                        <!-- EXPORT QUERY RESULTS DROPDOWN MENU -->
                                        <template x-if="queryResult.type === 'select' && queryResult.rows && queryResult.rows.length > 0">
                                            <div class="relative" x-data="{ open: false }">
                                                <button @click="open = !open" @click.away="open = false" class="bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-[11px] font-semibold px-2.5 py-1 rounded-lg border border-slate-300 dark:border-slate-700 transition flex items-center gap-1 active:scale-95">
                                                    <i data-lucide="download" class="w-3 h-3 text-emerald-500"></i>
                                                    <span>Export Results</span>
                                                    <i data-lucide="chevron-down" class="w-3 h-3 text-slate-400 transition" :class="open ? 'rotate-180' : ''"></i>
                                                </button>

                                                <div x-show="open" x-cloak class="absolute right-0 mt-2 w-48 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl p-1.5 z-50 text-xs space-y-1">
                                                    <button @click="exportQueryResultsCsv(); open = false" class="w-full text-left p-2 rounded-xl text-slate-800 dark:text-slate-200 hover:bg-emerald-500/10 hover:text-emerald-600 dark:hover:text-emerald-400 flex items-center gap-2 transition font-medium">
                                                        <i data-lucide="file-spreadsheet" class="w-3.5 h-3.5 text-emerald-500"></i>
                                                        <span>Export CSV</span>
                                                    </button>
                                                    <button @click="exportQueryResultsJson(); open = false" class="w-full text-left p-2 rounded-xl text-slate-800 dark:text-slate-200 hover:bg-amber-500/10 hover:text-amber-600 dark:hover:text-amber-400 flex items-center gap-2 transition font-medium">
                                                        <i data-lucide="file-json" class="w-3.5 h-3.5 text-amber-500"></i>
                                                        <span>Export JSON</span>
                                                    </button>
                                                    <button @click="exportQueryResultsExcel(); open = false" class="w-full text-left p-2 rounded-xl text-slate-800 dark:text-slate-200 hover:bg-emerald-500/10 hover:text-emerald-600 dark:hover:text-emerald-400 flex items-center gap-2 transition font-medium">
                                                        <i data-lucide="sheet" class="w-3.5 h-3.5 text-emerald-600"></i>
                                                        <span>Export Excel (.xls)</span>
                                                    </button>
                                                    <button @click="exportQueryResultsHtml(); open = false" class="w-full text-left p-2 rounded-xl text-slate-800 dark:text-slate-200 hover:bg-sky-500/10 hover:text-sky-600 dark:hover:text-sky-400 flex items-center gap-2 transition font-medium border-t border-slate-100 dark:border-slate-800">
                                                        <i data-lucide="file-code" class="w-3.5 h-3.5 text-sky-500"></i>
                                                        <span>Export Styled HTML Report</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </template>

                                        <template x-if="queryResult.auto_limit_applied">
                                            <span class="text-[10px] bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 px-2 py-0.5 rounded-full font-semibold flex items-center gap-1" title="Query automatically capped to prevent browser freeze">
                                                <span>🛡️ Safety LIMIT Active</span>
                                            </span>
                                        </template>

                                        <template x-if="queryResult.execution_time_ms !== undefined">
                                            <span class="text-emerald-600 dark:text-emerald-400 font-mono" x-text="'⚡ ' + queryResult.execution_time_ms + ' ms'"></span>
                                        </template>
                                    </div>
                                </div>

                                <div class="overflow-x-auto p-4 space-y-4">
                                    <!-- DRY RUN SANDBOX MODE BANNER -->
                                    <template x-if="queryResult.dry_run">
                                        <div class="bg-amber-500/10 border border-amber-500/30 text-amber-700 dark:text-amber-300 p-3.5 rounded-2xl text-xs font-mono flex items-center justify-between shadow-xs">
                                            <div class="flex items-center gap-3">
                                                <span class="text-xl">🧪</span>
                                                <div>
                                                    <div class="font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">DRY RUN LOCK ACTIVE (AUTOMATICALLY ROLLED BACK)</div>
                                                    <p class="text-[11px] opacity-90 font-sans mt-0.5">Query executed inside sandbox transaction and was 100% rolled back. 0 changes written to database disk!</p>
                                                </div>
                                            </div>
                                            <span class="text-[10px] font-bold bg-amber-500 text-slate-950 px-2.5 py-1 rounded-full uppercase tracking-wider shrink-0 shadow-xs">Sandbox Test Mode</span>
                                        </div>
                                    </template>

                                    <template x-if="queryResult.error">
                                        <div class="text-xs text-rose-600 dark:text-rose-400 bg-rose-500/10 border border-rose-500/20 p-4 rounded-xl font-mono" x-text="queryResult.error"></div>
                                    </template>

                                    <template x-if="queryResult.type === 'exec'">
                                        <div class="text-xs text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 p-4 rounded-xl font-mono" x-text="(queryResult.dry_run ? '[DRY RUN] ' : '') + 'Query OK! Affected Rows: ' + queryResult.affected_rows"></div>
                                    </template>

                                    <!-- GRID DATA VIEW -->
                                    <template x-if="queryResult.type === 'select' && queryViewMode === 'grid'">
                                        <table class="w-full text-left border-collapse text-xs">
                                            <thead class="sticky top-0 bg-slate-100 dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 font-semibold shadow-xs z-10">
                                                <tr>
                                                    <th class="p-2.5 w-12 text-center border-r border-slate-200 dark:border-slate-800">#</th>
                                                    <template x-for="col in queryResult.columns" :key="col">
                                                        <th @click="sortQueryResult(col)" class="p-2.5 border-r border-slate-200 dark:border-slate-800 hover:bg-slate-200/60 dark:hover:bg-slate-800/80 cursor-pointer select-none transition" :class="querySortColumn === col ? 'bg-sky-500/10 dark:bg-sky-500/20 text-sky-600 dark:text-sky-400 font-bold' : ''" title="Click to sort by this column">
                                                            <div class="flex items-center justify-between gap-2">
                                                                <span x-text="col" class="font-semibold truncate"></span>
                                                                <div class="flex items-center shrink-0">
                                                                    <template x-if="querySortColumn === col">
                                                                        <span class="text-[9px] font-mono font-bold px-1.5 py-0.5 rounded bg-sky-600 text-white shadow-xs" x-text="querySortDir === 'ASC' ? '▲ ASC' : '▼ DESC'"></span>
                                                                    </template>
                                                                    <template x-if="querySortColumn !== col">
                                                                        <span class="text-slate-400 dark:text-slate-600 text-[10px]">↕</span>
                                                                    </template>
                                                                </div>
                                                            </div>
                                                        </th>
                                                    </template>
                                                    <th class="p-2.5 w-16 text-center">Inspect</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800/60 font-mono text-slate-800 dark:text-slate-300">
                                                <template x-for="(r, idx) in pagedQueryRows" :key="idx">
                                                    <tr class="hover:bg-slate-100 dark:hover:bg-slate-800/40">
                                                        <td class="p-2.5 text-center text-slate-400 border-r border-slate-200 dark:border-slate-800/60" x-text="(queryPage - 1) * Math.min(250, parseInt(queryPageLimit || 50)) + idx + 1"></td>
                                                        <template x-for="col in queryResult.columns" :key="col">
                                                            <td class="p-2.5 border-r border-slate-200 dark:border-slate-800/60" x-text="r[col]"></td>
                                                        </template>
                                                        <td class="p-2.5 text-center">
                                                            <button @click="inspectRow(r)" class="text-sky-500 hover:text-sky-400 hover:bg-sky-500/10 px-2 py-1 rounded-lg transition font-sans font-bold flex items-center justify-center gap-1 mx-auto" title="Inspect Record Details">
                                                                <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                                                <span>View</span>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </template>

                                    <!-- SQL Results Bottom Pagination Summary Bar -->
                                    <template x-if="queryResult.type === 'select' && queryResult.rows && queryResult.rows.length > 0">
                                        <div class="p-3 bg-slate-100 dark:bg-slate-950 border-t border-slate-200 dark:border-slate-800/80 flex flex-wrap items-center justify-between text-xs text-slate-500 dark:text-slate-400 font-medium gap-3 rounded-b-2xl">
                                            <div class="flex items-center gap-2">
                                                <i data-lucide="layers" class="w-3.5 h-3.5 text-sky-500"></i>
                                                <span x-text="'Showing ' + ((queryPage - 1) * Math.min(250, parseInt(queryPageLimit || 50)) + 1) + ' to ' + Math.min(queryPage * Math.min(250, parseInt(queryPageLimit || 50)), queryResult.rows.length) + ' of ' + queryResult.rows.length + ' fetched rows'"></span>
                                            </div>

                                            <div class="flex items-center gap-2">
                                                <button @click="queryPage > 1 && queryPage--" :disabled="queryPage <= 1" class="px-2.5 py-1 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-200 dark:hover:bg-slate-800 disabled:opacity-30 font-semibold text-[11px] flex items-center gap-1 transition">
                                                    <i data-lucide="chevron-left" class="w-3 h-3"></i>
                                                    <span>Previous</span>
                                                </button>
                                                <span class="font-mono text-[11px] text-slate-800 dark:text-slate-200 px-2" x-text="'Page ' + queryPage + ' of ' + totalQueryPages"></span>
                                                <button @click="queryPage < totalQueryPages && queryPage++" :disabled="queryPage >= totalQueryPages" class="px-2.5 py-1 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-200 dark:hover:bg-slate-800 disabled:opacity-30 font-semibold text-[11px] flex items-center gap-1 transition">
                                                    <span>Next</span>
                                                    <i data-lucide="chevron-right" class="w-3 h-3"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- NUMERIC COLUMN AGGREGATION SUMMARY FOOTER PANEL -->
                                    <template x-if="queryResult.type === 'select' && queryColumnStats.length > 0">
                                        <div class="bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 rounded-2xl p-4 space-y-3 text-xs shadow-xs">
                                            <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800/80 pb-2">
                                                <div class="flex items-center gap-2 font-bold text-slate-700 dark:text-slate-300">
                                                    <i data-lucide="calculator" class="w-4 h-4 text-emerald-500"></i>
                                                    <span>Column Aggregation Statistics Summary</span>
                                                    <span class="text-[10px] bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 font-mono px-2 py-0.5 rounded-full border border-emerald-500/20" x-text="queryColumnStats.length + ' numeric columns'"></span>
                                                </div>
                                                <span class="text-[10px] text-slate-400 font-mono">Auto-calculated across all <span class="font-bold text-slate-700 dark:text-slate-300" x-text="queryResult.rows.length"></span> rows</span>
                                            </div>

                                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 font-mono">
                                                <template x-for="(stat, idx) in queryColumnStats" :key="idx">
                                                    <div class="p-3 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800/80 rounded-xl space-y-1.5 shadow-xs hover:border-slate-300 dark:hover:border-slate-700 transition">
                                                        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800/80 pb-1">
                                                            <span class="font-bold text-sky-600 dark:text-sky-400 truncate text-xs" x-text="stat.column"></span>
                                                            <span class="text-[9px] bg-slate-100 dark:bg-slate-800 text-slate-500 px-1.5 py-0.5 rounded font-bold" x-text="'n=' + stat.count"></span>
                                                        </div>
                                                        <div class="grid grid-cols-2 gap-x-2 gap-y-1 text-[11px] text-slate-600 dark:text-slate-400">
                                                            <div><span class="text-slate-400">SUM:</span> <strong class="text-emerald-600 dark:text-emerald-400" x-text="stat.sum.toLocaleString()"></strong></div>
                                                            <div><span class="text-slate-400">AVG:</span> <strong class="text-purple-600 dark:text-purple-400" x-text="stat.avg.toLocaleString()"></strong></div>
                                                            <div><span class="text-slate-400">MIN:</span> <strong class="text-amber-600 dark:text-amber-400" x-text="stat.min.toLocaleString()"></strong></div>
                                                            <div><span class="text-slate-400">MAX:</span> <strong class="text-rose-600 dark:text-rose-400" x-text="stat.max.toLocaleString()"></strong></div>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- CHART VISUALIZER VIEW -->
                                    <template x-if="queryResult.type === 'select' && queryViewMode === 'chart'">
                                        <div class="space-y-4">
                                            <!-- Chart Controls -->
                                            <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-50 dark:bg-slate-950 p-3 rounded-xl border border-slate-200 dark:border-slate-800 text-xs">
                                                <div class="flex items-center gap-3">
                                                    <div class="flex items-center gap-1.5">
                                                        <label class="font-bold text-slate-500 dark:text-slate-400">Chart Type:</label>
                                                        <select x-model="chartType" class="bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-lg px-2 py-1 text-slate-900 dark:text-white font-mono">
                                                            <option value="bar">📊 Bar Chart</option>
                                                            <option value="pie">🥧 Pie Chart Ratio</option>
                                                        </select>
                                                    </div>

                                                    <div class="flex items-center gap-1.5">
                                                        <label class="font-bold text-slate-500 dark:text-slate-400">Label Column (X):</label>
                                                        <select x-model="chartLabelCol" class="bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-lg px-2 py-1 text-slate-900 dark:text-white font-mono">
                                                            <template x-for="c in queryResult.columns" :key="c">
                                                                <option :value="c" x-text="c"></option>
                                                            </template>
                                                        </select>
                                                    </div>

                                                    <div class="flex items-center gap-1.5">
                                                        <label class="font-bold text-slate-500 dark:text-slate-400">Metric Value (Y):</label>
                                                        <select x-model="chartValCol" class="bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-lg px-2 py-1 text-slate-900 dark:text-white font-mono">
                                                            <template x-for="c in queryResult.columns" :key="c">
                                                                <option :value="c" x-text="c"></option>
                                                            </template>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- BAR CHART RENDERER -->
                                            <template x-if="chartType === 'bar'">
                                                <div class="bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-2xl p-4">
                                                    <div class="flex items-end justify-between gap-2 h-56 pt-6 pb-2 px-2 border-b border-slate-200 dark:border-slate-800">
                                                        <template x-for="(v, i) in queryChartData.values" :key="i">
                                                            <div class="flex-1 flex flex-col items-center gap-1 h-full justify-end group relative">
                                                                <div class="absolute -top-7 opacity-0 group-hover:opacity-100 transition bg-slate-900 text-white text-[10px] font-mono px-2 py-0.5 rounded shadow z-20 pointer-events-none" x-text="queryChartData.labels[i] + ': ' + v"></div>
                                                                <div class="w-full max-w-[40px] bg-gradient-to-t from-sky-600 to-indigo-500 hover:from-sky-500 hover:to-indigo-400 rounded-t-lg transition-all duration-500 shadow-sm" :style="'height: ' + Math.max(8, (v / queryChartData.maxVal) * 100) + '%'"></div>
                                                            </div>
                                                        </template>
                                                    </div>
                                                    <div class="flex items-center justify-between text-[10px] font-mono text-slate-400 pt-2 px-2 truncate">
                                                        <template x-for="(l, i) in queryChartData.labels" :key="i">
                                                            <span class="truncate text-center flex-1 px-1" x-text="l"></span>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>

                                            <!-- PIE / RATIO CHART RENDERER -->
                                            <template x-if="chartType === 'pie'">
                                                <div class="bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-3">
                                                    <template x-for="(v, i) in queryChartData.values" :key="i">
                                                        <div class="space-y-1 font-mono text-xs">
                                                            <div class="flex items-center justify-between">
                                                                <span class="font-bold text-slate-800 dark:text-slate-200" x-text="queryChartData.labels[i]"></span>
                                                                <span class="text-sky-500 font-extrabold" x-text="v + ' (' + Math.round((v / (queryChartData.values.reduce((a,b)=>a+b,0)||1)) * 100) + '%)'"></span>
                                                            </div>
                                                            <div class="w-full bg-slate-200 dark:bg-slate-800 rounded-full h-3 overflow-hidden">
                                                                <div class="bg-gradient-to-r from-sky-500 via-teal-400 to-indigo-500 h-full rounded-full transition-all duration-500" :style="'width: ' + Math.min(100, Math.max(2, (v / (queryChartData.values.reduce((a,b)=>a+b,0)||1)) * 100)) + '%'"></div>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                        
                        <!-- TAB 4: DATABASE ANALYTICS & HEALTH DIAGNOSTICS DASHBOARD -->
                        <div x-show="activeTab === 'analytics'" class="flex-1 overflow-auto p-6 space-y-6">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <i data-lucide="activity" class="w-5 h-5 text-emerald-500"></i>
                                        <span>Database Health & Diagnostics Analytics</span>
                                    </h2>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">Live SQLite engine stats, integrity check & database optimization controls</p>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <button @click="openDdlModal()" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition shadow-md shadow-emerald-600/20 flex items-center gap-1.5" title="Generate and export complete DDL migration script">
                                        <i data-lucide="file-code" class="w-3.5 h-3.5"></i>
                                        <span>Export Schema DDL</span>
                                    </button>

                                    <button @click="runWalCheckpoint()" class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition shadow-md shadow-indigo-600/20 flex items-center gap-1.5" title="Flush and truncate .sqlite-wal log file">
                                        <i data-lucide="zap" class="w-3.5 h-3.5 text-amber-300"></i>
                                        <span>WAL Checkpoint</span>
                                    </button>

                                    <button @click="runMaintenance('integrity_check')" class="bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs font-semibold px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 transition flex items-center gap-1.5">
                                        <i data-lucide="shield-check" class="w-3.5 h-3.5 text-emerald-500"></i>
                                        <span>Integrity Check</span>
                                    </button>

                                    <button @click="runMaintenance('reindex')" class="bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs font-semibold px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 transition flex items-center gap-1.5">
                                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-sky-500"></i>
                                        <span>REINDEX</span>
                                    </button>

                                    <button @click="runMaintenance('optimize')" class="bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs font-semibold px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 transition flex items-center gap-1.5">
                                        <i data-lucide="zap" class="w-3.5 h-3.5 text-amber-500"></i>
                                        <span>PRAGMA Optimize</span>
                                    </button>

                                    <button @click="vacuumDb(); loadAnalytics()" class="bg-gradient-to-r from-emerald-600 to-teal-600 text-white text-xs font-semibold px-4 py-1.5 rounded-xl shadow-md shadow-emerald-600/20 transition flex items-center gap-1.5">
                                        <i data-lucide="sparkles" class="w-3.5 h-3.5"></i>
                                        <span>VACUUM Database</span>
                                    </button>
                                </div>
                            </div>

                            <!-- METRIC STAT CARDS -->
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-2 shadow-sm">
                                    <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                        <span>Database Size</span>
                                        <i data-lucide="hard-drive" class="w-4 h-4 text-sky-500"></i>
                                    </div>
                                    <div class="text-xl font-extrabold font-mono text-slate-900 dark:text-white" x-text="analyticsData.formatted_size || '0 B'"></div>
                                    <div class="flex items-center justify-between text-[11px] text-slate-400 font-mono">
                                        <span x-text="'Pages: ' + (analyticsData.page_count || 0)"></span>
                                        <span class="text-amber-500 font-bold" x-text="'WAL Log: ' + (analyticsData.formatted_wal_size || '0 B')"></span>
                                    </div>
                                </div>

                                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-2 shadow-sm">
                                    <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                        <span>Total Records</span>
                                        <i data-lucide="layers" class="w-4 h-4 text-emerald-500"></i>
                                    </div>
                                    <div class="text-xl font-extrabold font-mono text-slate-900 dark:text-white" x-text="(analyticsData.total_rows || 0).toLocaleString() + ' rows'"></div>
                                    <div class="text-[11px] text-slate-400" x-text="analyticsData.total_tables + ' Tables, ' + analyticsData.total_views + ' Views'"></div>
                                </div>

                                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-2 shadow-sm">
                                    <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                        <span>SQLite Config</span>
                                        <i data-lucide="cpu" class="w-4 h-4 text-indigo-500"></i>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <select :value="analyticsData.journal_mode" @change="changeJournalMode($event.target.value)" class="bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-mono font-bold text-slate-800 dark:text-slate-200 rounded-lg px-2 py-1 focus:outline-none">
                                            <option value="WAL">Journal: WAL</option>
                                            <option value="DELETE">Journal: DELETE</option>
                                            <option value="TRUNCATE">Journal: TRUNCATE</option>
                                            <option value="MEMORY">Journal: MEMORY</option>
                                        </select>
                                        <button @click="toggleForeignKeys()" :class="analyticsData.foreign_keys === 'ON' ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border-emerald-500/30' : 'bg-slate-100 text-slate-400 border-slate-300'" class="text-[10px] font-mono font-bold px-2 py-1 rounded-lg border" x-text="'FK: ' + analyticsData.foreign_keys"></button>
                                    </div>
                                    <div class="text-[11px] text-slate-400 font-mono" x-text="'Engine v' + (analyticsData.sqlite_version || '3.x')"></div>
                                </div>

                                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-2 shadow-sm">
                                    <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                        <span>Integrity Status</span>
                                        <i data-lucide="shield-check" class="w-4 h-4 text-emerald-500"></i>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold font-mono" :class="analyticsData.integrity_check === 'ok' || analyticsData.integrity_check === 'OK' ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30' : 'bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-500/30'" x-text="analyticsData.integrity_check || 'OK'"></span>
                                    </div>
                                    <div class="text-[11px] text-slate-400 font-mono" x-text="'Freelist: ' + (analyticsData.freelist_count || 0) + ' pages (' + (analyticsData.freelist_size || '0 B') + ')'"></div>
                                </div>
                            </div>

                            <!-- SQLITE PRAGMA PERFORMANCE TUNER & MEMORY CACHE CONFIGURATOR -->
                            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 space-y-4 shadow-sm" x-data="{ pragmas: { cache_size: -2000, synchronous: 1, temp_store: 2, busy_timeout: 5000 }, loadingPragmas: false }" x-init="fetch('?api=get_pragmas&db_path=' + encodeURIComponent(activeDb)).then(r=>r.json()).then(d=>{ if(d.success) pragmas = d.pragmas; })">
                                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                                    <div>
                                        <h3 class="text-xs font-bold text-slate-800 dark:text-slate-200 uppercase tracking-widest flex items-center gap-2">
                                            <i data-lucide="gauge" class="w-4 h-4 text-sky-500"></i>
                                            <span>SQLite PRAGMA Performance Tuner & Memory Cache Configurator</span>
                                        </h3>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400">Optimize RAM page cache, disk sync modes, temp table storage & lock timeout settings</p>
                                    </div>

                                    <button @click="loadingPragmas = true; fetch('?api=set_pragmas&db_path=' + encodeURIComponent(activeDb), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pragmas }) }).then(r=>r.json()).then(d=>{ loadingPragmas = false; if(d.success) showToast('PRAGMA performance settings applied!', 'success'); else showToast('Failed to apply PRAGMAs', 'error'); })" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-4 py-2 rounded-xl transition shadow-lg shadow-sky-600/20 flex items-center gap-1.5 active:scale-95">
                                        <i data-lucide="zap" class="w-3.5 h-3.5 text-amber-300"></i>
                                        <span>Apply PRAGMA Tunings</span>
                                    </button>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-xs font-mono">
                                    <!-- 1. Cache Size -->
                                    <div class="bg-slate-50 dark:bg-slate-950 p-3 rounded-xl border border-slate-200 dark:border-slate-800 space-y-1.5">
                                        <label class="font-bold text-slate-700 dark:text-slate-300 block">Memory Cache Size</label>
                                        <select x-model.number="pragmas.cache_size" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg p-2 text-slate-900 dark:text-white">
                                            <option value="-2000">2 MB (Default)</option>
                                            <option value="-8000">8 MB (High Perf)</option>
                                            <option value="-16000">16 MB (Turbo Speed)</option>
                                            <option value="-64000">64 MB (Max RAM Speed)</option>
                                        </select>
                                        <div class="text-[10px] text-slate-400">PRAGMA cache_size</div>
                                    </div>

                                    <!-- 2. Synchronous Mode -->
                                    <div class="bg-slate-50 dark:bg-slate-950 p-3 rounded-xl border border-slate-200 dark:border-slate-800 space-y-1.5">
                                        <label class="font-bold text-slate-700 dark:text-slate-300 block">Synchronous Mode</label>
                                        <select x-model.number="pragmas.synchronous" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg p-2 text-slate-900 dark:text-white">
                                            <option value="2">FULL (0% Risk - Safest)</option>
                                            <option value="1">NORMAL (Balanced - WAL)</option>
                                            <option value="0">OFF (Max Write Speed)</option>
                                        </select>
                                        <div class="text-[10px] text-slate-400">PRAGMA synchronous</div>
                                    </div>

                                    <!-- 3. Temp Store -->
                                    <div class="bg-slate-50 dark:bg-slate-950 p-3 rounded-xl border border-slate-200 dark:border-slate-800 space-y-1.5">
                                        <label class="font-bold text-slate-700 dark:text-slate-300 block">Temp Table Storage</label>
                                        <select x-model.number="pragmas.temp_store" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg p-2 text-slate-900 dark:text-white">
                                            <option value="0">DEFAULT (Auto)</option>
                                            <option value="2">MEMORY (100% RAM Temp)</option>
                                            <option value="1">FILE (Disk Storage)</option>
                                        </select>
                                        <div class="text-[10px] text-slate-400">PRAGMA temp_store</div>
                                    </div>

                                    <!-- 4. Busy Timeout -->
                                    <div class="bg-slate-50 dark:bg-slate-950 p-3 rounded-xl border border-slate-200 dark:border-slate-800 space-y-1.5">
                                        <label class="font-bold text-slate-700 dark:text-slate-300 block">Lock Busy Timeout</label>
                                        <select x-model.number="pragmas.busy_timeout" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg p-2 text-slate-900 dark:text-white">
                                            <option value="1000">1000 ms (1 sec)</option>
                                            <option value="3000">3000 ms (3 sec)</option>
                                            <option value="5000">5000 ms (5 sec)</option>
                                            <option value="10000">10000 ms (10 sec)</option>
                                        </select>
                                        <div class="text-[10px] text-slate-400">PRAGMA busy_timeout</div>
                                    </div>
                                </div>
                            </div>

                            <!-- DATABASE STORAGE ALLOCATION & TABLE SIZE ANALYZER -->
                            <template x-if="analyticsData.storage_analysis && analyticsData.storage_analysis.tables">
                                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-4 shadow-sm">
                                    <div class="flex items-center justify-between">
                                        <h3 class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-widest flex items-center gap-2">
                                            <i data-lucide="hard-drive" class="w-4 h-4 text-purple-500"></i>
                                            <span>Database Storage Space Allocation & Table Size Breakdown</span>
                                        </h3>
                                        <span class="text-xs font-mono font-bold bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20 px-2.5 py-0.5 rounded-full" x-text="'DB Size: ' + (analyticsData.storage_analysis.db_formatted || analyticsData.formatted_size)"></span>
                                    </div>

                                    <table class="w-full text-left border-collapse text-xs">
                                        <thead class="bg-slate-100 dark:bg-slate-950 text-slate-600 dark:text-slate-400 font-semibold border-b border-slate-200 dark:border-slate-800">
                                            <tr>
                                                <th class="p-2.5">Table Name</th>
                                                <th class="p-2.5 text-right">Rows</th>
                                                <th class="p-2.5 text-right">Avg Row Size</th>
                                                <th class="p-2.5 text-right">Est. Disk Usage</th>
                                                <th class="p-2.5 w-44">Storage Share %</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800/60 font-mono text-slate-800 dark:text-slate-300">
                                            <template x-for="st in analyticsData.storage_analysis.tables" :key="st.name">
                                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                                    <td class="p-2.5 font-semibold text-slate-900 dark:text-white flex items-center gap-1.5">
                                                        <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                                                        <span x-text="st.name"></span>
                                                    </td>
                                                    <td class="p-2.5 text-right text-slate-500" x-text="st.rows.toLocaleString()"></td>
                                                    <td class="p-2.5 text-right text-slate-400" x-text="st.avg_row_bytes + ' B'"></td>
                                                    <td class="p-2.5 text-right font-bold text-purple-600 dark:text-purple-400" x-text="st.est_formatted"></td>
                                                    <td class="p-2.5">
                                                        <div class="space-y-1">
                                                            <div class="flex items-center justify-between text-[10px] text-slate-400">
                                                                <span x-text="st.share_pct + '%'"></span>
                                                            </div>
                                                            <div class="w-full bg-slate-200 dark:bg-slate-800 rounded-full h-2 overflow-hidden">
                                                                <div class="bg-gradient-to-r from-purple-600 to-indigo-500 h-full rounded-full" :style="'width: ' + st.share_pct + '%'"></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </template>

                            <!-- TABLE DISTRIBUTION BREAKDOWN -->
                            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-3 shadow-sm">
                                <h3 class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-widest flex items-center gap-2">
                                    <i data-lucide="pie-chart" class="w-4 h-4 text-sky-500"></i>
                                    <span>Table Row Distribution Breakdown</span>
                                </h3>

                                <table class="w-full text-left border-collapse text-xs">
                                    <thead class="bg-slate-100 dark:bg-slate-950 text-slate-600 dark:text-slate-400 font-semibold border-b border-slate-200 dark:border-slate-800">
                                        <tr>
                                            <th class="p-2.5">Table Name</th>
                                            <th class="p-2.5">Type</th>
                                            <th class="p-2.5 text-right">Row Count</th>
                                            <th class="p-2.5 w-48">Share Ratio</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800/60 font-mono text-slate-800 dark:text-slate-300">
                                        <template x-for="t in analyticsData.table_distribution" :key="t.name">
                                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                                <td class="p-2.5 font-semibold text-slate-900 dark:text-white" x-text="t.name"></td>
                                                <td class="p-2.5 text-slate-400 uppercase text-[11px]" x-text="t.type"></td>
                                                <td class="p-2.5 text-right font-bold text-sky-600 dark:text-sky-400" x-text="t.rows.toLocaleString()"></td>
                                                <td class="p-2.5">
                                                    <div class="w-full bg-slate-200 dark:bg-slate-800 rounded-full h-2 overflow-hidden">
                                                        <div class="bg-sky-500 h-full rounded-full" :style="'width: ' + (analyticsData.total_rows > 0 ? (t.rows / analyticsData.total_rows * 100) : 0) + '%'"></div>
                                                    </div>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- TAB 5: VISUAL ER DATABASE SCHEMA DIAGRAM -->
                        <div x-show="activeTab === 'er'" class="flex-1 overflow-auto p-6 space-y-6 bg-slate-100/50 dark:bg-slate-950/50">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <i data-lucide="git-fork" class="w-5 h-5 text-indigo-500"></i>
                                        <span>Visual ER Database Schema Diagram</span>
                                    </h2>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">Interactive visual table cards, column schemas, primary keys, and foreign key connections</p>
                                </div>

                                <div class="flex items-center gap-2">
                                    <!-- EXPORT DIAGRAM DROPDOWN MENU -->
                                    <div class="relative" x-data="{ open: false }">
                                        <button @click="open = !open" @click.away="open = false" class="bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition shadow-md shadow-indigo-600/20 flex items-center gap-1.5 active:scale-95">
                                            <i data-lucide="download" class="w-3.5 h-3.5 text-indigo-200"></i>
                                            <span>Export Diagram</span>
                                            <i data-lucide="chevron-down" class="w-3 h-3 text-indigo-200 transition" :class="open ? 'rotate-180' : ''"></i>
                                        </button>

                                        <div x-show="open" x-cloak class="absolute right-0 mt-2 w-52 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl p-1.5 z-50 text-xs space-y-1">
                                            <button @click="copyMermaidMarkup(); open = false" class="w-full text-left p-2 rounded-xl text-slate-800 dark:text-slate-200 hover:bg-indigo-500/10 hover:text-indigo-600 dark:hover:text-indigo-400 flex items-center gap-2 transition font-medium">
                                                <i data-lucide="copy" class="w-3.5 h-3.5 text-indigo-500"></i>
                                                <span>Copy Mermaid.js Markup</span>
                                            </button>
                                            <button @click="exportErSvg(); open = false" class="w-full text-left p-2 rounded-xl text-slate-800 dark:text-slate-200 hover:bg-purple-500/10 hover:text-purple-600 dark:hover:text-purple-400 flex items-center gap-2 transition font-medium border-t border-slate-100 dark:border-slate-800">
                                                <i data-lucide="file-code-2" class="w-3.5 h-3.5 text-purple-500"></i>
                                                <span>Export SVG Vector Diagram</span>
                                            </button>
                                        </div>
                                    </div>

                                    <button @click="loadErDiagram()" class="bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs font-semibold px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 transition flex items-center gap-1.5">
                                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-sky-500"></i>
                                        <span>Refresh Diagram</span>
                                    </button>
                                </div>
                            </div>

                            <!-- FOREIGN KEY RELATIONSHIPS SUMMARY CARDS -->
                            <template x-if="erData.relationships && erData.relationships.length > 0">
                                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-2 shadow-sm">
                                    <h4 class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-widest flex items-center gap-2">
                                        <i data-lucide="link-2" class="w-4 h-4 text-emerald-500"></i>
                                        <span>Foreign Key Relationships (<span x-text="erData.relationships.length"></span>)</span>
                                    </h4>

                                    <div class="flex flex-wrap gap-2 pt-1">
                                        <template x-for="(rel, rIdx) in erData.relationships" :key="rIdx">
                                            <div class="bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 rounded-xl px-3 py-1.5 text-xs font-mono flex items-center gap-2 shadow-sm">
                                                <span class="font-bold text-slate-800 dark:text-slate-200" x-text="rel.from_table + '.' + rel.from_column"></span>
                                                <span class="text-emerald-500 font-bold">➔</span>
                                                <span class="font-bold text-sky-600 dark:text-sky-400" x-text="rel.to_table + '.' + rel.to_column"></span>
                                                <template x-if="rel.on_delete">
                                                    <span class="text-[10px] bg-slate-200 dark:bg-slate-800 text-slate-500 px-1.5 py-0.5 rounded" x-text="'DEL: ' + rel.on_delete"></span>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <!-- GRID OF INTERACTIVE TABLE CARDS -->
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                <template x-for="tbl in erData.tables" :key="tbl.name">
                                    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm hover:shadow-md transition overflow-hidden flex flex-col">
                                        <!-- TABLE CARD HEADER -->
                                        <div class="p-3 bg-slate-50 dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                            <div class="flex items-center gap-2 truncate">
                                                <i :data-lucide="tbl.type === 'view' ? 'eye' : 'table'" class="w-4 h-4" :class="tbl.type === 'view' ? 'text-amber-500' : 'text-sky-500'"></i>
                                                <span class="font-bold text-sm text-slate-900 dark:text-white truncate" x-text="tbl.name"></span>
                                            </div>
                                            <span class="text-[10px] bg-sky-500/10 text-sky-600 dark:text-sky-400 border border-sky-500/20 px-2 py-0.5 rounded-full font-mono font-bold" x-text="tbl.columns.length + ' cols'"></span>
                                        </div>

                                        <!-- COLUMNS LIST -->
                                        <div class="p-3 space-y-1.5 flex-1 max-h-72 overflow-y-auto font-mono text-xs">
                                            <template x-for="col in tbl.columns" :key="col.name">
                                                <div class="flex items-center justify-between p-2 rounded-xl bg-slate-50/50 dark:bg-slate-950/50 border border-slate-200/60 dark:border-slate-800/60 hover:border-sky-500/40 transition">
                                                    <div class="flex items-center gap-2 truncate">
                                                        <template x-if="col.pk">
                                                            <span title="Primary Key" class="text-emerald-500 text-[10px]">🔑</span>
                                                        </template>
                                                        <template x-if="col.fk">
                                                            <span title="Foreign Key" class="text-indigo-500 text-[10px]">🔗</span>
                                                        </template>
                                                        <span class="font-semibold text-slate-800 dark:text-slate-200 truncate" x-text="col.name"></span>
                                                    </div>

                                                    <div class="flex items-center gap-1.5">
                                                        <span class="text-[10px] text-sky-600 dark:text-sky-400 bg-sky-500/10 px-1.5 py-0.5 rounded font-bold" x-text="col.type || 'ANY'"></span>
                                                        <template x-if="col.notnull">
                                                            <span class="text-[10px] text-amber-600 dark:text-amber-400 font-bold" title="Not Null">*</span>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </main>
        </div>

        <!-- MODAL 1: NEW DATABASE -->
        <div x-show="showNewDbModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="database-backup" class="w-4 h-4 text-emerald-500"></i>
                    <span>Create New Database</span>
                </h3>

                <div>
                    <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1 font-medium">Database File Name</label>
                    <input type="text" x-model="newDbName" placeholder="my_school_db.sqlite" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-sky-500">
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showNewDbModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                    <button @click="createDb()" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold px-4 py-1.5 rounded-xl transition">Create DB</button>
                </div>
            </div>
        </div>

        <!-- MODAL 2: NEW TABLE WIZARD -->
        <div x-show="showNewTableModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-4xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="table-plus" class="w-4 h-4 text-emerald-500"></i>
                    <span>Create New Table Wizard</span>
                </h3>

                <div>
                    <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1 font-medium">Table Name</label>
                    <input type="text" x-model="newTableName" placeholder="students" class="w-full max-w-xs bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-sky-500 font-semibold">
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400">
                        <span>Columns Specification</span>
                        <button @click="addNewTableColRow()" class="text-sky-600 dark:text-sky-400 hover:underline flex items-center gap-1 font-bold"><i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> Add Field</button>
                    </div>

                    <div class="max-h-[360px] overflow-y-auto space-y-2 pr-1">
                        <div class="grid grid-cols-12 gap-2 text-[11px] font-bold text-slate-400 dark:text-slate-500 px-2 uppercase tracking-wider">
                            <div class="col-span-4">Field Name</div>
                            <div class="col-span-2">Type</div>
                            <div class="col-span-3">Default Value</div>
                            <div class="col-span-1 text-center">PK</div>
                            <div class="col-span-1 text-center">Not Null</div>
                            <div class="col-span-1 text-center">Action</div>
                        </div>

                        <template x-for="(col, idx) in newTableCols" :key="idx">
                            <div class="grid grid-cols-12 gap-2 items-center bg-slate-50 dark:bg-slate-950 p-2.5 rounded-xl border border-slate-200 dark:border-slate-800 text-xs">
                                <input type="text" x-model="col.name" placeholder="column_name" class="col-span-4 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-xl px-2.5 py-1.5 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                                <select x-model="col.type" class="col-span-2 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-xl px-2 py-1.5 text-slate-900 dark:text-white font-mono text-xs focus:outline-none focus:border-sky-500">
                                    <option value="INTEGER">INTEGER</option>
                                    <option value="TEXT">TEXT</option>
                                    <option value="REAL">REAL</option>
                                    <option value="BLOB">BLOB</option>
                                    <option value="DATETIME">DATETIME</option>
                                    <option value="NUMERIC">NUMERIC</option>
                                    <option value="BOOLEAN">BOOLEAN</option>
                                </select>
                                <input type="text" x-model="col.default" placeholder="Default (e.g. NULL, 0)" class="col-span-3 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-xl px-2.5 py-1.5 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                                <div class="col-span-1 flex justify-center"><input type="checkbox" x-model="col.pk" class="w-4 h-4 rounded border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-emerald-600 focus:ring-emerald-500 cursor-pointer"></div>
                                <div class="col-span-1 flex justify-center"><input type="checkbox" x-model="col.notnull" class="w-4 h-4 rounded border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-sky-600 focus:ring-sky-500 cursor-pointer"></div>
                                <button @click="newTableCols.splice(idx, 1)" class="col-span-1 text-slate-400 hover:text-rose-500 text-center"><i data-lucide="trash-2" class="w-4 h-4 mx-auto"></i></button>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showNewTableModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                    <button @click="createTable()" class="bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-md shadow-emerald-600/20 flex items-center gap-1.5">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                        <span>Create Table</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- MODAL 3: INSERT ROW -->
        <div x-show="showInsertModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="plus-circle" class="w-4 h-4 text-sky-500"></i>
                    <span>Insert New Row in <span x-text="activeTable" class="text-sky-600 dark:text-sky-400"></span></span>
                </h3>

                <div class="max-h-80 overflow-y-auto space-y-3">
                    <template x-for="col in tableColumns" :key="col.name">
                        <div class="space-y-1">
                            <label class="block text-xs text-slate-600 dark:text-slate-400 font-semibold" x-text="col.name + ' (' + (col.type || 'ANY') + ')'"></label>
                            <input type="text" x-model="newRowData[col.name]" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-sky-500 font-mono">
                        </div>
                    </template>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showInsertModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                    <button @click="submitInsertRow()" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-4 py-1.5 rounded-xl transition">Insert Row</button>
                </div>
            </div>
        </div>

        <!-- MODAL 4: EDIT FULL ROW / RECORD -->
        <div x-show="showEditRowModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="edit-3" class="w-4 h-4 text-sky-500"></i>
                    <span>Edit Record in <span x-text="activeTable" class="text-sky-600 dark:text-sky-400"></span></span>
                </h3>

                <div class="max-h-80 overflow-y-auto space-y-3">
                    <template x-for="col in tableColumns" :key="col.name">
                        <div class="space-y-1">
                            <div class="flex items-center justify-between">
                                <label class="block text-xs text-slate-700 dark:text-slate-300 font-semibold" x-text="col.name + ' (' + (col.type || 'ANY') + ')'"></label>
                                <template x-if="col.pk > 0">
                                    <span class="text-[9px] bg-amber-500/20 text-amber-600 dark:text-amber-300 border border-amber-500/30 px-1 rounded font-bold">PRIMARY KEY</span>
                                </template>
                            </div>
                            <input type="text" x-model="editingRowData[col.name]" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-sky-500 font-mono">
                        </div>
                    </template>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showEditRowModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                    <button @click="submitUpdateRow()" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-lg shadow-sky-600/20 flex items-center gap-1.5">
                        <i data-lucide="check" class="w-3.5 h-3.5"></i>
                        <span>Save Changes</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- MODAL 5: ADD COLUMN -->
        <div x-show="showAddColModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="columns" class="w-4 h-4 text-sky-500"></i>
                    <span>Add Column to <span x-text="activeTable"></span></span>
                </h3>

                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block text-slate-500 dark:text-slate-400 mb-1 font-medium">Column Name</label>
                        <input type="text" x-model="newColObj.name" placeholder="phone_number" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white focus:outline-none focus:border-sky-500 font-mono">
                    </div>

                    <div>
                        <label class="block text-slate-500 dark:text-slate-400 mb-1 font-medium">Data Type</label>
                        <select x-model="newColObj.type" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono">
                            <option value="TEXT">TEXT</option>
                            <option value="INTEGER">INTEGER</option>
                            <option value="REAL">REAL</option>
                            <option value="BLOB">BLOB</option>
                            <option value="DATETIME">DATETIME</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showAddColModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                    <button @click="submitAddColumn()" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-4 py-1.5 rounded-xl transition">Add Column</button>
                </div>
            </div>
        </div>

        <!-- MODAL 6: IMPORT CSV / JSON WIZARD -->
        <div x-show="showImportModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="file-up" class="w-4 h-4 text-sky-500"></i>
                    <span>Import Data File (CSV / JSON)</span>
                </h3>

                <form @submit.prevent="submitImportFile()" class="space-y-4 text-xs">
                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Select File (.csv or .json)</label>
                        <input type="file" x-ref="importFileInput" accept=".csv,.json" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-800 dark:text-slate-200 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">File Format</label>
                        <select x-model="importOptions.format" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-800 dark:text-slate-200 font-medium">
                            <option value="csv">CSV (Comma-Separated Values)</option>
                            <option value="json">JSON (Array of Objects)</option>
                        </select>
                    </div>

                    <div x-show="importOptions.format === 'csv'">
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">CSV Delimiter</label>
                        <select x-model="importOptions.delimiter" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-800 dark:text-slate-200 font-mono">
                            <option value=",">Comma (,)</option>
                            <option value=";">Semicolon (;)</option>
                            <option value="	">Tab (\t)</option>
                        </select>
                    </div>

                    <div class="space-y-2 pt-2 border-t border-slate-200 dark:border-slate-800">
                        <label class="block text-slate-600 dark:text-slate-400 font-semibold">Target Table</label>

                        <div class="flex items-center gap-2">
                            <input type="radio" id="tgt_existing" value="existing" x-model="importOptions.targetMode" class="text-sky-600">
                            <label for="tgt_existing" class="text-slate-700 dark:text-slate-300">Insert into active table (<span x-text="activeTable || 'Select table'" class="font-semibold text-sky-500"></span>)</label>
                        </div>

                        <div class="flex items-center gap-2">
                            <input type="radio" id="tgt_new" value="new" x-model="importOptions.targetMode" class="text-sky-600">
                            <label for="tgt_new" class="text-slate-700 dark:text-slate-300">Create new table from file</label>
                        </div>

                        <div x-show="importOptions.targetMode === 'new'" class="pt-1">
                            <input type="text" x-model="importOptions.newTableName" placeholder="Enter new table name..." class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-800 dark:text-slate-200 font-semibold">
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" @click="showImportModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                        <button type="submit" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-4 py-2 rounded-xl transition shadow-lg shadow-sky-600/20 flex items-center gap-1.5">
                            <i data-lucide="upload" class="w-3.5 h-3.5"></i>
                            <span>Import Data</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL 7: RENAME TABLE -->
        <div x-show="showRenameTableModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="edit-2" class="w-4 h-4 text-sky-500"></i>
                    <span>Rename Table</span>
                </h3>

                <div>
                    <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1 font-medium">New Table Name</label>
                    <input type="text" x-model="renameTableNewName" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-sky-500 font-semibold">
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showRenameTableModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                    <button @click="submitRenameTable()" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-4 py-1.5 rounded-xl transition">Rename Table</button>
                </div>
            </div>
        </div>

        <!-- MODAL 8: RENAME COLUMN -->
        <div x-show="showRenameColModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="edit-2" class="w-4 h-4 text-sky-500"></i>
                    <span>Rename Column</span>
                </h3>

                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block text-slate-500 dark:text-slate-400 mb-1 font-medium">Current Name</label>
                        <input type="text" :value="renameColOldName" readonly class="w-full bg-slate-100 dark:bg-slate-950/50 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-500 dark:text-slate-400 font-mono">
                    </div>

                    <div>
                        <label class="block text-slate-500 dark:text-slate-400 mb-1 font-medium">New Column Name</label>
                        <input type="text" x-model="renameColNewName" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white focus:outline-none focus:border-sky-500 font-mono">
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showRenameColModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                    <button @click="submitRenameColumn()" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-4 py-1.5 rounded-xl transition">Rename Column</button>
                </div>
            </div>
        </div>

        <!-- MODAL 9: BATCH EDIT COLUMNS & SCHEMA DEFINITIONS -->
        <div x-show="showBulkRenameColModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-4xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                        <i data-lucide="edit-3" class="w-4 h-4 text-sky-500"></i>
                        <span>Batch Edit Table Schema & Column Definitions (<span x-text="activeTable" class="text-sky-500"></span>)</span>
                    </h3>
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400">Edit Column Names, Data Types, Default Values, Not Null, and Primary Keys below. All changes execute in an atomic transaction while preserving existing row data.</p>

                <div class="max-h-[420px] overflow-y-auto space-y-2 pr-1">
                    <div class="grid grid-cols-12 gap-2 text-[11px] font-bold text-slate-400 dark:text-slate-500 px-2 uppercase tracking-wider">
                        <div class="col-span-2">Original</div>
                        <div class="col-span-3">New Name</div>
                        <div class="col-span-2">Data Type</div>
                        <div class="col-span-3">Default Value</div>
                        <div class="col-span-1 text-center">Not Null</div>
                        <div class="col-span-1 text-center">PK</div>
                    </div>

                    <template x-for="(col, idx) in bulkColRenames" :key="idx">
                        <div class="grid grid-cols-12 gap-2 items-center bg-slate-50 dark:bg-slate-950 p-2.5 rounded-xl border border-slate-200 dark:border-slate-800 text-xs">
                            <!-- Original Name -->
                            <div class="col-span-2 font-mono text-slate-600 dark:text-slate-300 font-semibold truncate" x-text="col.old" title="Current Column Name"></div>
                            
                            <!-- New Name -->
                            <input type="text" x-model="col.new" placeholder="Column name" class="col-span-3 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-xl px-2.5 py-1.5 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                            
                            <!-- Type -->
                            <select x-model="col.type" class="col-span-2 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-xl px-2 py-1.5 text-slate-900 dark:text-white font-mono text-xs focus:outline-none focus:border-sky-500">
                                <option value="INTEGER">INTEGER</option>
                                <option value="TEXT">TEXT</option>
                                <option value="REAL">REAL</option>
                                <option value="BLOB">BLOB</option>
                                <option value="DATETIME">DATETIME</option>
                                <option value="NUMERIC">NUMERIC</option>
                                <option value="BOOLEAN">BOOLEAN</option>
                            </select>

                            <!-- Default Value -->
                            <input type="text" x-model="col.default" placeholder="Default (e.g. NULL, 0)" class="col-span-3 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-xl px-2.5 py-1.5 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">

                            <!-- Not Null -->
                            <div class="col-span-1 flex justify-center">
                                <input type="checkbox" x-model="col.notnull" class="w-4 h-4 rounded border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-sky-600 focus:ring-sky-500 cursor-pointer">
                            </div>

                            <!-- Primary Key -->
                            <div class="col-span-1 flex justify-center">
                                <input type="checkbox" x-model="col.pk" class="w-4 h-4 rounded border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-emerald-600 focus:ring-emerald-500 cursor-pointer">
                            </div>
                        </div>
                    </template>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showBulkRenameColModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                    <button @click="submitBulkRenameColumns()" class="bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-lg shadow-sky-600/20 flex items-center gap-1.5">
                        <i data-lucide="check" class="w-3.5 h-3.5"></i>
                        <span>Save All Column Definitions</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- MODAL 10: UPLOAD DB FILE -->
        <div x-show="showUploadDbModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="upload-cloud" class="w-4 h-4 text-sky-500"></i>
                    <span>Upload SQLite Database File</span>
                </h3>

                <p class="text-xs text-slate-500 dark:text-slate-400">Select a local SQLite database file (.sqlite, .db, .sqlite3) from your computer to open and manage it instantly.</p>

                <form @submit.prevent="submitUploadDbFile()" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Select Database File</label>
                        <input type="file" x-ref="dbFileInput" accept=".sqlite,.db,.sqlite3,.db3" class="w-full text-xs text-slate-700 dark:text-slate-300 bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl p-2.5 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-sky-500/10 file:text-sky-600 dark:file:text-sky-400 cursor-pointer">
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="showUploadDbModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                        <button type="submit" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-lg shadow-sky-600/20 flex items-center gap-1.5">
                            <i data-lucide="upload-cloud" class="w-3.5 h-3.5"></i>
                            <span>Upload & Open</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL 11: SECURITY SETTINGS & PASSWORD MANAGER -->
        <div x-show="showSecurityModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                        <i data-lucide="shield-check" class="w-4 h-4 text-amber-500"></i>
                        <span>Security & Access Control</span>
                    </h3>
                    <span class="text-[10px] font-mono bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 px-2 py-0.5 rounded-full font-bold">BCrypt Secured</span>
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400">Change your master login password. The new password will be encrypted using BCrypt hashing and saved to configuration.</p>

                <form @submit.prevent="submitChangePassword()" class="space-y-3 text-xs">
                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Current Password</label>
                        <input type="password" x-model="securityForm.currentPassword" required placeholder="Enter current password" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                    </div>

                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">New Password</label>
                        <input type="password" x-model="securityForm.newPassword" required placeholder="Enter new password (min 4 chars)" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                    </div>

                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Confirm New Password</label>
                        <input type="password" x-model="securityForm.confirmPassword" required placeholder="Re-enter new password" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                        <button type="button" @click="showSecurityModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                        <button type="submit" class="bg-gradient-to-r from-amber-500 to-orange-600 hover:from-amber-400 hover:to-orange-500 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-lg shadow-amber-500/20 flex items-center gap-1.5">
                            <i data-lucide="key-round" class="w-3.5 h-3.5"></i>
                            <span>Update Password</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL 12: COMMAND PALETTE (Ctrl+K / Cmd+K) -->
        <div x-show="showCmdPalette" class="fixed inset-0 z-50 flex items-start justify-center pt-20 bg-slate-950/80 backdrop-blur-md p-4" x-cloak @click.self="showCmdPalette = false">
            <div class="w-full max-w-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl overflow-hidden flex flex-col space-y-0">
                <!-- Search Input Header -->
                <div class="p-4 border-b border-slate-200 dark:border-slate-800 flex items-center gap-3">
                    <i data-lucide="search" class="w-5 h-5 text-sky-500 shrink-0"></i>
                    <input type="text" x-model="cmdSearch" x-ref="cmdSearchInput" placeholder="Type a command, table name, or database..." class="w-full bg-transparent text-sm font-medium text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none font-sans">
                    <kbd class="text-[10px] font-mono bg-slate-100 dark:bg-slate-800 text-slate-400 px-2 py-1 rounded-lg border border-slate-200 dark:border-slate-700">ESC</kbd>
                </div>

                <!-- Command Items List -->
                <div class="max-h-80 overflow-y-auto p-2 space-y-1">
                    <template x-for="(item, idx) in cmdPaletteItems" :key="idx">
                        <div @click="item.action(); showCmdPalette = false" class="p-3 rounded-2xl hover:bg-sky-500/10 dark:hover:bg-sky-500/20 text-slate-800 dark:text-slate-200 flex items-center justify-between cursor-pointer group transition border border-transparent hover:border-sky-500/30">
                            <div class="flex items-center gap-3 truncate">
                                <div class="w-7 h-7 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-sky-500 shrink-0 group-hover:scale-110 transition">
                                    <i :data-lucide="item.icon" class="w-4 h-4"></i>
                                </div>
                                <span class="text-xs font-semibold truncate" x-text="item.title"></span>
                            </div>
                            <span class="text-[10px] font-mono bg-slate-100 dark:bg-slate-800/80 text-slate-400 px-2 py-0.5 rounded-full uppercase" x-text="item.type"></span>
                        </div>
                    </template>

                    <template x-if="cmdPaletteItems.length === 0">
                        <div class="text-xs text-slate-400 text-center py-6">No matching command or table found.</div>
                    </template>
                </div>

                <!-- Footer Hint -->
                <div class="p-3 bg-slate-50 dark:bg-slate-950 border-t border-slate-200 dark:border-slate-800/80 flex items-center justify-between text-[11px] text-slate-400 font-mono">
                    <span>Press <kbd class="px-1.5 py-0.5 bg-slate-200 dark:bg-slate-800 rounded">Ctrl+K</kbd> anytime to open</span>
                    <span>LiteSQL Studio v<?php echo LITESQL_VERSION; ?></span>
                </div>
            </div>
        </div>

        <!-- MODAL 13: CREATE INDEX WIZARD -->
        <div x-show="showCreateIndexModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="key" class="w-4 h-4 text-sky-500"></i>
                    <span>Create Table Index</span>
                </h3>

                <form @submit.prevent="submitCreateIndex()" class="space-y-4 text-xs">
                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Index Name</label>
                        <input type="text" x-model="newIndex.name" required placeholder="idx_tablename_column" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="checkbox" id="idx_unique" x-model="newIndex.unique" class="w-4 h-4 rounded border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-sky-600 focus:ring-sky-500 cursor-pointer">
                        <label for="idx_unique" class="text-slate-700 dark:text-slate-300 font-semibold cursor-pointer">Unique Index (UNIQUE)</label>
                    </div>

                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Select Target Columns</label>
                        <div class="max-h-40 overflow-y-auto bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl p-3 space-y-2">
                            <template x-for="c in schema.columns" :key="c.name">
                                <label class="flex items-center gap-2 cursor-pointer font-mono">
                                    <input type="checkbox" :value="c.name" x-model="newIndex.columns" class="w-4 h-4 rounded border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-sky-600 focus:ring-sky-500">
                                    <span class="text-slate-800 dark:text-slate-200" x-text="c.name"></span>
                                    <span class="text-[10px] text-slate-400" x-text="'(' + c.type + ')'"></span>
                                </label>
                            </template>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <button type="button" @click="showCreateIndexModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                        <button type="submit" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-lg shadow-sky-600/20 flex items-center gap-1.5">
                            <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            <span>Create Index</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL 14: DUAL DATABASE DIFF & SCHEMA COMPARATOR -->
        <div x-show="showDbDiffModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-4xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-5 max-h-[90vh] flex flex-col">
                <!-- Header -->
                <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-3 shrink-0">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                        <i data-lucide="git-compare" class="w-5 h-5 text-indigo-500"></i>
                        <span>Dual Database Diff & Schema Comparator</span>
                    </h3>
                    <button @click="showDbDiffModal = false" class="text-slate-400 hover:text-slate-900 dark:hover:text-white text-xs"><i data-lucide="x" class="w-5 h-5"></i></button>
                </div>

                <!-- Database Pickers -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-50 dark:bg-slate-950 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shrink-0 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Base Database (DB 1)</label>
                        <input type="text" :value="activeDbName" readonly class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono">
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Target Database (DB 2)</label>
                        <select x-model="diffTargetDb" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                            <option value="">-- Select Target Database to Compare --</option>
                            <template x-for="d in databases" :key="d.path">
                                <option :value="d.path" x-text="d.name + ' (' + d.formatted_size + ')'" :disabled="d.path === activeDb"></option>
                            </template>
                        </select>
                    </div>

                    <div class="md:col-span-2 flex justify-end">
                        <button @click="submitDbDiff()" :disabled="!diffTargetDb" class="bg-gradient-to-r from-indigo-600 to-sky-600 hover:from-indigo-500 hover:to-sky-500 disabled:opacity-40 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-lg shadow-indigo-600/20 flex items-center gap-1.5">
                            <i data-lucide="git-compare" class="w-3.5 h-3.5"></i>
                            <span>Run Full Database Diff</span>
                        </button>
                    </div>
                </div>

                <!-- Diff Results View -->
                <div class="flex-1 overflow-y-auto space-y-4">
                    <template x-if="diffResult.db1_name">
                        <div class="space-y-4">
                            <!-- Overview Badges -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                                <div class="bg-slate-50 dark:bg-slate-950 p-3 rounded-xl border border-slate-200 dark:border-slate-800 text-center">
                                    <div class="text-[10px] text-slate-400 uppercase font-bold">Only in DB 1</div>
                                    <div class="text-base font-extrabold text-indigo-500 font-mono" x-text="diffResult.only_in_db1 ? diffResult.only_in_db1.length : 0"></div>
                                </div>
                                <div class="bg-slate-50 dark:bg-slate-950 p-3 rounded-xl border border-slate-200 dark:border-slate-800 text-center">
                                    <div class="text-[10px] text-slate-400 uppercase font-bold">Only in DB 2</div>
                                    <div class="text-base font-extrabold text-sky-500 font-mono" x-text="diffResult.only_in_db2 ? diffResult.only_in_db2.length : 0"></div>
                                </div>
                                <div class="bg-slate-50 dark:bg-slate-950 p-3 rounded-xl border border-slate-200 dark:border-slate-800 text-center">
                                    <div class="text-[10px] text-slate-400 uppercase font-bold">Common Tables</div>
                                    <div class="text-base font-extrabold text-emerald-500 font-mono" x-text="diffResult.table_diffs ? diffResult.table_diffs.length : 0"></div>
                                </div>
                                <div class="bg-slate-50 dark:bg-slate-950 p-3 rounded-xl border border-slate-200 dark:border-slate-800 text-center">
                                    <div class="text-[10px] text-slate-400 uppercase font-bold">Schema Mismatches</div>
                                    <div class="text-base font-extrabold text-amber-500 font-mono" x-text="diffResult.table_diffs ? diffResult.table_diffs.filter(t => t.status !== 'MATCH').length : 0"></div>
                                </div>
                            </div>

                            <!-- Table Diffs Table -->
                            <div class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden shadow-sm">
                                <table class="w-full text-left text-xs font-mono">
                                    <thead>
                                        <tr class="bg-slate-50 dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 text-slate-500 uppercase text-[10px]">
                                            <th class="p-3">Table Name</th>
                                            <th class="p-3">Schema Status</th>
                                            <th class="p-3 text-right">DB 1 Rows</th>
                                            <th class="p-3 text-right">DB 2 Rows</th>
                                            <th class="p-3 text-right">Row Diff</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                                        <template x-for="td in diffResult.table_diffs" :key="td.table">
                                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40">
                                                <td class="p-3 font-bold text-slate-900 dark:text-white" x-text="td.table"></td>
                                                <td class="p-3">
                                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold" :class="td.status === 'MATCH' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20'" x-text="td.status"></span>
                                                </td>
                                                <td class="p-3 text-right text-slate-700 dark:text-slate-300" x-text="td.db1_rows"></td>
                                                <td class="p-3 text-right text-slate-700 dark:text-slate-300" x-text="td.db2_rows"></td>
                                                <td class="p-3 text-right font-bold" :class="td.row_diff === 0 ? 'text-slate-400' : 'text-amber-500'" x-text="td.row_diff > 0 ? '+' + td.row_diff : td.row_diff"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- MODAL 15: SAVE QUERY TO FAVORITES WIZARD -->
        <div x-show="showSaveQueryModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="star" class="w-4 h-4 text-amber-500"></i>
                    <span>Save Query to Favorites Library</span>
                </h3>

                <form @submit.prevent="submitSaveQuery()" class="space-y-3 text-xs">
                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Query Title</label>
                        <input type="text" x-model="saveQueryForm.title" required placeholder="e.g. Active Users Count" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-sans focus:outline-none focus:border-sky-500">
                    </div>

                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Category / Tag</label>
                        <input type="text" x-model="saveQueryForm.tag" placeholder="e.g. Reports, Users, Maintenance" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-sans focus:outline-none focus:border-sky-500">
                    </div>

                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">SQL Statement</label>
                        <textarea x-model="saveQueryForm.sql" rows="3" readonly class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl p-2.5 text-slate-600 dark:text-slate-400 font-mono text-[11px]"></textarea>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <button type="button" @click="showSaveQueryModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                        <button type="submit" class="bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-lg shadow-amber-500/20 flex items-center gap-1.5">
                            <i data-lucide="star" class="w-3.5 h-3.5"></i>
                            <span>Save to Library</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL 16: ADD FOREIGN KEY CONSTRAINT WIZARD -->
        <div x-show="showAddFkModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="link" class="w-4 h-4 text-emerald-500"></i>
                    <span>Create Foreign Key Relational Link</span>
                </h3>

                <form @submit.prevent="submitAddForeignKey()" class="space-y-4 text-xs">
                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Local Column (<span x-text="activeTable"></span>)</label>
                        <select x-model="fkForm.local_col" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                            <option value="">-- Select Local Column --</option>
                            <template x-for="c in schema.columns" :key="c.name">
                                <option :value="c.name" x-text="c.name + ' (' + c.type + ')'"></option>
                            </template>
                        </select>
                    </div>

                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Referenced Table</label>
                        <select x-model="fkForm.ref_table" @change="onFkRefTableChange()" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                            <option value="">-- Select Referenced Table --</option>
                            <template x-for="t in tables" :key="t.name">
                                <option :value="t.name" x-text="t.name" :disabled="t.name === activeTable"></option>
                            </template>
                        </select>
                    </div>

                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Referenced Column</label>
                        <select x-model="fkForm.ref_col" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                            <option value="">-- Select Target Column --</option>
                            <template x-for="rc in fkTargetCols" :key="rc.name">
                                <option :value="rc.name" x-text="rc.name + ' (' + rc.type + ')'"></option>
                            </template>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">ON DELETE Action</label>
                            <select x-model="fkForm.on_delete" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-2.5 py-2 text-slate-900 dark:text-white font-mono">
                                <option value="CASCADE">CASCADE</option>
                                <option value="SET NULL">SET NULL</option>
                                <option value="RESTRICT">RESTRICT</option>
                                <option value="NO ACTION">NO ACTION</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">ON UPDATE Action</label>
                            <select x-model="fkForm.on_update" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-2.5 py-2 text-slate-900 dark:text-white font-mono">
                                <option value="NO ACTION">NO ACTION</option>
                                <option value="CASCADE">CASCADE</option>
                                <option value="SET NULL">SET NULL</option>
                                <option value="RESTRICT">RESTRICT</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <button type="button" @click="showAddFkModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                        <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-lg shadow-emerald-600/20 flex items-center gap-1.5">
                            <i data-lucide="link" class="w-3.5 h-3.5"></i>
                            <span>Create Relationship</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL 17: CREATE VIRTUAL VIEW WIZARD -->
        <div x-show="showCreateViewModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="eye" class="w-4 h-4 text-purple-500"></i>
                    <span>Create Virtual View (CREATE VIEW)</span>
                </h3>

                <form @submit.prevent="submitCreateView()" class="space-y-4 text-xs">
                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">View Name</label>
                        <input type="text" x-model="newViewName" required placeholder="vw_active_users" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                    </div>

                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">SELECT Query Statement</label>
                        <textarea x-model="newViewSql" rows="6" required placeholder="SELECT id, name, email FROM users WHERE status = 'active';" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl p-3 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500"></textarea>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <button type="button" @click="showCreateViewModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                        <button type="submit" class="bg-purple-600 hover:bg-purple-500 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-lg shadow-purple-600/20 flex items-center gap-1.5">
                            <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            <span>Create Virtual View</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL 18: CREATE TRIGGER WIZARD -->
        <div x-show="showCreateTriggerModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="zap" class="w-4 h-4 text-purple-500"></i>
                    <span>Create Database Trigger</span>
                </h3>

                <form @submit.prevent="submitCreateTrigger()" class="space-y-4 text-xs">
                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Trigger Name</label>
                        <input type="text" x-model="triggerForm.name" required placeholder="trg_orders_audit" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Timing</label>
                            <select x-model="triggerForm.timing" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-2.5 py-2 text-slate-900 dark:text-white font-mono">
                                <option value="AFTER">AFTER</option>
                                <option value="BEFORE">BEFORE</option>
                                <option value="INSTEAD OF">INSTEAD OF</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Event</label>
                            <select x-model="triggerForm.event" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-2.5 py-2 text-slate-900 dark:text-white font-mono">
                                <option value="INSERT">INSERT</option>
                                <option value="UPDATE">UPDATE</option>
                                <option value="DELETE">DELETE</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Target Table</label>
                        <select x-model="triggerForm.table" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                            <template x-for="t in tables" :key="t.name">
                                <option :value="t.name" x-text="t.name"></option>
                            </template>
                        </select>
                    </div>

                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Trigger Body SQL (BEGIN ... END;)</label>
                        <textarea x-model="triggerForm.body" rows="4" required placeholder="BEGIN update users set updated_at = datetime('now') where id = NEW.id; END;" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl p-3 text-slate-900 dark:text-white font-mono text-[11px] focus:outline-none focus:border-sky-500"></textarea>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <button type="button" @click="showCreateTriggerModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                        <button type="submit" class="bg-purple-600 hover:bg-purple-500 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-lg shadow-purple-600/20 flex items-center gap-1.5">
                            <i data-lucide="zap" class="w-3.5 h-3.5"></i>
                            <span>Create Trigger</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL 19: MOCK DATA GENERATOR WIZARD -->
        <div x-show="showMockDataModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="dices" class="w-4 h-4 text-purple-500"></i>
                    <span>Generate Sample Mock Data</span>
                </h3>

                <form @submit.prevent="submitGenerateMockData()" class="space-y-4 text-xs">
                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Target Table</label>
                        <input type="text" :value="activeTable" readonly class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-700 dark:text-slate-300 font-mono font-bold">
                    </div>

                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Number of Mock Rows to Generate</label>
                        <select x-model="mockDataCount" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                            <option value="10">10 Rows (Quick Test)</option>
                            <option value="25">25 Rows (Standard Sample)</option>
                            <option value="50">50 Rows (Medium Batch)</option>
                            <option value="100">100 Rows (Large Batch)</option>
                            <option value="500">500 Rows (Stress Test)</option>
                        </select>
                    </div>

                    <div class="p-3 bg-purple-500/10 border border-purple-500/20 rounded-xl space-y-1 text-purple-600 dark:text-purple-400 text-[11px]">
                        <div class="font-bold flex items-center gap-1">
                            <i data-lucide="sparkles" class="w-3.5 h-3.5"></i>
                            <span>Smart Field Detection Active</span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 font-sans">LiteSQL Studio automatically detects column names (emails, names, cities, statuses, dates, prices) and generates realistic mock values.</p>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <button type="button" @click="showMockDataModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                        <button type="submit" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-lg shadow-purple-600/20 flex items-center gap-1.5">
                            <i data-lucide="dices" class="w-3.5 h-3.5"></i>
                            <span>Generate Mock Data</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL 20: DUPLICATE TABLE WIZARD -->
        <div x-show="showDuplicateTableModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4" x-cloak>
            <div class="w-full max-w-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="copy" class="w-4 h-4 text-emerald-500"></i>
                    <span>Duplicate / Clone Table</span>
                </h3>

                <form @submit.prevent="submitDuplicateTable()" class="space-y-4 text-xs">
                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">Source Table</label>
                        <input type="text" :value="activeTable" readonly class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-700 dark:text-slate-300 font-mono font-bold">
                    </div>

                    <div>
                        <label class="block text-slate-600 dark:text-slate-400 mb-1 font-semibold">New Cloned Table Name</label>
                        <input type="text" x-model="duplicateForm.new_name" required placeholder="table_copy" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono focus:outline-none focus:border-sky-500">
                    </div>

                    <div class="space-y-2">
                        <label class="block text-slate-600 dark:text-slate-400 font-semibold">Clone Contents</label>
                        <label class="flex items-center gap-2 text-slate-700 dark:text-slate-300 cursor-pointer">
                            <input type="checkbox" x-model="duplicateForm.include_data" class="rounded text-sky-600 focus:ring-sky-500">
                            <span>Include all table row data (Full Table Copy)</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <button type="button" @click="showDuplicateTableModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                        <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-lg shadow-emerald-600/20 flex items-center gap-1.5">
                            <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                            <span>Duplicate Table</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL 21: DUAL SQL QUERY BENCHMARKER & SPEED COMPARATOR -->
        <div x-show="showBenchmarkModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/85 backdrop-blur-md p-4 overflow-y-auto" x-cloak>
            <div class="w-full max-w-4xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-5 max-h-[90vh] flex flex-col">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                        <i data-lucide="zap" class="w-4 h-4 text-amber-500"></i>
                        <span>Dual SQL Query Performance Benchmarker & Speed Comparator</span>
                    </h3>
                    <button @click="showBenchmarkModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-white transition"><i data-lucide="x" class="w-4 h-4"></i></button>
                </div>

                <div class="space-y-4 overflow-y-auto flex-1 pr-1 text-xs">
                    <!-- INPUT QUERIES ROW -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- QUERY A -->
                        <div class="space-y-2 bg-slate-50 dark:bg-slate-950 p-3.5 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                            <div class="flex items-center justify-between">
                                <label class="font-bold text-sky-600 dark:text-sky-400 uppercase tracking-wider flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-sky-500"></span>
                                    <span>Query A (Baseline)</span>
                                </label>
                            </div>
                            <textarea x-model="benchmarkForm.sql_a" rows="4" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-xl p-3 font-mono text-[11px] text-slate-900 dark:text-white focus:outline-none focus:border-sky-500" placeholder="SELECT * FROM users;"></textarea>
                        </div>

                        <!-- QUERY B -->
                        <div class="space-y-2 bg-slate-50 dark:bg-slate-950 p-3.5 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                            <div class="flex items-center justify-between">
                                <label class="font-bold text-purple-600 dark:text-purple-400 uppercase tracking-wider flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                                    <span>Query B (Optimized Candidate)</span>
                                </label>
                            </div>
                            <textarea x-model="benchmarkForm.sql_b" rows="4" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-xl p-3 font-mono text-[11px] text-slate-900 dark:text-white focus:outline-none focus:border-purple-500" placeholder="SELECT id, name FROM users LIMIT 50;"></textarea>
                        </div>
                    </div>

                    <!-- RUN BENCHMARK BAR -->
                    <div class="flex flex-wrap items-center justify-between gap-3 bg-gradient-to-r from-slate-100 to-slate-200 dark:from-slate-800/50 dark:to-slate-900/50 p-3 rounded-2xl border border-slate-300 dark:border-slate-700/60">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-slate-700 dark:text-slate-300">Test Iterations:</span>
                            <select x-model="benchmarkForm.iterations" class="bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-1.5 font-mono text-slate-900 dark:text-white">
                                <option value="5">5 Iterations (Quick Test)</option>
                                <option value="10">10 Iterations (Standard)</option>
                                <option value="25">25 Iterations (High Precision)</option>
                                <option value="50">50 Iterations (Stress Benchmark)</option>
                            </select>
                        </div>

                        <button @click="runBenchmarkTest()" :disabled="benchmarkLoading" class="bg-gradient-to-r from-purple-600 via-indigo-600 to-sky-600 hover:from-purple-500 hover:to-sky-500 text-white font-bold px-6 py-2 rounded-xl transition shadow-lg shadow-indigo-600/20 flex items-center gap-2 active:scale-95 disabled:opacity-50">
                            <i data-lucide="play-circle" class="w-4 h-4 text-amber-300"></i>
                            <span x-text="benchmarkLoading ? 'Benchmarking Executions...' : '⚡ Run Speed Benchmark'"></span>
                        </button>
                    </div>

                    <!-- BENCHMARK RESULTS DASHBOARD -->
                    <template x-if="benchmarkResults.success">
                        <div class="space-y-4 pt-2">
                            <!-- WINNER BANNER -->
                            <div class="p-4 rounded-2xl border flex items-center justify-between shadow-sm" :class="benchmarkResults.winner === 'B' ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-600 dark:text-emerald-400' : (benchmarkResults.winner === 'A' ? 'bg-sky-500/10 border-sky-500/30 text-sky-600 dark:text-sky-400' : 'bg-slate-500/10 border-slate-500/30 text-slate-600 dark:text-slate-400')">
                                <div class="flex items-center gap-3">
                                    <div class="p-2.5 rounded-xl bg-white dark:bg-slate-950 shadow-inner">
                                        <i data-lucide="trophy" class="w-6 h-6 text-amber-500"></i>
                                    </div>
                                    <div>
                                        <h4 class="font-bold text-sm" x-text="benchmarkResults.winner === 'B' ? '🚀 Query B is the WINNER!' : (benchmarkResults.winner === 'A' ? '⚡ Query A is the WINNER!' : '🤝 It\'s a TIE!')"></h4>
                                        <p class="text-xs text-slate-600 dark:text-slate-400" x-text="'Tested over ' + benchmarkResults.iterations + ' iterations. ' + (benchmarkResults.winner !== 'tie' ? ('Query ' + benchmarkResults.winner + ' is ' + benchmarkResults.speedup + 'x FASTER!') : 'Both queries executed at virtually identical speeds.')"></p>
                                    </div>
                                </div>
                                <span class="text-lg font-black font-mono px-3.5 py-1.5 bg-white dark:bg-slate-950 rounded-xl border border-current shadow-sm" x-text="benchmarkResults.speedup + 'x Faster'"></span>
                            </div>

                            <!-- STATS CARDS COMPARISON -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 font-mono">
                                <!-- QUERY A STATS CARD -->
                                <div class="bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-2">
                                    <div class="font-bold text-sky-600 dark:text-sky-400 flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-2">
                                        <span>Query A Performance</span>
                                        <span class="text-xs px-2 py-0.5 rounded bg-sky-500/10" x-text="benchmarkResults.query_a.avg_ms + ' ms (Avg)'"></span>
                                    </div>
                                    <div class="grid grid-cols-3 gap-2 text-center text-[11px] pt-1">
                                        <div class="bg-white dark:bg-slate-900 p-2 rounded-xl border border-slate-200 dark:border-slate-800">
                                            <div class="text-[10px] text-slate-400">AVG TIME</div>
                                            <div class="font-bold text-slate-900 dark:text-white" x-text="benchmarkResults.query_a.avg_ms + ' ms'"></div>
                                        </div>
                                        <div class="bg-white dark:bg-slate-900 p-2 rounded-xl border border-slate-200 dark:border-slate-800">
                                            <div class="text-[10px] text-slate-400">FASTEST (MIN)</div>
                                            <div class="font-bold text-emerald-500" x-text="benchmarkResults.query_a.min_ms + ' ms'"></div>
                                        </div>
                                        <div class="bg-white dark:bg-slate-900 p-2 rounded-xl border border-slate-200 dark:border-slate-800">
                                            <div class="text-[10px] text-slate-400">SLOWEST (MAX)</div>
                                            <div class="font-bold text-amber-500" x-text="benchmarkResults.query_a.max_ms + ' ms'"></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- QUERY B STATS CARD -->
                                <div class="bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-2">
                                    <div class="font-bold text-purple-600 dark:text-purple-400 flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-2">
                                        <span>Query B Performance</span>
                                        <span class="text-xs px-2 py-0.5 rounded bg-purple-500/10" x-text="benchmarkResults.query_b.avg_ms + ' ms (Avg)'"></span>
                                    </div>
                                    <div class="grid grid-cols-3 gap-2 text-center text-[11px] pt-1">
                                        <div class="bg-white dark:bg-slate-900 p-2 rounded-xl border border-slate-200 dark:border-slate-800">
                                            <div class="text-[10px] text-slate-400">AVG TIME</div>
                                            <div class="font-bold text-slate-900 dark:text-white" x-text="benchmarkResults.query_b.avg_ms + ' ms'"></div>
                                        </div>
                                        <div class="bg-white dark:bg-slate-900 p-2 rounded-xl border border-slate-200 dark:border-slate-800">
                                            <div class="text-[10px] text-slate-400">FASTEST (MIN)</div>
                                            <div class="font-bold text-emerald-500" x-text="benchmarkResults.query_b.min_ms + ' ms'"></div>
                                        </div>
                                        <div class="bg-white dark:bg-slate-900 p-2 rounded-xl border border-slate-200 dark:border-slate-800">
                                            <div class="text-[10px] text-slate-400">SLOWEST (MAX)</div>
                                            <div class="font-bold text-amber-500" x-text="benchmarkResults.query_b.max_ms + ' ms'"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- MODAL 22: RECORD DETAILS ROW INSPECTOR MODAL -->
        <div x-show="showRowInspectorModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/85 backdrop-blur-md p-4 overflow-y-auto" x-cloak>
            <div class="w-full max-w-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4 max-h-[85vh] flex flex-col">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                    <div class="flex items-center gap-2">
                        <i data-lucide="eye" class="w-4 h-4 text-sky-500"></i>
                        <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest">
                            <span>Record Details Inspector</span>
                        </h3>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="copyRowJson()" class="bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold px-3 py-1 rounded-xl border border-slate-300 dark:border-slate-700 transition flex items-center gap-1">
                            <i data-lucide="copy" class="w-3.5 h-3.5 text-sky-500"></i>
                            <span>Copy JSON</span>
                        </button>
                        <button @click="showRowInspectorModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-white transition"><i data-lucide="x" class="w-4 h-4"></i></button>
                    </div>
                </div>

                <div class="space-y-3 overflow-y-auto flex-1 pr-1 font-mono text-xs">
                    <template x-if="inspectRowData">
                        <div class="space-y-2.5">
                            <template x-for="(val, key) in inspectRowData" :key="key">
                                <div class="bg-slate-50 dark:bg-slate-950 p-3 rounded-2xl border border-slate-200 dark:border-slate-800/80 space-y-1.5">
                                    <div class="flex items-center justify-between">
                                        <span class="font-bold text-sky-600 dark:text-sky-400 flex items-center gap-1.5">
                                            <i data-lucide="tag" class="w-3 h-3"></i>
                                            <span x-text="key"></span>
                                        </span>
                                        <span class="text-[10px] text-slate-400 bg-slate-200 dark:bg-slate-800 px-2 py-0.5 rounded-full" x-text="val === null ? 'NULL' : typeof val"></span>
                                    </div>
                                    <div class="bg-white dark:bg-slate-900 p-2.5 rounded-xl border border-slate-200 dark:border-slate-800/80 break-words whitespace-pre-wrap text-slate-900 dark:text-slate-100" x-text="val === null ? 'NULL' : (typeof val === 'object' ? JSON.stringify(val, null, 2) : val)"></div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- MODAL 23: SQLITE TABLE COLUMN REORDER WIZARD -->
        <div x-show="showReorderColsModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/85 backdrop-blur-md p-4 overflow-y-auto" x-cloak>
            <div class="w-full max-w-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                        <i data-lucide="arrow-up-down" class="w-4 h-4 text-indigo-500"></i>
                        <span>Reorder Column Positions</span>
                    </h3>
                    <button @click="showReorderColsModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-white transition"><i data-lucide="x" class="w-4 h-4"></i></button>
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400">Use the Up/Down buttons to reorder columns. LiteSQL Studio will rebuild the table schema preserving 100% of existing data.</p>

                <div class="max-h-72 overflow-y-auto space-y-2 pr-1 text-xs font-mono">
                    <template x-for="(col, idx) in reorderColsList" :key="col.name">
                        <div class="bg-slate-50 dark:bg-slate-950 p-3 rounded-2xl border border-slate-200 dark:border-slate-800/80 flex items-center justify-between shadow-sm">
                            <div class="flex items-center gap-3">
                                <span class="text-[10px] font-bold text-slate-400 w-5" x-text="'#' + (idx + 1)"></span>
                                <span class="font-bold text-slate-900 dark:text-white" x-text="col.name"></span>
                                <span class="text-[10px] text-slate-400 uppercase" x-text="col.type"></span>
                                <template x-if="col.pk > 0">
                                    <span class="text-[9px] bg-amber-500/20 text-amber-600 border border-amber-500/30 px-1 rounded font-bold">PK</span>
                                </template>
                            </div>

                            <div class="flex items-center gap-1">
                                <button @click="moveColUp(idx)" :disabled="idx === 0" class="p-1 rounded-lg border border-slate-300 dark:border-slate-800 hover:bg-slate-200 dark:hover:bg-slate-800 disabled:opacity-30 transition"><i data-lucide="chevron-up" class="w-3.5 h-3.5"></i></button>
                                <button @click="moveColDown(idx)" :disabled="idx === reorderColsList.length - 1" class="p-1 rounded-lg border border-slate-300 dark:border-slate-800 hover:bg-slate-200 dark:hover:bg-slate-800 disabled:opacity-30 transition"><i data-lucide="chevron-down" class="w-3.5 h-3.5"></i></button>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" @click="showReorderColsModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                    <button @click="submitReorderCols()" class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold px-5 py-2 rounded-xl transition shadow-lg shadow-indigo-600/20 flex items-center gap-1.5">
                        <i data-lucide="save" class="w-3.5 h-3.5"></i>
                        <span>Save Column Order</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- MODAL 24: DATABASE SCHEMA DDL SQL EXPORTER & MIGRATION WIZARD -->
        <div x-show="showDdlModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/85 backdrop-blur-md p-4 overflow-y-auto" x-cloak>
            <div class="w-full max-w-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4 max-h-[85vh] flex flex-col">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                    <div class="flex items-center gap-2">
                        <i data-lucide="file-code" class="w-4 h-4 text-emerald-500"></i>
                        <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest">
                            <span>Database Schema DDL & Migration Generator</span>
                        </h3>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="copyDdlCode()" class="bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold px-3 py-1 rounded-xl border border-slate-300 dark:border-slate-700 transition flex items-center gap-1">
                            <i data-lucide="copy" class="w-3.5 h-3.5 text-sky-500"></i>
                            <span>Copy SQL</span>
                        </button>
                        <button @click="downloadDdlFile()" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold px-3 py-1 rounded-xl shadow-md transition flex items-center gap-1">
                            <i data-lucide="download" class="w-3.5 h-3.5"></i>
                            <span>Download .sql</span>
                        </button>
                        <button @click="showDdlModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-white transition"><i data-lucide="x" class="w-4 h-4"></i></button>
                    </div>
                </div>

                <!-- DDL OPTIONS BAR -->
                <div class="flex flex-wrap items-center gap-4 bg-slate-50 dark:bg-slate-950 p-3 rounded-2xl border border-slate-200 dark:border-slate-800 text-xs">
                    <label class="flex items-center gap-1.5 cursor-pointer font-medium text-slate-700 dark:text-slate-300 select-none">
                        <input type="checkbox" x-model="ddlOptions.drops" @change="loadDdlCode()" class="w-3.5 h-3.5 rounded border-slate-300 dark:border-slate-700 text-emerald-600 focus:ring-emerald-500">
                        <span>Include DROP Statements (DROP TABLE IF EXISTS)</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer font-medium text-slate-700 dark:text-slate-300 select-none">
                        <input type="checkbox" x-model="ddlOptions.indexes" @change="loadDdlCode()" class="w-3.5 h-3.5 rounded border-slate-300 dark:border-slate-700 text-emerald-600 focus:ring-emerald-500">
                        <span>Include Indexes</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer font-medium text-slate-700 dark:text-slate-300 select-none">
                        <input type="checkbox" x-model="ddlOptions.triggers" @change="loadDdlCode()" class="w-3.5 h-3.5 rounded border-slate-300 dark:border-slate-700 text-emerald-600 focus:ring-emerald-500">
                        <span>Include Triggers</span>
                    </label>
                </div>

                <!-- DDL CODE PREVIEW AREA -->
                <div class="flex-1 overflow-hidden relative">
                    <textarea x-model="ddlCode" readonly class="w-full h-full min-h-[300px] bg-slate-900 text-emerald-400 p-4 rounded-2xl font-mono text-xs focus:outline-none resize-none border border-slate-800"></textarea>
                </div>
            </div>
        </div>

        <!-- MODAL 25: SQLITE COLUMN QUICK DUPLICATE & CLONING WIZARD -->
        <div x-show="showDuplicateColModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/85 backdrop-blur-md p-4 overflow-y-auto" x-cloak>
            <div class="w-full max-w-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                        <i data-lucide="copy" class="w-4 h-4 text-indigo-500"></i>
                        <span>Duplicate Column</span>
                    </h3>
                    <button @click="showDuplicateColModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-white transition"><i data-lucide="x" class="w-4 h-4"></i></button>
                </div>

                <form @submit.prevent="submitDuplicateCol()" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Source Column Name</label>
                        <input type="text" x-model="duplicateColForm.source_col" readonly class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-xs font-mono text-slate-500 cursor-not-allowed">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">New Duplicate Column Name</label>
                        <input type="text" x-model="duplicateColForm.new_col" required class="w-full bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-xs font-mono text-slate-900 dark:text-white focus:outline-none focus:border-indigo-500">
                    </div>

                    <div class="bg-slate-50 dark:bg-slate-950 p-3 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                        <label class="flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-800 dark:text-slate-200 select-none">
                            <input type="checkbox" x-model="duplicateColForm.copy_data" class="w-4 h-4 rounded border-slate-300 dark:border-slate-700 text-indigo-600 focus:ring-indigo-500">
                            <span>Copy existing column row data to new column</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="showDuplicateColModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold px-4 py-2 rounded-xl transition shadow-lg shadow-indigo-600/20 flex items-center gap-1.5">
                            <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                            <span>Duplicate Column</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL 26: SQLITE COLUMN COMMENT / DESCRIPTION MANAGER -->
        <div x-show="showCommentModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/85 backdrop-blur-md p-4 overflow-y-auto" x-cloak>
            <div class="w-full max-w-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                        <i data-lucide="message-square" class="w-4 h-4 text-amber-500"></i>
                        <span>Column Description Note</span>
                    </h3>
                    <button @click="showCommentModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-white transition"><i data-lucide="x" class="w-4 h-4"></i></button>
                </div>

                <form @submit.prevent="submitComment()" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Column Name</label>
                        <input type="text" x-model="commentForm.column" readonly class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-xs font-mono text-slate-500 cursor-not-allowed">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Description / Note</label>
                        <textarea x-model="commentForm.comment" rows="3" placeholder="Write description or documentation notes for this column..." class="w-full bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl p-3 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-amber-500 resize-none font-sans"></textarea>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="showCommentModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Cancel</button>
                        <button type="submit" class="bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold px-4 py-2 rounded-xl transition shadow-lg shadow-amber-600/20 flex items-center gap-1.5">
                            <i data-lucide="save" class="w-3.5 h-3.5"></i>
                            <span>Save Note</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL 27: REST API & MULTI-LANGUAGE CODE SNIPPET GENERATOR WIZARD -->
        <div x-show="showCodeGeneratorModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/85 backdrop-blur-md p-4 overflow-y-auto" x-cloak>
            <div class="w-full max-w-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 space-y-4 max-h-[85vh] flex flex-col">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-widest flex items-center gap-2">
                        <i data-lucide="code" class="w-4 h-4 text-sky-500"></i>
                        <span>Code Snippet & REST API Generator</span>
                    </h3>
                    <button @click="showCodeGeneratorModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-white transition"><i data-lucide="x" class="w-4 h-4"></i></button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Target Language / Environment</label>
                        <select x-model="codeGenLang" @change="generateCodeSnippet()" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-xs font-semibold text-slate-900 dark:text-white focus:outline-none focus:border-sky-500">
                            <option value="php">🐘 PHP (PDO Database Driver)</option>
                            <option value="nodejs">🟢 Node.js (better-sqlite3)</option>
                            <option value="python">🐍 Python (sqlite3 native)</option>
                            <option value="flutter">💙 Flutter / Dart (sqflite plugin)</option>
                            <option value="curl">⚡ cURL HTTP REST API</option>
                        </select>
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Operation Type</label>
                        <select x-model="codeGenOp" @change="generateCodeSnippet()" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl px-3 py-2 text-xs font-semibold text-slate-900 dark:text-white focus:outline-none focus:border-sky-500">
                            <option value="select">SELECT All Rows</option>
                            <option value="insert">INSERT Record</option>
                            <option value="update">UPDATE Record</option>
                            <option value="delete">DELETE Record</option>
                            <option value="custom">Active Editor SQL Query</option>
                        </select>
                    </div>
                </div>

                <div class="flex-1 overflow-hidden relative">
                    <textarea x-model="generatedCode" readonly class="w-full h-full min-h-[260px] bg-slate-900 text-sky-400 p-4 rounded-2xl font-mono text-xs focus:outline-none resize-none border border-slate-800"></textarea>
                </div>

                <div class="flex justify-between items-center pt-3 border-t border-slate-100 dark:border-slate-800">
                    <span class="text-[11px] text-slate-400">1-click drop-in snippet ready for production code!</span>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="showCodeGeneratorModal = false" class="px-3 py-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Close</button>
                        <button @click="copyCodeSnippet()" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-4 py-2 rounded-xl transition shadow-lg shadow-sky-600/20 flex items-center gap-1.5">
                            <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                            <span>Copy Code Snippet</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- GLOBAL DATABASE SEARCH MODAL -->
    <template x-if="showGlobalSearchModal">
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-md" @keydown.escape.window="showGlobalSearchModal = false">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl w-full max-w-4xl max-h-[85vh] flex flex-col shadow-2xl overflow-hidden" @click.away="showGlobalSearchModal = false">
                <!-- Modal Header / Search Bar -->
                <div class="p-4 bg-slate-50 dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800 flex items-center gap-3">
                    <i data-lucide="search" class="w-5 h-5 text-sky-500 shrink-0"></i>
                    <input type="text" x-ref="globalSearchInput" x-model="globalSearchQuery" @keyup.enter="performGlobalSearch()" placeholder="Search keyword across ALL database tables (e.g. 'Ghazipur', 'Sharma')..." class="w-full bg-transparent text-sm font-semibold text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none" autofocus>
                    
                    <button @click="performGlobalSearch()" class="bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold px-4 py-2 rounded-xl transition shrink-0 flex items-center gap-1.5 shadow-md shadow-sky-600/20 active:scale-95">
                        <i :data-lucide="globalSearchLoading ? 'loader-2' : 'search'" class="w-3.5 h-3.5" :class="globalSearchLoading ? 'animate-spin' : ''"></i>
                        <span>Search</span>
                    </button>
                    <button @click="showGlobalSearchModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl border border-slate-200 dark:border-slate-800 shrink-0"><i data-lucide="x" class="w-4 h-4"></i></button>
                </div>

                <!-- Modal Body Results Area -->
                <div class="flex-1 overflow-y-auto p-6 space-y-6">
                    <template x-if="globalSearchLoading">
                        <div class="py-16 text-center space-y-3">
                            <i data-lucide="loader-2" class="w-8 h-8 text-sky-500 animate-spin mx-auto"></i>
                            <p class="text-xs text-slate-500 font-mono">Searching all tables in active database...</p>
                        </div>
                    </template>

                    <template x-if="!globalSearchLoading && globalSearchResults.query && globalSearchResults.total_matches === 0">
                        <div class="py-16 text-center space-y-2">
                            <i data-lucide="search-x" class="w-10 h-10 text-slate-400 mx-auto"></i>
                            <h4 class="text-sm font-bold text-slate-700 dark:text-slate-300">No Matches Found</h4>
                            <p class="text-xs text-slate-500">No records found matching "<span x-text="globalSearchResults.query"></span>" across any tables.</p>
                        </div>
                    </template>

                    <template x-if="!globalSearchLoading && globalSearchResults.results && globalSearchResults.results.length > 0">
                        <div class="space-y-6">
                            <!-- Summary Header -->
                            <div class="flex items-center justify-between bg-sky-500/10 border border-sky-500/20 p-3.5 rounded-2xl text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="text-base">🎉</span>
                                    <span class="font-bold text-sky-600 dark:text-sky-400" x-text="'Found ' + globalSearchResults.total_matches + ' matching records across ' + globalSearchResults.results.length + ' tables!'"></span>
                                </div>
                                <span class="text-[10px] text-slate-400 font-mono">Showing top 50 matches per table</span>
                            </div>

                            <!-- Results grouped by Table -->
                            <template x-for="(res, rIdx) in globalSearchResults.results" :key="rIdx">
                                <div class="bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden shadow-xs space-y-2">
                                    <div class="p-3 bg-slate-100 dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <i data-lucide="table" class="w-4 h-4 text-sky-500"></i>
                                            <span class="font-bold text-slate-800 dark:text-slate-200 text-xs" x-text="res.table"></span>
                                            <span class="text-[10px] bg-sky-500/10 text-sky-600 dark:text-sky-400 font-bold px-2 py-0.5 rounded-full border border-sky-500/20" x-text="res.match_count + ' matches'"></span>
                                        </div>

                                        <button @click="jumpToSearchResultTable(res.table, globalSearchResults.query)" class="bg-sky-600 hover:bg-sky-500 text-white text-[11px] font-bold px-3 py-1 rounded-xl transition flex items-center gap-1 shadow-xs active:scale-95">
                                            <span>Open Table</span>
                                            <i data-lucide="arrow-right" class="w-3 h-3"></i>
                                        </button>
                                    </div>

                                    <!-- Rows Preview Grid -->
                                    <div class="overflow-x-auto p-2">
                                        <table class="w-full text-left border-collapse text-[11px]">
                                            <thead class="bg-slate-200/50 dark:bg-slate-900/50 text-slate-600 dark:text-slate-400 font-semibold border-b border-slate-200 dark:border-slate-800">
                                                <tr>
                                                    <th class="p-2 w-10 text-center">#</th>
                                                    <template x-for="col in res.columns" :key="col">
                                                        <th class="p-2 border-r border-slate-200 dark:border-slate-800/60 truncate" x-text="col"></th>
                                                    </template>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800/60 font-mono text-slate-800 dark:text-slate-300">
                                                <template x-for="(row, rowIdx) in res.rows" :key="rowIdx">
                                                    <tr class="hover:bg-sky-500/5 transition">
                                                        <td class="p-2 text-center text-slate-400 border-r border-slate-200 dark:border-slate-800/60" x-text="rowIdx + 1"></td>
                                                        <template x-for="col in res.columns" :key="col">
                                                            <td class="p-2 border-r border-slate-200 dark:border-slate-800/60 truncate max-w-[200px]" :class="String(row[col] || '').toLowerCase().includes(globalSearchResults.query.toLowerCase()) ? 'bg-amber-500/10 text-amber-600 dark:text-amber-300 font-bold' : ''" x-text="row[col]"></td>
                                                        </template>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </template>
    </div>
    </div>

    <!-- ALPINE APP REASONING & AJAX ENGINE -->
    <script>
        function litesqlApp() {
            return {
                version: '<?php echo LITESQL_VERSION; ?>',
                authenticated: <?php echo Auth::check() ? 'true' : 'false'; ?>,
                themeMode: localStorage.getItem('litesql_theme') || 'system',
                loginPassword: '',
                loginError: '',

                showSecurityModal: false,
                securityForm: { currentPassword: '', newPassword: '', confirmPassword: '' },

                showCmdPalette: false,
                cmdSearch: '',

                databases: [],
                activeDb: '',
                tables: [],
                tableSearch: '',
                activeTable: '',
                
                activeTab: 'data',

                tableColumns: [],
                primaryKeys: [],
                tableRows: [],
                totalRows: 0,
                currentPage: 1,
                pageLimit: 25,
                sortColumn: '',
                sortDir: 'ASC',
                dataSearch: '',
                loading: false,

                editingCell: { row: null, col: null, value: '' },

                schema: { columns: [], foreign_keys: [], indexes: [] },
                analyticsData: {},
                erData: { tables: [], relationships: [] },

                showRowInspectorModal: false,
                inspectRowData: null,

                sqlQuery: '',
                queryResult: {},
                queryPlanData: {},
                queryHistory: JSON.parse(localStorage.getItem('litesql_query_history') || '[]'),
                savedQueries: JSON.parse(localStorage.getItem('litesql_saved_queries') || '[]'),
                historySearch: '',
                favoriteSearch: '',
                selectedFavTag: 'All',
                showSavedQueriesDrawer: false,
                showSaveQueryModal: false,
                saveQueryForm: { title: '', tag: 'Custom', sql: '' },

                get filteredQueryHistory() {
                    if (!this.historySearch) return this.queryHistory;
                    const q = this.historySearch.toLowerCase();
                    return this.queryHistory.filter(h => (h.sql || '').toLowerCase().includes(q) || (h.time || '').toLowerCase().includes(q));
                },

                get filteredSavedQueries() {
                    return this.savedQueries.filter(item => {
                        if (this.selectedFavTag !== 'All' && item.tag !== this.selectedFavTag) return false;
                        if (this.favoriteSearch) {
                            const q = this.favoriteSearch.toLowerCase();
                            const matchTitle = (item.title || '').toLowerCase().includes(q);
                            const matchSql = (item.sql || '').toLowerCase().includes(q);
                            if (!matchTitle && !matchSql) return false;
                        }
                        return true;
                    });
                },
                
                sqlSuggestions: [],
                selectedSuggestionIdx: 0,
                showAutocomplete: false,

                queryViewMode: 'grid',
                chartType: 'bar',
                chartLabelCol: '',
                chartValCol: '',
                autoSafetyLimitVal: '500',
                isDryRun: false,

                queryPage: 1,
                queryPageLimit: '50',
                querySortColumn: '',
                querySortDir: 'ASC',

                sortQueryResult(colName) {
                    if (this.querySortColumn === colName) {
                        if (this.querySortDir === 'ASC') {
                            this.querySortDir = 'DESC';
                        } else {
                            this.querySortColumn = '';
                            this.querySortDir = 'ASC';
                        }
                    } else {
                        this.querySortColumn = colName;
                        this.querySortDir = 'ASC';
                    }
                    this.queryPage = 1;
                },

                get sortedQueryResultRows() {
                    if (!this.queryResult || !this.queryResult.rows) return [];
                    let rows = [...this.queryResult.rows];
                    if (this.querySortColumn) {
                        const col = this.querySortColumn;
                        const dir = this.querySortDir === 'ASC' ? 1 : -1;
                        rows.sort((a, b) => {
                            let valA = a[col];
                            let valB = b[col];
                            if (valA === null || valA === undefined) return 1;
                            if (valB === null || valB === undefined) return -1;
                            
                            const numA = Number(valA);
                            const numB = Number(valB);
                            if (!isNaN(numA) && !isNaN(numB) && String(valA).trim() !== '' && String(valB).trim() !== '') {
                                return (numA - numB) * dir;
                            }
                            return String(valA).localeCompare(String(valB), undefined, { numeric: true, sensitivity: 'base' }) * dir;
                        });
                    }
                    return rows;
                },

                get pagedQueryRows() {
                    if (!this.queryResult || !this.queryResult.rows) return [];
                    const limit = Math.min(250, parseInt(this.queryPageLimit) || 50);
                    const start = (this.queryPage - 1) * limit;
                    return this.sortedQueryResultRows.slice(start, start + limit);
                },

                get totalQueryPages() {
                    if (!this.queryResult || !this.queryResult.rows) return 1;
                    const limit = Math.min(250, parseInt(this.queryPageLimit) || 50);
                    return Math.max(1, Math.ceil(this.queryResult.rows.length / limit));
                },

                get queryColumnStats() {
                    if (!this.queryResult || !this.queryResult.rows || this.queryResult.rows.length === 0 || !this.queryResult.columns) {
                        return [];
                    }

                    const stats = [];
                    const rows = this.queryResult.rows;

                    this.queryResult.columns.forEach(col => {
                        let sum = 0;
                        let min = null;
                        let max = null;
                        let numericCount = 0;

                        for (let i = 0; i < rows.length; i++) {
                            const val = rows[i][col];
                            if (val === null || val === undefined || val === '') continue;
                            const num = Number(val);
                            if (typeof val !== 'boolean' && !isNaN(num) && isFinite(num)) {
                                numericCount++;
                                sum += num;
                                if (min === null || num < min) min = num;
                                if (max === null || num > max) max = num;
                            }
                        }

                        if (numericCount > 0 && (numericCount / rows.length) >= 0.2) {
                            const avg = sum / numericCount;
                            stats.push({
                                column: col,
                                count: numericCount,
                                sum: Math.round(sum * 100) / 100,
                                avg: Math.round(avg * 100) / 100,
                                min: min,
                                max: max
                            });
                        }
                    });

                    return stats;
                },

                showGlobalSearchModal: false,
                globalSearchQuery: '',
                globalSearchLoading: false,
                globalSearchResults: { query: '', total_matches: 0, results: [] },

                showCreateViewModal: false,
                newViewName: '',
                newViewSql: '',

                showCreateTriggerModal: false,
                triggerForm: { name: '', timing: 'AFTER', event: 'INSERT', table: '', body: '' },

                showMockDataModal: false,
                mockDataCount: 25,

                showDuplicateTableModal: false,
                duplicateForm: { new_name: '', include_data: true },

                colSearch: '',
                colFilterType: 'all',

                showBenchmarkModal: false,
                benchmarkLoading: false,
                benchmarkForm: { sql_a: '', sql_b: '', iterations: 10 },
                benchmarkResults: {},

                showReorderColsModal: false,
                reorderColsList: [],

                showDdlModal: false,
                ddlOptions: { drops: false, indexes: true, triggers: true },
                ddlCode: '',

                showDuplicateColModal: false,
                duplicateColForm: { source_col: '', new_col: '', copy_data: true },

                colComments: {},
                showCommentModal: false,
                commentForm: { column: '', comment: '' },

                showCodeGeneratorModal: false,
                codeGenLang: 'php',
                codeGenOp: 'select',
                generatedCode: '',

                get filteredColumns() {
                    if (!this.schema || !this.schema.columns) return [];
                    return this.schema.columns.filter(c => {
                        if (this.colSearch) {
                            const query = this.colSearch.toLowerCase();
                            const nameMatch = c.name.toLowerCase().includes(query);
                            const typeMatch = (c.type || '').toLowerCase().includes(query);
                            if (!nameMatch && !typeMatch) return false;
                        }
                        if (this.colFilterType === 'pk' && !(c.pk > 0)) return false;
                        if (this.colFilterType === 'notnull' && !c.notnull) return false;
                        return true;
                    });
                },

                get queryChartData() {
                    if (!this.queryResult.rows || this.queryResult.rows.length === 0 || !this.chartLabelCol || !this.chartValCol) {
                        return { labels: [], values: [], maxVal: 1 };
                    }
                    const labels = [];
                    const values = [];
                    this.queryResult.rows.forEach(r => {
                        labels.push(String(r[this.chartLabelCol] !== null ? r[this.chartLabelCol] : 'NULL'));
                        values.push(Number(r[this.chartValCol]) || 0);
                    });
                    const maxVal = Math.max(...values, 1);
                    return { labels, values, maxVal };
                },

                showNewDbModal: false,
                newDbName: '',

                showUploadDbModal: false,

                showNewTableModal: false,
                newTableName: '',
                newTableCols: [
                    { name: 'id', type: 'INTEGER', pk: true, autoincrement: true, notnull: true },
                    { name: 'title', type: 'TEXT', pk: false, autoincrement: false, notnull: false }
                ],

                showInsertModal: false,
                newRowData: {},

                showEditRowModal: false,
                editingRowData: {},
                editingRowPk: {},

                showAddColModal: false,
                newColObj: { name: '', type: 'TEXT' },

                showImportModal: false,
                importOptions: {
                    format: 'csv',
                    delimiter: ',',
                    targetMode: 'existing',
                    newTableName: ''
                },

                showRenameTableModal: false,
                renameTableNewName: '',

                showRenameColModal: false,
                renameColOldName: '',
                renameColNewName: '',

                showCreateIndexModal: false,
                newIndex: { name: '', unique: false, columns: [] },

                showDbDiffModal: false,
                diffTargetDb: '',
                diffResult: {},

                showAddFkModal: false,
                fkForm: { local_col: '', ref_table: '', ref_col: '', on_delete: 'CASCADE', on_update: 'NO ACTION' },
                fkTargetCols: [],

                showBulkRenameColModal: false,
                bulkColRenames: [],

                get cmdPaletteItems() {
                    const q = this.cmdSearch.toLowerCase().trim();
                    const items = [];

                    const navs = [
                        { title: 'Global Database Search (All Tables)', icon: 'search', action: () => { this.openGlobalSearchModal(); } },
                        { title: 'Go to Data Grid', icon: 'grid', action: () => { this.activeTab = 'data'; } },
                        { title: 'Go to Structure', icon: 'layers', action: () => { this.activeTab = 'structure'; this.loadSchema(); } },
                        { title: 'Go to SQL Console', icon: 'terminal', action: () => { this.activeTab = 'query'; } },
                        { title: 'Go to Analytics & Health', icon: 'activity', action: () => { this.activeTab = 'analytics'; this.loadAnalytics(); } },
                        { title: 'Go to ER Diagram', icon: 'git-fork', action: () => { this.activeTab = 'er'; this.loadErDiagram(); } },
                        { title: 'Create New Table', icon: 'plus-circle', action: () => { this.showNewTableModal = true; } },
                        { title: 'Create New Database', icon: 'database', action: () => { this.showNewDbModal = true; } },
                        { title: 'Upload Database File', icon: 'upload-cloud', action: () => { this.showUploadDbModal = true; } },
                        { title: 'Import Data (CSV/JSON)', icon: 'file-up', action: () => { this.showImportModal = true; } },
                        { title: 'Backup Database (.sqlite)', icon: 'hard-drive-download', action: () => { window.location.href = '?api=download_db&db_path=' + encodeURIComponent(this.activeDb); } },
                        { title: 'Dual Database Diff', icon: 'git-compare', action: () => { this.openDbDiffModal(); } },
                        { title: 'Switch Theme: Day Light', icon: 'sun', action: () => { this.setTheme('light'); } },
                        { title: 'Switch Theme: Dark Night', icon: 'moon', action: () => { this.setTheme('dark'); } },
                        { title: 'Security Settings', icon: 'shield', action: () => { this.showSecurityModal = true; } }
                    ];

                    navs.forEach(n => {
                        if (!q || n.title.toLowerCase().includes(q)) {
                            items.push({ type: 'Action', ...n });
                        }
                    });

                    this.tables.forEach(t => {
                        if (!q || t.name.toLowerCase().includes(q)) {
                            items.push({
                                type: 'Table',
                                title: 'Table: ' + t.name,
                                icon: t.type === 'view' ? 'eye' : 'table',
                                action: () => { this.selectTable(t.name); }
                            });
                        }
                    });

                    this.databases.forEach(d => {
                        if (!q || d.name.toLowerCase().includes(q)) {
                            items.push({
                                type: 'Database',
                                title: 'DB: ' + d.name,
                                icon: 'database',
                                action: () => { this.selectDb(d.path); }
                            });
                        }
                    });

                    return items;
                },

                initApp() {
                    this.applyTheme();
                    if (this.authenticated) {
                        this.loadDatabases();
                    }
                    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                        if (this.themeMode === 'system') {
                            this.applyTheme();
                        }
                    });
                    window.addEventListener('keydown', (e) => {
                        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                            e.preventDefault();
                            this.showCmdPalette = !this.showCmdPalette;
                            if (this.showCmdPalette) {
                                this.cmdSearch = '';
                                setTimeout(() => this.$refs.cmdSearchInput && this.$refs.cmdSearchInput.focus(), 50);
                            }
                        } else if (e.key === 'Escape' && this.showCmdPalette) {
                            this.showCmdPalette = false;
                        }
                    });
                    setTimeout(() => lucide.createIcons(), 100);
                },

                setTheme(mode) {
                    this.themeMode = mode;
                    localStorage.setItem('litesql_theme', mode);
                    this.applyTheme();
                    setTimeout(() => lucide.createIcons(), 100);
                },

                applyTheme() {
                    const isDark = this.themeMode === 'dark' || (this.themeMode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    if (isDark) {
                        document.documentElement.classList.add('dark');
                    } else {
                        document.documentElement.classList.remove('dark');
                    }
                },

                showToast(msg, type = 'success') {
                    Toastify({
                        text: msg,
                        duration: 2500,
                        gravity: "bottom",
                        position: "right",
                        style: {
                            background: type === 'success' ? '#0284c7' : '#e11d48',
                            borderRadius: '12px',
                            fontSize: '12px',
                            fontWeight: '600'
                        }
                    }).showToast();
                },

                async login() {
                    this.loginError = '';
                    try {
                        const res = await fetch('?api=login', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ password: this.loginPassword })
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.authenticated = true;
                            this.loadDatabases();
                        } else {
                            this.loginError = data.error || 'Authentication failed';
                        }
                    } catch (e) {
                        this.loginError = 'Server error during login';
                    }
                },

                async loadDatabases() {
                    const res = await fetch('?api=databases');
                    const data = await res.json();
                    this.databases = data.databases || [];
                    if (data.active) {
                        this.activeDb = data.active;
                        this.loadTables();
                        this.loadAnalytics();
                    } else if (this.databases.length > 0) {
                        this.selectDb(this.databases[0].path);
                    }
                    setTimeout(() => lucide.createIcons(), 100);
                },

                async createDb() {
                    if (!this.newDbName) return;
                    const res = await fetch('?api=create_database', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ name: this.newDbName })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showNewDbModal = false;
                        this.newDbName = '';
                        this.showToast('New database created!', 'success');
                        this.loadDatabases();
                    } else {
                        this.showToast(data.error || 'Failed to create DB', 'error');
                    }
                },

                async selectDb(path) {
                    if (!path) return;
                    const res = await fetch('?api=select_db', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ db_path: path })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.activeDb = path;
                        this.activeTable = '';
                        this.loadTables();
                        this.loadAnalytics();
                    }
                },

                async vacuumDb() {
                    if (!this.activeDb) return;
                    const res = await fetch(`?api=vacuum&db_path=${encodeURIComponent(this.activeDb)}`);
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Database VACUUM completed!', 'success');
                        this.loadDatabases();
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'VACUUM failed', 'error');
                    }
                },

                async runMaintenance(action) {
                    if (!this.activeDb) return;
                    const res = await fetch(`?api=${action}&db_path=${encodeURIComponent(this.activeDb)}`);
                    const data = await res.json();
                    if (data.success) {
                        const msg = data.result ? `Integrity Check: ${data.result}` : `Action '${action.toUpperCase()}' completed successfully!`;
                        this.showToast(msg, 'success');
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || `Failed to execute '${action}'`, 'error');
                    }
                },

                async loadAnalytics() {
                    if (!this.activeDb) return;
                    const res = await fetch(`?api=analytics&db_path=${encodeURIComponent(this.activeDb)}`);
                    this.analyticsData = await res.json();
                    setTimeout(() => lucide.createIcons(), 100);
                },

                get activeDbName() {
                    const db = this.databases.find(d => d.path === this.activeDb);
                    return db ? db.name : this.activeDb;
                },

                get filteredTables() {
                    if (!this.tableSearch) return this.tables;
                    return this.tables.filter(t => t.name.toLowerCase().includes(this.tableSearch.toLowerCase()));
                },

                get totalPages() {
                    return Math.ceil(this.totalRows / this.pageLimit) || 1;
                },

                get selectedRows() {
                    return this.tableRows.filter(r => r._selected === true);
                },

                get isAllSelected() {
                    return this.tableRows.length > 0 && this.tableRows.every(r => r._selected === true);
                },

                toggleSelectAll(checked) {
                    this.tableRows.forEach(r => r._selected = checked);
                },

                async loadTables() {
                    const res = await fetch(`?api=tables&db_path=${encodeURIComponent(this.activeDb)}`);
                    const data = await res.json();
                    this.tables = data.tables || [];
                    if (!this.activeTable && this.tables.length > 0) {
                        this.selectTable(this.tables[0].name);
                    }
                    setTimeout(() => lucide.createIcons(), 100);
                },

                selectTable(tableName) {
                    this.activeTable = tableName;
                    this.currentPage = 1;
                    this.dataSearch = '';
                    this.loadData();
                    if (this.activeTab === 'structure') {
                        this.loadSchema();
                    }
                },

                async loadData() {
                    if (!this.activeTable) return;
                    this.loading = true;
                    const url = `?api=data&table=${encodeURIComponent(this.activeTable)}&page=${this.currentPage}&limit=${this.pageLimit}&sort=${encodeURIComponent(this.sortColumn)}&dir=${this.sortDir}&search=${encodeURIComponent(this.dataSearch)}&db_path=${encodeURIComponent(this.activeDb)}`;
                    const res = await fetch(url);
                    const data = await res.json();

                    this.tableColumns = data.columns || [];
                    this.primaryKeys = data.primary_keys || [];
                    this.tableRows = (data.rows || []).map(r => ({ ...r, _selected: false }));
                    this.totalRows = data.total || 0;
                    this.loading = false;

                    setTimeout(() => lucide.createIcons(), 100);
                },

                sortData(colName) {
                    if (this.sortColumn === colName) {
                        this.sortDir = this.sortDir === 'ASC' ? 'DESC' : 'ASC';
                    } else {
                        this.sortColumn = colName;
                        this.sortDir = 'ASC';
                    }
                    this.loadData();
                },

                startCellEdit(rowIdx, colName, currentValue) {
                    this.editingCell = {
                        row: rowIdx,
                        col: colName,
                        value: currentValue === null ? '' : currentValue
                    };
                },

                async saveCellEdit(rowObj) {
                    if (this.editingCell.row === null || this.editingCell.col === null) return;

                    const col = this.editingCell.col;
                    const newVal = this.editingCell.value;

                    const pkConditions = {};
                    if (this.primaryKeys.length > 0) {
                        this.primaryKeys.forEach(pk => pkConditions[pk] = rowObj[pk]);
                    } else {
                        Object.keys(rowObj).forEach(k => {
                            if (k !== '_selected') pkConditions[k] = rowObj[k];
                        });
                    }

                    const res = await fetch(`?api=update_cell&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            pk: pkConditions,
                            column: col,
                            value: newVal
                        })
                    });
                    const data = await res.json();

                    if (data.success) {
                        rowObj[col] = newVal;
                        this.showToast(`Updated '${col}' successfully!`, 'success');
                    } else {
                        this.showToast(data.error || 'Failed to update cell', 'error');
                    }

                    this.editingCell = { row: null, col: null, value: '' };
                },

                openEditRowModal(rowObj) {
                    this.editingRowData = { ...rowObj };
                    delete this.editingRowData._selected;
                    this.editingRowPk = {};
                    if (this.primaryKeys.length > 0) {
                        this.primaryKeys.forEach(pk => this.editingRowPk[pk] = rowObj[pk]);
                    } else {
                        Object.keys(rowObj).forEach(k => {
                            if (k !== '_selected') this.editingRowPk[k] = rowObj[k];
                        });
                    }
                    this.showEditRowModal = true;
                    setTimeout(() => lucide.createIcons(), 100);
                },

                async submitUpdateRow() {
                    const res = await fetch(`?api=update_row&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            pk: this.editingRowPk,
                            data: this.editingRowData
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showEditRowModal = false;
                        this.showToast('Record updated successfully!', 'success');
                        this.loadData();
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to update record', 'error');
                    }
                },

                openInsertModal() {
                    this.newRowData = {};
                    this.tableColumns.forEach(c => this.newRowData[c.name] = '');
                    this.showInsertModal = true;
                },

                async submitInsertRow() {
                    const res = await fetch(`?api=insert_row&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            data: this.newRowData
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showInsertModal = false;
                        this.showToast('New row inserted!', 'success');
                        this.loadData();
                        this.loadTables();
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to insert row', 'error');
                    }
                },

                async deleteRow(rowObj) {
                    if (!confirm('Are you sure you want to delete this row?')) return;

                    const pkConditions = {};
                    if (this.primaryKeys.length > 0) {
                        this.primaryKeys.forEach(pk => pkConditions[pk] = rowObj[pk]);
                    } else {
                        Object.keys(rowObj).forEach(k => {
                            if (k !== '_selected') pkConditions[k] = rowObj[k];
                        });
                    }

                    const res = await fetch(`?api=delete_row&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            pk: pkConditions
                        })
                    });
                    const data = await res.json();

                    if (data.success) {
                        this.showToast('Row deleted successfully!', 'success');
                        this.loadData();
                        this.loadTables();
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to delete row', 'error');
                    }
                },

                async bulkDeleteSelected() {
                    const selected = this.selectedRows;
                    if (selected.length === 0) return;
                    if (!confirm(`Are you sure you want to delete ${selected.length} selected row(s)?`)) return;

                    const pksList = selected.map(rowObj => {
                        const pkConditions = {};
                        if (this.primaryKeys.length > 0) {
                            this.primaryKeys.forEach(pk => pkConditions[pk] = rowObj[pk]);
                        } else {
                            Object.keys(rowObj).forEach(k => {
                                if (k !== '_selected') pkConditions[k] = rowObj[k];
                            });
                        }
                        return pkConditions;
                    });

                    const res = await fetch(`?api=bulk_delete&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            pks: pksList
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast(`${pksList.length} row(s) deleted!`, 'success');
                        this.loadData();
                        this.loadTables();
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to perform bulk delete', 'error');
                    }
                },

                async submitImportFile() {
                    const fileInput = this.$refs.importFileInput;
                    if (!fileInput.files || fileInput.files.length === 0) {
                        this.showToast('Please select a file to import', 'error');
                        return;
                    }

                    const formData = new FormData();
                    formData.append('file', fileInput.files[0]);
                    formData.append('format', this.importOptions.format);
                    formData.append('delimiter', this.importOptions.delimiter);
                    
                    if (this.importOptions.targetMode === 'new') {
                        formData.append('create_table', 'true');
                        formData.append('new_table_name', this.importOptions.newTableName);
                    } else {
                        formData.append('table', this.activeTable);
                    }

                    const res = await fetch(`?api=import_data&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();

                    if (data.success) {
                        this.showImportModal = false;
                        this.showToast(`Successfully imported ${data.inserted} record(s)!`, 'success');
                        await this.loadTables();
                        if (data.table) {
                            this.selectTable(data.table);
                        }
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to import file', 'error');
                    }
                },

                addNewTableColRow() {
                    this.newTableCols.push({ name: '', type: 'TEXT', pk: false, autoincrement: false, notnull: false, default: '' });
                },

                async createTable() {
                    if (!this.newTableName) return;
                    const res = await fetch(`?api=create_table&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table_name: this.newTableName,
                            columns: this.newTableCols
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showNewTableModal = false;
                        this.showToast(`Table '${this.newTableName}' created!`, 'success');
                        await this.loadTables();
                        this.selectTable(this.newTableName);
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to create table', 'error');
                    }
                },

                async dropActiveTable() {
                    if (!confirm(`Are you sure you want to DROP table '${this.activeTable}'?`)) return;
                    const res = await fetch(`?api=drop_table&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ table: this.activeTable })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast(`Table '${this.activeTable}' dropped!`, 'success');
                        this.activeTable = '';
                        this.tableColumns = [];
                        this.tableRows = [];
                        this.totalRows = 0;
                        await this.loadTables();
                        if (this.tables.length > 0) {
                            this.selectTable(this.tables[0].name);
                        }
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to drop table', 'error');
                    }
                },

                async submitAddColumn() {
                    if (!this.newColObj.name) return;
                    const res = await fetch(`?api=add_column&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            column: this.newColObj
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showAddColModal = false;
                        this.newColObj = { name: '', type: 'TEXT' };
                        this.showToast('Column added!', 'success');
                        this.loadSchema();
                        this.loadData();
                        this.loadTables();
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to add column', 'error');
                    }
                },

                openGlobalSearchModal() {
                    this.showGlobalSearchModal = true;
                    this.globalSearchQuery = '';
                    this.globalSearchResults = { query: '', total_matches: 0, results: [] };
                    setTimeout(() => {
                        lucide.createIcons();
                        if (this.$refs.globalSearchInput) {
                            this.$refs.globalSearchInput.focus();
                        }
                    }, 50);
                },

                async performGlobalSearch() {
                    if (!this.globalSearchQuery.trim()) return;
                    this.globalSearchLoading = true;
                    const res = await fetch(`?api=global_search&q=${encodeURIComponent(this.globalSearchQuery.trim())}&db_path=${encodeURIComponent(this.activeDb)}`);
                    this.globalSearchResults = await res.json();
                    this.globalSearchLoading = false;
                    setTimeout(() => lucide.createIcons(), 100);
                },

                jumpToSearchResultTable(tableName, searchVal) {
                    this.showGlobalSearchModal = false;
                    this.selectTable(tableName);
                    this.searchQuery = searchVal;
                    this.loadData();
                    this.activeTab = 'data';
                    this.showToast(`Jumped to '${tableName}' with search filter applied!`, 'success');
                },

                async loadSchema() {
                    if (!this.activeTable) return;
                    const res = await fetch(`?api=schema&table=${encodeURIComponent(this.activeTable)}&db_path=${encodeURIComponent(this.activeDb)}`);
                    this.schema = await res.json();
                    await this.loadComments();
                    setTimeout(() => lucide.createIcons(), 100);
                },

                async runQuery() {
                    if (!this.sqlQuery.trim()) return;
                    this.queryPlanData = {};
                    this.queryPage = 1;
                    this.querySortColumn = '';
                    this.querySortDir = 'ASC';

                    const res = await fetch(`?api=query&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ sql: this.sqlQuery, auto_limit: parseInt(this.autoSafetyLimitVal || '0'), dry_run: this.isDryRun })
                    });
                    this.queryResult = await res.json();
                    
                    const isSuccess = Boolean(this.queryResult.success);

                    if (isSuccess && /^SELECT/i.test(this.sqlQuery.trim())) {
                        try {
                            const planRes = await fetch(`?api=explain_query&db_path=${encodeURIComponent(this.activeDb)}`, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ sql: this.sqlQuery })
                            });
                            this.queryPlanData = await planRes.json();
                        } catch(e) {}
                    }

                    if (isSuccess && this.queryResult.columns && this.queryResult.columns.length > 0) {
                        this.chartLabelCol = this.queryResult.columns[0];
                        this.chartValCol = this.queryResult.columns[1] || this.queryResult.columns[0];
                    }

                    this.queryHistory.unshift({
                        sql: this.sqlQuery,
                        time: new Date().toLocaleTimeString(),
                        duration: this.queryResult.execution_time_ms || 0,
                        success: isSuccess
                    });
                    if (this.queryHistory.length > 30) this.queryHistory = this.queryHistory.slice(0, 30);
                    localStorage.setItem('litesql_query_history', JSON.stringify(this.queryHistory));

                    if (isSuccess) {
                        await this.loadTables();
                        if (this.activeTable) {
                            this.loadData();
                            this.loadSchema();
                        }
                        this.loadAnalytics();
                    }
                    setTimeout(() => lucide.createIcons(), 100);
                },

                clearQueryHistory() {
                    this.queryHistory = [];
                    localStorage.removeItem('litesql_query_history');
                    this.showToast('Query history cleared', 'success');
                },

                formatSqlQuery() {
                    if (!this.sqlQuery || !this.sqlQuery.trim()) return;
                    let sql = this.sqlQuery.trim();

                    const majorKeywords = [
                        'SELECT', 'FROM', 'WHERE', 'GROUP BY', 'ORDER BY', 'HAVING', 
                        'LIMIT', 'OFFSET', 'INNER JOIN', 'LEFT JOIN', 'RIGHT JOIN', 
                        'CROSS JOIN', 'JOIN', 'UNION ALL', 'UNION', 'INSERT INTO', 
                        'VALUES', 'UPDATE', 'SET', 'DELETE FROM', 'CREATE TABLE', 
                        'CREATE INDEX', 'CREATE VIEW', 'ALTER TABLE', 'DROP TABLE'
                    ];

                    majorKeywords.forEach(kw => {
                        const regex = new RegExp('\\b' + kw.replace(/\s+/g, '\\s+') + '\\b', 'gi');
                        sql = sql.replace(regex, '\n' + kw);
                    });

                    ['AND', 'OR', 'ON'].forEach(kw => {
                        const regex = new RegExp('\\b' + kw + '\\b', 'gi');
                        sql = sql.replace(regex, '\n  ' + kw);
                    });

                    const rawLines = sql.split('\n').map(l => l.trim()).filter(l => l.length > 0);
                    const formattedLines = [];

                    rawLines.forEach(line => {
                        if (/^SELECT\b/i.test(line)) {
                            const selectRest = line.substring(6).trim();
                            if (selectRest.length > 0) {
                                const cols = selectRest.split(',').map(c => c.trim()).filter(c => c.length > 0);
                                if (cols.length > 1) {
                                    formattedLines.push('SELECT');
                                    cols.forEach((c, idx) => {
                                        formattedLines.push('  ' + c + (idx < cols.length - 1 ? ',' : ''));
                                    });
                                    return;
                                }
                            }
                            formattedLines.push('SELECT ' + selectRest);
                        } else if (/^(AND|OR|ON)\b/i.test(line)) {
                            formattedLines.push('  ' + line.toUpperCase().split(' ')[0] + line.substring(line.indexOf(' ')));
                        } else if (/^(FROM|WHERE|GROUP BY|ORDER BY|HAVING|LIMIT|OFFSET|JOIN|LEFT JOIN|INNER JOIN|RIGHT JOIN|INSERT INTO|VALUES|UPDATE|SET|DELETE FROM|CREATE|ALTER|DROP)\b/i.test(line)) {
                            const firstWord = line.split(' ')[0].toUpperCase();
                            const rest = line.substring(firstWord.length);
                            formattedLines.push(firstWord + rest);
                        } else {
                            formattedLines.push('  ' + line);
                        }
                    });

                    this.sqlQuery = formattedLines.join('\n');
                    this.showToast('✨ SQL Query Cleaned & Formatted!', 'success');
                },

                openRenameTableModal() {
                    this.renameTableNewName = this.activeTable;
                    this.showRenameTableModal = true;
                },

                async submitRenameTable() {
                    if (!this.renameTableNewName || this.renameTableNewName === this.activeTable) return;
                    const res = await fetch(`?api=rename_table&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            old_name: this.activeTable,
                            new_name: this.renameTableNewName
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showRenameTableModal = false;
                        this.showToast(`Table renamed to '${this.renameTableNewName}'!`, 'success');
                        const newName = this.renameTableNewName;
                        this.activeTable = newName;
                        await this.loadTables();
                        this.selectTable(newName);
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to rename table', 'error');
                    }
                },

                openRenameColModal(colName) {
                    this.renameColOldName = colName;
                    this.renameColNewName = colName;
                    this.showRenameColModal = true;
                },

                async submitRenameColumn() {
                    if (!this.renameColNewName || this.renameColNewName === this.renameColOldName) return;
                    const res = await fetch(`?api=rename_column&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            old_col: this.renameColOldName,
                            new_col: this.renameColNewName
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showRenameColModal = false;
                        this.showToast(`Column renamed to '${this.renameColNewName}'!`, 'success');
                        this.loadSchema();
                        this.loadData();
                        this.loadTables();
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to rename column', 'error');
                    }
                },

                async truncateActiveTable() {
                    if (!confirm(`Are you sure you want to empty (truncate) all records from table '${this.activeTable}'?`)) return;
                    const res = await fetch(`?api=truncate_table&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ table: this.activeTable })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast(`Table '${this.activeTable}' truncated!`, 'success');
                        this.loadData();
                        this.loadTables();
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to truncate table', 'error');
                    }
                },

                openBulkRenameColModal() {
                    if (!this.schema.columns || this.schema.columns.length === 0) return;
                    this.bulkColRenames = this.schema.columns.map(c => ({
                        old: c.name,
                        new: c.name,
                        type: (c.type || 'TEXT').toUpperCase(),
                        pk: c.pk > 0,
                        notnull: c.notnull > 0,
                        default: c.dflt_value === null ? '' : c.dflt_value
                    }));
                    this.showBulkRenameColModal = true;
                    setTimeout(() => lucide.createIcons(), 100);
                },

                async submitBulkRenameColumns() {
                    const updatedCols = this.bulkColRenames.map(item => ({
                        old: item.old,
                        name: (item.new && item.new.trim() !== '') ? item.new.trim() : item.old,
                        type: item.type,
                        pk: Boolean(item.pk),
                        notnull: Boolean(item.notnull),
                        default: (item.default !== undefined && item.default !== '') ? item.default : null
                    }));

                    const res = await fetch(`?api=bulk_update_columns&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            columns: updatedCols
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showBulkRenameColModal = false;
                        this.showToast(`Updated column definitions & schema successfully!`, 'success');
                        await this.loadSchema();
                        await this.loadData();
                        await this.loadTables();
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to update columns', 'error');
                    }
                },

                async submitUploadDbFile() {
                    const input = this.$refs.dbFileInput;
                    if (!input.files || input.files.length === 0) {
                        this.showToast('Please select a database file to upload', 'error');
                        return;
                    }

                    const formData = new FormData();
                    formData.append('db_file', input.files[0]);

                    const res = await fetch('?api=upload_db', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showUploadDbModal = false;
                        this.showToast(`Database '${data.name}' uploaded successfully!`, 'success');
                        this.activeDb = data.db_path;
                        await this.loadDatabases();
                        this.loadTables();
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to upload database', 'error');
                    }
                },

                async submitChangePassword() {
                    if (this.securityForm.newPassword !== this.securityForm.confirmPassword) {
                        this.showToast('New passwords do not match!', 'error');
                        return;
                    }
                    if (this.securityForm.newPassword.length < 4) {
                        this.showToast('New password must be at least 4 characters long', 'error');
                        return;
                    }

                    const res = await fetch('?api=change_password', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            current_password: this.securityForm.currentPassword,
                            new_password: this.securityForm.newPassword
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showSecurityModal = false;
                        this.securityForm = { currentPassword: '', newPassword: '', confirmPassword: '' };
                        this.showToast('Master password updated successfully!', 'success');
                    } else {
                        this.showToast(data.error || 'Failed to update password', 'error');
                    }
                },

                async loadErDiagram() {
                    if (!this.activeDb) return;
                    const res = await fetch(`?api=er_diagram&db_path=${encodeURIComponent(this.activeDb)}`);
                    this.erData = await res.json();
                    setTimeout(() => lucide.createIcons(), 100);
                },

                openCreateIndexModal() {
                    this.newIndex = {
                        name: `idx_${this.activeTable}_${Date.now().toString().slice(-4)}`,
                        unique: false,
                        columns: []
                    };
                    this.showCreateIndexModal = true;
                },

                async submitCreateIndex() {
                    if (!this.newIndex.name || this.newIndex.columns.length === 0) {
                        this.showToast('Please enter index name and select at least one column', 'error');
                        return;
                    }

                    const res = await fetch(`?api=create_index&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            index_name: this.newIndex.name,
                            columns: this.newIndex.columns,
                            unique: this.newIndex.unique
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showCreateIndexModal = false;
                        this.showToast(`Index '${this.newIndex.name}' created!`, 'success');
                        this.loadSchema();
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to create index', 'error');
                    }
                },

                async dropIndex(indexName) {
                    if (!confirm(`Are you sure you want to drop index '${indexName}'?`)) return;
                    const res = await fetch(`?api=drop_index&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ index_name: indexName })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast(`Index '${indexName}' dropped!`, 'success');
                        this.loadSchema();
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to drop index', 'error');
                    }
                },

                openDbDiffModal() {
                    this.diffResult = {};
                    this.diffTargetDb = '';
                    this.showDbDiffModal = true;
                },

                async submitDbDiff() {
                    if (!this.diffTargetDb) return;
                    const res = await fetch(`?api=db_diff&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ target_db: this.diffTargetDb })
                    });
                    this.diffResult = await res.json();
                    setTimeout(() => lucide.createIcons(), 100);
                },

                openSaveQueryModal() {
                    if (!this.sqlQuery || this.sqlQuery.trim() === '') {
                        this.showToast('SQL Query editor is empty', 'error');
                        return;
                    }
                    this.saveQueryForm = {
                        title: '',
                        tag: 'Custom',
                        sql: this.sqlQuery
                    };
                    this.showSaveQueryModal = true;
                },

                submitSaveQuery() {
                    if (!this.saveQueryForm.title || !this.saveQueryForm.sql) {
                        this.showToast('Please enter query title and SQL statement', 'error');
                        return;
                    }

                    const item = {
                        id: Date.now(),
                        title: this.saveQueryForm.title.trim(),
                        tag: (this.saveQueryForm.tag || 'Custom').trim(),
                        sql: this.saveQueryForm.sql.trim(),
                        created: new Date().toLocaleDateString()
                    };

                    this.savedQueries.unshift(item);
                    localStorage.setItem('litesql_saved_queries', JSON.stringify(this.savedQueries));
                    this.showSaveQueryModal = false;
                    this.showToast('Query saved to Favorites Library!', 'success');
                    setTimeout(() => lucide.createIcons(), 100);
                },

                deleteSavedQuery(id) {
                    this.savedQueries = this.savedQueries.filter(q => q.id !== id);
                    localStorage.setItem('litesql_saved_queries', JSON.stringify(this.savedQueries));
                    this.showToast('Saved query deleted', 'success');
                },

                loadSavedQuery(sql) {
                    this.sqlQuery = sql;
                    this.runQuery();
                    this.showSavedQueriesDrawer = false;
                },

                async runWalCheckpoint() {
                    const res = await fetch(`?api=wal_checkpoint&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ mode: 'TRUNCATE' })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast(`WAL Checkpoint complete! Truncated ${data.log || 0} log frame(s).`, 'success');
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to checkpoint WAL file', 'error');
                    }
                },

                async changeJournalMode(mode) {
                    const res = await fetch(`?api=set_journal_mode&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ mode: mode })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast(`Journal mode updated to '${mode}'!`, 'success');
                        this.loadAnalytics();
                    } else {
                        this.showToast('Failed to change journal mode', 'error');
                    }
                },

                async toggleForeignKeys() {
                    const nextState = this.analyticsData.foreign_keys !== 'ON';
                    const res = await fetch(`?api=set_foreign_keys&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ enable: nextState })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast(`Foreign Keys constraint status set to '${nextState ? 'ON' : 'OFF'}'!`, 'success');
                        this.loadAnalytics();
                    } else {
                        this.showToast('Failed to update Foreign Keys setting', 'error');
                    }
                },

                handleSqlInput(e) {
                    const textarea = e.target;
                    const pos = textarea.selectionStart || 0;
                    const textBeforeCursor = textarea.value.substring(0, pos);
                    const words = textBeforeCursor.split(/[\s,();]+/);
                    const lastWord = words[words.length - 1] || '';

                    if (lastWord.length < 1) {
                        this.showAutocomplete = false;
                        return;
                    }

                    const q = lastWord.toLowerCase();
                    
                    const keywords = [
                        'SELECT', 'FROM', 'WHERE', 'INSERT INTO', 'UPDATE', 'DELETE FROM', 'JOIN',
                        'LEFT JOIN', 'INNER JOIN', 'ON', 'GROUP BY', 'ORDER BY', 'HAVING', 'LIMIT',
                        'OFFSET', 'AS', 'AND', 'OR', 'NOT', 'IN', 'LIKE', 'IS NULL', 'IS NOT NULL',
                        'CREATE TABLE', 'DROP TABLE', 'ALTER TABLE', 'CREATE INDEX', 'DROP INDEX',
                        'PRAGMA', 'COUNT(*)', 'SUM()', 'AVG()', 'MAX()', 'MIN()', 'EXPLAIN QUERY PLAN'
                    ];

                    const suggestions = [];

                    keywords.forEach(kw => {
                        if (kw.toLowerCase().startsWith(q) && kw.toLowerCase() !== q) {
                            suggestions.push({ type: 'Keyword', text: kw, icon: 'code' });
                        }
                    });

                    if (this.tables) {
                        this.tables.forEach(t => {
                            if (t.name.toLowerCase().startsWith(q) && t.name.toLowerCase() !== q) {
                                suggestions.push({ type: 'Table', text: '`' + t.name + '`', icon: 'table' });
                            }
                        });
                    }

                    if (this.schema && this.schema.columns) {
                        this.schema.columns.forEach(c => {
                            if (c.name.toLowerCase().startsWith(q) && c.name.toLowerCase() !== q) {
                                suggestions.push({ type: 'Column', text: '`' + c.name + '`', icon: 'tag' });
                            }
                        });
                    }

                    if (suggestions.length > 0) {
                        this.sqlSuggestions = suggestions.slice(0, 8);
                        this.selectedSuggestionIdx = 0;
                        this.showAutocomplete = true;
                        setTimeout(() => lucide.createIcons(), 50);
                    } else {
                        this.showAutocomplete = false;
                    }
                },

                insertSuggestion(sItem) {
                    const textarea = this.$refs.sqlTextarea;
                    if (!textarea) return;
                    const pos = textarea.selectionStart || 0;
                    const textBeforeCursor = textarea.value.substring(0, pos);
                    const textAfterCursor = textarea.value.substring(pos);
                    
                    const words = textBeforeCursor.split(/[\s,();]+/);
                    const lastWord = words[words.length - 1] || '';
                    
                    const newTextBefore = textBeforeCursor.substring(0, textBeforeCursor.length - lastWord.length) + sItem.text + ' ';
                    this.sqlQuery = newTextBefore + textAfterCursor;
                    this.showAutocomplete = false;
                    
                    this.$nextTick(() => {
                        textarea.focus();
                        const newPos = newTextBefore.length;
                        textarea.setSelectionRange(newPos, newPos);
                    });
                },

                handleSqlKeyDown(e) {
                    if (!this.showAutocomplete) {
                        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                            e.preventDefault();
                            this.runQuery();
                        }
                        return;
                    }

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        this.selectedSuggestionIdx = (this.selectedSuggestionIdx + 1) % this.sqlSuggestions.length;
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        this.selectedSuggestionIdx = (this.selectedSuggestionIdx - 1 + this.sqlSuggestions.length) % this.sqlSuggestions.length;
                    } else if (e.key === 'Tab' || (e.key === 'Enter' && !e.ctrlKey && !e.metaKey)) {
                        e.preventDefault();
                        if (this.sqlSuggestions[this.selectedSuggestionIdx]) {
                            this.insertSuggestion(this.sqlSuggestions[this.selectedSuggestionIdx]);
                        }
                    } else if (e.key === 'Escape') {
                        this.showAutocomplete = false;
                    }
                },

                openAddFkModal() {
                    this.fkForm = { local_col: '', ref_table: '', ref_col: '', on_delete: 'CASCADE', on_update: 'NO ACTION' };
                    this.fkTargetCols = [];
                    this.showAddFkModal = true;
                },

                async onFkRefTableChange() {
                    if (!this.fkForm.ref_table) {
                        this.fkTargetCols = [];
                        return;
                    }
                    const res = await fetch(`?api=get_schema&db_path=${encodeURIComponent(this.activeDb)}&table=${encodeURIComponent(this.fkForm.ref_table)}`);
                    const targetSchema = await res.json();
                    this.fkTargetCols = targetSchema.columns || [];
                },

                async submitAddForeignKey() {
                    if (!this.fkForm.local_col || !this.fkForm.ref_table || !this.fkForm.ref_col) {
                        this.showToast('Please fill all required foreign key fields', 'error');
                        return;
                    }

                    const res = await fetch(`?api=add_foreign_key&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            local_col: this.fkForm.local_col,
                            ref_table: this.fkForm.ref_table,
                            ref_col: this.fkForm.ref_col,
                            on_delete: this.fkForm.on_delete,
                            on_update: this.fkForm.on_update
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showAddFkModal = false;
                        this.showToast(`Foreign key relationship added to '${this.activeTable}'!`, 'success');
                        this.loadSchema();
                        this.loadAnalytics();
                        this.loadErDiagram();
                    } else {
                        this.showToast(data.error || 'Failed to add foreign key', 'error');
                    }
                },

                async dropForeignKey(fkId) {
                    if (!confirm(`Are you sure you want to drop this foreign key constraint?`)) return;
                    const res = await fetch(`?api=drop_foreign_key&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            fk_id: fkId
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Foreign key constraint dropped!', 'success');
                        this.loadSchema();
                        this.loadAnalytics();
                        this.loadErDiagram();
                    } else {
                        this.showToast(data.error || 'Failed to drop foreign key', 'error');
                    }
                },

                openCreateViewModal() {
                    this.newViewName = '';
                    this.newViewSql = 'SELECT * FROM `' + (this.activeTable || 'table') + '`;';
                    this.showCreateViewModal = true;
                },

                async submitCreateView() {
                    if (!this.newViewName || !this.newViewSql) {
                        this.showToast('Please enter View Name and SELECT query statement', 'error');
                        return;
                    }

                    const res = await fetch(`?api=create_view&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            view_name: this.newViewName,
                            select_sql: this.newViewSql
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showCreateViewModal = false;
                        this.showToast(`Virtual View '${this.newViewName}' created successfully!`, 'success');
                        await this.loadTables();
                        this.selectTable(this.newViewName);
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to create view', 'error');
                    }
                },

                async dropView(viewName) {
                    const nameToDrop = viewName || this.activeTable;
                    if (!confirm(`Are you sure you want to DROP virtual view '${nameToDrop}'?`)) return;

                    const res = await fetch(`?api=drop_view&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ view_name: nameToDrop })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast(`Virtual View '${nameToDrop}' dropped!`, 'success');
                        this.activeTable = '';
                        await this.loadTables();
                        if (this.tables.length > 0) {
                            this.selectTable(this.tables[0].name);
                        }
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to drop view', 'error');
                    }
                },

                openCreateTriggerModal() {
                    this.triggerForm = {
                        name: `trg_${this.activeTable || 'table'}_audit`,
                        timing: 'AFTER',
                        event: 'INSERT',
                        table: this.activeTable || (this.tables[0] ? this.tables[0].name : ''),
                        body: "BEGIN\n  -- Insert trigger statements here\nEND;"
                    };
                    this.showCreateTriggerModal = true;
                },

                async submitCreateTrigger() {
                    if (!this.triggerForm.name || !this.triggerForm.table || !this.triggerForm.body) {
                        this.showToast('Please fill in Trigger Name, Table, and SQL Body', 'error');
                        return;
                    }

                    const res = await fetch(`?api=create_trigger&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(this.triggerForm)
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showCreateTriggerModal = false;
                        this.showToast(`Trigger '${this.triggerForm.name}' created!`, 'success');
                        this.loadSchema();
                    } else {
                        this.showToast(data.error || 'Failed to create trigger', 'error');
                    }
                },

                async dropTrigger(triggerName) {
                    if (!confirm(`Are you sure you want to DROP trigger '${triggerName}'?`)) return;

                    const res = await fetch(`?api=drop_trigger&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ name: triggerName })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast(`Trigger '${triggerName}' dropped!`, 'success');
                        this.loadSchema();
                    } else {
                        this.showToast(data.error || 'Failed to drop trigger', 'error');
                    }
                },

                openMockDataModal() {
                    if (!this.activeTable) {
                        this.showToast('Please select a table first', 'error');
                        return;
                    }
                    this.mockDataCount = 25;
                    this.showMockDataModal = true;
                },

                async submitGenerateMockData() {
                    if (!this.activeTable) return;
                    this.loading = true;

                    const res = await fetch(`?api=generate_mock_data&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            count: Number(this.mockDataCount) || 25
                        })
                    });
                    const data = await res.json();
                    this.loading = false;

                    if (data.success) {
                        this.showMockDataModal = false;
                        this.showToast(`Generated ${data.inserted || 0} mock record(s) in '${this.activeTable}'!`, 'success');
                        await this.loadTables();
                        this.loadData();
                    } else {
                        this.showToast(data.error || 'Failed to generate mock data', 'error');
                    }
                },

                openDuplicateTableModal() {
                    if (!this.activeTable) {
                        this.showToast('Please select a table first', 'error');
                        return;
                    }
                    this.duplicateForm = {
                        new_name: `${this.activeTable}_copy`,
                        include_data: true
                    };
                    this.showDuplicateTableModal = true;
                },

                async submitDuplicateTable() {
                    if (!this.activeTable || !this.duplicateForm.new_name) {
                        this.showToast('Please enter new cloned table name', 'error');
                        return;
                    }

                    const res = await fetch(`?api=duplicate_table&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            source_table: this.activeTable,
                            new_table: this.duplicateForm.new_name,
                            include_data: this.duplicateForm.include_data
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showDuplicateTableModal = false;
                        this.showToast(`Table '${this.activeTable}' duplicated to '${this.duplicateForm.new_name}'!`, 'success');
                        await this.loadTables();
                        this.selectTable(this.duplicateForm.new_name);
                        this.loadAnalytics();
                    } else {
                        this.showToast(data.error || 'Failed to duplicate table', 'error');
                    }
                },

                exportQueryResultsCsv() {
                    if (!this.queryResult.rows || this.queryResult.rows.length === 0) return;
                    const cols = Object.keys(this.queryResult.rows[0]);
                    const lines = [cols.join(',')];

                    this.queryResult.rows.forEach(row => {
                        const values = cols.map(c => {
                            const val = row[c] !== null ? String(row[c]) : '';
                            return `"${val.replace(/"/g, '""')}"`;
                        });
                        lines.push(values.join(','));
                    });

                    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = `query_result_${Date.now()}.csv`;
                    link.click();
                    this.showToast('Query results exported to CSV!', 'success');
                },

                exportQueryResultsJson() {
                    if (!this.queryResult.rows || this.queryResult.rows.length === 0) return;
                    const jsonStr = JSON.stringify(this.queryResult.rows, null, 2);
                    const blob = new Blob([jsonStr], { type: 'application/json' });
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = `query_result_${Date.now()}.json`;
                    link.click();
                    this.showToast('Query results exported to JSON!', 'success');
                },

                exportQueryResultsExcel() {
                    if (!this.queryResult.rows || this.queryResult.rows.length === 0) return;
                    const cols = Object.keys(this.queryResult.rows[0]);

                    let tableHtml = '<table border="1"><thead><tr>';
                    cols.forEach(c => { tableHtml += `<th style="background-color:#0284c7;color:#ffffff;font-weight:bold;">${c}</th>`; });
                    tableHtml += '</tr></thead><tbody>';

                    this.queryResult.rows.forEach(r => {
                        tableHtml += '<tr>';
                        cols.forEach(c => {
                            const val = r[c] !== null ? r[c] : '';
                            tableHtml += `<td>${val}</td>`;
                        });
                        tableHtml += '</tr>';
                    });
                    tableHtml += '</tbody></table>';

                    const excelBlob = new Blob([tableHtml], { type: 'application/vnd.ms-excel' });
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(excelBlob);
                    link.download = `query_result_${Date.now()}.xls`;
                    link.click();
                    this.showToast('Query results exported to Excel (.xls)!', 'success');
                },

                exportQueryResultsHtml() {
                    if (!this.queryResult.rows || this.queryResult.rows.length === 0) return;
                    const cols = Object.keys(this.queryResult.rows[0]);

                    let thRows = cols.map(c => '<th style="padding:12px;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #334155;text-align:left;">' + c + '</th>').join('');

                    let tbodyRows = '';
                    this.queryResult.rows.forEach(r => {
                        tbodyRows += '<tr style="border-bottom:1px solid #1e293b;">';
                        cols.forEach(c => {
                            const val = r[c] !== null ? r[c] : '<span style="color:#64748b;font-style:italic;">NULL</span>';
                            tbodyRows += '<td style="padding:12px;color:#e2e8f0;">' + val + '</td>';
                        });
                        tbodyRows += '</tr>';
                    });

                    const dbName = this.activeDb ? this.activeDb.split(/[/\\]/).pop() : 'SQLite Database';
                    const recordCount = this.queryResult.rows.length;
                    const dateStr = new Date().toLocaleString();

                    const htmlContent = [
                        '<!DOCTYPE html>',
                        '<html>',
                        '<head>',
                        '<meta charset="UTF-8">',
                        '<title>LiteSQL Studio - Query Results Report</title>',
                        '<style>',
                        'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: #0f172a; color: #f8fafc; margin: 0; padding: 32px; }',
                        '.container { max-width: 1200px; margin: 0 auto; }',
                        '.header { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #334155; padding-bottom: 16px; margin-bottom: 24px; }',
                        '.title { font-size: 20px; font-weight: 800; color: #ffffff; margin: 0; }',
                        '.meta { font-size: 12px; color: #94a3b8; margin-top: 4px; font-family: monospace; }',
                        '.badge { font-size: 12px; background: rgba(14,165,233,0.15); color: #38bdf8; border: 1px solid rgba(14,165,233,0.3); padding: 4px 12px; border-radius: 9999px; font-weight: bold; font-family: monospace; }',
                        '.card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }',
                        'table { width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; font-family: monospace; }',
                        'th { background: #0f172a; color: #94a3b8; }',
                        'tr:nth-child(even) { background: rgba(255,255,255,0.02); }',
                        '</style>',
                        '</head>',
                        '<body>',
                        '<div class="container">',
                        '  <div class="header">',
                        '    <div>',
                        '      <h1 class="title">⚡ LiteSQL Studio - Query Results Report</h1>',
                        '      <div class="meta">' + dateStr + ' | Database: ' + dbName + '</div>',
                        '    </div>',
                        '    <span class="badge">' + recordCount + ' Record(s)</span>',
                        '  </div>',
                        '  <div class="card">',
                        '    <table>',
                        '      <thead><tr>' + thRows + '</tr></thead>',
                        '      <tbody>' + tbodyRows + '</tbody>',
                        '    </table>',
                        '  </div>',
                        '</div>',
                        '</body>',
                        '</html>'
                    ].join('\n');

                    const blob = new Blob([htmlContent], { type: 'text/html;charset=utf-8;' });
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = `query_report_${Date.now()}.html`;
                    link.click();
                    this.showToast('Query results exported to Styled HTML Report!', 'success');
                },

                openBenchmarkModal() {
                    this.benchmarkForm = {
                        sql_a: this.sqlQuery || `SELECT * FROM \`${this.activeTable || 'table'}\`;`,
                        sql_b: `SELECT * FROM \`${this.activeTable || 'table'}\` LIMIT 50;`,
                        iterations: 10
                    };
                    this.benchmarkResults = {};
                    this.showBenchmarkModal = true;
                },

                async runBenchmarkTest() {
                    if (!this.benchmarkForm.sql_a || !this.benchmarkForm.sql_b) {
                        this.showToast('Please enter both Query A and Query B', 'error');
                        return;
                    }
                    this.benchmarkLoading = true;
                    this.benchmarkResults = {};

                    const res = await fetch(`?api=benchmark_queries&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            sql_a: this.benchmarkForm.sql_a,
                            sql_b: this.benchmarkForm.sql_b,
                            iterations: Number(this.benchmarkForm.iterations) || 10
                        })
                    });
                    const data = await res.json();
                    this.benchmarkLoading = false;

                    if (data.success) {
                        this.benchmarkResults = data;
                        setTimeout(() => lucide.createIcons(), 50);
                    } else {
                        this.showToast(data.error || 'Benchmark test failed', 'error');
                    }
                },

                copyMermaidMarkup() {
                    if (!this.erData || !this.erData.tables || this.erData.tables.length === 0) {
                        this.showToast('No ER diagram data to export', 'error');
                        return;
                    }
                    let code = 'erDiagram\n';
                    this.erData.tables.forEach(t => {
                        code += `    ${t.name} {\n`;
                        if (t.columns) {
                            t.columns.forEach(c => {
                                const pkFk = c.pk ? 'PK' : (c.fk ? 'FK' : '');
                                code += `        ${(c.type || 'TEXT').replace(/\s+/g, '_')} ${c.name} ${pkFk}\n`;
                            });
                        }
                        code += `    }\n`;
                    });
                    if (this.erData.relationships) {
                        this.erData.relationships.forEach(r => {
                            code += `    ${r.from_table} ||--o{ ${r.to_table} : "${r.from_col}->${r.to_col}"\n`;
                        });
                    }

                    navigator.clipboard.writeText(code).then(() => {
                        this.showToast('Mermaid.js ER markup copied to clipboard!', 'success');
                    }).catch(() => {
                        this.showToast('Failed to copy to clipboard', 'error');
                    });
                },

                exportErSvg() {
                    if (!this.erData || !this.erData.tables || this.erData.tables.length === 0) {
                        this.showToast('No ER diagram data to export', 'error');
                        return;
                    }
                    const dbName = this.activeDb ? this.activeDb.split(/[/\\]/).pop() : 'SQLite Database';
                    let cardsSvg = '';
                    let xOffset = 20;
                    let yOffset = 70;
                    const cardWidth = 260;
                    const cardGap = 30;

                    this.erData.tables.forEach((t, tIdx) => {
                        const colHeight = (t.columns ? t.columns.length : 0) * 22 + 40;
                        cardsSvg += `<g transform="translate(${xOffset}, ${yOffset})">
                            <rect width="${cardWidth}" height="${colHeight}" rx="12" fill="#1e293b" stroke="#334155" stroke-width="2" />
                            <rect width="${cardWidth}" height="32" rx="12" fill="#0f172a" />
                            <text x="15" y="21" fill="#38bdf8" font-size="13" font-weight="bold" font-family="sans-serif">📊 ${t.name}</text>`;

                        if (t.columns) {
                            t.columns.forEach((c, cIdx) => {
                                const cY = 52 + (cIdx * 22);
                                const pkFkText = c.pk ? ' 🔑' : (c.fk ? ' 🔗' : '');
                                cardsSvg += `<text x="15" y="${cY}" fill="#cbd5e1" font-size="11" font-family="monospace">${c.name}${pkFkText}</text>
                                <text x="${cardWidth - 15}" y="${cY}" fill="#64748b" font-size="10" font-family="monospace" text-anchor="end">${c.type || 'ANY'}</text>`;
                            });
                        }
                        cardsSvg += `</g>`;

                        xOffset += cardWidth + cardGap;
                        if ((tIdx + 1) % 3 === 0) {
                            xOffset = 20;
                            yOffset += 260;
                        }
                    });

                    const totalWidth = Math.max(900, Math.min(3, this.erData.tables.length) * (cardWidth + cardGap) + 40);
                    const totalHeight = yOffset + 300;

                    const xmlHeader = '<' + '?xml version="1.0" encoding="UTF-8"?' + '>';
                    const svgContent = `${xmlHeader}
<svg width="${totalWidth}" height="${totalHeight}" xmlns="http://www.w3.org/2000/svg">
  <rect width="100%" height="100%" fill="#0f172a"/>
  <text x="20" y="35" fill="#ffffff" font-size="18" font-weight="bold" font-family="sans-serif">⚡ LiteSQL Studio - ER Schema Diagram (${dbName})</text>
  ${cardsSvg}
</svg>`;

                    const blob = new Blob([svgContent], { type: 'image/svg+xml;charset=utf-8;' });
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = `er_diagram_${Date.now()}.svg`;
                    link.click();
                    this.showToast('ER Diagram exported to SVG Vector Image!', 'success');
                },

                sortData(colName) {
                    if (this.sortColumn === colName) {
                        if (this.sortDir === 'ASC') {
                            this.sortDir = 'DESC';
                        } else {
                            this.sortColumn = '';
                            this.sortDir = 'ASC';
                        }
                    } else {
                        this.sortColumn = colName;
                        this.sortDir = 'ASC';
                    }
                    this.currentPage = 1;
                    this.loadData();
                },

                inspectRow(row) {
                    this.inspectRowData = row;
                    this.showRowInspectorModal = true;
                    setTimeout(() => lucide.createIcons(), 50);
                },

                copyRowJson() {
                    if (!this.inspectRowData) return;
                    navigator.clipboard.writeText(JSON.stringify(this.inspectRowData, null, 2)).then(() => {
                        this.showToast('Record JSON copied to clipboard!', 'success');
                    });
                },

                openReorderColsModal() {
                    if (!this.schema || !this.schema.columns || this.schema.columns.length === 0) {
                        this.showToast('No column definitions loaded for active table', 'error');
                        return;
                    }
                    this.reorderColsList = JSON.parse(JSON.stringify(this.schema.columns));
                    this.showReorderColsModal = true;
                    setTimeout(() => lucide.createIcons(), 50);
                },

                moveColUp(idx) {
                    if (idx <= 0) return;
                    const item = this.reorderColsList.splice(idx, 1)[0];
                    this.reorderColsList.splice(idx - 1, 0, item);
                    setTimeout(() => lucide.createIcons(), 50);
                },

                moveColDown(idx) {
                    if (idx >= this.reorderColsList.length - 1) return;
                    const item = this.reorderColsList.splice(idx, 1)[0];
                    this.reorderColsList.splice(idx + 1, 0, item);
                    setTimeout(() => lucide.createIcons(), 50);
                },

                async submitReorderCols() {
                    const orderedNames = this.reorderColsList.map(c => c.name);
                    const res = await fetch(`?api=reorder_columns&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            columns: orderedNames
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Table columns successfully reordered!', 'success');
                        this.showReorderColsModal = false;
                        await this.loadSchema();
                        await this.loadData();
                    } else {
                        this.showToast(data.error || 'Failed to reorder columns', 'error');
                    }
                },

                async openDdlModal() {
                    if (!this.activeDb) {
                        this.showToast('No active database connected', 'error');
                        return;
                    }
                    this.showDdlModal = true;
                    await this.loadDdlCode();
                    setTimeout(() => lucide.createIcons(), 50);
                },

                async loadDdlCode() {
                    const drops = this.ddlOptions.drops ? '1' : '0';
                    const indexes = this.ddlOptions.indexes ? '1' : '0';
                    const triggers = this.ddlOptions.triggers ? '1' : '0';
                    const res = await fetch(`?api=export_ddl&db_path=${encodeURIComponent(this.activeDb)}&drops=${drops}&indexes=${indexes}&triggers=${triggers}`);
                    const data = await res.json();
                    if (data.success) {
                        this.ddlCode = data.sql;
                    } else {
                        this.showToast('Failed to generate DDL script', 'error');
                    }
                },

                copyDdlCode() {
                    if (!this.ddlCode) return;
                    navigator.clipboard.writeText(this.ddlCode).then(() => {
                        this.showToast('Schema DDL SQL copied to clipboard!', 'success');
                    });
                },

                downloadDdlFile() {
                    const drops = this.ddlOptions.drops ? '1' : '0';
                    const indexes = this.ddlOptions.indexes ? '1' : '0';
                    const triggers = this.ddlOptions.triggers ? '1' : '0';
                    window.location.href = `?api=export_ddl&db_path=${encodeURIComponent(this.activeDb)}&drops=${drops}&indexes=${indexes}&triggers=${triggers}&download=1`;
                },

                openDuplicateColModal(colName) {
                    this.duplicateColForm = {
                        source_col: colName,
                        new_col: colName + '_copy',
                        copy_data: true
                    };
                    this.showDuplicateColModal = true;
                },

                async submitDuplicateCol() {
                    if (!this.duplicateColForm.new_col) {
                        this.showToast('Please enter a new column name', 'error');
                        return;
                    }
                    const res = await fetch(`?api=duplicate_column&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            source_col: this.duplicateColForm.source_col,
                            new_col: this.duplicateColForm.new_col,
                            copy_data: this.duplicateColForm.copy_data
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast(`Column '${this.duplicateColForm.source_col}' successfully duplicated as '${this.duplicateColForm.new_col}'!`, 'success');
                        this.showDuplicateColModal = false;
                        await this.loadSchema();
                        await this.loadData();
                    } else {
                        this.showToast(data.error || 'Failed to duplicate column', 'error');
                    }
                },

                async loadComments() {
                    if (!this.activeTable || !this.activeDb) return;
                    const res = await fetch(`?api=get_column_comments&table=${encodeURIComponent(this.activeTable)}&db_path=${encodeURIComponent(this.activeDb)}`);
                    const data = await res.json();
                    if (data.success) {
                        this.colComments = data.comments || {};
                    }
                },

                openCommentModal(colName) {
                    this.commentForm = {
                        column: colName,
                        comment: this.colComments[colName] || ''
                    };
                    this.showCommentModal = true;
                },

                async submitComment() {
                    const res = await fetch(`?api=save_column_comment&db_path=${encodeURIComponent(this.activeDb)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: this.activeTable,
                            column: this.commentForm.column,
                            comment: this.commentForm.comment
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Column description note saved!', 'success');
                        this.showCommentModal = false;
                        await this.loadComments();
                    } else {
                        this.showToast(data.error || 'Failed to save note', 'error');
                    }
                },

                openCodeGeneratorModal() {
                    this.showCodeGeneratorModal = true;
                    this.generateCodeSnippet();
                },

                generateCodeSnippet() {
                    const dbName = this.activeDb ? this.activeDb.split(/[/\\]/).pop() : 'database.sqlite';
                    const table = this.activeTable || 'table_name';
                    const lang = this.codeGenLang;
                    const op = this.codeGenOp;
                    const sql = this.sqlQuery.trim() || `SELECT * FROM \`${table}\` LIMIT 50;`;

                    let code = '';
                    const phpTag = '<' + '?php';
                    if (lang === 'php') {
                        if (op === 'select') {
                            code = `${phpTag}\n// PHP PDO SQLite - Select All Records\n$pdo = new PDO('sqlite:' . __DIR__ . '/${dbName}');\n$stmt = $pdo->query("SELECT * FROM \`${table}\`");\n$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);\nprint_r($rows);`;
                        } else if (op === 'insert') {
                            code = `${phpTag}\n// PHP PDO SQLite - Insert Record\n$pdo = new PDO('sqlite:' . __DIR__ . '/${dbName}');\n$stmt = $pdo->prepare("INSERT INTO \`${table}\` (column1, column2) VALUES (?, ?)");\n$stmt->execute(['value1', 'value2']);\necho "Inserted ID: " . $pdo->lastInsertId();`;
                        } else if (op === 'update') {
                            code = `${phpTag}\n// PHP PDO SQLite - Update Record\n$pdo = new PDO('sqlite:' . __DIR__ . '/${dbName}');\n$stmt = $pdo->prepare("UPDATE \`${table}\` SET column1 = ? WHERE id = ?");\n$stmt->execute(['new_value', 1]);`;
                        } else if (op === 'delete') {
                            code = `${phpTag}\n// PHP PDO SQLite - Delete Record\n$pdo = new PDO('sqlite:' . __DIR__ . '/${dbName}');\n$stmt = $pdo->prepare("DELETE FROM \`${table}\` WHERE id = ?");\n$stmt->execute([1]);`;
                        } else {
                            code = `${phpTag}\n// PHP PDO SQLite - Custom Query\n$pdo = new PDO('sqlite:' . __DIR__ . '/${dbName}');\n$stmt = $pdo->query("${sql}");\n$result = $stmt->fetchAll(PDO::FETCH_ASSOC);\nprint_r($result);`;
                        }
                    } else if (lang === 'nodejs') {
                        if (op === 'select') {
                            code = `// Node.js - better-sqlite3\nconst Database = require('better-sqlite3');\nconst db = new Database('${dbName}');\n\nconst rows = db.prepare("SELECT * FROM \`${table}\`").all();\nconsole.log(rows);`;
                        } else if (op === 'insert') {
                            code = `// Node.js - better-sqlite3 Insert\nconst Database = require('better-sqlite3');\nconst db = new Database('${dbName}');\n\nconst info = db.prepare("INSERT INTO \`${table}\` (column1, column2) VALUES (?, ?)").run('val1', 'val2');\nconsole.log('Inserted Row ID:', info.lastInsertRowid);\n`;
                        } else {
                            code = `// Node.js - better-sqlite3 Exec\nconst Database = require('better-sqlite3');\nconst db = new Database('${dbName}');\n\nconst result = db.prepare(\`${sql}\`).all();\nconsole.log(result);`;
                        }
                    } else if (lang === 'python') {
                        if (op === 'select') {
                            code = `# Python 3 - sqlite3 native\nimport sqlite3\n\nconn = sqlite3.connect('${dbName}')\ncursor = conn.cursor()\ncursor.execute("SELECT * FROM \`${table}\`")\nrows = cursor.fetchall()\nprint(rows)\nconn.close()`;
                        } else if (op === 'insert') {
                            code = `# Python 3 - sqlite3 Insert\nimport sqlite3\n\nconn = sqlite3.connect('${dbName}')\ncursor = conn.cursor()\ncursor.execute("INSERT INTO \`${table}\` (column1, column2) VALUES (?, ?)", ('val1', 'val2'))\nconn.commit()\nconn.close()`;
                        } else {
                            code = `# Python 3 - sqlite3 Query\nimport sqlite3\n\nconn = sqlite3.connect('${dbName}')\ncursor = conn.cursor()\ncursor.execute("""${sql}""")\nresult = cursor.fetchall()\nprint(result)\nconn.close()`;
                        }
                    } else if (lang === 'flutter') {
                        code = `// Flutter / Dart - sqflite plugin\nimport 'package:sqflite/sqflite.dart';\nimport 'package:path/path.dart';\n\nFuture<List<Map<String, dynamic>>> getRecords() async {\n  final dbPath = await getDatabasesPath();\n  final path = join(dbPath, '${dbName}');\n  final Database db = await openDatabase(path);\n  \n  return await db.rawQuery("SELECT * FROM \`${table}\`");\n}`;
                    } else if (lang === 'curl') {
                        const host = window.location.origin + window.location.pathname;
                        code = `# cURL HTTP REST API Call\ncurl -X POST "${host}?api=query&db_path=${encodeURIComponent(this.activeDb)}"\n  -H "Content-Type: application/json"\n  -d '{"sql": "${sql.replace(/'/g, "\\'")}"}'`;
                    }
                    this.generatedCode = code;
                },

                copyCodeSnippet() {
                    if (!this.generatedCode) return;
                    navigator.clipboard.writeText(this.generatedCode).then(() => {
                        this.showToast('Code snippet copied to clipboard!', 'success');
                    });
                }
            }
        }
    </script>
</body>
</html>
