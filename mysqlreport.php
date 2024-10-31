<?php

interface mysqlreport_driver
{
  /**
   * return the db results as Array
   * such as
   * 
   *  ------------
   * | id | name  |
   * |------------
   * | 1  | robbin|
   *  ------------
   */
  public function dbResults($sql);
  
  /**
   * a http link for mysql report guide
   * e.g, http://hackmysql.com/mysqlreportguide
   */
  public function reportGuideUrl();
}

class mysqlreport_version
{
	public $major ;
	public $minor ;
	public $patch ;
	
	public function __construct(
		$theMajor = 0,
		$theMinor = 0,
		$thePatch = 0) {
		$this->major = $theMajor ;
		$this->minor = $theMinor ;
		$this->patch = $thePatch ;
	}
	
	public function __toString() {
  	return sprintf('%02d%02d%02d', $this->major, $this->minor, $this->patch) ;
  }
  
  public function checkVersion(
  	$theDesiredVersion,
  	$theOperation = '==') {
  	$c = $this->__toString() ;
  	$d = $theDesiredVersion->__toString() ;
  	
  	$xxx = 'return $c' . $theOperation . '$d ;' ;

   	return eval($xxx) ;
  }
}


class mysqlreport 
{
  /**
   *
   * @var mysqlreport_driver
   */
  private $driver;
  
  /**
   *
   * @var mysqlreport_version 
   */
  private $version;
  
  /**
   * output data
   * @var Array 
   */
  private $out;
  
  private function _init_version() {
    $v = array_pop($this->driver->dbResults("SHOW VARIABLES LIKE 'VERSION'")) ;
    $v = $v->Value ;
    
    preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{1,2})/', $v, $matches) ;
    array_shift($matches);
    $v = new mysqlreport_version($matches[0], $matches[1], $matches[2]) ;
    
