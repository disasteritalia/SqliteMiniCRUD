<?php
/**
 * SQLite Admin - Versione semplificata e consolidata
 * Tutte le configurazioni modificabili in alto
 * Tutte le funzioni in un'unico file
 */

// ====================================================================
// 🔧 CONFIGURAZIONI - MODIFICA QUI
// ====================================================================
session_start();

$CONFIG = [
    'password'    => 'admin',              // Password di accesso
    'db_dir'      => '/PATH/deL/mIO/dBASE',  // Cartella database
    'app_name'    => 'SQLite Admin',       // Nome applicazione
    'per_page'    => 50,                   // Record per pagina
];

// ====================================================================
// 🔐 FUNZIONI DI AUTENTICAZIONE
// ====================================================================
function is_logged() {
    global $CONFIG;
    if (empty($CONFIG['password'])) return true;
    return isset($_SESSION['logged']) && $_SESSION['logged'];
}

function login($pwd) {
    global $CONFIG;
    if ($pwd === $CONFIG['password']) {
        $_SESSION['logged'] = true;
        return true;
    }
    return false;
}

function logout() {
    session_destroy();
}

// ====================================================================
// 🛡️ FUNZIONI DI SICUREZZA
// ====================================================================
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function format_size($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function quote_ident($name) {
    return '"' . str_replace('"', '""', (string)$name) . '"';
}

function is_valid_identifier($name) {
    return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$name) === 1;
}

function sql_value($value) {
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    return "'" . SQLite3::escapeString((string)$value) . "'";
}

// ====================================================================
// 💾 FUNZIONI DATABASE
// ====================================================================
function get_databases() {
    global $CONFIG;
    $dbs = [];
    $dir = $CONFIG['db_dir'];
    
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    foreach (scandir($dir) as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['db', 'sqlite', 'sqlite3', 'db3'])) {
            $path = $dir . '/' . $file;
            if (file_exists($path)) {
                $dbs[] = [
                    'name' => $file,
                    'path' => $path,
                    'size' => filesize($path)
                ];
            }
        }
    }
    
    return $dbs;
}

function connect_db($path) {
    if (!file_exists($path)) return ['error' => 'Database non trovato'];
    try {
        $db = new SQLite3($path);
        return ['db' => $db];
    } catch (Exception $e) {
        return ['error' => 'Errore connessione: ' . $e->getMessage()];
    }
}

function get_tables($db_path) {
    $conn = connect_db($db_path);
    if (isset($conn['error'])) return [];
    $db = $conn['db'];
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    $tables = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $tables[] = $row['name'];
    }
    $db->close();
    return $tables;
}

function get_table_info($db_path, $table_name) {
    $conn = connect_db($db_path);
    if (isset($conn['error'])) return null;
    $db = $conn['db'];
    
    $result = $db->query("PRAGMA table_info('" . SQLite3::escapeString($table_name) . "')");
    $columns = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $row;
    }
    
    $result = $db->query("SELECT COUNT(*) as cnt FROM \"" . SQLite3::escapeString($table_name) . "\"");
    $count = $result->fetchArray(SQLITE3_ASSOC);
    
    $db->close();
    return ['columns' => $columns, 'row_count' => $count['cnt']];
}

function get_pk($db_path, $table_name) {
    $info = get_table_info($db_path, $table_name);
    if (!$info) return null;
    foreach ($info['columns'] as $col) {
        if ($col['pk'] == 1) return $col['name'];
    }
    return null;
}

function run_query($db_path, $sql, $params = []) {
    $conn = connect_db($db_path);
    if (isset($conn['error'])) throw new Exception($conn['error']);
    $db = $conn['db'];

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        $message = $db->lastErrorMsg();
        $db->close();
        throw new Exception($message);
    }

    foreach ($params as $i => $val) {
        if ($val === null) {
            $stmt->bindValue($i + 1, null, SQLITE3_NULL);
        } elseif (is_int($val)) {
            $stmt->bindValue($i + 1, $val, SQLITE3_INTEGER);
        } elseif (is_float($val)) {
            $stmt->bindValue($i + 1, $val, SQLITE3_FLOAT);
        } else {
            $stmt->bindValue($i + 1, (string)$val, SQLITE3_TEXT);
        }
    }

    $result = $stmt->execute();
    if ($result === false) {
        $message = $db->lastErrorMsg();
        $stmt->close();
        $db->close();
        throw new Exception($message);
    }

    if ($result->numColumns() > 0) {
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        $result->finalize();
        $stmt->close();
        $db->close();
        return $rows;
    } else {
        $result->finalize();
        $stmt->close();
        $db->close();
        return true;
    }
}

function get_schema_objects($db_path, $type = null) {
    $sql = "SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'";
    $params = [];
    if ($type !== null) {
        $sql .= " AND type = ?";
        $params[] = $type;
    }
    $sql .= " ORDER BY type, name";
    return run_query($db_path, $sql, $params);
}

function get_object_names($db_path, $type) {
    $objects = get_schema_objects($db_path, $type);
    return array_map(function($row) {
        return $row['name'];
    }, $objects);
}

function get_object_definition($db_path, $name, $type = null) {
    $sql = "SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name = ?";
    $params = [$name];
    if ($type !== null) {
        $sql .= " AND type = ?";
        $params[] = $type;
    }
    $rows = run_query($db_path, $sql, $params);
    return $rows[0] ?? null;
}

function get_related_indexes($db_path, $table_name) {
    return run_query($db_path, "SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name NOT LIKE 'sqlite_%' ORDER BY name", [$table_name]);
}

function get_related_triggers($db_path, $table_name) {
    return run_query($db_path, "SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ? ORDER BY name", [$table_name]);
}

