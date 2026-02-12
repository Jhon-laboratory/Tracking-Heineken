<?php
// conexion.php
class Database {
    private $host = "Jorgeserver.database.windows.net";
    private $dbname = "DPL";
    private $username = "Jmmc";
    private $password = "ChaosSoldier01";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        
        $connectionInfo = array(
            "Database" => $this->dbname,
            "UID" => $this->username,
            "PWD" => $this->password,
            "CharacterSet" => "UTF-8",
            "ReturnDatesAsStrings" => true,
            "MultipleActiveResultSets" => false
        );

        try {
            $this->conn = sqlsrv_connect($this->host, $connectionInfo);
            
            if ($this->conn === false) {
                $errors = sqlsrv_errors();
                error_log("Error de conexión SQL Server: " . print_r($errors, true));
                throw new Exception("No se pudo conectar a la base de datos");
            }
        } catch(Exception $e) {
            die("Error de conexión: " . $e->getMessage());
        }
        
        return $this->conn;
    }
}
?>