    return $v ;
  }
  
  private function _my_status() {
    $v = new stdClass() ;

    if ($this->version->checkVersion(new mysqlreport_version(5, 0, 2), '<')) {
      $query = "show status" ;
    } else {
      $query = "show global status" ;
    }

    $status = $this->driver->dbResults($query);
    foreach ($status as $item) {
      $xxx = $item->Variable_name ;
      $v->$xxx = $item->Value ;
    }

    //var_dump($query);
    return $v ;
  }
  
  private function _my_variables(){
    $v = new stdClass() ;

    $results = $this->driver->dbResults("show variables") ;

    foreach ($results as $item) {
      $xxx = $item->Variable_name ;
      $v->$xxx = $item->Value ;
    }
    return $v ;
  }
  
  private function _my_uptime($theUptime) {
    $days = (int)( $theUptime / 86400 ) ;
    $theUptime %= 86400 ;

    $hours = (int)( $theUptime / 3600 ) ;
    $theUptime %= 3600 ;

    $minutes = (int)( $theUptime / 60 ) ;
    $seconds = $theUptime % 60 ;

    $xxx = sprintf('%d days %02d:%02d:%02d', $days, $hours, $minutes, $seconds) ;

    return $xxx ;
  }
  
  private function _myisam(&$theStatus, &$theVariables) {
    $myisam = new stdClass() ;

    $myisam->questions = $theStatus->Questions ;

    $myisam->keyReadRatio =
      $theStatus->Key_read_requests ?
        ( 1.0 - ( $theStatus->Key_reads / $theStatus->Key_read_requests ) ) * 100.0 : 
        0 ;

    $myisam->keyWriteRatio =
      $theStatus->Key_write_requests ?
        ( 1.0 - ( $theStatus->Key_writes / $theStatus->Key_write_requests ) ) * 100.0 : 
        0 ;

    $myisam->keyCacheBlockSize =
      isset($theVariables->key_cache_block_size) ?
        $theVariables->key_cache_block_size :
        1024 ;

    $myisam->keyBufferUsed =
      $theStatus->Key_blocks_used * $myisam->keyCacheBlockSize ;

    $myisam->keyBufferUsage =
      isset($theStatus->Key_blocks_unused) ?
        $theVariables->key_buffer_size - ( $theStatus->Key_blocks_unused * $myisam->keyCacheBlockSize ) :
        -1 ;

    // Data Manipulation Statements: http://dev.mysql.com/doc/refman/5.0/en/data-manipulation.html

    $myisam->DMS =
      array(
        'SELECT' => $theStatus->Com_select,
        'INSERT' => $theStatus->Com_insert + $theStatus->Com_insert_select,
        'REPLACE' => $theStatus->Com_replace + $theStatus->Com_replace_select,
        'UPDATE' => $theStatus->Com_update + ( isset($theStatus->Com_update_multi) ? $theStatus->Com_update_multi : 0 ),
        'DELETE' => $theStatus->Com_delete + ( isset($theStatus->Com_delete_multi) ? $theStatus->Com_delete_multi : 0 ),
        ) ;

    $myisam->DMS['DMS'] = array_sum($myisam->DMS) ;

    arsort($myisam->DMS);

    $myisam->slowQueryTime = $theVariables->long_query_time ;

    return $myisam ;
  }

  private function _innodb( &$theStatistics, &$theVariables) {
    $innodb = new stdClass() ;

    if ($this->version->checkVersion(new mysqlreport_version(5, 0, 2), '<')) {
      return $innodb ;
    }

    $innodb->bufferPoolUsed =
      ( $theStatistics->Innodb_buffer_pool_pages_total - $theStatistics->Innodb_buffer_pool_pages_free ) *
      $theStatistics->Innodb_page_size ;

    $innodb->bufferPoolTotal =
      $theStatistics->Innodb_buffer_pool_pages_total * $theStatistics->Innodb_page_size ;

    $innodb->bufferPoolReadRatio =
      $theStatistics->Innodb_buffer_pool_read_requests != 0 ? 
        ( 1.0 - ( $theStatistics->Innodb_buffer_pool_reads / $theStatistics->Innodb_buffer_pool_read_requests ) ) : 
        0 ;

    return $innodb ;
  }
  
  private function _make_short($number, $kb = FALSE, $d = 2) {
    $n = 0 ;
    $xxx= array() ;

    if ($kb) { 
      $xxx = array('b','Kb','Mb','Gb','Tb') ;
      while ($number > 1023) { 
        $number /= 1024; $n++; 
      }; 
    } else { 
      $xxx = array('','K','M','G','T') ;
      while ($number > 999) { 
        $number /= 1000; $n++; 
      }; 
    }

    $short = sprintf("%.${d}f%s", $number, $xxx[$n]) ;

    if (preg_match('/^(.+)\.00$/', $short, $matches)) {
      return $matches[1] ; // Convert 12.00 to 12, but not 12.00kb to 12kb
    }

    return $short;
  }

  private function _toPercentage($theFraction, $theInteger = FALSE) {
    if ($theInteger) {
      return (int) $theFraction * 100 ;
    }

    return floatval($theFraction * 100) ;
  }
  
  /**
   * @param type $tail
   * @return String the guide URL
   */
  private function _guide($tail) {
  
    return $this->driver->reportGuideUrl() . $tail;
  }

  function report_uptime($uptime) {

    $date = date('d-M-Y H:m:s') ;
    $xxx  = sprintf('%d.%d.%d', $this->version->major, $this->version->minor, $this->version->patch);

    $this->out[] = 
      sprintf(
        'MySQL %-16s uptime %-12s %24s',
        $xxx,
        $uptime,
        $date) ;

    return $this;
  }
  
  function report_write_key_buff_max(&$theVariables, &$theMyisam) {
    $xxx = $this->_guide('#key_report');

    $this->out[] = 
      sprintf(
        '__ <a target="_blank" href="%s">Key</a> _________________________________________________________________',
        $xxx) ;

    $this->out[] =
      sprintf(
        'Buffer used    % 7s of % 7s %%Used: %6.2f',
        $this->_make_short($theMyisam->keyBufferUsed, TRUE),
        $this->_make_short($theVariables->key_buffer_size, TRUE),
        $this->_toPercentage($theMyisam->keyBufferUsed / $theVariables->key_buffer_size)) ;

    return $this ;
  }
  
  function report_write_key_buff_usage(&$theVariables, &$theMyisam) {

    if ($theMyisam->keyBufferUsage == -1) {
      return $this ;
    }

    $this->out[] =
      sprintf(
        '  Current      % 7s            %%Usage: %6.2f',
        $this->_make_short($theMyisam->keyBufferUsage, TRUE),
        $this->_toPercentage($theMyisam->keyBufferUsage / $theVariables->key_buffer_size)) ;

    return $this ;
  }

  function report_write_key_ratios(&$theMyisam) {
	
    $this->out[] = sprintf('Write hit      %6.2f%%', $theMyisam->keyWriteRatio) ;
    $this->out[] = sprintf('Read hit       %6.2f%%', $theMyisam->keyReadRatio) ;
    return $this ;
  }

  function report_write_questions(&$theStatus, &$theMyisam){
    $this->out[] = 
      sprintf(
        '__ <a target="_blank" href="%s">Questions</a> ___________________________________________________________',
        $this->_guide('#questions_report')) ;

    $this->out[] =
      sprintf(
        "Total       %9s  %6.2f/s",
        $this->_make_short($theMyisam->questions),
        floatval($theMyisam->questions) / floatval($theStatus->Uptime)) ;

    return $this;
  }


  // Write DTQ report in descending order by values
  function report_write_DTQ($theStatus, $theVariables, $theMyisam) {
    /*
     * format string is name, value, value, label, percentage
     */
    $dtq_format = '  %-8s  %9s  %6.2f/s  %7s %6.2f' ;

    /*
     * Calculate the Total Com values
     */
    $theStatus->DTQ['Com_'] = 0 ;
    $theStatus->COM = array() ;

    $xxx = get_object_vars($theStatus) ;
    foreach (array_keys($xxx) as $k) {
      if (preg_match('/^Com_(.*)/', $k, $matches)) {
        $theStatus->COM[$matches[1]] = $xxx[$k] ;
      }
    }

    arsort($theStatus->COM) ;

    unset($theStatus->COM{'select'}) ;
    unset($theStatus->COM{'insert'}) ;
    unset($theStatus->COM{'insert_select'}) ;
    unset($theStatus->COM{'replace'}) ;
    unset($theStatus->COM{'replace_select'}) ;
    unset($theStatus->COM{'update'}) ;
    unset($theStatus->COM{'update_multi'}) ;
    unset($theStatus->COM{'delete'}) ;
    unset($theStatus->COM{'delete_multi'}) ;

    $theStatus->DTQ['Com_'] = array_sum($theStatus->COM) ;

    $theStatus->DTQ['DMS'] = $theMyisam->DMS['DMS'] ;

    if ($theStatus->Qcache_hits != 0) {
      $theStatus->DTQ['QC Hits'] = $theStatus->Qcache_hits ;
    }

    $theStatus->DTQ['COM_QUIT'] = ( $theStatus->Connections - 2 ) - (int)( $theStatus->Aborted_clients / 2 ) ;

    $xxx = array_sum($theStatus->DTQ);

    if ($theMyisam->questions != $xxx) {
      if ($theMyisam->questions > $xxx) {
        $theStatus->DTQ['+Unknown'] = abs($theMyisam->questions - $xxx) ;
      } else {
        $theStatus->DTQ['-Unknown'] = abs($theMyisam->questions - $xxx) ;
      }
    }

    arsort($theStatus->DTQ) ;

    $first = TRUE ;

    foreach ($theStatus->DTQ as $k => $v) {
      $this->out[] =
        sprintf(
          $dtq_format,
          $k,
          $this->_make_short($v),
          ($v / $theStatus->Uptime),
          ($first ? '%Total:' : ''),
          $this->_toPercentage($v / $theMyisam->questions)) ;
      $first = FALSE ;
    }

    return $this ;
  }
  
  function report_write_slow_DMS(&$theStatistics, &$theVariables, &$theMyisam) {
    $this->out[] =
      sprintf(
        "Slow %-8s %7s  %6.2f/s          %6.2f  %%DMS: %6.2f  Log: %s",
        sprintf('%ds', $theMyisam->slowQueryTime),
        $this->_make_short($theStatistics->Slow_queries),
        $theStatistics->Slow_queries / $theStatistics->Uptime,
        $this->_toPercentage($theStatistics->Slow_queries / $theMyisam->questions),
        $this->_toPercentage($theStatistics->Slow_queries / $theMyisam->DMS['DMS']),
        $theVariables->log_slow_queries) ;

    return $this ;
  }
  
