<?php
namespace ClickHouseDB;

class Client
{
    /**
     * @var Transport\Http
     */
    private $_transport=false;
  
    private $_connect_username=false;
    private $_connect_password=false;
    private $_connect_host=false;
    private $_connect_port=false;
    private $_connect_uri=false;
    public function __construct($connect_params,$settings=[])
    {
        if (!isset($connect_params['username']))  throw  new \InvalidArgumentException('not set username');
        if (!isset($connect_params['password']))  throw  new \InvalidArgumentException('not set password');
        if (!isset($connect_params['port']))  throw  new \InvalidArgumentException('not set port');
        if (!isset($connect_params['host']))  throw  new \InvalidArgumentException('not set host');

        if (isset($connect_params['settings']) && is_array($connect_params['settings']))
        {
            if (empty($settings))
            {
                $settings=$connect_params['settings'];
            }
        }

        $this->_connect_username=$connect_params['username'];
        $this->_connect_password=$connect_params['password'];
        $this->_connect_port=$connect_params['port'];
        $this->_connect_host=$connect_params['host'];
        $this->settings()->database('default');
        if (sizeof($settings))
        {
            $this->settings()->apply($settings);
        }

    }

    /**
     * @return Transport\Http
     */
    public function transport()
    {
        if (!$this->_transport)
        {
            $this->_transport=new \ClickHouseDB\Transport\Http(
                $this->_connect_host,$this->_connect_port,$this->_connect_username,$this->_connect_password
            );
        }
        return $this->_transport;
    }

    /**
     * @param int $max_time_out
     * @param bool $changeHost
     * @return array
     * @throws \QueryException
     */
    public function findActiveHostAndCheckCluster($max_time_out=2,$changeHost=true)
    {
        $hostsips=$this->transport()->getHostIPs();
        $selectHost = false;
        if (sizeof($hostsips)>1)
        {
            list($resultGoodHost,$resultBadHost)=$this->transport()->checkServerReplicas($hostsips,$max_time_out);

            if (!sizeof($resultGoodHost)) throw new QueryException("All host is down:".json_encode($resultBadHost));

            // @todo : add make some

            if ($changeHost && sizeof($resultGoodHost))
            {
                $selectHost=array_rand($resultGoodHost);
                $this->transport()->setHost($selectHost);
            }
        }
        else
        {
            return [[$this->_connect_host=>1],[],false];
        }


        return [$resultGoodHost,$resultBadHost,$selectHost];

    }

    public function verbose()
    {
        return $this->transport()->verbose(true);
    }
    /**
     * @return Settings
     */
    public function settings()
    {
        return $this->transport()->settings();
    }

    public function write($sql,$bindings=[],$exception=true)
    {
        return $this->transport()->write($sql,$bindings,$exception);
    }
    /**
     *
     */
    public function database($db)
    {
        $this->settings()->database($db);
    }

    public function enableHttpCompression($flag=true)
    {
        $this->settings()->enableHttpCompression($flag);
    }
    /**
     * @param $sql
     * @param array $bindings
     * @return Statement
     */
    public function select($sql,$bindings = [],$whereInFile=null)
    {
        return $this->transport()->select($sql,$bindings,$whereInFile);
    }

    /**
     * @return bool
     */
    public function executeAsync()
    {
        return $this->transport()->executeAsync();
    }

    /**
     * @param $sql
     * @param array $bindings
     * @param bool $query_id
     * @return Statement
     */
    public function selectAsync($sql,$bindings=[],$whereInFile=null)
    {
        return $this->transport()->selectAsync($sql,$bindings,$whereInFile);
    }

    /**
     * @return array
     */
    public function showProcesslist()
    {
        return $this->select('SHOW PROCESSLIST')->rows();
    }

    /**
     * @return array
     */
    public function showDatabases()
    {
        return $this->select('show databases')->rows();
    }

    /**
     * @return array
     */
    public function showTables()
    {
        return $this->select('SHOW TABLES')->rows();
    }



    /**
     * @param array $row
     * @return array
     */
    protected function quote(array $row)
    {
        $quote = function ($value) {

            $enclosure="'";
            $delimiter=',';
            $delimiter_esc = preg_quote($delimiter, '/');
            $enclosure_esc = preg_quote($enclosure, '/');
            $type=gettype($value);

            if ($type== 'integer' || $type == 'double') {
                return strval($value);
            }

            if (is_string($value) ) {
                if (preg_match( "/(?:${delimiter_esc}|${enclosure_esc}|\s)/", $value ))
                {
                    return $enclosure . str_replace($enclosure, '\\' . $enclosure, $value) . $enclosure;
                }
                return $enclosure . strval($value) . $enclosure;
            }
            if (is_array($value)) {
                // Массивы форматируются в виде списка значений через запятую в квадратных скобках.
                // Элементы массива - числа форматируются как обычно, а даты, даты-с-временем и строки - в одинарных кавычках с такими же правилами экранирования, как указано выше.
                // Массивы сериализуются в CSV следующим образом: сначла массив сериализуется в строку,
                // как в формате TabSeparated, а затем полученная строка выводится в CSV в двойных кавычках.

                $value=$this->quote($value);
                $result_array=implode($delimiter,$value);
                return "[" . $result_array . "]";

            }

            if (null === $value)
                return '';

            return $value;
        };
        return array_map($quote, $row);
    }

