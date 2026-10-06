# Creates a compressed, restorable local backup of the attendance database and
# student photos. This script is intended to run once each night via Task Scheduler.

$ErrorActionPreference = 'Stop'

$backupRoot = 'C:\Biometric_Backups'
$dailyFolder = Join-Path $backupRoot 'daily'
$weeklyFolder = Join-Path $backupRoot 'weekly'
$monthlyFolder = Join-Path $backupRoot 'monthly'
$stamp = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$workFolder = Join-Path $backupRoot ("work-" + $stamp)
$archiveName = "biometric-backup-" + $stamp + '.zip'

$mySqlDump = 'C:\xampp\mysql\bin\mysqldump.exe'
$studentUploads = 'C:\xampp\htdocs\biometric\uploads\students'
$recognitionPhotos = 'C:\Biometric_Capstone\known_faces'

foreach ($folder in @($backupRoot, $dailyFolder, $weeklyFolder, $monthlyFolder, $workFolder)) {
    New-Item -ItemType Directory -Path $folder -Force | Out-Null
}

try {
    if (-not (Test-Path -LiteralPath $mySqlDump)) {
        throw "MySQL backup tool was not found at $mySqlDump"
    }

    $databaseFile = Join-Path $workFolder 'biometric-database.sql'
    & $mySqlDump --host=127.0.0.1 --port=3306 --user=root --databases biometric --single-transaction --routines --events --add-drop-table | Out-File -LiteralPath $databaseFile -Encoding utf8
    if ($LASTEXITCODE -ne 0) {
        throw "MySQL database backup failed with exit code $LASTEXITCODE"
    }

    $manifest = @(
        "Created: " + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'),
        'Database: biometric',
        'Database server: 127.0.0.1:3306',
        'Included photo folders:',
        ' - uploads\\students',
        ' - C:\\Biometric_Capstone\\known_faces'
    )
    $manifest | Set-Content -LiteralPath (Join-Path $workFolder 'backup-info.txt') -Encoding utf8

    if (Test-Path -LiteralPath $studentUploads) {
        Copy-Item -LiteralPath $studentUploads -Destination (Join-Path $workFolder 'uploads-students') -Recurse -Force
    }
    if (Test-Path -LiteralPath $recognitionPhotos) {
        Copy-Item -LiteralPath $recognitionPhotos -Destination (Join-Path $workFolder 'known-faces') -Recurse -Force
    }

    $dailyArchive = Join-Path $dailyFolder $archiveName
    Compress-Archive -Path (Join-Path $workFolder '*') -DestinationPath $dailyArchive -CompressionLevel Optimal -Force

    # Keep seven latest daily backups. Sunday copies are kept as weekly backups;
    # first-day-of-month copies are kept as monthly backups.
    if ((Get-Date).DayOfWeek -eq [System.DayOfWeek]::Sunday) {
        Copy-Item -LiteralPath $dailyArchive -Destination (Join-Path $weeklyFolder $archiveName) -Force
    }
    if ((Get-Date).Day -eq 1) {
        Copy-Item -LiteralPath $dailyArchive -Destination (Join-Path $monthlyFolder $archiveName) -Force
    }

    foreach ($retention in @(
        @{ Folder = $dailyFolder; Keep = 7 },
        @{ Folder = $weeklyFolder; Keep = 4 },
        @{ Folder = $monthlyFolder; Keep = 12 }
    )) {
        Get-ChildItem -LiteralPath $retention.Folder -Filter 'biometric-backup-*.zip' -File |
            Sort-Object LastWriteTime -Descending |
            Select-Object -Skip $retention.Keep |
            ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force }
    }

    Write-Output "Backup completed: $dailyArchive"
}
finally {
    if (Test-Path -LiteralPath $workFolder) {
        Remove-Item -LiteralPath $workFolder -Recurse -Force
    }
}