// Write DMS report in descending order by values
function report_write_DMS(&$theStatus, &$theMyisam) {
    $format_dms = '%-10s  %9s  %6.2f/s          %6.2f' ;
    $format = '  %-8s  %9s  %6.2f/s          %6.2f        %6.2f' ;

    foreach ($theMyisam->DMS as $k => $v) {
      if ($k == 'DMS') {
        $this->out[] =
          sprintf(
            $format_dms,
            $k,
            $this->_make_short($v),
            $this->_toPercentage($v / $theStatus->Uptime),
            $this->_toPercentage($v / $theMyisam->questions)) ;
      } else {
        $this->out[] =
          sprintf(
            $format,
            $k,
            $this->_make_short($v),
            $this->_toPercentage($v / $theStatus->Uptime),
            $this->_toPercentage($v / $theMyisam->questions),
            $this->_toPercentage($v / $theMyisam->DMS['DMS'])) ;
      }
    }

    return $this ;
  }

  // Write COM report in descending order by values
  function report_write_COM(&$theStatus, &$theMyisam) {
    $format_1 = 'Com_        %9s  %6.2f/s          %6.2f' ;
    $format_2 = '  %-11s %7s  %6.2f/s          %6.2f' ;

    $this->out[] =
      sprintf(
        $format_1,
        $this->_make_short($theStatus->DTQ['Com_']),
        $this->_make_short($theStatus->DTQ['Com_'] / $theStatus->Uptime),
        $this->_toPercentage($theStatus->DTQ['Com_'] / $theMyisam->DMS['DMS'])) ;

    $i = 3; //variable_get('mysqlreport_number_of_COM_status', 3) ;

    foreach ($theStatus->COM as $k => $v) {
      $this->out[] =
        sprintf(
          $format_2,
          $k,
          $this->_make_short($v),
          ($v / $theStatus->Uptime),
          $this->_toPercentage($v / $theMyisam->questions)) ;

      if (--$i == 0) {
        return $this ;
      }
    }

    return $this ;
  }

  function report_write_qcache(&$theStatus, &$theVariables) {
    if (  !property_exists($theVariables, 'query_cache_size')
       || $theVariables->query_cache_size == 0) {
      return $this ;
    }

    $qcMemoryUsed = $theVariables->query_cache_size - $theStatus->Qcache_free_memory ;

    $qcHitsPerInsert =
      $theStatus->Qcache_hits / ($theStatus->Qcache_inserts != 0 ? $theStatus->Qcache_inserts : 1) ;

    $qcInsertsPerPrune =
        $theStatus->Qcache_inserts / ($theStatus->Qcache_lowmem_prunes != 0 ? $theStatus->Qcache_lowmem_prunes : 1) ;

    $this->out[] = 
      sprintf(
        '__ <a target="_blank" href="%s">Query Cache</a> _________________________________________________________',
        $this->_guide('#qc_report')) ;

    $this->out[] =
      sprintf(
        "Memory usage  %7s of %7s  %%Used: %6.2f",
        $this->_make_short($qcMemoryUsed, TRUE),
        $this->_make_short($theVariables->query_cache_size, TRUE),
        $this->_toPercentage($qcMemoryUsed / $theVariables->query_cache_size)) ;

    $this->out[] =
      sprintf(
        "Block Fragmnt %6.2f%%",
        $this->_toPercentage($theStatus->Qcache_free_blocks / $theStatus->Qcache_total_blocks)) ;

    $this->out[] =
      sprintf(
        "Hits          %7s   %5.2f/s",
        $this->_make_short($theStatus->Qcache_hits),
        ($theStatus->Qcache_hits / $theStatus->Uptime)) ;

    $this->out[] =
      sprintf(
        "Inserts       %7s   %5.2f/s",
        $this->_make_short($theStatus->Qcache_inserts),
        ($theStatus->Qcache_inserts / $theStatus->Uptime)) ;

    $this->out[] =
      sprintf(
        "Insrt:Prune %7s:1   %5.2f/s",
        $this->_make_short($qcInsertsPerPrune),
        ( ( $theStatus->Qcache_inserts - $theStatus->Qcache_lowmem_prunes ) / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "Hit:Insert  %7s:1   %5.2f/s",
        $this->_make_short($qcHitsPerInsert),
        ( $qcHitsPerInsert / $theStatus->Uptime )) ;

    return $this ;
  }

  function report_write_report_end(&$theStatus, &$theVariables) {

    $this->out[] = 
      sprintf(
        '__ <a target="_blank" href="%s">Table Locks</a> _________________________________________________________',
        $this->_guide('#tl_report')) ;

    $this->out[] =
      sprintf(
        "Waited      %9s  %6.2f/s  %%Total: %6.2f",
        $this->_make_short($theStatus->Table_locks_waited),
        ( $theStatus->Table_locks_waited / $theStatus->Uptime ),
        $this->_toPercentage($theStatus->Table_locks_waited / ( $theStatus->Table_locks_waited + $theStatus->Table_locks_immediate))) ;

    $this->out[] =
      sprintf(
        "Immediate   %9s  %6.2f/s",
        $this->_make_short($theStatus->Table_locks_immediate),
        ( $theStatus->Table_locks_immediate / $theStatus->Uptime )) ;

    $this->out[] = 
      sprintf(
        '__ <a target="_blank" href="%s">Tables</a> ______________________________________________________________',
        $this->_guide('#tables_report')) ;

    $this->out[] =
      sprintf(
        "Open        %9d of %4d    %%Cache: %6.2f",
        $theStatus->Open_tables,
        $theVariables->table_cache,
        $this->_toPercentage( $theStatus->Open_tables / $theVariables->table_cache )) ;

    $this->out[] =
      sprintf(
        "Opened      %9s  %6.2f/s",
        $this->_make_short($theStatus->Opened_tables),
        ( $theStatus->Opened_tables / $theStatus->Uptime )) ;


    $this->out[] = 
      sprintf(
        '__ <a target="_blank" href="%s">Connections</a> _________________________________________________________',
        $this->_guide('#conn_report')) ;

    $this->out[] =
      sprintf(
        "Max used    %9d of %4d      %%Max: %6.2f",
        $theStatus->Max_used_connections,
        $theVariables->max_connections,
        $this->_toPercentage($theStatus->Max_used_connections / $theVariables->max_connections)) ;

    $this->out[] =
      sprintf(
        "Total       %9s  %6.2f/s",
        $this->_make_short($theStatus->Connections),
        ( $theStatus->Connections / $theStatus->Uptime )) ;


    $this->out[] = 
      sprintf(
        '__ <a target="_blank" href="%s">Created Temp</a> ________________________________________________________',
        $this->_guide('#temp_report')) ;

    $this->out[] =
      sprintf(
        "Disk table  %9s  %6.2f/s",
        $this->_make_short($theStatus->Created_tmp_disk_tables),
        ( $theStatus->Created_tmp_disk_tables / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "Table       %9s  %6.2f/s    Size: %6s",
        $this->_make_short($theStatus->Created_tmp_tables),
        ( $theStatus->Created_tmp_tables / $theStatus->Uptime ),
        $this->_make_short($theVariables->tmp_table_size, TRUE, 1)) ;

    $this->out[] =
      sprintf(
        "File        %9s  %6.2f/s",
        $this->_make_short($theStatus->Created_tmp_files),
        ( $theStatus->Created_tmp_files / $theStatus->Uptime )) ;

    return $this;
  }

  function report_write_select_and_sort(&$theStatistics)  {

    $xxx = $this->_guide('#sas_report'); 

    $this->out[] = 
      sprintf(
        '__ <a target="_blank" href="%s">SELECT and Sort</a> _____________________________________________________',
        $xxx) ;

    $this->out[] =
      sprintf(
        "Scan          %7s   %5.2f/s %%SELECT: %6.2f",
        $this->_make_short($theStatistics->Select_scan),
        ($theStatistics->Select_scan / $theStatistics->Uptime),
        $this->_toPercentage($theStatistics->Select_scan / $theStatistics->Com_select)) ;

    $this->out[] =
      sprintf(
        "Range         %7s   %5.2f/s          %6.2f",
        $this->_make_short($theStatistics->Select_range),
        ($theStatistics->Select_range / $theStatistics->Uptime),
        $this->_toPercentage($theStatistics->Select_range / $theStatistics->Com_select)) ;

    $this->out[] =
      sprintf(
        "Full join     %7s   %5.2f/s          %6.2f",
        $this->_make_short($theStatistics->Select_full_join),
        ($theStatistics->Select_full_join / $theStatistics->Uptime),
        $this->_toPercentage($theStatistics->Select_full_join / $theStatistics->Com_select)) ;

    $this->out[] =
      sprintf(
        "Range check   %7s   %5.2f/s          %6.2f",
        $this->_make_short($theStatistics->Select_range_check),
        ($theStatistics->Select_range_check / $theStatistics->Uptime),
        $this->_toPercentage($theStatistics->Select_range_check / $theStatistics->Com_select)) ;

    $this->out[] =
      sprintf(
        "Full rng join %7s   %5.2f/s          %6.2f",
        $this->_make_short($theStatistics->Select_full_range_join),
        ($theStatistics->Select_full_range_join / $theStatistics->Uptime),
        $this->_toPercentage($theStatistics->Select_full_range_join / $theStatistics->Com_select)) ;

    $this->out[] =
      sprintf(
        "Sort scan     %7s   %5.2f/s",
        $this->_make_short($theStatistics->Sort_scan),
        ($theStatistics->Sort_scan / $theStatistics->Uptime)) ;

    $this->out[] =
      sprintf(
        "Sort range    %7s   %5.2f/s",
        $this->_make_short($theStatistics->Sort_range),
        ($theStatistics->Sort_range / $theStatistics->Uptime)) ;

    $this->out[] =
      sprintf(
        "Sort mrg pass %7s   %5.2f/s",
        $this->_make_short($theStatistics->Sort_merge_passes),
        ($theStatistics->Sort_merge_passes / $theStatistics->Uptime)) ;

    return $this ;
  }

  function report_write_threads_aborted_bytes(&$theStatus, &$theVariables) {
    $this->out[] = 
      sprintf(
        '__ <a target="_blank" href="%s">Threads</a> _____________________________________________________________',
        $this->_guide('#thd_report')) ;

    $this->out[] =
      sprintf(
        "Running     %9d of %4d",
        $theStatus->Threads_running,
        $theStatus->Threads_connected) ;

    $this->out[] =
      sprintf(
        "Cached      %9d of %4d      %%Hit: %6.2f",
        $theStatus->Threads_cached,
        $theVariables->thread_cache_size,
        $this->_toPercentage(1.0 - ( $theStatus->Threads_created / $theStatus->Connections ))) ;

    $this->out[] =
      sprintf(
        "Created     %9s  %6.2f/s",
        $this->_make_short($theStatus->Threads_created),
        ($theStatus->Threads_created / $theStatus->Uptime)) ;

    $this->out[] =
      sprintf(
        "Slow        %9s  %6.2f/s",
        $this->_make_short($theStatus->Slow_launch_threads),
        ($theStatus->Slow_launch_threads / $theStatus->Uptime)) ;

    $this->out[] = 
      sprintf(
        '__ <a target="_blank" href="%s">Aborted</a> _____________________________________________________________',
         $this->_guide('#thd_report')) ;

    $this->out[] =
      sprintf(
        "Clients     %9s  %6.2f/s",
        $this->_make_short($theStatus->Aborted_clients),
        ($theStatus->Aborted_clients / $theStatus->Uptime)) ;

    $this->out[] =
      sprintf(
        "Connects    %9s  %6.2f/s",
        $this->_make_short($theStatus->Aborted_connects),
        ( $theStatus->Aborted_connects / $theStatus->Uptime )) ;


    $this->out[] = 
      sprintf(
        '__ <a target="_blank" href="%s">Bytes</a> _______________________________________________________________',
        $this->_guide('#thd_report')) ;

    $this->out[] =
      sprintf(
        "Sent        %9s  %6.2f/s",
        $this->_make_short($theStatus->Bytes_sent, TRUE),
        ( $theStatus->Bytes_sent / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "Received    %9s  %6.2f/s",
        $this->_make_short($theStatus->Bytes_received, TRUE),
        ( $theStatus->Bytes_received / $theStatus->Uptime )) ;

    return $this ;
  }

  function report_write_InnoDB(&$theStatus, &$theVariables, &$theInnodb, &$theVersion) {
    if ($this->version->checkVersion(new mysqlreport_version(5, 0, 2), '<')) {
      return $this ;
    }

    if (!property_exists($theStatus, 'Innodb_page_size')) {
      return $this ;
    }

    $this->out[] = 
      sprintf(
        '__ <a target="_blank" href="%s">InnoDB Buffer Pool</a> __________________________________________________',
        $this->_guide('#idb_bp_report')) ;

    $this->out[] =
      sprintf(
        "Usage         %7s of %7s  %%Used: %6.2f",
        $this->_make_short($theInnodb->bufferPoolUsed, TRUE),
        $this->_make_short($theInnodb->bufferPoolTotal, TRUE),
        $this->_toPercentage($theInnodb->bufferPoolUsed / $theInnodb->bufferPoolTotal)) ;

    $this->out[] =
      sprintf(
        "Read hit      %6.2f%%",
        $this->_toPercentage($theInnodb->bufferPoolReadRatio)) ;

    $this->out[] = 'Pages' ;

    $this->out[] =
      sprintf(
        "  Free      %9s            %%Total: %6.2f",
        $this->_make_short($theStatus->Innodb_buffer_pool_pages_free),
        $this->_toPercentage($theStatus->Innodb_buffer_pool_pages_free / $theStatus->Innodb_buffer_pool_pages_total)) ;

    $this->out[] =
      sprintf(
        "  Data      %9s                    %6.2f %%Drty: %6.2f",
        $this->_make_short($theStatus->Innodb_buffer_pool_pages_data),
        $this->_toPercentage($theStatus->Innodb_buffer_pool_pages_data / $theStatus->Innodb_buffer_pool_pages_total),
        $this->_toPercentage($theStatus->Innodb_buffer_pool_pages_dirty / $theStatus->Innodb_buffer_pool_pages_data)) ;

    $this->out[] =
      sprintf(
        "  Misc      %9s                    %6.2f",
        $this->_make_short($theStatus->Innodb_buffer_pool_pages_misc),
        $this->_toPercentage($theStatus->Innodb_buffer_pool_pages_misc / $theStatus->Innodb_buffer_pool_pages_total)) ;

    $this->out[] =
      sprintf(
        "  Latched   %9s                    %6.2f",
        $this->_make_short($theStatus->Innodb_buffer_pool_pages_latched),
        $this->_toPercentage($theStatus->Innodb_buffer_pool_pages_latched / $theStatus->Innodb_buffer_pool_pages_total)) ;

    $this->out[] =
      sprintf(
        "Reads       %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_buffer_pool_read_requests),
        ( $theStatus->Innodb_buffer_pool_read_requests / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "  From file %9s  %6.2f/s          %6.2f",
        $this->_make_short($theStatus->Innodb_buffer_pool_reads),
        ( $theStatus->Innodb_buffer_pool_reads / $theStatus->Uptime ),
        $this->_toPercentage($theStatus->Innodb_buffer_pool_reads / $theStatus->Innodb_buffer_pool_read_requests)) ;

    $this->out[] =
      sprintf(
        "  Ahead Rnd %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_buffer_pool_read_ahead_rnd),
        ( $theStatus->Innodb_buffer_pool_read_ahead_rnd / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "  Ahead Sql %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_buffer_pool_read_ahead_seq),
        ( $theStatus->Innodb_buffer_pool_read_ahead_seq / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "Writes      %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_buffer_pool_write_requests),
        ( $theStatus->Innodb_buffer_pool_write_requests / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "Flushes     %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_buffer_pool_pages_flushed),
        ( $theStatus->Innodb_buffer_pool_pages_flushed / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "Wait Free   %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_buffer_pool_wait_free),
        ( $theStatus->Innodb_buffer_pool_wait_free / $theStatus->Uptime )) ;

    if ($this->version->checkVersion(new mysqlreport_version(5, 0, 3), '>=')) {

      $this->out[] = 
        sprintf(
          '__ <a target="_blank" href="%s">InnoDB Lock</a> _________________________________________________________',
           $this->_guide('#idb_lock_report')) ;

      $this->out[] =
        sprintf(
          "Waits       %9s  %6.2f/s",
          $this->_make_short($theStatus->Innodb_row_lock_waits),
          ( $theStatus->Innodb_row_lock_waits / $theStatus->Uptime )) ;

      $this->out[] =
        sprintf(
          "Waits       %9s  %6.2f/s",
          $this->_make_short($theStatus->Innodb_row_lock_waits),
          ( $theStatus->Innodb_row_lock_waits / $theStatus->Uptime )) ;

      $this->out[] =
        sprintf(
          "Current     %9d",
          $theStatus->Innodb_row_lock_current_waits) ;

      $this->out[] = 'Time acquiring' ;

      $this->out[] =
        sprintf(
          "  Total     %9d ms",
          $theStatus->Innodb_row_lock_time) ;

      $this->out[] =
        sprintf(
          "  Average   %9d ms",
          $theStatus->Innodb_row_lock_time_avg) ;

      $this->out[] =
        sprintf(
          "  Max       %9d ms",
          $theStatus->Innodb_row_lock_time_max) ;
    }


    $this->out[] = 
      sprintf(
        '__ <a target="_blank" href="%s">InnoDB Data, Pages, Rows</a> ____________________________________________',
        $this->_guide('#idb_dpr_report')) ;

    $this->out[] = 'Data' ;

    $this->out[] =
      sprintf(
        "  Reads     %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_data_reads),
        ( $theStatus->Innodb_data_reads / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "  Writes    %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_data_writes),
        ( $theStatus->Innodb_data_writes / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "  fsync     %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_data_fsyncs),
        ( $theStatus->Innodb_data_fsyncs / $theStatus->Uptime )) ;

    $this->out[] = '  Pending' ;

    $this->out[] =
      sprintf(
        "    Reads   %9s",
        $this->_make_short($theStatus->Innodb_data_pending_reads)) ;

    $this->out[] =
      sprintf(
        "    Writes  %9s",
        $this->_make_short($theStatus->Innodb_data_pending_fsyncs)) ;

    $this->out[] =
      sprintf(
        "    fsync   %9s",
        $this->_make_short($theStatus->Innodb_data_pending_writes)) ;

    $this->out[] = 'Pages' ;

    $this->out[] =
      sprintf(
        "  Created   %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_pages_created),
        ( $theStatus->Innodb_pages_created / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "  Read      %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_pages_read),
        ( $theStatus->Innodb_pages_read / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "  Written   %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_pages_written),
        ( $theStatus->Innodb_pages_written / $theStatus->Uptime )) ;

    $this->out[] = 'Rows' ;

    $this->out[] =
      sprintf(
        "  Deleted   %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_rows_deleted),
        ( $theStatus->Innodb_rows_deleted / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "  Inserted  %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_rows_inserted),
        ( $theStatus->Innodb_rows_inserted / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "  Read      %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_rows_read),
        ( $theStatus->Innodb_rows_read / $theStatus->Uptime )) ;

    $this->out[] =
      sprintf(
        "  Updated   %9s  %6.2f/s",
        $this->_make_short($theStatus->Innodb_rows_updated),
        ( $theStatus->Innodb_rows_updated / $theStatus->Uptime )) ;

    return $this ;
  }
  
  public function __construct(mysqlreport_driver $_driver) {
    $this->driver  = $_driver;
    $this->version = $this->_init_version();

  }
  
  public function output() {
    $rpt = '';
    foreach ($this->out as $v) {
       
      if (substr($v, 0, 1) == '_') {
        $rpt .= "\n" ;
      }

      $rpt .= $v . "\n" ;
    }
    
    return $rpt;
  }
  
  public function report() {

    $status    = $this->_my_status() ;
    $variables = $this->_my_variables() ;

    $uptime = $this->_my_uptime($status->Uptime) ;
    $myisam = $this->_myisam($status, $variables) ;

    $innodb = $this->_innodb($status, $variables) ;

    $this->report_uptime($uptime);
    $this->report_write_key_buff_max($variables, $myisam) ;
    $this->report_write_key_ratios($myisam);
    $this->report_write_questions($status, $myisam);
    $this->report_write_DTQ($status, $variables, $myisam);
    $this->report_write_slow_DMS($status, $variables, $myisam); 
    
    $this->report_write_DMS($status, $myisam);
    $this->report_write_COM($status, $myisam);
    
    $this->report_write_select_and_sort($status) ;

    $this->report_write_qcache($status, $variables);

    $this->report_write_report_end($status, $variables);

    $this->report_write_threads_aborted_bytes($status, $variables);

    $this->report_write_InnoDB($status, $variables, $innodb) ;

    //return $this->output();
    return '<pre>' . $this->output() . "</pre>" ;
  }
}























