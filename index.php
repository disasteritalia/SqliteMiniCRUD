<?php
/* Copyright 2025 Disaster.net@gmail.com 
/
/ ------------------------------------------------------------------ /
/ This software is largely coded by a human, but many tasks have     /
/ been outsourced to EURIA, a sovereign artificial intelligence.     /
/ ------------------------------------------------------------------ /  

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS “AS IS” AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

*/

// index.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

$config = [
    'password' => 'admin',             // da cambiare
    'db_dir' => 'la/mia/directory/',   // meglio usare un path assouto /home/user/web/AA_databaseDir
    'app_name' => 'SQLite Admin',      // nome
    'per_page' => 50,                  // numero di record
];

function is_logged() {
    global $config;
    if (empty($config['password'])) return true;
    return isset($_SESSION['logged']) && $_SESSION['logged'];
}

function login($pwd) {
    global $config;
    if ($pwd === $config['password']) {
        $_SESSION['logged'] = true;
        return true;
    }
    return false;
}

function logout() {
    session_destroy();
}

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





// === 1. LOGIN ===
if (isset($_GET['logout'])) {
    logout();
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['password'])) {
    if (login($_POST['password'])) {
        redirect('index.php');
    } else {
        $login_error = 'Password errata!';
    }
}

if (!is_logged()) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Login</title>
    <style>body{font-family:Arial;margin:50px;text-align:center;}
    input{padding:10px;margin:5px;width:200px;}button{padding:10px 20px;}
    .error{color:red;margin:10px;}</style></head><body>
    <h2>Login</h2>';
    if (isset($login_error)) echo '<div class="error">' . $login_error . '</div>';
    echo '<form method="post"><input type="password" name="password" placeholder="Password"><br><br>
    <button type="submit">Login</button></form></body></html>';
    exit;
}

// === 2. VARIABILI ===
$dbs = get_databases();

// Gestione selezione database (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['db'])) {
    $current_db = $_POST['db'];
    redirect('?db=' . urlencode($current_db));
}

// Gestione selezione database (GET)
$current_db = $_GET['db'] ?? null;
$current_table = $_GET['table'] ?? null;
$action = $_GET['action'] ?? 'browse';
$page = max(1, intval($_GET['page'] ?? 1));

// Ottieni tabelle
$tables = [];
$table_info = null;
if ($current_db && file_exists($current_db)) {
    $tables = get_tables($current_db);
    if ($current_table && in_array($current_table, $tables)) {
        $table_info = get_table_info($current_db, $current_table);
    }
}

// Gestione download PRIMA di output
if ($action == 'export_schema' && $current_db) {
    $result = export_schema($current_db);
    if (isset($result['error'])) {
        $schema_error = $result['error'];
    } else {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/html');
        header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
        echo $result['html'];
        exit;
    }
}

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

// === 3. OUTPUT HTML ===
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= e($config['app_name']) ?></title>
    <?php EchoStyle(); ?>
</head>
<body>

<?php render_header($current_db, $current_table, $dbs); ?>

