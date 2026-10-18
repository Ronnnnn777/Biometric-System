<?php
/** Stores the one LAN address used by Maruhat Attendance on mobile devices. */
function ensureMobileScannerSettingsSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS mobile_scanner_settings (ID TINYINT UNSIGNED NOT NULL PRIMARY KEY, SERVER_URL VARCHAR(255) NOT NULL DEFAULT '', SCANNER_NAME VARCHAR(80) NOT NULL DEFAULT '', SCANNER_ID VARCHAR(60) NOT NULL DEFAULT '', UPDATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $columns=[];$result=$conn->query('SHOW COLUMNS FROM mobile_scanner_settings');while($result&&($column=$result->fetch_assoc()))$columns[strtoupper($column['Field'])]=true;
    if(!isset($columns['SCANNER_NAME']))$conn->query("ALTER TABLE mobile_scanner_settings ADD COLUMN SCANNER_NAME VARCHAR(80) NOT NULL DEFAULT '' AFTER SERVER_URL");
    if(!isset($columns['SCANNER_ID']))$conn->query("ALTER TABLE mobile_scanner_settings ADD COLUMN SCANNER_ID VARCHAR(60) NOT NULL DEFAULT '' AFTER SCANNER_NAME");
    $conn->query("INSERT IGNORE INTO mobile_scanner_settings (ID, SERVER_URL, SCANNER_NAME, SCANNER_ID) VALUES (1, '', '', '')");
    $conn->query("CREATE TABLE IF NOT EXISTS authorized_mobile_scanners (ID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, SCANNER_NAME VARCHAR(80) NOT NULL, SCANNER_ID VARCHAR(60) NOT NULL UNIQUE, IS_ENABLED TINYINT(1) NOT NULL DEFAULT 1, DISABLED_AT DATETIME NULL, CREATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    // Removed scanners are kept in this small block list.  Without it, a
    // phone using automatic registration would recreate its row immediately.
    $conn->query("CREATE TABLE IF NOT EXISTS removed_mobile_scanners (SCANNER_ID VARCHAR(60) NOT NULL PRIMARY KEY, SCANNER_NAME VARCHAR(80) NOT NULL DEFAULT '', REMOVED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $authorizedColumns=[];$authorizedColumnResult=$conn->query('SHOW COLUMNS FROM authorized_mobile_scanners');while($authorizedColumnResult&&$authorizedColumn=$authorizedColumnResult->fetch_assoc())$authorizedColumns[strtoupper($authorizedColumn['Field'])]=true;
    if(!isset($authorizedColumns['IS_ENABLED']))$conn->query('ALTER TABLE authorized_mobile_scanners ADD COLUMN IS_ENABLED TINYINT(1) NOT NULL DEFAULT 1 AFTER SCANNER_ID');
    if(!isset($authorizedColumns['DISABLED_AT']))$conn->query('ALTER TABLE authorized_mobile_scanners ADD COLUMN DISABLED_AT DATETIME NULL AFTER IS_ENABLED');
    $legacy=$conn->query('SELECT SCANNER_NAME,SCANNER_ID FROM mobile_scanner_settings WHERE ID=1 LIMIT 1');
    $legacyScanner=$legacy?$legacy->fetch_assoc():null;
    if(trim((string)($legacyScanner['SCANNER_NAME']??''))!==''&&trim((string)($legacyScanner['SCANNER_ID']??''))!==''){
        $seed=$conn->prepare('INSERT IGNORE INTO authorized_mobile_scanners (SCANNER_NAME,SCANNER_ID) VALUES (?,?)');
        $seed->bind_param('ss',$legacyScanner['SCANNER_NAME'],$legacyScanner['SCANNER_ID']);
        $seed->execute();
    }
}

function mobileScannerConfiguration(mysqli $conn): array
{
    ensureMobileScannerSettingsSchema($conn);
    $result=$conn->query('SELECT SERVER_URL,SCANNER_NAME,SCANNER_ID FROM mobile_scanner_settings WHERE ID=1 LIMIT 1');
    $row=$result?$result->fetch_assoc():null;
    return ['server_url'=>trim((string)($row['SERVER_URL']??'')),'scanner_name'=>trim((string)($row['SCANNER_NAME']??'')),'scanner_id'=>trim((string)($row['SCANNER_ID']??''))];
}

function normaliseMobileScannerServerUrl(string $url): ?string
{
    $url=trim($url);
    if($url==='')return null;
    if(preg_match('#^https://#i',$url))$url='http://'.substr($url,8);
    if(!preg_match('#^http://#i',$url))$url='http://'.$url;
    $parts=parse_url($url);
    if(!$parts||empty($parts['host'])||strtolower((string)($parts['scheme']??''))!=='http'||isset($parts['query'])||isset($parts['fragment']))return null;
    return rtrim($url,'/').'/';
}

function setMobileScannerConfiguration(mysqli $conn,string $url,string $name,string $id): void
{
    ensureMobileScannerSettingsSchema($conn);
    $update=$conn->prepare('INSERT INTO mobile_scanner_settings (ID, SERVER_URL, SCANNER_NAME, SCANNER_ID) VALUES (1, ?, ?, ?) ON DUPLICATE KEY UPDATE SERVER_URL=VALUES(SERVER_URL), SCANNER_NAME=VALUES(SCANNER_NAME), SCANNER_ID=VALUES(SCANNER_ID)');
    $update->bind_param('sss',$url,$name,$id);
    $update->execute();
}

function authorizedMobileScanners(mysqli $conn): array
{
    ensureMobileScannerSettingsSchema($conn);
    $result=$conn->query('SELECT a.ID,a.SCANNER_NAME,a.SCANNER_ID,a.IS_ENABLED,a.DISABLED_AT,a.CREATED_AT FROM authorized_mobile_scanners a WHERE NOT EXISTS (SELECT 1 FROM removed_mobile_scanners r WHERE r.SCANNER_ID=a.SCANNER_ID) ORDER BY a.IS_ENABLED DESC,a.SCANNER_NAME,a.SCANNER_ID');
    $scanners=[];while($result&&$row=$result->fetch_assoc())$scanners[]=$row;
    return $scanners;
}

function mobileScannerIsAuthorized(mysqli $conn,string $name,string $id): bool
{
    ensureMobileScannerSettingsSchema($conn);
    $statement=$conn->prepare('SELECT 1 FROM authorized_mobile_scanners WHERE SCANNER_NAME=? AND SCANNER_ID=? AND IS_ENABLED=1 LIMIT 1');
    $statement->bind_param('ss',$name,$id);$statement->execute();
    return $statement->get_result()->num_rows===1;
}
