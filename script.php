<?php

if(http_response_code() !== FALSE) {
    die('CLI ONLY');
}

echo "Path to binlog?:";

$binlogPath = stream_get_line(STDIN, 1024, PHP_EOL);
if($binlogPath === false) {
    die();
}

if(!file_exists($binlogPath)) {
    die("File does not exist or is not readable");
}

$output = [];
exec('/usr/bin/mysqlbinlog --base64-output=decode-rows -vv ' . $binlogPath, $output);

$requests = [];

$table = null;
$query = null;

$linesParsed = 0;

foreach($output as $line) {

    $matches = [];
    if(preg_match('/^#[0-9]{6} .*Table_map: (.*) mapped/', $line, $matches)) {

        $table = $matches[1];
        $query = null;
        $linesParsed++;
        continue;
    }

    // Only recognize lines, when a table is found
    if($table === null) {
        continue;
    }

    // This a request line
    if(substr($line, 0, 4) == '### ') {

        if($query === null) {
            $query = "";
        }

        $query .= $line . PHP_EOL;

        $linesParsed++;

    } else if($query !== null) {
        // End of request found:
        $requests[] = [
            'table' => $table,
            'query' => $query
        ];
        $table = null;
        $query = null;
    }
}

echo $linesParsed . ' of ' . count($output) . ' lines parsed' . PHP_EOL . PHP_EOL;

$stat = [];
$countRequests = 0;
$strlenQueries = 0; // = String Length - Gives some information about the size of a query

foreach($requests as $request) {

    $table = $request['table'];
    $query = $request['query'];

    if(!isset($stat[$table])) {
        $stat[$table] = [
            'countRequests' => 0,
            'strlenQueries' => 0,
            'requests' => []
        ];
    }

    $stat[$table]['countRequests']++;
    $stat[$table]['strlenQueries']+= mb_strlen($query);
    $stat[$table]['requests'][] = $request;

    $countRequests++;
    $strlenQueries += mb_strlen($query);
}

//ksort($stat, SORT_STRING);

$sortArray = [];

foreach($stat as $table => $data) {
    $sortArray[] = $stat[$table]['strlenQueries'];
}

array_multisort($sortArray, SORT_NUMERIC, SORT_DESC, $stat);

$mask = "|%80s |%-15s |%-15s \n";
printf($mask, 'Table', 'count requests', 'strlen queries');

foreach($stat as $table => $data) {
    printf(
        $mask,
        $table,
        $data['countRequests'] . ' (' . ($countRequests == 0 ? 0 : round($data['countRequests']/$countRequests*100, 2)) . '%)',
        $data['strlenQueries'] . ' (' . ($strlenQueries == 0 ? 0 : round($data['strlenQueries']/$strlenQueries*100, 2)) . '%)'
    );
}

while(true) {

    echo "Print specific database.table?:";

    $table = stream_get_line(STDIN, 1024, PHP_EOL);

    if($table === "")
        break;

    if(isset($stat[$table])) {
        print_r($stat[$table]);
    } else {
        echo "Not found" . PHP_EOL;
    }
}