<div class="container">
    <?php if ($current_db): ?>
        <?php render_sidebar($current_db, $current_table, $tables); ?>
    <?php endif; ?>
    
    <div class="content" style="margin-left: <?= $current_db ? '250px' : '0' ?>;">
        <?php if (isset($csv_error)): ?>
            <div class="alert alert-error">Errore CSV: <?= e($csv_error) ?></div>
        <?php endif; ?>
        
        <?php if (isset($schema_error)): ?>
            <div class="alert alert-error">Errore schema: <?= e($schema_error) ?></div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error"><?= e($_GET['error']) ?></div>
        <?php endif; ?>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success"><?= e($_GET['success']) ?></div>
        <?php endif; ?>
        
        <?php
        // === 4. ROUTING ===
        if (!$current_db) {
            // HOME - Nessun DB selezionato
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
                
                render_new_db_form();
                
            } else {
                echo '<h3>Seleziona un database</h3>';
                if (empty($dbs)) {
                    echo '<p>Nessun database trovato nella directory: ' . e($config['db_dir']) . '</p>';
                }
            }
            
        } elseif (!$current_table) {
            // DB SELEZIONATO, MA NESSUNA TABELLA
            if ($action == 'new_table') {
                if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                    $table_name = $_POST['name'];
                    $columns = [];
                    
                    for ($i = 0; $i < count($_POST['col_name']); $i++) {
                        if (!empty($_POST['col_name'][$i])) {
                            $columns[] = [
                                'name' => $_POST['col_name'][$i],
                                'type' => $_POST['col_type'][$i],
                                'pk' => isset($_POST['col_pk'][$i]) && $_POST['col_pk'][$i] == 'on',
                                'ai' => isset($_POST['col_ai'][$i]) && $_POST['col_ai'][$i] == 'on'
                            ];
                        }
                    }
                    
                    if (empty($columns)) {
                        echo '<div class="alert alert-error">Aggiungi almeno una colonna</div>';
                    } else {
                        $result = create_table($current_db, $table_name, $columns);
                        if (isset($result['success'])) {
                            echo '<div class="alert alert-success">Tabella creata!</div>';
                            echo '<p><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($table_name) . '&action=browse" class="btn">Apri tabella</a></p>';
                        } else {
                            echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                        }
                    }
                }
                
                render_new_table_form($current_db);
                
            } else {
                echo '<h3>Database: ' . e(basename($current_db)) . '</h3>';
                echo '<p>Seleziona una tabella dalla sidebar.</p>';
            }
            
        } else {
            // TABELLA SELEZIONATA
            render_table_menu($current_db, $current_table);
            
            // AZIONI
            switch ($action) {
                case 'browse':
                default:
                    render_browse_table($current_db, $current_table, $page);
                    break;
                    
                case 'add_record':
                    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                        $data = $_POST;
                        unset($data['action']);
                        $result = insert_record($current_db, $current_table, $data);
                        if (isset($result['success'])) {
                            echo '<div class="alert alert-success">Record aggiunto!</div>';
                            echo '<p><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=browse" class="btn">← Torna</a></p>';
                        } else {
                            echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                        }
                    }
                    
                    render_add_record_form($current_db, $current_table);
                    break;
                    
                case 'edit':
                    $id = $_GET['id'] ?? null;
                    
                    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                        $data = $_POST;
                        unset($data['action']);
                        $result = update_record($current_db, $current_table, $data, $id);
                        if (isset($result['success'])) {
                            echo '<div class="alert alert-success">Record aggiornato!</div>';
                            echo '<p><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=browse" class="btn">← Torna</a></p>';
                        } else {
                            echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                        }
                    } else {
                        render_edit_form($current_db, $current_table, $id);
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
                    echo '<p><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=browse" class="btn">← Torna</a></p>';
                    break;
                    
                case 'drop':
                    $result = drop_table($current_db, $current_table);
                    if (isset($result['success'])) {
                        echo '<div class="alert alert-success">Tabella eliminata!</div>';
                        echo '<p><a href="?db=' . urlencode($current_db) . '" class="btn">← Torna al database</a></p>';
                    } else {
                        echo '<div class="alert alert-error">Errore: ' . e($result['error']) . '</div>';
                    }
                    break;
                    
                case 'truncate':
                    $result = truncate_table($current_db, $current_table);
                    if (isset($result['success'])) {
                        echo '<div class="alert alert-success">Tabella svuotata!</div>';
                        echo '<p><a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=browse" class="btn">← Torna</a></p>';
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
                    
                    render_import_form($current_db, $current_table);
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
                    
                    render_sql_console($current_db, $current_table);
                    break;
            }
        }
        ?>
    </div>
</div>
</body>
</html>

<?php
//  Funzioni database

function get_databases() {
    global $config;
    $dbs = [];
    $dir = $config['db_dir'];
    
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        return $dbs;
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
        return ['error' => 'Errore connessione'];
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
    
    // Colonne
    $result = $db->query("PRAGMA table_info('" . SQLite3::escapeString($table_name) . "')");
    $columns = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $row;
    }
    
    // Conteggio record
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
    
    if (empty($params)) {
        $result = $db->query($sql);
    } else {
        $stmt = $db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val);
        }
        $result = $stmt->execute();
    }
    
    if (stripos(trim($sql), 'SELECT') === 0) {
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        $db->close();
        return $rows;
    } else {
        $db->close();
        return true;
    }
}

