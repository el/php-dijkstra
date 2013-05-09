<?php

/******************		Settings	*******************************/

	$debug = true;

	$s = "distance";		// shortest path
//	$s = "weight";			// best path

	$df = (isset($_GET["q"])) ? floatval($_GET["q"]) : 0.0001;

/******************************************************************/


	if ($debug) $time_start = microtime(true); 
	ini_set('memory_limit', '1024M');
	ini_set('max_execution_time', 60*20);

	if (isset($_GET["sX"])) {
		$sX = floatval($_GET["sX"]);
		$sY = floatval($_GET["sY"]);

		$dX = floatval($_GET["dX"]);
		$dY = floatval($_GET["dY"]);
	} else {
		$sX = 32.8545792290041;
		$sY = 39.9418974908640;

		$dX = 32.8550907297269;
		$dY = 39.9131826672174;
/*/
		// Atılım
		$sY = 39.816079;
		$sX = 32.720838;
		// Kızılay
		$dY = 39.920823;
		$dX = 32.854219;
//*/
	}

	$dbh = new PDO("pgsql:dbname=osm;host=localhost", "postgres", ""); 

	// closest point to given start
	$q = "SELECT ST_AsGeoJSON(sp) as sp
  FROM ways order by ST_Distance(sp,ST_GeomFromText('POINT($sX $sY)',4326)) asc LIMIT 1;";
  	$arr = $dbh->query($q)->fetch();
  	$sX = json_decode($arr["sp"])->coordinates[0];
  	$sY = json_decode($arr["sp"])->coordinates[1];

  	// closest point to given destination
	$q = "SELECT ST_AsGeoJSON(dp) as dp
  FROM ways order by ST_Distance(dp,ST_GeomFromText('POINT($dX $dY)',4326)) asc LIMIT 1;";
  	$arr = $dbh->query($q)->fetch();
  	$dX = json_decode($arr["dp"])->coordinates[0];
  	$dY = json_decode($arr["dp"])->coordinates[1];

	if ($debug) echo json_encode(array("type"=>"MultiPoint","coordinates"=>array(array($sX,$sY),array($dX,$dY))))."<br><br>";

	/**
	* Edge class to keep points
	*/
	class Edge {
		
		public $point = array();
		public $connections  = array();
		public $weight = 100000000;
		
		public function __construct($point, $connection) {
			$this->point = $point;
			$this->add($connection);
		}

		public function add($connection) {
			if (!in_array($connection, $this->connections)) $this->connections[] = $connection;
		}

		public function connect($k) {
			if ($this->weight > $k || $this->weight == 100000000) {
				$this->weight = sizeof($k);
				return true;
			}
			else 
				return false;
		}
	}

	/**
	* Graph class to keep Edges and calculate distance
	*/
	class Graph
	{
		private $nodes = array();
		private $visited = array();
		private $from = array();
		private $to = array();
		private $path = array(array(),10000000000);
		private $float = 10;
		private $round = array();

		private function name($point) {
			$d = $this->float;
			return round($point[0], $d)."_".round($point[1], $d);
		}

		public function addedge($start, $end, $weight = 0) {
			$name = $this->name($start);
			$connection = array(
				"name"	=>	$this->name($end),
				"point"	=>	$end,
				"weight"=>	$weight
			);
			if (!isset($this->nodes[$name]))
				$this->nodes[$name] = new Edge($start, $connection);
			else
				$this->nodes[$name]->add($connection);
		}

		public function path($from,$to) {
			$d = $this->float;
			$this->from = array(round($from[0],$d),round($from[1],$d));
			$this->to   = array(round($to[0],$d),round($to[1],$d));

			global $debug;
			if ($debug) {
				echo "Total nodes: ".sizeof($this->nodes)."<br/>";
				flush();
			}

			$s = 0;
			$this->round[$s][] = array(array($this->from),0);
			while (isset($this->round[$s])) {
				if (isset($this->round[$s-1])) unset($this->round[$s-1]);
				foreach ($this->round[$s] as $thisround) {
					$this->createPaths($thisround,$s);
				}
				$s++;
				if ($debug) echo "Used Memory: ".intval(memory_get_usage()/(1024*1024)) . " MB - $s<br/>";
			}
			if ($debug) echo sizeof($this->visited)." / ".sizeof($this->nodes)."<br/><br/>";
			return json_encode(array("type"=>"LineString","coordinates"=>$this->path[0]));
		}

		private function createPaths($round,$w) {
			$pp = $round[0];
			$weight = $round[1];

			$s = $this->name($pp[sizeof($pp)-1]);
			if (!in_array($s, $this->visited)) $this->visited[] = $s;
			$arr = $this->nodes[$s]->connections;
			foreach ($arr as $r) {
				$k = $pp;
				$k[] = $r["point"];
				if ($r["name"]==$this->name($this->to)) {
					if ($this->path[1]>$weight+$r["weight"]) 
						$this->path = array($k,$weight+$r["weight"]);
					$saa = $this->nodes[$r["name"]]->connect($r["weight"]);
				} elseif (!in_array($r["name"], $this->visited)) {
					if (isset($this->nodes[$r["name"]]) && $this->nodes[$r["name"]]->connect($r["weight"])) 
						$this->round[$w+1][] = array($k,($weight+$r["weight"]));
				}
			}
		}

	}


	$g = new Graph();

	// Center point
	$min = array(min($sX,$dX),min($sY,$dY));
	$max = array(max($sX,$dX),max($sY,$dY));
	$center = array(($min[0]+$max[0])/2,($min[1]+$max[1])/2,sqrt(pow((($max[0]-$min[0])/2),2)+pow((($max[1]-$min[1])/2),2)+$df));
	
	// Get road blocks within a circle
 	$q = "SELECT ST_AsGeoJSON(sp) as sp, ST_AsGeoJSON(dp) as dp, weight, oneway, distance FROM ways WHERE 
	ST_Within(sp, ST_Buffer(ST_GeomFromText('POINT($center[0] $center[1])',4326),$center[2])) OR
	ST_Within(dp, ST_Buffer(ST_GeomFromText('POINT($center[0] $center[1])',4326),$center[2]));";

	// Add points to graph
	$arr = $dbh->query($q)->fetchAll();
	foreach ($arr as $r) {
		if ($r["oneway"]=="-1") {	//Reverse way road
			$g->addedge(json_decode($r["dp"])->coordinates, json_decode($r["sp"])->coordinates, floatval($r[$s]));
		} elseif ($r["oneway"]=="yes") {	//One way road
			$g->addedge(json_decode($r["sp"])->coordinates, json_decode($r["dp"])->coordinates, floatval($r[$s]));
		} else {	//Two way road
			$g->addedge(json_decode($r["sp"])->coordinates, json_decode($r["dp"])->coordinates, floatval($r[$s]));
			$g->addedge(json_decode($r["dp"])->coordinates, json_decode($r["sp"])->coordinates, floatval($r[$s]));
		}
	}

	unset($arr);

	// Create path
	echo $g->path(array($sX,$sY),array($dX,$dY));


	if ($debug) echo '<br/><br/><b>Total Execution Time:</b> '.round((microtime(true) - $time_start),3).' s';


