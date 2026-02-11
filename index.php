<?php

/**
 * Fetching ENV params
 */
$logFilePath = getenv('LOG_FILE_PATH') ?: 'nginx.log';
$outputFileName = getenv('OUTPUT_FILE_NAME') ?: 'output.csv';
$fileSavePath = getenv('FILE_SAVE_PATH') ?: '/tmp/csvFolder';


$gitSshUrl = getenv('GIT_SSH_URL');
$gitSshUrl = $gitSshUrl !== false ? escapeshellarg($gitSshUrl) : '';

$gitUser = getenv('GIT_USER');
$gitUser = $gitUser !== false ? escapeshellarg($gitUser) : 'test';

$gitEmail = getenv('GIT_EMAIL');
$gitEmail = $gitEmail !== false ? escapeshellarg($gitEmail) : 'service@test.com';

$gitBranch = getenv('GIT_BRANCH');
$gitBranch = $gitBranch !== false ? escapeshellarg($gitBranch) : 'dev';


if (!$logFilePath) {
    echo "\nLog file is not provided. Script won't be executed!\n";
    exit(1);
}

if (!file_exists($logFilePath)) {
    echo "\nFile was not found! The script won't be executed! Please make sure the file exists and path is correct\n";
    exit(1);
}

/**
 * Reading and validating income arguments
 */
echo "\nReading income script arguments:";
$sorts = [];
$filters = [];
$commitMessage = 'generated CSV at ';
$dryRun = in_array('--dry-run', $argv);

$mode = null;

foreach ($argv as $arg) {
    if ($arg == '--filter') {
        $mode = 'filter';
        continue;
    }
    if ($arg == '--sort') {
        $mode = 'sort';
        continue;
    }
    if ($arg == '--message') {
        $mode = 'message';
        continue;
    }
    if (strstr($arg, '.php') || $arg == '--dry-run') {
        continue;
    }

    switch ($mode) {
        case 'filter':
            $filters[] = $arg;
            break;
        case 'sort':
            $sorts[] = $arg;
            break;
        case 'message':
            $commitMessage = escapeshellarg($arg);
            $mode = null;
            break;
    }
}

/**
 * Preparing sort rules
 */
$sortRules = [];

if ($sorts) {
    echo "\n\n Preparing sort rules:";
    foreach ($sorts as $s) {
        [$field, $direction] = explode('=', $s);
        if (!in_array($direction, ['ASC', 'DESC', 'asc', 'desc'], true)) continue;
        $sortRules[$field] = $direction;
        echo "\n The next sort rule will be applied: $s";
    }
    echo "\n If some sort rule wasn't mentioned, please make sure You passed it correctly in format \$field=\$order";
}

/**
 * Preparing filter rules
 */
$filterRules = [];

if ($filters) {
    echo "\n\n Preparing filter rules:";
    foreach ($filters as $f) {
        if (preg_match('/^(.+?)(!=|>=|<=|=|>|<|!%|%)(.+)$/', $f, $matches)) {
            $filterRules[] = [
                'field' => $matches[1],
                'operator' => $matches[2],
                'value'    => trim($matches[3])
            ];
            echo "\n The next filter rule will be applied: $f";
        }
    }
    echo "\n If some filter rule wasn't mentioned, please make sure You passed it correctly in format \$field=\$value";
}


$data = [];
$pattern = '/^(\S+)\s(.*?)\s(.*?)\s\[(.*?)\]\s\"(([A-Z]+)\s(\S+)\s(.*?))\"\s(\d{3})\s(\d+)\s\"(.*?)\"\s\"(.*?)\"\s(\d+)\s(\d+\.\d+)\s\[(.*?)\]\s\[(.*?)\]\s(\S+)\s(\d+)\s(\d+\.\d+)\s(\d{3})\s(.*)$/';

echo "\n\n Reading from the file...";

$handle = fopen($logFilePath, "r");
if ($handle) {
    $counter = 0;
    while (($line = fgets($handle)) !== false) {
        if (preg_match($pattern, $line, $matches)) {
            $row  = [
                'ip' => $matches[1],
                // 'idk' => $matches[2],
                // 'remote_user' => $matches[3],
                'datetime' => $matches[4],
                // 'full_request_info' => $matches[5],
                'http_method' => $matches[6],
                'api_endpoint' => $matches[7],
                'http_version' => $matches[8],
                'response_code' => $matches[9],
                'bytes_sent' => $matches[10],
                'http_referer' => $matches[11],
                'user_agent' => $matches[12],
                'request_length' => $matches[13],
                'request_time' => $matches[14],
                'upstream_addr' => $matches[15],
                // 'custom_field' => $matches[16],
                'upstream_ip' => $matches[17],
                'upstream_response_length' => $matches[18],
                'upstream_response_time' => $matches[19],
                'upstream_status' => $matches[20],
                'request_id' => $matches[21]
            ];
            $add = true;

            // Apply filters
            foreach ($filterRules as $filter) {
                $value = $filter['value'];
                $field = $filter['field'];
                if (!isset($row[$field])) {
                    continue;
                }

                $rowValue = $row[$field];

                if ($field === 'datetime') {
                    $value = strtotime($value);
                    $rowValue = strtotime($rowValue);

                    if ($value === null) {
                        continue;
                    }
                }

                switch ($filter['operator']) {
                    case '>':
                        if(!($rowValue > $value)) $add = false;
                        break;
                    case '<':
                        if(!($rowValue < $value)) $add = false;
                        break;
                    case '=':
                        if(!($rowValue == $value)) $add = false;
                        break;
                    case '!=':
                        if(!($rowValue != $value)) $add = false;
                        break;
                    case '>=':
                        if(!($rowValue >= $value)) $add = false;
                        break;
                    case '<=':
                        if(!($rowValue <= $value)) $add = false;
                        break;
                    case '%':
                        if(!strstr($rowValue, $value)) $add = false;
                        break;
                    case '!%':
                        if(strstr($rowValue, $value)) $add = false;
                        break;
                }
            }
            if ($add) {
                $data[] = $row;
                $counter++;
            }
        }
    }
    fclose($handle);
} else {
    echo "\n Error: Could not open the file for reading\n";
    exit(1);
}