function create_db($name) {
    global $config;
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    if (empty($name)) return ['error' => 'Nome non valido'];
    $path = $config['db_dir'] . '/' . $name . '.db';
    if (file_exists($path)) return ['error' => 'Database già esistente'];
    try {
        $db = new SQLite3($path);
        $db->close();
        return ['success' => true, 'path' => $path, 'name' => $name . '.db'];
    } catch (Exception $e) {
        return ['error' => 'Errore creazione'];
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
        
        $sets = []; $values = [];
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
        
        $output = '';
        $output .= implode(',', array_keys($rows[0])) . "\n";
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

function export_schema($db_path) {
    try {
        $conn = connect_db($db_path);
        if (isset($conn['error'])) return ['error' => $conn['error']];
        $db = $conn['db'];
        
        $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Schema Database: " . basename($db_path) . "</title>
        <style>body{font-family:Arial;margin:20px;} .table{margin-bottom:30px;border:1px solid #ccc;padding:15px;border-radius:5px;}
        .table-name{font-size:18px;font-weight:bold;margin-bottom:10px;color:#0066cc;} 
        .column-row{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:10px;padding:8px;border-bottom:1px solid #eee;}
        .column-header{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:10px;font-weight:bold;background:#f0f0f0;padding:8px;border-radius:3px;}
        .yes{color:green;font-weight:bold;} .no{color:#999;} h1{color:#333;border-bottom:2px solid #0066cc;padding-bottom:10px;}
        </style></head><body>
        <h1>📊 Schema Database: " . basename($db_path) . "</h1>
        <p>Generato il: " . date('Y-m-d H:i:s') . "</p>";
        
        $tables_result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        
        while ($table_row = $tables_result->fetchArray(SQLITE3_ASSOC)) {
            $table = $table_row['name'];
            $html .= "<div class='table'><div class='table-name'>📋 Tabella: " . htmlspecialchars($table) . "</div>";
            $html .= "<div class='column-header'><div>Nome</div><div>Tipo</div><div>PK</div><div>AutoInc</div></div>";
            
            $columns_result = $db->query("PRAGMA table_info('" . $db->escapeString($table) . "')");
            while ($col = $columns_result->fetchArray(SQLITE3_ASSOC)) {
                $html .= "<div class='column-row'>
                    <div>" . htmlspecialchars($col['name']) . "</div>
                    <div>" . htmlspecialchars($col['type']) . "</div>
                    <div class='" . ($col['pk'] ? 'yes' : 'no') . "'>" . ($col['pk'] ? '✓' : '') . "</div>
                    <div class='no'>" . ($col['pk'] && stripos($col['type'], 'INT') !== false ? '?' : '') . "</div>
                </div>";
            }
            $html .= "</div>";
        }
        
        $html .= "</body></html>";
        $db->close();
        
        return [
            'html' => $html,
            'filename' => basename($db_path, '.db') . '_schema_' . date('Y-m-d') . '.html'
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// Funzioni di template e interfaccia

function render_header($current_db = null, $current_table = null, $dbs = []) {
    global $config;
    ?>
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
            <span style="font-size:15px;font-weight:bold;"><?= e($config['app_name']) ?></span>
            <?php if ($current_table): ?>
                <br><small style="font-size:11px;color:#ccc;"><?= e($current_table) ?></small>
            <?php endif; ?>
        </div>
        
        <div class="header-col col-right">
            <a href="?logout=1" class="logout-btn">Logout</a>
        </div>
    </div>
    <?php
}

function render_sidebar($current_db = null, $current_table = null, $tables = []) {
    global $config;
    ?>
    <!-- SIDEBAR -->
    <div class="sidebar">
        <?php if ($current_db): ?>
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
            
            <a href="?db=<?= urlencode($current_db) ?>&action=new_table" class="sidebar-btn">+ Nuova Tabella</a>
            <a href="?db=<?= urlencode($current_db) ?>&action=export_schema" class="sidebar-btn sidebar-btn-green">📋 Scarica Schema</a>
            
        <?php else: ?>
            <h3 style="margin-top:0;font-size:16px;">Database</h3>
            <?php if (empty($dbs = get_databases())): ?>
                <p style="color:#666;font-size:13px;">Nessun database</p>
            <?php else: ?>
                <ul class="table-list">
                    <?php foreach ($dbs as $db): ?>
                        <li style="padding:8px 10px;border-bottom:1px solid #eee;">
                            <a href="?db=<?= urlencode($db['path']) ?>" style="text-decoration:none;color:#0066cc;font-weight:bold;">
                                <?= e($db['name']) ?>
                            </a>
                            <span style="float:right;color:#666;font-size:11px;"><?= format_size($db['size']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            
            <a href="?action=new_db" class="sidebar-btn">+ Nuovo Database</a>
        <?php endif; ?>
    </div>
    <?php
}

function render_new_db_form() {
    ?>
    <div style="max-width:600px;margin:0 auto;padding:20px;background:white;border-radius:5px;box-shadow:0 0 10px rgba(0,0,0,0.1);">
        <h3 style="margin-top:0;">Crea nuovo database</h3>
        <form method="post">
            <div class="form-group">
                <label>Nome database (senza estensione):</label>
                <input type="text" name="name" placeholder="es: mio_database" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:3px;">
                <p style="font-size:12px;color:#666;margin:5px 0 0 0;">Verrà creato con estensione .db</p>
            </div>
            <button type="submit" class="btn">Crea</button>
            <a href="?" class="btn">Annulla</a>
        </form>
    </div>
    <?php
}

function render_new_table_form($current_db) {
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
                            <option value="BLOB">BLOB</option>
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
        newRow.innerHTML = '<div><input type="text" name="col_name[]" placeholder="es: nome" required></div>' +
                          '<div><select name="col_type[]"><option value="INTEGER">INTEGER</option><option value="TEXT" selected>TEXT</option><option value="REAL">REAL</option><option value="BLOB">BLOB</option></select></div>' +
                          '<div><input type="checkbox" name="col_pk[]"></div>' +
                          '<div><input type="checkbox" name="col_ai[]"></div>' +
                          '<div><button type="button" class="btn" onclick="addColumn()" style="padding:4px 8px;font-size:12px;">+</button></div>';
        container.appendChild(newRow);
    }
    </script>
    <?php
}

function render_table_menu($current_db, $current_table) {
    ?>
    <div class="table-menu">
        <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=browse" class="btn">BROWSE</a>
        <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=add_record" class="btn">Inserisci record</a>
        <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=drop" class="btn btn-red" onclick="return confirm('Eliminare tutta la tabella?')">DROP</a>
        <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=truncate" class="btn btn-red" onclick="return confirm('Svuotare tutta la tabella?')">Elimina</a>
        <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=export" class="btn">Esporta</a>
        <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=import" class="btn">Importa</a>
        <a href="?db=<?= urlencode($current_db) ?>&action=sql" class="btn btn-green">Console SQL</a>
    </div>
    <?php
}

function render_add_record_form($current_db, $current_table) {
    $info = get_table_info($current_db, $current_table);
    ?>
    <h3>Aggiungi Record</h3>
    <form method="post">
    <?php 
    foreach ($info['columns'] as $col) {
        if ($col['pk'] == 1 && stripos($col['type'], 'INT') !== false) continue;
        ?>
        <div class="form-group">
            <label><?= e($col['name']) ?> (<?= e($col['type']) ?>)</label>
            <input type="text" name="<?= e($col['name']) ?>" placeholder="<?= e($col['type']) ?>">
        </div>
        <?php
    }
    ?>
    <button type="submit" class="btn">Aggiungi</button>
    <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=browse" class="btn">Annulla</a>
    </form>
    <?php
}

function render_edit_form($current_db, $current_table, $id) {
    $pk = get_pk($current_db, $current_table);
    $row = run_query($current_db, "SELECT * FROM \"" . $current_table . "\" WHERE \"$pk\" = ?", [$id]);
    
    if (empty($row)) {
        echo '<div class="alert alert-error">Record non trovato</div>';
        return;
    }
    
    $row = $row[0];
    $info = get_table_info($current_db, $current_table);
    ?>
    <h3>Modifica Record</h3>
    <form method="post">
    <?php 
    foreach ($info['columns'] as $col) {
        ?>
        <div class="form-group">
            <label><?= e($col['name']) ?> (<?= e($col['type']) ?>)</label>
            <?php if ($col['name'] == $pk): ?>
                <input type="text" value="<?= e($row[$col['name']]) ?>" readonly style="background:#eee;">
            <?php else: ?>
                <input type="text" name="<?= e($col['name']) ?>" value="<?= e($row[$col['name']]) ?>">
            <?php endif; ?>
        </div>
        <?php
    }
    ?>
    <button type="submit" class="btn">Aggiorna</button>
    <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=browse" class="btn">Annulla</a>
    </form>
    <?php
}

function render_import_form($current_db, $current_table) {
    ?>
    <h3>Importa CSV</h3>
    <p>Il CSV deve avere la prima riga con i nomi delle colonne</p>
    <form method="post" enctype="multipart/form-data">
        <div class="form-group">
            <label>File CSV:</label>
            <input type="file" name="csv_file" accept=".csv" required>
        </div>
        <button type="submit" class="btn">Importa</button>
        <a href="?db=<?= urlencode($current_db) ?>&table=<?= urlencode($current_table) ?>&action=browse" class="btn">Annulla</a>
    </form>
    <?php
}

function render_sql_console($current_db, $current_table = null) {
    ?>
    <h3>Console SQL</h3>
    <form method="post">
        <div class="form-group">
            <label>Comando SQL:</label>
            <textarea name="sql" rows="6" style="width:100%;padding:10px;font-family:monospace;" placeholder="Inserisci comando SQL..."></textarea>
        </div>
        <button type="submit" class="btn">Esegui SQL</button>
        <a href="?db=<?= urlencode($current_db) . ($current_table ? '&table=' . urlencode($current_table) . '&action=browse' : '') ?>" class="btn">Annulla</a>
    </form>
    <?php
}

function render_browse_table($current_db, $current_table, $page = 1) {
    global $config;
    $pk = get_pk($current_db, $current_table);
    $limit = $config['per_page'];
    $offset = ($page - 1) * $limit;
    
    try {
        $count = run_query($current_db, "SELECT COUNT(*) as cnt FROM \"" . $current_table . "\"");
        $total = $count[0]['cnt'];
        $pages = ceil($total / $limit);
        
        $rows = run_query($current_db, "SELECT * FROM \"" . $current_table . "\" LIMIT ? OFFSET ?", [$limit, $offset]);
        
        echo '<h4>Record: ' . $total . ' totali</h4>';
        
        if (empty($rows)) {
            echo '<p>Nessun record presente.</p>';
        } else {
            echo '<table class="data-table">
                <thead><tr><th class="actions-cell">Azioni</th>';
            foreach (array_keys($rows[0]) as $col) {
                echo '<th>' . e($col) . '</th>';
            }
            echo '</tr></thead><tbody>';
            
            foreach ($rows as $row) {
                echo '<tr><td class="actions-cell">';
                if ($pk && isset($row[$pk])) {
                    echo '<a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=edit&id=' . urlencode($row[$pk]) . '" title="Modifica">✏️</a> ';
                    echo '<a href="?db=' . urlencode($current_db) . '&table=' . urlencode($current_table) . '&action=delete_record&id=' . urlencode($row[$pk]) . '" onclick="return confirm(\'Eliminare?\')" title="Elimina">🗑️</a>';
                }
                echo '</td>';
                foreach ($row as $val) {
                    echo '<td>' . e($val) . '</td>';
                }
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
    } catch (Exception $e) {
        echo '<div class="alert alert-error">Errore: ' . e($e->getMessage()) . '</div>';
    }
}

function EchoStyle(){
echo '
<!-- Includi CSS direttamente per ora -->
<style>
/* Tutti gli stili CSS vanno qui (o in un file styles.css separato) */
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Arial, sans-serif; }

/* HEADER - 3 COLONNE UGUALI */
.header {
    display: flex;
    background: #333;
    color: white;
    padding: 8px 15px;
    border-bottom: 2px solid #444;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 45px;
    align-items: center;
    z-index: 1000;
}
.header-col {
    flex: 1;
    display: flex;
    align-items: center;
}
.col-left { justify-content: flex-start; }
.col-center { justify-content: center; }
.col-right { justify-content: flex-end; }

/* SELECT DB */
.db-form {
    display: flex;
    gap: 8px;
    align-items: center;
}
.db-select {
    padding: 6px 10px;
    width: 220px;
    border: 1px solid #666;
    border-radius: 3px;
    background: #444;
    color: white;
    font-size: 14px;
}
.btn-go {
    padding: 6px 12px;
    background: #0066cc;
    color: white;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-size: 14px;
}
.btn-go:hover { background: #0055aa; }

/* LOGOUT */
.logout-btn {
    padding: 6px 12px;
    background: #666;
    color: white;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    text-decoration: none;
    font-size: 14px;
}
.logout-btn:hover { background: #555; }

/* NUOVO DB IN HEADER */
.new-db-btn {
    padding: 6px 12px;
    background: #28a745;
    color: white;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    text-decoration: none;
    font-size: 14px;
    margin-left: 10px;
}
.new-db-btn:hover { background: #218838; }

/* LAYOUT */
.container {
    display: flex;
    margin-top: 45px;
    min-height: calc(100vh - 45px);
}

/* SIDEBAR */
.sidebar {
    width: 250px;
    background: #f5f5f5;
    border-right: 1px solid #ddd;
    padding: 15px;
    position: fixed;
    top: 45px;
    bottom: 0;
    left: 0;
    overflow-y: auto;
}

/* CONTENUTO */
.content {
    flex: 1;
    margin-left: 250px;
    padding: 20px;
}

/* TABELLE SIDEBAR */
.table-list {
    list-style: none;
    margin: 0 0 15px 0;
    padding: 0;
}
.table-item {
    border: 1px solid #ddd;
    margin-bottom: 4px;
    background: white;
    border-radius: 3px;
}
.table-item a {
    display: block;
    padding: 8px 10px;
    text-decoration: none;
    color: #333;
    font-size: 13px;
}
.table-item:hover {
    background: #f0f0f0;
}
.table-item.active {
    background: #e6f7ff;
    border-color: #0066cc;
}
.table-count {
    float: right;
    color: #666;
    font-size: 11px;
    background: #eee;
    padding: 2px 6px;
    border-radius: 10px;
}

/* PULSANTI SIDEBAR */
.sidebar-btn {
    display: block;
    width: 100%;
    padding: 8px;
    margin: 8px 0;
    text-align: center;
    background: #0066cc;
    color: white;
    border: none;
    border-radius: 3px;
    text-decoration: none;
    font-size: 13px;
    cursor: pointer;
}
.sidebar-btn:hover { background: #0055aa; }
.sidebar-btn-green {
    background: #28a745;
}
.sidebar-btn-green:hover { background: #218838; }

/* MENU TABELLA */
.table-menu {
    background: #f8f8f8;
    border: 1px solid #ddd;
    padding: 10px;
    margin-bottom: 20px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* TABELLA DATI */
.data-table {
    width: 100%;
    border-collapse: collapse;
    margin: 15px 0;
}
.data-table th, .data-table td {
    border: 1px solid #ccc;
    padding: 8px;
    text-align: left;
}
.data-table th {
    background: #f0f0f0;
    cursor: pointer;
}
.data-table tr:hover {
    background: #f9f9f9;
}

/* AZIONI */ 
.actions-cell {
    width: 70px;
    white-space: nowrap;
}

/* BOTTONI */
.btn {
    padding: 6px 12px;
    background: #0066cc;
    color: white;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    text-decoration: none;
    font-size: 13px;
    display: inline-block;
}
.btn:hover { background: #0055aa; }
.btn-red {
    background: #dc3545;
}
.btn-red:hover { background: #c82333; }
.btn-green {
    background: #28a745;
}
.btn-green:hover { background: #218838; }

/* FORM */
.form-group {
    margin-bottom: 15px;
}
.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}
.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 3px;
}

/* MESSAGGI */
.alert {
    padding: 10px 15px;
    margin: 10px 0;
    border-radius: 3px;
}
.alert-error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}
.alert-success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

/* PAGINAZIONE */
.pagination {
    margin: 20px 0;
    text-align: center;
}
.pagination a {
    padding: 5px 10px;
    margin: 0 2px;
    border: 1px solid #ddd;
    text-decoration: none;
    color: #333;
}
.pagination a.active {
    background: #0066cc;
    color: white;
    border-color: #0066cc;
}

/* CREAZIONE TABELLA */
.column-form {
    background: #f9f9f9;
    padding: 15px;
    margin: 15px 0;
    border: 1px solid #ddd;
    border-radius: 3px;
}
.column-row {
    display: grid;
    grid-template-columns: 2fr 1.5fr 1fr 1fr 0.5fr;
    gap: 8px;
    margin-bottom: 8px;
    align-items: center;
    padding: 8px;
    background: white;
    border: 1px solid #eee;
}
.column-header {
    display: grid;
    grid-template-columns: 2fr 1.5fr 1fr 1fr 0.5fr;
    gap: 8px;
    margin-bottom: 8px;
    font-weight: bold;
    color: #555;
    font-size: 13px;
}
</style>';
}

?>
