<?php
namespace App\Service;
use PDO;
final class Database
{
    private ?PDO $pdo=null;
    public function __construct(private string $host,private int $port,private string $name,private string $user,private string $password) {}
    public function connection(): PDO
    {
        return $this->pdo ??= new PDO("mysql:host={$this->host};port={$this->port};dbname={$this->name};charset=utf8mb4",$this->user,$this->password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    }
    public function install(): void
    {
        $sql=file_get_contents(dirname(__DIR__,2).'/config/schema.sql');
        foreach (explode(';',$sql) as $statement) { if (trim($statement)) { $this->connection()->exec($statement); } }
    }
}