echo "\n Number of read lines that accepts format and filters - $counter";

if (empty($data) && $counter == 0) {
    echo "\n There are no logs that matches pattern and filters. CSV file won't be created and pushed to GIT\n";
    exit(0);
}

/**
 * Sorting output
 */
echo "\n\n Sorting output...";
usort($data, function ($a, $b) use ($sortRules) {
    foreach ($sortRules as $field => $direction) {

        if ($field === 'datetime') {
            $valueA = strtotime($a[$field]);
            $valueB = strtotime($b[$field]);
        } else {
            $valueA = $a[$field];
            $valueB = $b[$field];
        }

        $result = $valueA <=> $valueB;

        if ($result !== 0) {
            return ($direction === 'DESC') ? -$result : $result;
        }
    }
    return 0;
});

/**
 * Checking if folder exists
 */
if (!is_dir($fileSavePath)) {
    mkdir($fileSavePath, 0755, true);
    echo "\n\n Created new folder: $fileSavePath";
} else {
    echo "\n\n Folder $fileSavePath is already exists";
}

chdir($fileSavePath);

echo "\n\n Writing to the CSV file";
$csv = fopen($outputFileName, 'w');


/**
 * Writing to CSV
 */
fputcsv($csv, ['IP', 'DateTime', 'HTTP Method', 'Endpoint', 'HTTP Version', 'Response Code', 'Bytes Sent', 'HTTP Referer', 'User Agent',
'Request Length', 'Request Time', 'Upstream Addr', 'Upstream IP', 'Upstream Response Length', 'Upstream Response Time', 'Upstream Status', 'Request ID']);
foreach ($data as $row) {
    fputcsv($csv, $row);
}

fclose($csv);
echo "\n\n CSV is done!";

if (!$dryRun) {
    if (!$gitSshUrl) {
        echo "\n\n Repo URL isn't provided, changes won't be pushed to GIT\n";
        exit(0);
    }
    echo "\n\n Initializing Git repository...";
    $hasChanges = false;
    if (!is_dir('.git')) {
        exec('git init');
        exec('git remote add origin ' . $gitSshUrl);
    } else {
        exec('git remote set-url origin ' . $gitSshUrl);
        $hasChanges = true;
    }
    $checkCommand = "GIT_SSH_COMMAND='ssh -i /var/www/html/id_ssh -o StrictHostKeyChecking=no' git ls-remote $gitSshUrl HEAD 2>&1";
    exec($checkCommand, $output, $resultCode);
    if ($resultCode !== 0) {
        echo "\n Git Connection Failed! CSV is not pushed to GIT";
        echo "\n Details: " . implode("\n", $output) . "\n";
        exit(1);
    }

    echo "\n Initializing Git configs...\n";
    exec('git config user.email ' . $gitEmail);
    exec('git config user.name "' . $gitUser .'"');
    exec('git config core.sshCommand "ssh -i /var/www/html/id_ssh -o StrictHostKeyChecking=no"');
    exec('git fetch origin');
    if ($hasChanges) {
        exec('git stash -u');
        exec("git checkout -f $gitBranch || git checkout -f -b $gitBranch origin/$gitBranch");
        exec('git pull origin '  . $gitBranch);
        exec('git checkout stash@{0} -- ' . $outputFileName);
        exec('git stash clear');
    } else {
        exec("git checkout $gitBranch || git checkout -b $gitBranch origin/$gitBranch");
        exec('git pull origin '  . $gitBranch);
    }
    echo "\n Commit changes";
    exec('git add ' . $outputFileName);
    $message = $commitMessage . date("Y-m-d H:i:s");
    exec('git commit -m "' . $message . '"');

    echo "\n Pushing changes";
    exec('git push origin ' . $gitBranch);

//    echo "\n Removing work folder and CSV";
//    exec('rm -rf ' . $fileSavePath);
} else {
    echo "\n\n Dry-run is active, changes won't be pushed to GIT\n";
}

exit(0);