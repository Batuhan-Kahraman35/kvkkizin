<?php
/**
 * Veritabanı Bağlantı Sınıfı
 * Portal Örnek Yazılım - MSSQL
 */

class Database {
    private static $instance = null;
    private $conn;
    private $config;
    
    private function __construct() {
        $this->config = require __DIR__ . '/../config/database.php';
        $this->connect();
    }
    
    private function connect() {
        $db = $this->config['connections']['sqlsrv'];
        
        $serverName = $db['host'];
        $connectionInfo = [
            "Database" => $db['database'],
            "UID" => $db['username'],
            "PWD" => $db['password'],
            "CharacterSet" => "UTF-8"
        ];
        
        $this->conn = sqlsrv_connect($serverName, $connectionInfo);
        
        if ($this->conn === false) {
            die(json_encode([
                'success' => false,
                'message' => 'Veritabanı bağlantısı başarısız: ' . print_r(sqlsrv_errors(), true)
            ]));
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->debugModuUygula();
        }
        return self::$instance;
    }

    /**
     * Site ayarlarındaki Debug Modu'na göre hata gösterimini ayarlar
     * site_ayarlari_debug_mode = 1 → hatalar ekranda gösterilir
     */
    private function debugModuUygula() {
        try {
            $row = $this->fetchOne("SELECT TOP 1 site_ayarlari_debug_mode FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
            if ($row && (int)$row['site_ayarlari_debug_mode'] === 1) {
                error_reporting(E_ALL);
                ini_set('display_errors', '1');
            } else {
                ini_set('display_errors', '0');
            }
        } catch (Throwable $e) {
            // Ayar okunamazsa varsayılan davranış korunur
        }
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    public function query($sql, $params = []) {
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        
        if ($stmt === false) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $caller = isset($trace[1]) ? ($trace[1]['file'] ?? '?') . ':' . ($trace[1]['line'] ?? '?') : 'unknown';
            error_log('SQL Hatası [' . $caller . ']: ' . print_r(sqlsrv_errors(), true));
            return false;
        }
        
        return $stmt;
    }
    
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        if ($stmt === false) return null;
        
        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        if (!$result) return $result;

        // NVARCHAR(MAX) gibi alanlar PHP stream, DATETIME alanlar DateTime nesnesi olarak gelebilir
        foreach ($result as $key => $value) {
            if (is_resource($value)) {
                $result[$key] = stream_get_contents($value);
            } elseif ($value instanceof DateTime) {
                $result[$key] = $value->format('Y-m-d H:i:s');
            }
        }

        return $result;
    }
    
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        if ($stmt === false) return [];
        
        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // NVARCHAR(MAX) gibi alanlar PHP stream, DATETIME alanlar DateTime nesnesi olarak gelebilir
            foreach ($row as $key => $value) {
                if (is_resource($value)) {
                    $row[$key] = stream_get_contents($value);
                } elseif ($value instanceof DateTime) {
                    $row[$key] = $value->format('Y-m-d H:i:s');
                }
            }
            $results[] = $row;
        }
        
        sqlsrv_free_stmt($stmt);
        return $results;
    }
    
    public function execute($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        if ($stmt === false) return false;
        
        sqlsrv_free_stmt($stmt);
        return true;
    }
    
    public function getLastInsertId() {
        $result = $this->fetchOne("SELECT @@IDENTITY as id");
        return $result ? $result['id'] : null;
    }
    
    /**
     * INSERT işlemi
     * @param string $table Tablo adı
     * @param array $data Kolon => Değer dizisi
     * @return bool|int Başarılıysa son eklenen ID, hata varsa false
     */
    public function insert($table, $data) {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($data), '?');
        
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = sqlsrv_query($this->conn, $sql, array_values($data));
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log('INSERT Hatası: ' . print_r($errors, true));
            throw new Exception('Kayıt eklenirken hata oluştu: ' . $errors[0]['message']);
        }
        
        sqlsrv_free_stmt($stmt);
        return $this->getLastInsertId();
    }
    
    /**
     * UPDATE işlemi
     * @param string $table Tablo adı
     * @param array $data Güncellenecek kolon => değer dizisi
     * @param array $where Koşul dizisi (kolon => değer)
     * @return bool Başarı durumu
     */
    public function update($table, $data, $where) {
        $setClauses = [];
        $params = [];
        
        foreach ($data as $column => $value) {
            $setClauses[] = "{$column} = ?";
            $params[] = $value;
        }
        
        $whereClauses = [];
        foreach ($where as $column => $value) {
            $whereClauses[] = "{$column} = ?";
            $params[] = $value;
        }
        
        $sql = "UPDATE {$table} SET " . implode(', ', $setClauses) . " WHERE " . implode(' AND ', $whereClauses);
        
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log('UPDATE Hatası: ' . print_r($errors, true));
            throw new Exception('Kayıt güncellenirken hata oluştu: ' . $errors[0]['message']);
        }
        
        sqlsrv_free_stmt($stmt);
        return true;
    }
    
    /**
     * DELETE işlemi
     * @param string $table Tablo adı
     * @param array $where Koşul dizisi (kolon => değer)
     * @return bool Başarı durumu
     */
    public function delete($table, $where) {
        $whereClauses = [];
        $params = [];
        
        foreach ($where as $column => $value) {
            $whereClauses[] = "{$column} = ?";
            $params[] = $value;
        }
        
        $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $whereClauses);
        
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log('DELETE Hatası: ' . print_r($errors, true));
            throw new Exception('Kayıt silinirken hata oluştu: ' . $errors[0]['message']);
        }
        
        sqlsrv_free_stmt($stmt);
        return true;
    }
    
    /**
     * SQL metnindeki -- ve /* *\/ yorumlarını siler; '...' string ve [...] adların içine dokunmaz.
     */
    private static function yorumlariTemizle(string $sql): string {
        $out = '';
        $n = strlen($sql);
        for ($i = 0; $i < $n; $i++) {
            $c = $sql[$i];
            $s = $sql[$i + 1] ?? '';
            if ($c === "'" || $c === '[') {
                $kapat = $c === "'" ? "'" : ']';
                $j = $i + 1;
                while ($j < $n) {
                    if ($sql[$j] === $kapat) {
                        if (($sql[$j + 1] ?? '') === $kapat) { $j += 2; continue; } // '' veya ]] kaçışı
                        break;
                    }
                    $j++;
                }
                $out .= substr($sql, $i, $j - $i + 1);
                $i = $j;
            } elseif ($c === '-' && $s === '-') {
                while ($i < $n && $sql[$i] !== "\n" && $sql[$i] !== "\r") $i++;
                $i--;
            } elseif ($c === '/' && $s === '*') {
                $son = strpos($sql, '*/', $i + 2);
                $i = $son === false ? $n : $son + 1;
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    /**
     * Ham SQL çalıştır (GO komutuyla ayrılmış çoklu sorgular için)
     * @param string $sql SQL komutları
     * @return bool Başarı durumu
     */
    public function executeRaw($sql) {
        // Yorumları temizle (string ve [köşeli] adların içindeki -- ve /* */ korunur)
        $sql = self::yorumlariTemizle($sql);
        
        // GO komutlarını ayır
        $batches = preg_split('/^\s*GO\s*$/im', $sql);
        
        // PRINT ve bilgi mesajlarının (severity < 10) hata sayılmasını geçici olarak devre dışı bırak
        // Aksi hâlde sqlsrv_query PRINT ifadelerini hata olarak döndürür
        sqlsrv_configure("WarningsReturnAsErrors", 0);
        
        try {
            foreach ($batches as $batch) {
                $batch = trim($batch);
                if (empty($batch)) continue;
                
                // USE [database] komutlarını atla
                if (preg_match('/^\s*USE\s+\[/i', $batch)) {
                    continue;
                }
                
                // Batch sp_executesql'e parametre olarak verilir: ODBC sürücüsü metni ayrıştırmaz,
                // string içindeki ? (parametre işareti) ve { } (kaçış dizisi) hata üretmez
                $stmt = sqlsrv_query($this->conn, 'EXEC sp_executesql ?', [
                    [$batch, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING('UTF-8'), SQLSRV_SQLTYPE_NVARCHAR('max')]
                ]);
                
                if ($stmt === false) {
                    // Yalnızca gerçek hataları al (uyarı/bilgi mesajlarını değil)
                    $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
                    error_log('SQL Batch Hatası: ' . print_r($errors, true));
                    throw new Exception('SQL çalıştırılırken hata oluştu: ' . ($errors[0]['message'] ?? 'Bilinmeyen hata'));
                }
                
                sqlsrv_free_stmt($stmt);
            }
        } finally {
            // Her durumda eski ayarı geri yükle
            sqlsrv_configure("WarningsReturnAsErrors", 1);
        }
        
        return true;
    }
    
    public function __destruct() {
        if ($this->conn) {
            sqlsrv_close($this->conn);
        }
    }
}