function vacuum_db($db_path) {
    try {
        run_query($db_path, 'VACUUM');
        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function drop_object($db_path, $type, $name) {
    $map = [
        'view' => 'VIEW',
        'index' => 'INDEX',
        'trigger' => 'TRIGGER',
        'table' => 'TABLE',
    ];

    if (!isset($map[$type])) {
        return ['error' => 'Tipo oggetto non supportato'];
    }

    try {
        run_query($db_path, 'DROP ' . $map[$type] . ' ' . quote_ident($name));
        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function create_view($db_path, $view_name, $select_sql) {
    if (!is_valid_identifier($view_name)) {
        return ['error' => 'Nome vista non valido'];
    }
    if (stripos(ltrim($select_sql), 'SELECT') !== 0) {
        return ['error' => 'La definizione della vista deve iniziare con SELECT'];
    }
    try {
        run_query($db_path, 'CREATE VIEW ' . quote_ident($view_name) . ' AS ' . trim($select_sql));
        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function create_index($db_path, $index_name, $table_name, $columns, $unique = false) {
    if (!is_valid_identifier($index_name)) {
        return ['error' => 'Nome indice non valido'];
    }
    if (empty($columns)) {
        return ['error' => 'Seleziona almeno una colonna'];
    }
    try {
        $quoted_columns = array_map(function($column) {
            return quote_ident($column);
        }, $columns);
        $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . quote_ident($index_name) . ' ON ' . quote_ident($table_name) . ' (' . implode(', ', $quoted_columns) . ')';
        run_query($db_path, $sql);
        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function create_trigger($db_path, $trigger_name, $timing, $event, $table_name, $body_sql) {
    if (!is_valid_identifier($trigger_name)) {
        return ['error' => 'Nome trigger non valido'];
    }

    $timing = strtoupper($timing);
    $event = strtoupper($event);
    if (!in_array($timing, ['BEFORE', 'AFTER'], true) || !in_array($event, ['INSERT', 'UPDATE', 'DELETE'], true)) {
        return ['error' => 'Configurazione trigger non valida'];
    }

    $body_sql = trim($body_sql);
    if ($body_sql === '') {
        return ['error' => 'Il corpo del trigger non puo essere vuoto'];
    }

    try {
        $sql = 'CREATE TRIGGER ' . quote_ident($trigger_name) . ' ' . $timing . ' ' . $event . ' ON ' . quote_ident($table_name) . ' BEGIN ' . $body_sql . ' END';
        run_query($db_path, $sql);
        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function export_sql($db_path, $object_name = null) {
    $dump = [];

    if ($object_name !== null) {
        $objects = [];
        $definition = get_object_definition($db_path, $object_name);
        if ($definition) {
            $objects[] = $definition;
        }
    } else {
        $objects = get_schema_objects($db_path);
    }

    foreach ($objects as $object) {
        if (!empty($object['sql'])) {
            if ($object['type'] === 'table') {
                $dump[] = 'DROP TABLE IF EXISTS ' . quote_ident($object['name']) . ';';
            } elseif ($object['type'] === 'view') {
                $dump[] = 'DROP VIEW IF EXISTS ' . quote_ident($object['name']) . ';';
            } elseif ($object['type'] === 'index') {
                $dump[] = 'DROP INDEX IF EXISTS ' . quote_ident($object['name']) . ';';
            } elseif ($object['type'] === 'trigger') {
                $dump[] = 'DROP TRIGGER IF EXISTS ' . quote_ident($object['name']) . ';';
            }
            $dump[] = rtrim($object['sql'], ';') . ';';
        }

        if ($object['type'] === 'table') {
            $rows = run_query($db_path, 'SELECT * FROM ' . quote_ident($object['name']));
            foreach ($rows as $row) {
                $values = array_map('sql_value', array_values($row));
                $dump[] = 'INSERT INTO ' . quote_ident($object['name']) . ' VALUES (' . implode(', ', $values) . ');';
            }
        }
    }

    return implode("\n", $dump) . "\n";
}

function create_db($name) {
    global $CONFIG;
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    if (empty($name)) return ['error' => 'Nome non valido'];
    $path = $CONFIG['db_dir'] . '/' . $name . '.db';
    if (file_exists($path)) return ['error' => 'Database già esistente'];
    try {
        $db = new SQLite3($path);
        $db->close();
        return ['success' => true, 'path' => $path, 'name' => $name . '.db'];
    } catch (Exception $e) {
        return ['error' => 'Errore creazione: ' . $e->getMessage()];
    }
}

function delete_db($path) {
    if (!file_exists($path)) return ['error' => 'Database non trovato'];
    if (!unlink($path)) return ['error' => 'Impossibile eliminare'];
    return ['success' => true];
}

function create_table($db_path, $table_name, $columns) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS \"" . SQLite3::escapeString($table_name) . "\" (";
        $cols = [];
        foreach ($columns as $col) {
            $col_def = "\"" . SQLite3::escapeString($col['name']) . "\" " . $col['type'];
            if ($col['pk']) $col_def .= " PRIMARY KEY";
            if ($col['ai']) $col_def .= " AUTOINCREMENT";
            $cols[] = $col_def;
        }
        $sql .= implode(", ", $cols) . ")";
        run_query($db_path, $sql);
        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function drop_table($db_path, $table_name) {
    try {
        run_query($db_path, "DROP TABLE \"" . SQLite3::escapeString($table_name) . "\"");
        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function truncate_table($db_path, $table_name) {
    try {
        run_query($db_path, "DELETE FROM \"" . SQLite3::escapeString($table_name) . "\"");
        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function insert_record($db_path, $table_name, $data) {
    try {
        $columns = array_keys($data);
        $values = array_values($data);
        $sql = "INSERT INTO \"" . SQLite3::escapeString($table_name) . "\" (\"" . 
               implode('", "', array_map(function($c) { return SQLite3::escapeString($c); }, $columns)) . "\") VALUES (" . 
               str_repeat('?, ', count($values) - 1) . "?)";
        run_query($db_path, $sql, $values);
        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function update_record($db_path, $table_name, $data, $where) {
    try {
        $pk = get_pk($db_path, $table_name);
        if (!$pk) return ['error' => 'Chiave primaria non trovata'];
        
        $sets = [];
        $values = [];
        foreach ($data as $key => $value) {
            if ($key === $pk) continue;
            $sets[] = "\"" . SQLite3::escapeString($key) . "\" = ?";
            $values[] = $value;
        }
        
        $sql = "UPDATE \"" . SQLite3::escapeString($table_name) . "\" SET " . 
               implode(", ", $sets) . " WHERE \"" . SQLite3::escapeString($pk) . "\" = ?";
        $values[] = $where;
        
        run_query($db_path, $sql, $values);
        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function delete_record($db_path, $table_name, $id) {
    try {
        $pk = get_pk($db_path, $table_name);
        if (!$pk) return ['error' => 'Chiave primaria non trovata'];
        run_query($db_path, "DELETE FROM \"" . SQLite3::escapeString($table_name) . "\" WHERE \"" . SQLite3::escapeString($pk) . "\" = ?", [$id]);
        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function export_csv($db_path, $table_name) {
    try {
        $rows = run_query($db_path, "SELECT * FROM \"" . SQLite3::escapeString($table_name) . "\"");
        if (empty($rows)) return ['error' => 'Tabella vuota'];
        
        $output = implode(',', array_keys($rows[0])) . "\n";
        foreach ($rows as $row) {
            $output .= implode(',', array_map(function($v) {
                return '"' . str_replace('"', '""', $v) . '"';
            }, $row)) . "\n";
        }
        
        return ['success' => true, 'data' => $output];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function import_csv($db_path, $table_name, $tmp_path) {
    try {
        $handle = fopen($tmp_path, 'r');
        if (!$handle) return ['error' => 'Impossibile leggere file'];
        
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return ['error' => 'File CSV vuoto'];
        }
        
        $count = 0;
        while ($row = fgetcsv($handle)) {
            if (count($row) !== count($headers)) continue;
            $data = array_combine($headers, $row);
            $result = insert_record($db_path, $table_name, $data);
            if (isset($result['error'])) {
                fclose($handle);
                return ['error' => 'Errore riga ' . ($count+1) . ': ' . $result['error']];
            }
            $count++;
        }
        
        fclose($handle);
        return ['success' => true, 'count' => $count];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ====================================================================
// 🎨 CSS STYLES
// ====================================================================
function echo_styles() {
    echo '<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Arial, sans-serif; background: #f5f5f5; }

.header {
    display: flex; background: #333; color: white; padding: 8px 15px;
    border-bottom: 2px solid #444; position: fixed; top: 0; left: 0; right: 0;
    height: 45px; align-items: center; z-index: 1000;
}
.header-col { flex: 1; display: flex; align-items: center; }
.col-left { justify-content: flex-start; }
.col-center { justify-content: center; }
.col-right { justify-content: flex-end; }

.db-form { display: flex; gap: 8px; align-items: center; }
.db-select { padding: 6px 10px; width: 220px; border: 1px solid #666; border-radius: 3px; background: #444; color: white; }
.new-db-btn, .logout-btn { padding: 6px 12px; background: #28a745; color: white; border: none; border-radius: 3px; cursor: pointer; text-decoration: none; font-size: 14px; margin-left: 10px; }
.logout-btn { background: #666; }
.new-db-btn:hover { background: #218838; }
.logout-btn:hover { background: #555; }

.container { display: flex; margin-top: 45px; min-height: calc(100vh - 45px); }

.sidebar {
    width: 250px; background: #f5f5f5; border-right: 1px solid #ddd; padding: 15px;
    position: fixed; top: 45px; bottom: 0; left: 0; overflow-y: auto;
}

.content { flex: 1; margin-left: 250px; padding: 20px; }

.table-list { list-style: none; padding: 0; margin: 0 0 15px 0; }
.table-item { border: 1px solid #ddd; margin-bottom: 4px; background: white; border-radius: 3px; }
.table-item a { display: block; padding: 8px 10px; text-decoration: none; color: #333; font-size: 13px; }
.table-item:hover { background: #f0f0f0; }
.table-item.active { background: #e6f7ff; border-color: #0066cc; }
.table-count { float: right; color: #666; font-size: 11px; background: #eee; padding: 2px 6px; border-radius: 10px; }

.sidebar-btn { display: block; width: 100%; padding: 8px; margin: 8px 0; text-align: center; background: #0066cc; color: white; border: none; border-radius: 3px; text-decoration: none; font-size: 13px; cursor: pointer; }
.sidebar-btn:hover { background: #0055aa; }
.sidebar-btn-green { background: #28a745; }
.sidebar-btn-green:hover { background: #218838; }

.sidebar-section { margin-bottom: 18px; }
.sidebar-section h4 { font-size: 13px; color: #666; margin-bottom: 8px; text-transform: uppercase; }
.schema-list { list-style: none; padding: 0; margin: 0; }
.schema-list li { padding: 6px 8px; border: 1px solid #ddd; background: white; border-radius: 3px; margin-bottom: 4px; font-size: 12px; }
.schema-list a { color: #333; text-decoration: none; display: block; }
.muted { color: #666; }
.code-block { background: #1f2430; color: #f1f1f1; padding: 12px; border-radius: 4px; overflow-x: auto; font-family: monospace; font-size: 13px; }
.grid-two { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
.stat-card { background: white; border: 1px solid #ddd; border-radius: 4px; padding: 14px; }

.table-menu { background: #f8f8f8; border: 1px solid #ddd; padding: 10px; margin-bottom: 20px; display: flex; gap: 8px; flex-wrap: wrap; }

.data-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
.data-table th, .data-table td { border: 1px solid #ccc; padding: 8px; text-align: left; }
.data-table th { background: #f0f0f0; }
.data-table tr:hover { background: #f9f9f9; }

.btn { padding: 6px 12px; background: #0066cc; color: white; border: none; border-radius: 3px; cursor: pointer; text-decoration: none; font-size: 13px; display: inline-block; }
.btn:hover { background: #0055aa; }
.btn-red { background: #dc3545; }
.btn-red:hover { background: #c82333; }
.btn-green { background: #28a745; }
.btn-green:hover { background: #218838; }

.form-group { margin-bottom: 15px; }
.form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; }

.alert { padding: 10px 15px; margin: 10px 0; border-radius: 3px; }
.alert-error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
.alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }

.pagination { margin: 20px 0; text-align: center; }
.pagination a { padding: 5px 10px; margin: 0 2px; border: 1px solid #ddd; text-decoration: none; color: #333; }
.pagination a.active { background: #0066cc; color: white; border-color: #0066cc; }

.column-form { background: #f9f9f9; padding: 15px; margin: 15px 0; border: 1px solid #ddd; border-radius: 3px; }
.column-row { display: grid; grid-template-columns: 2fr 1.5fr 1fr 1fr 0.5fr; gap: 8px; margin-bottom: 8px; align-items: center; padding: 8px; background: white; border: 1px solid #eee; }
.column-header { display: grid; grid-template-columns: 2fr 1.5fr 1fr 1fr 0.5fr; gap: 8px; margin-bottom: 8px; font-weight: bold; color: #555; font-size: 13px; }

.actions-cell { width: 70px; white-space: nowrap; }
    </style>';
}

// ====================================================================
// 🎯 MAIN - LOGICA PRINCIPALE
// ====================================================================

// Logout
if (isset($_GET['logout'])) {
    logout();
    redirect($_SERVER['PHP_SELF']);
}

// Login
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['password'])) {
    if (login($_POST['password'])) {
        redirect($_SERVER['PHP_SELF']);
    } else {
        $login_error = 'Password errata!';
    }
}

if (!is_logged()) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Login</title>
    <style>body{font-family:Arial;margin:50px;text-align:center;} input{padding:10px;margin:5px;width:200px;} button{padding:10px 20px;} .error{color:red;margin:10px;}</style>
    </head><body><h2>Login</h2>';
    if (isset($login_error)) echo '<div class="error">' . $login_error . '</div>';
    echo '<form method="post"><input type="password" name="password" placeholder="Password"><br><br><button type="submit">Login</button></form></body></html>';
    exit;
}

// Variabili
$dbs = get_databases();
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['db'])) {
    $current_db = $_POST['db'];
    redirect('?db=' . urlencode($current_db));
}

$current_db = $_GET['db'] ?? null;
$current_table = $_GET['table'] ?? null;
$action = $_GET['action'] ?? 'browse';
$page = max(1, intval($_GET['page'] ?? 1));

$tables = [];
$views = [];
$indexes = [];
$triggers = [];
$table_info = null;
$current_object_type = 'table';
if ($current_db && file_exists($current_db)) {
    $tables = get_object_names($current_db, 'table');
    $views = get_object_names($current_db, 'view');
    $indexes = get_schema_objects($current_db, 'index');
    $triggers = get_schema_objects($current_db, 'trigger');
    if ($current_table && in_array($current_table, array_merge($tables, $views), true)) {
        $current_object_type = in_array($current_table, $views, true) ? 'view' : 'table';
        $table_info = get_table_info($current_db, $current_table);
    }
}

$is_readonly_object = ($current_object_type === 'view');

// Download export
if ($action == 'export' && $current_db && $current_table) {
    $result = export_csv($current_db, $current_table);
    if (isset($result['error'])) {
        $csv_error = $result['error'];
    } else {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $current_table . '_' . date('Y-m-d') . '.csv"');
        echo $result['data'];
        exit;
    }
}

if ($action == 'export_sql' && $current_db) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/sql; charset=utf-8');
    $target_name = $current_table ? $current_table : basename($current_db, '.db');
    header('Content-Disposition: attachment; filename="' . $target_name . '_' . date('Y-m-d') . '.sql"');
    echo export_sql($current_db, $current_table ?: null);
    exit;
}

// ====================================================================
// 🎨 OUTPUT HTML
// ====================================================================
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= e($CONFIG['app_name']) ?></title>
    <?php echo_styles(); ?>
</head>
<body>

<!-- HEADER -->
<div class="header">
    <div class="header-col col-left">
        <form method="post" class="db-form">
            <select name="db" class="db-select" onchange="if(this.value) this.form.submit()">
                <option value="">-- Database --</option>
                <?php foreach ($dbs as $db): ?>
                    <option value="<?= e($db['path']) ?>" <?= ($db['path'] == $current_db) ? 'selected' : '' ?>>
                        <?= e($db['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="?action=new_db" class="new-db-btn">+ DB</a>
    </div>
    <div class="header-col col-center">
        <span style="font-size:15px;font-weight:bold;"><?= e($CONFIG['app_name']) ?></span>
    </div>
    <div class="header-col col-right">
        <a href="?logout=1" class="logout-btn">Logout</a>
    </div>
</div>

<div class="container">
    <!-- SIDEBAR -->
    <?php if ($current_db): ?>
    <div class="sidebar">
        <div class="sidebar-section">
            <h3 style="margin-top:0;font-size:16px;">Tabelle</h3>
            <?php if (empty($tables)): ?>
                <p style="color:#666;font-size:13px;">Nessuna tabella</p>
            <?php else: ?>
                <ul class="table-list">
                    <?php foreach ($tables as $table): 
                        $info = get_table_info($current_db, $table);
                        $count = $info['row_count'] ?? 0;
                        $active = ($table == $current_table) ? 'active' : '';
                    ?>
                    <li class="table-item <?= $active ?>">
                        <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($table) ?>&action=browse">
                            <?= e($table) ?> <span class="table-count"><?= $count ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="sidebar-section">
            <h4>Viste</h4>
            <?php if (empty($views)): ?>
                <p class="muted" style="font-size:12px;">Nessuna vista</p>
            <?php else: ?>
                <ul class="table-list">
                    <?php foreach ($views as $view): 
                        $info = get_table_info($current_db, $view);
                        $count = $info['row_count'] ?? 0;
                        $active = ($view == $current_table) ? 'active' : '';
                    ?>
                    <li class="table-item <?= $active ?>">
                        <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($view) ?>&action=browse">
                            <?= e($view) ?> <span class="table-count"><?= $count ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="sidebar-section">
            <h4>Indici</h4>
            <?php if (empty($indexes)): ?>
                <p class="muted" style="font-size:12px;">Nessun indice</p>
            <?php else: ?>
                <ul class="schema-list">
                    <?php foreach ($indexes as $index): ?>
                    <li><?= e($index['name']) ?><br><span class="muted"><?= e($index['tbl_name']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="sidebar-section">
            <h4>Trigger</h4>
            <?php if (empty($triggers)): ?>
                <p class="muted" style="font-size:12px;">Nessun trigger</p>
            <?php else: ?>
                <ul class="schema-list">
                    <?php foreach ($triggers as $trigger): ?>
                    <li><?= e($trigger['name']) ?><br><span class="muted"><?= e($trigger['tbl_name']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <a href="?db=<?= urlencode($current_db) ?>&action=new_table" class="sidebar-btn">+ Nuova Tabella</a>
        <a href="?db=<?= urlencode($current_db) ?>&action=new_view" class="sidebar-btn sidebar-btn-green">+ Nuova Vista</a>
    </div>
    <?php endif; ?>

    <!-- CONTENT -->
    <div class="content" style="margin-left: <?= $current_db ? '250px' : '0' ?>;">
        
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error"><?= e($_GET['error']) ?></div>
        <?php endif; ?>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success"><?= e($_GET['success']) ?></div>
        <?php endif; ?>

        <?php
        // Routing
        if (!$current_db) {
            // HOME
            if ($action == 'new_db') {
                if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['name'])) {
                    $result = create_db(trim($_POST['name']));
                    if (isset($result['success'])) {
                        echo '<div class="alert alert-success">Database creato!</div>';
                        echo '<p><a href="?db=' . urlencode($result['path']) . '">Apri: ' . e($result['name']) . '</a></p>';
                    } else {
                        echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                    }
                }
                ?>
                <div style="max-width:600px;margin:0 auto;padding:20px;background:white;border-radius:5px;">
                    <h3>Crea nuovo database</h3>
                    <form method="post">
                        <div class="form-group">
                            <label>Nome database (senza estensione):</label>
                            <input type="text" name="name" placeholder="es: mio_database" required>
                            <p style="font-size:12px;color:#666;margin:5px 0 0 0;">Verrà creato con estensione .db</p>
                        </div>
                        <button type="submit" class="btn">Crea</button>
                        <a href="?" class="btn">Annulla</a>
                    </form>
                </div>
                <?php
            } else {
                echo '<h3>Seleziona un database</h3>';
                if (empty($dbs)) {
                    echo '<p>Nessun database trovato nella directory: ' . e($CONFIG['db_dir']) . '</p>';
                    echo '<p><a href="?action=new_db" class="btn">Crea primo database</a></p>';
                }
            }

        } elseif (!$current_table) {
            // DB SELEZIONATO, NESSUNA TABELLA
            if ($action == 'vacuum') {
                $result = vacuum_db($current_db);
                if (isset($result['success'])) {
                    echo '<div class="alert alert-success">VACUUM completato.</div>';
                } else {
                    echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                }
                echo '<p><a href="?db=' . urlencode($current_db) . '" class="btn">← Torna al database</a></p>';
            } elseif ($action == 'sql_db') {
                if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['sql'])) {
                    try {
                        $result = run_query($current_db, $_POST['sql']);
                        if (is_array($result)) {
                            echo '<div class="alert alert-success">Query eseguita! Risultati: ' . count($result) . ' righe</div>';
                            if (!empty($result)) {
                                echo '<table class="data-table"><thead><tr>';
                                foreach (array_keys($result[0]) as $col) {
                                    echo '<th>' . e($col) . '</th>';
                                }
                                echo '</tr></thead><tbody>';
                                foreach ($result as $row) {
                                    echo '<tr>';
                                    foreach ($row as $val) {
                                        echo '<td>' . e($val) . '</td>';
                                    }
                                    echo '</tr>';
                                }
                                echo '</tbody></table>';
                            }
                        } else {
                            echo '<div class="alert alert-success">Query eseguita!</div>';
                        }
                    } catch (Exception $e) {
                        echo '<div class="alert alert-error">Errore SQL: ' . e($e->getMessage()) . '</div>';
                    }
                }

                echo '<h3>Console SQL Database</h3>';
                echo '<form method="post">
                    <div class="form-group">
                        <label>Comando SQL:</label>
                        <textarea name="sql" rows="8" placeholder="PRAGMA table_info(...), SELECT ..., CREATE VIEW ..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-green">Esegui SQL</button>
                    <a href="?db=' . urlencode($current_db) . '" class="btn">Annulla</a>
                </form>';
            } elseif ($action == 'new_view') {
                if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                    $result = create_view($current_db, trim($_POST['view_name'] ?? ''), trim($_POST['view_sql'] ?? ''));
                    if (isset($result['success'])) {
                        echo '<div class="alert alert-success">Vista creata!</div>';
                    } else {
                        echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                    }
                }

                echo '<h3>Crea nuova vista</h3>';
                echo '<form method="post">
                    <div class="form-group">
                        <label>Nome vista:</label>
                        <input type="text" name="view_name" required>
                    </div>
                    <div class="form-group">
                        <label>Query SELECT:</label>
                        <textarea name="view_sql" rows="8" placeholder="SELECT ..." required></textarea>
                    </div>
                    <button type="submit" class="btn">Crea Vista</button>
                    <a href="?db=' . urlencode($current_db) . '" class="btn">Annulla</a>
                </form>';
            } elseif ($action == 'new_table') {
                if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                    $table_name = $_POST['name'] ?? '';
                    $columns = [];
                    
                    for ($i = 0; $i < count($_POST['col_name'] ?? []); $i++) {
                        if (!empty($_POST['col_name'][$i])) {
                            $columns[] = [
                                'name' => $_POST['col_name'][$i],
                                'type' => $_POST['col_type'][$i],
                                'pk' => isset($_POST['col_pk'][$i]),
                                'ai' => isset($_POST['col_ai'][$i])
                            ];
                        }
                    }
                    
                    if (empty($columns)) {
                        echo '<div class="alert alert-error">Aggiungi almeno una colonna</div>';
                    } else {
                        $result = create_table($current_db, $table_name, $columns);
                        if (isset($result['success'])) {
                            echo '<div class="alert alert-success">Tabella creata!</div>';
                            echo '<p><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($table_name) . '" class="btn">Apri tabella</a></p>';
                        } else {
                            echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                        }
                    }
                }
                ?>
                <h3>Crea nuova tabella</h3>
                <form method="post">
                    <div class="form-group">
                        <label>Nome tabella:</label>
                        <input type="text" name="name" required>
                    </div>
                    <h4>Colonne:</h4>
                    <div class="column-form">
                        <div class="column-header">
                            <div>Nome</div><div>Tipo</div><div>Primary</div><div>AutoInc</div><div>Azioni</div>
                        </div>
                        <div id="columns-container">
                            <div class="column-row">
                                <div><input type="text" name="col_name[]" placeholder="es: id" required></div>
                                <div>
                                    <select name="col_type[]">
                                        <option value="INTEGER">INTEGER</option>
                                        <option value="TEXT" selected>TEXT</option>
                                        <option value="REAL">REAL</option>
                                    </select>
                                </div>
                                <div><input type="checkbox" name="col_pk[]"></div>
                                <div><input type="checkbox" name="col_ai[]"></div>
                                <div><button type="button" class="btn" onclick="addColumn()" style="padding:4px 8px;font-size:12px;">+</button></div>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn">Crea Tabella</button>
                    <a href="?db=<?= urlencode($current_db) ?>" class="btn">Annulla</a>
                </form>
                <script>
                function addColumn() {
                    var container = document.getElementById("columns-container");
                    var lastRow = container.lastElementChild;
                    var lastBtn = lastRow.querySelector("button");
                    lastBtn.textContent = "-";
                    lastBtn.onclick = function() { this.parentNode.parentNode.remove(); };
                    
                    var newRow = document.createElement("div");
                    newRow.className = "column-row";
                    newRow.innerHTML = '<div><input type="text" name="col_name[]" required></div><div><select name="col_type[]"><option value="INTEGER">INTEGER</option><option value="TEXT" selected>TEXT</option><option value="REAL">REAL</option></select></div><div><input type="checkbox" name="col_pk[]"></div><div><input type="checkbox" name="col_ai[]"></div><div><button type="button" class="btn" onclick="addColumn()" style="padding:4px 8px;font-size:12px;">+</button></div>';
                    container.appendChild(newRow);
                }
                </script>
                <?php
            } else {
                echo '<h3>Database: ' . e(basename($current_db)) . '</h3>';
                echo '<div class="table-menu">';
                echo '<a href="?db=' . urlencode($current_db) . '&action=new_table" class="btn">+ Nuova Tabella</a>';
                echo '<a href="?db=' . urlencode($current_db) . '&action=new_view" class="btn">+ Nuova Vista</a>';
                echo '<a href="?db=' . urlencode($current_db) . '&action=sql_db" class="btn btn-green">SQL Database</a>';
                echo '<a href="?db=' . urlencode($current_db) . '&action=export_sql" class="btn">Esporta SQL</a>';
                echo '<a href="?db=' . urlencode($current_db) . '&action=vacuum" class="btn">VACUUM</a>';
                echo '</div>';
                echo '<div class="grid-two">';
                echo '<div class="stat-card"><h4>Riepilogo</h4>';
                echo '<p>Tabelle: <strong>' . count($tables) . '</strong></p>';
                echo '<p>Viste: <strong>' . count($views) . '</strong></p>';
                echo '<p>Indici: <strong>' . count($indexes) . '</strong></p>';
                echo '<p>Trigger: <strong>' . count($triggers) . '</strong></p>';
                echo '</div>';
                echo '<div class="stat-card"><h4>Oggetti recenti</h4>';
                if (empty($tables) && empty($views)) {
                    echo '<p class="muted">Nessun oggetto applicativo presente.</p>';
                } else {
                    echo '<ul class="schema-list">';
                    foreach (array_slice(array_merge($tables, $views), 0, 10) as $object_name) {
                        echo '<li>' . e($object_name) . '</li>';
                    }
                    echo '</ul>';
                }
                echo '</div>';
                echo '</div>';
            }

        } else {
            // TABELLA SELEZIONATA
            ?>
            <div class="table-menu">
                <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=browse" class="btn">BROWSE</a>
                <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=structure" class="btn">Struttura</a>
                <?php if (!$is_readonly_object): ?>
                <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=add_record" class="btn">Inserisci record</a>
                <?php endif; ?>
                <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=drop" class="btn btn-red" onclick="return confirm('Eliminare questo oggetto?')"><?= $is_readonly_object ? 'Drop View' : 'DROP' ?></a>
                <?php if (!$is_readonly_object): ?>
                <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=truncate" class="btn btn-red" onclick="return confirm('Svuotare tutta la tabella?')">Svuota</a>
                <?php endif; ?>
                <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=export" class="btn">Esporta CSV</a>
                <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=export_sql" class="btn">Esporta SQL</a>
                <?php if (!$is_readonly_object): ?>
                <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=import" class="btn">Importa CSV</a>
                <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=create_index" class="btn">+ Indice</a>
                <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=create_trigger" class="btn">+ Trigger</a>
                <?php endif; ?>
                <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=sql" class="btn btn-green">SQL</a>
            </div>

            <?php
            switch ($action) {
                case 'structure':
                    $definition = get_object_definition($current_db, $current_table, $current_object_type);
                    $related_indexes = !$is_readonly_object ? get_related_indexes($current_db, $current_table) : [];
                    $related_triggers = get_related_triggers($current_db, $current_table);

                    echo '<h3>Struttura: ' . e($current_table) . '</h3>';
                    if ($is_readonly_object) {
                        echo '<div class="alert alert-success">Questa vista e di sola lettura.</div>';
                    }

                    if ($table_info && !empty($table_info['columns'])) {
                        echo '<table class="data-table"><thead><tr><th>Colonna</th><th>Tipo</th><th>Not Null</th><th>Default</th><th>PK</th></tr></thead><tbody>';
                        foreach ($table_info['columns'] as $col) {
                            echo '<tr>';
                            echo '<td>' . e($col['name']) . '</td>';
                            echo '<td>' . e($col['type']) . '</td>';
                            echo '<td>' . ($col['notnull'] ? 'SI' : 'NO') . '</td>';
                            echo '<td>' . e($col['dflt_value']) . '</td>';
                            echo '<td>' . ($col['pk'] ? 'SI' : 'NO') . '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    }

                    echo '<h4>SQL di creazione</h4>';
                    echo '<pre class="code-block">' . e($definition['sql'] ?? '-- definizione non disponibile --') . '</pre>';

                    if (!$is_readonly_object) {
                        echo '<h4>Indici</h4>';
                        if (empty($related_indexes)) {
                            echo '<p class="muted">Nessun indice definito.</p>';
                        } else {
                            echo '<ul class="schema-list">';
                            foreach ($related_indexes as $index) {
                                echo '<li><strong>' . e($index['name']) . '</strong><br><span class="muted">' . e($index['sql']) . '</span><br><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=drop_index&name=' . urlencode($index['name']) . '" onclick="return confirm(\'Eliminare indice?\')">Elimina indice</a></li>';
                            }
                            echo '</ul>';
                        }
                    }

                    echo '<h4>Trigger</h4>';
                    if (empty($related_triggers)) {
                        echo '<p class="muted">Nessun trigger definito.</p>';
                    } else {
                        echo '<ul class="schema-list">';
                        foreach ($related_triggers as $trigger) {
                            echo '<li><strong>' . e($trigger['name']) . '</strong><br><span class="muted">' . e($trigger['sql']) . '</span><br><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=drop_trigger&name=' . urlencode($trigger['name']) . '" onclick="return confirm(\'Eliminare trigger?\')">Elimina trigger</a></li>';
                        }
                        echo '</ul>';
                    }
                    break;

                case 'browse':
                default:
                    $pk = get_pk($current_db, $current_table);
                    $limit = $CONFIG['per_page'];
                    $offset = ($page - 1) * $limit;
                    
                    $count = run_query($current_db, "SELECT COUNT(*) as cnt FROM \"" . SQLite3::escapeString($current_table) . "\"");
                    $total = $count[0]['cnt'];
                    $pages = ceil($total / $limit);
                    
                    $rows = run_query($current_db, "SELECT * FROM \"" . SQLite3::escapeString($current_table) . "\" LIMIT ? OFFSET ?", [$limit, $offset]);
                    
                    echo '<h4>Record: ' . $total . ' totali</h4>';
                    
                    if (empty($rows)) {
                        echo '<p>Nessun record presente.</p>';
                    } else {
                        echo '<table class="data-table"><thead><tr><th class="actions-cell">Azioni</th>';
                        foreach (array_keys($rows[0]) as $col) {
                            echo '<th>' . e($col) . '</th>';
                        }
                        echo '</tr></thead><tbody>';
                        
                        foreach ($rows as $row) {
                            echo '<tr><td class="actions-cell">';
                            if (!$is_readonly_object && $pk && isset($row[$pk])) {
                                echo '<a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=edit&id=' . urlencode($row[$pk]) . '" title="Modifica">✏️</a> ';
                                echo '<a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=delete_record&id=' . urlencode($row[$pk]) . '" onclick="return confirm(\'Eliminare?\')" title="Elimina">🗑️</a>';
                            }
                            echo '</td>';
                            foreach ($row as $val) echo '<td>' . e($val) . '</td>';
                            echo '</tr>';
                        }
                        
                        echo '</tbody></table>';
                        
                        if ($pages > 1) {
                            echo '<div class="pagination">';
                            if ($page > 1) echo '<a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=browse&page=' . ($page-1) . '">←</a> ';
                            for ($i = max(1, $page-2); $i <= min($pages, $page+2); $i++) {
                                if ($i == $page) echo '<a class="active">' . $i . '</a> ';
                                else echo '<a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=browse&page=' . $i . '">' . $i . '</a> ';
                            }
                            if ($page < $pages) echo '<a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=browse&page=' . ($page+1) . '">→</a>';
                            echo '</div>';
                        }
                    }
                    break;

                case 'create_index':
                    if ($is_readonly_object) {
                        echo '<div class="alert alert-error">Gli indici possono essere creati solo sulle tabelle.</div>';
                        break;
                    }

                    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                        $columns = $_POST['columns'] ?? [];
                        $result = create_index($current_db, trim($_POST['index_name'] ?? ''), $current_table, $columns, isset($_POST['unique']));
                        if (isset($result['success'])) {
                            echo '<div class="alert alert-success">Indice creato!</div>';
                        } else {
                            echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                        }
                    }

                    echo '<h3>Crea indice</h3><form method="post">';
                    echo '<div class="form-group"><label>Nome indice:</label><input type="text" name="index_name" required></div>';
                    echo '<div class="form-group"><label>Colonne:</label>';
                    foreach (($table_info['columns'] ?? []) as $col) {
                        echo '<label style="display:block;font-weight:normal;"><input type="checkbox" name="columns[]" value="' . e($col['name']) . '"> ' . e($col['name']) . '</label>';
                    }
                    echo '</div>';
                    echo '<div class="form-group"><label style="font-weight:normal;"><input type="checkbox" name="unique" value="1"> Indice univoco</label></div>';
                    echo '<button type="submit" class="btn">Crea indice</button> <a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=structure" class="btn">Annulla</a>';
                    echo '</form>';
                    break;

                case 'create_trigger':
                    if ($is_readonly_object) {
                        echo '<div class="alert alert-error">Il wizard trigger e disponibile solo per le tabelle.</div>';
                        break;
                    }

                    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                        $result = create_trigger(
                            $current_db,
                            trim($_POST['trigger_name'] ?? ''),
                            $_POST['timing'] ?? '',
                            $_POST['event'] ?? '',
                            $current_table,
                            trim($_POST['body_sql'] ?? '')
                        );
                        if (isset($result['success'])) {
                            echo '<div class="alert alert-success">Trigger creato!</div>';
                        } else {
                            echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                        }
                    }

                    echo '<h3>Crea trigger</h3><form method="post">';
                    echo '<div class="form-group"><label>Nome trigger:</label><input type="text" name="trigger_name" required></div>';
                    echo '<div class="form-group"><label>Timing:</label><select name="timing"><option value="BEFORE">BEFORE</option><option value="AFTER">AFTER</option></select></div>';
                    echo '<div class="form-group"><label>Evento:</label><select name="event"><option value="INSERT">INSERT</option><option value="UPDATE">UPDATE</option><option value="DELETE">DELETE</option></select></div>';
                    echo '<div class="form-group"><label>Corpo SQL:</label><textarea name="body_sql" rows="8" placeholder="INSERT INTO log_table(...) VALUES (...);" required></textarea></div>';
                    echo '<button type="submit" class="btn">Crea trigger</button> <a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=structure" class="btn">Annulla</a>';
                    echo '</form>';
                    break;

                case 'add_record':
                    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                        $data = $_POST;
                        unset($data['action']);
                        $result = insert_record($current_db, $current_table, $data);
                        if (isset($result['success'])) {
                            echo '<div class="alert alert-success">Record aggiunto!</div>';
                            echo '<p><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '" class="btn">← Torna</a></p>';
                        } else {
                            echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                        }
                    }
                    
                    $info = get_table_info($current_db, $current_table);
                    echo '<h3>Aggiungi Record</h3><form method="post">';
                    foreach ($info['columns'] as $col) {
                        if ($col['pk'] == 1 && stripos($col['type'], 'INT') !== false) continue;
                        echo '<div class="form-group">
                            <label>' . e($col['name']) . ' (' . e($col['type']) . ')</label>
                            <input type="text" name="' . e($col['name']) . '" placeholder="' . e($col['type']) . '">
                        </div>';
                    }
                    echo '<button type="submit" class="btn">Aggiungi</button>
                    <a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '" class="btn">Annulla</a>
                    </form>';
                    break;

                case 'edit':
                    $id = $_GET['id'] ?? null;
                    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                        $data = $_POST;
                        unset($data['action']);
                        $result = update_record($current_db, $current_table, $data, $id);
                        if (isset($result['success'])) {
                            echo '<div class="alert alert-success">Record aggiornato!</div>';
                            echo '<p><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '" class="btn">← Torna</a></p>';
                        } else {
                            echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                        }
                    }
                    
                    $pk = get_pk($current_db, $current_table);
                    $row = run_query($current_db, "SELECT * FROM \"" . $current_table . "\" WHERE \"$pk\" = ?", [$id]);
                    if (empty($row)) {
                        echo '<div class="alert alert-error">Record non trovato</div>';
                    } else {
                        $row = $row[0];
                        $info = get_table_info($current_db, $current_table);
                        echo '<h3>Modifica Record</h3><form method="post">';
                        foreach ($info['columns'] as $col) {
                            echo '<div class="form-group">
                                <label>' . e($col['name']) . ' (' . e($col['type']) . ')</label>';
                            if ($col['name'] == $pk) {
                                echo '<input type="text" value="' . e($row[$col['name']]) . '" readonly style="background:#eee;">';
                            } else {
                                echo '<input type="text" name="' . e($col['name']) . '" value="' . e($row[$col['name']]) . '">';
                            }
                            echo '</div>';
                        }
                        echo '<button type="submit" class="btn">Aggiorna</button>
                        <a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '" class="btn">Annulla</a>
                        </form>';
                    }
                    break;

                case 'delete_record':
                    $id = $_GET['id'] ?? null;
                    $result = delete_record($current_db, $current_table, $id);
                    if (isset($result['success'])) {
                        echo '<div class="alert alert-success">Record eliminato!</div>';
                    } else {
                        echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                    }
                    echo '<p><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '" class="btn">← Torna</a></p>';
                    break;

                case 'drop':
                    $result = $is_readonly_object
                        ? drop_object($current_db, 'view', $current_table)
                        : drop_table($current_db, $current_table);
                    if (isset($result['success'])) {
                        echo '<div class="alert alert-success">Oggetto eliminato!</div>';
                        echo '<p><a href="?db=' . urlencode($current_db) . '" class="btn">← Torna al database</a></p>';
                    } else {
                        echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                    }
                    break;

                case 'drop_index':
                    $result = drop_object($current_db, 'index', $_GET['name'] ?? '');
                    if (isset($result['success'])) {
                        echo '<div class="alert alert-success">Indice eliminato!</div>';
                    } else {
                        echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                    }
                    echo '<p><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=structure" class="btn">← Torna</a></p>';
                    break;

                case 'drop_trigger':
                    $result = drop_object($current_db, 'trigger', $_GET['name'] ?? '');
                    if (isset($result['success'])) {
                        echo '<div class="alert alert-success">Trigger eliminato!</div>';
                    } else {
                        echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                    }
                    echo '<p><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=structure" class="btn">← Torna</a></p>';
                    break;

                case 'truncate':
                    $result = truncate_table($current_db, $current_table);
                    if (isset($result['success'])) {
                        echo '<div class="alert alert-success">Tabella svuotata!</div>';
                        echo '<p><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '" class="btn">← Torna</a></p>';
                    } else {
                        echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                    }
                    break;

                case 'import':
                    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['csv_file'])) {
                        $file = $_FILES['csv_file'];
                        if ($file['error'] === UPLOAD_ERR_OK) {
                            $result = import_csv($current_db, $current_table, $file['tmp_name']);
                            if (isset($result['success'])) {
                                echo '<div class="alert alert-success">Importati ' . $result['count'] . ' record!</div>';
                            } else {
                                echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                            }
                        } else {
                            echo '<div class="alert alert-error">Errore nel caricamento</div>';
                        }
                    }
                    
                    echo '<h3>Importa CSV</h3>
                    <p>Il CSV deve avere la prima riga con i nomi delle colonne</p>
                    <form method="post" enctype="multipart/form-data">
                        <div class="form-group">
                            <label>File CSV:</label>
                            <input type="file" name="csv_file" accept=".csv" required>
                        </div>
                        <button type="submit" class="btn">Importa</button>
                        <a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '" class="btn">Annulla</a>
                    </form>';
                    break;

                case 'sql':
                    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['sql'])) {
                        try {
                            $result = run_query($current_db, $_POST['sql']);
                            if (is_array($result)) {
                                echo '<div class="alert alert-success">Query eseguita! Risultati: ' . count($result) . ' righe</div>';
                                if (!empty($result)) {
                                    echo '<table class="data-table"><thead><tr>';
                                    foreach (array_keys($result[0]) as $col) {
                                        echo '<th>' . e($col) . '</th>';
                                    }
                                    echo '</tr></thead><tbody>';
                                    foreach ($result as $row) {
                                        echo '<tr>';
                                        foreach ($row as $val) echo '<td>' . e($val) . '</td>';
                                        echo '</tr>';
                                    }
                                    echo '</tbody></table>';
                                }
                            } else {
                                echo '<div class="alert alert-success">Query eseguita!</div>';
                            }
                        } catch (Exception $e) {
                            echo '<div class="alert alert-error">Errore SQL: ' . e($e->getMessage()) . '</div>';
                        }
                    }
                    
                    echo '<h3>Console SQL</h3>
                    <form method="post">
                        <div class="form-group">
                            <label>Comando SQL:</label>
                            <textarea name="sql" rows="6" placeholder="Inserisci comando SQL..."></textarea>
                        </div>
                        <button type="submit" class="btn">Esegui SQL</button>
                        <a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '" class="btn">Annulla</a>
                    </form>';
                    break;
            }
        }
        ?>
    </div>
</div>

</body>
</html>
