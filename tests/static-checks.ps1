param([string]$Root='D:\Development\Projects\QRScanner\backend-staging')
$ErrorActionPreference='Stop'
$checks=New-Object 'Collections.Generic.List[object]'
function Check([string]$Name,[bool]$Pass) {
    $checks.Add([pscustomobject]@{Check=$Name;Passed=$Pass})
}
$config=Get-Content -LiteralPath "$Root\config\staging.example.json" -Raw|ConvertFrom-Json
Check 'Sample config contains no database credentials' ($config.database.username -eq '' -and $config.database.password -eq '')
Check 'Sample has no operational token records' (@($config.tokens).Count -eq 0)
Check 'Staging environment and database guard' ($config.environment -eq 'staging' -and $config.database.name -eq 'qr_staging')
$public=@(Get-ChildItem -LiteralPath "$Root\public" -Recurse -File -Force)
Check 'Public tree contains exactly eight PHP entrypoints' ($public.Count -eq 8 -and @($public|Where-Object Extension -ne '.php').Count -eq 0)
$entryOk=$true
foreach($f in $public){$s=Get-Content -LiteralPath $f.FullName -Raw;if($s -notmatch 'src/bootstrap.php' -or $s -notmatch '\\QrStaging\\serve\('){$entryOk=$false}}
Check 'All public entrypoints use fixed shared guard' $entryOk
$app=Get-Content -LiteralPath "$Root\src\App.php" -Raw
$disabled=$true
foreach($n in @('delete_scan','delete_all_scans','create_employee','dashboard','submit_scan')){
    if($app -notmatch ("'/qr/"+$n+"\.php'\s*=>\s*\[[^\r\n]+true\]")){$disabled=$false}
}
Check 'All ambiguous operations are marked disabled' $disabled
$store=Get-Content -LiteralPath "$Root\src\MysqliStore.php" -Raw
Check 'Runtime store contains no destructive or employee-mutation SQL' ($store -notmatch '(?i)DELETE\s+FROM|TRUNCATE\s+|UPDATE\s+employees|INSERT\s+INTO\s+employees')
$sql=Get-Content -LiteralPath "$Root\database\initialize-staging.sql" -Raw
Check 'Initializer has no destructive DDL/DML or credential/grant statements' ($sql -notmatch '(?im)^\s*(DROP|TRUNCATE|DELETE|GRANT|CREATE\s+USER|ALTER\s+USER)\b')
Check 'Initializer selects only new staging database' ($sql -match '(?m)^CREATE DATABASE qr_staging ' -and $sql -match '(?m)^USE qr_staging;')
Check 'All four expected table declarations exist' (@([regex]::Matches($sql,'(?m)^CREATE TABLE (employees|lunch_scans|random_scans|audit_events) \(')).Count -eq 4)
Check 'Seed fixtures are explicitly synthetic' ($sql -match 'Synthetic Staging Employee' -and $sql -match 'SYNTHETIC-QR-003')
$refs=Get-Content -LiteralPath "$Root\evidence\original-endpoints-manifest.json" -Raw|ConvertFrom-Json
$referenceOk=$true
foreach($ref in $refs){if((Get-FileHash -LiteralPath (Join-Path $Root $ref.Reference)).Hash -ne $ref.SHA256){$referenceOk=$false}}
Check 'Seven inert original endpoints retain exact hashes' ($referenceOk -and @($refs).Count -eq 7)
Check 'No production config was copied' (-not(Test-Path -LiteralPath "$Root\reference\qr\config.php.txt"))
$forbidden=@(Get-ChildItem -LiteralPath $Root -Recurse -File -Force|Where-Object {
    $_.Name -match '\.(sql\.gz|pem|key|p12|pfx)$' -or $_.Name -like '*service-account*'
})
Check 'No compressed dumps or private-key files in package' ($forbidden.Count -eq 0)
$sourceFiles=@(Get-ChildItem -LiteralPath "$Root\src","$Root\public" -Recurse -File)
$runtimeSecret=$false
foreach($f in $sourceFiles){$s=Get-Content -LiteralPath $f.FullName -Raw;if($s -match 'BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY|darkgrey-turkey|hostingersite\.com|curl_exec|shell_exec|eval\s*\('){$runtimeSecret=$true}}
Check 'Runtime contains no production host, private-key block or outgoing/execution helper' (-not $runtimeSecret)
$phpPaths=@('C:\php\php.exe','C:\Program Files\PHP\php.exe','C:\xampp\php\php.exe','D:\Development\PHP\php.exe','D:\php\php.exe')
$detected=@(Get-Command php -ErrorAction SilentlyContinue|Select-Object -ExpandProperty Source)
$detected+=@($phpPaths|Where-Object {Test-Path -LiteralPath $_})
[pscustomobject]@{
    Kind='Static packaging checks only; not PHP syntax/runtime/SQL/integration validation'
    Checks=$checks
    Failed=@($checks|Where-Object {-not $_.Passed}).Count
    PhpDetected=@($detected|Select-Object -Unique)
    PhpLint='NOT RUN'
    PhpUnitTests='NOT RUN'
    HttpTests='NOT RUN'
    DatabaseTests='NOT RUN'
}|ConvertTo-Json -Depth 5
if(@($checks|Where-Object {-not $_.Passed}).Count){exit 1}