    public function getCountPendingQueue()
    {
        return $this->transport()->getCountPendingQueue();
    }
    public function insert($table,  $values,$columns = [])
    {
        $sql = 'INSERT INTO ' . $table;

        if (0 !== count($columns)) {
            $sql .= ' (' . implode(',', $columns) . ') ';
        }

        $sql .= 'VALUES ';

        foreach ($values as $row) {
            $sql .= ' (' . implode(',', $this->quote($row)) . '), ';
        }
        $sql = trim($sql, ', ');

        return $this->transport()->write($sql);
    }

    /**
     * @param $table_name
     * @param $file_names
     * @param $columns_array
     * @return array
     * @throws QueryException
     */
    public function insertBatchFiles($table_name, $file_names, $columns_array)
    {
        if ($this->getCountPendingQueue()>0)
        {
            throw new QueryException("Queue must be empty, before insertBatch,need executeAsync");
        }


        $result=[];

        foreach ($file_names as $fileName)
        {
            if (!is_file($fileName) || !is_readable($fileName)) {
                throw  new QueryException("Cant read file:".$fileName);
            }
            $sql='INSERT INTO '.$table_name.' ( '.implode(",",$columns_array).' ) FORMAT CSV ';
            $result[$fileName]=$this->transport()->writeAsyncCSV($sql,$fileName);
        }
        
        // exec
        $exec=$this->executeAsync();

        // fetch resutl
        foreach ($file_names as $fileName)
        {
            if ($result[$fileName]->isError())
            {
                $result[$fileName]->error();
            }
        }
        return $result;


    }

    /**
     * @return mixed|null
     */
    public function databaseSize()
    {
        $b=$this->settings()->getDatabase();
        return $this->select('
            SELECT database,formatReadableSize(sum(bytes)) as size
            FROM system.parts
            WHERE active AND database=:database
            GROUP BY database
',['database'=>$b])->fetchOne();
    }

    /**
     * @param $tableName
     * @return mixed
     */
    public function tableSize($tableName)
    {
        $tables=$this->tablesSize();
        if (isset($tables[$tableName])) return $tables[$tableName];
    }

    /**
     * ping & connect
     *
     * @return array
     */
    public function ping()
    {
        return $this->select("SELECT 1 as ping")->rows();
    }

    /**
     * @return array
     */
    public function tablesSize()
    {
        return $this->select('
SELECT table,
formatReadableSize(sum(bytes)) as size,
min(min_date) as min_date,
max(max_date) as max_date
FROM system.parts
WHERE active
GROUP BY table
')->rowsAsTree('table');

    }

    /**
     * @param $table
     * @param int $limit
     * @return array
     */
    public function partitions($table,$limit=-1)
    {
        return $this->select(
            '
            SELECT *
            FROM system.parts 
            WHERE like(table,\'%'.$table.'%\')  
            ORDER BY max_date '.($limit>0?' LIMIT '.intval($limit):'')
        )->rowsAsTree('name');
    }


    public function dropPartition($tableName,$partition_id)
    {
        $state=$this->write('ALTER TABLE {tableName} DROP PARTITION :partion_id',
            [
                'tableName'=>$tableName,
                'partion_id'=>$partition_id
            ]
            );
        if ($state->isError()) $state->error();
    }
    /**
     * @param $table_name
     * @param $days_ago
     * @param int $count_partitons_per_one
     */
    public function dropOldPartitions($table_name,$days_ago,$count_partitons_per_one=100)
    {
        $days_ago=strtotime(date('Y-m-d 00:00:00',strtotime('-'.$days_ago.' day')));
        $drop=[];
        $list_patitions=$this->partitions($table_name,$count_partitons_per_one);
        foreach ($list_patitions as $partion_id=>$partition)
        {
            if (stripos($partition['engine'],'mergetree')===false) continue;
            $min_date=strtotime($partition['min_date']);
            $max_date=strtotime($partition['max_date']);
            if ($max_date<$days_ago)
            {
                $drop[]=$partition['partition'];
            }
        }


        foreach ($drop as $partition_id)
        {
            $this->dropPartition($table_name,$partition_id);
        }
        return $drop;
    }

}