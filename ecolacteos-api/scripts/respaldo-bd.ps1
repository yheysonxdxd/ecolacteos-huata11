# Respaldo diario de la base de datos de Ecolacteos Huata.
#
# - Lee la conexion (DB_*) del .env de ecolacteos-api: aqui no hay claves.
# - Genera un .sql con mysqldump, verifica que este completo y lo comprime.
# - Borra los respaldos con mas de $Dias dias.
# - Deja un registro en respaldos.log (en la carpeta de destino).
#
# Uso manual:
#   powershell -ExecutionPolicy Bypass -File scripts\respaldo-bd.ps1
#   powershell -ExecutionPolicy Bypass -File scripts\respaldo-bd.ps1 -Destino E:\Respaldos -Dias 60
#
# Restaurar (OJO: reemplaza los datos de la base indicada):
#   1. Descomprimir el .zip
#   2. C:\xampp\mysql\bin\mysql.exe -u root ecolacteos_huata < ecolacteos_huata_AAAA-MM-DD_HHMM.sql

param(
    [string]$Destino = "D:\Respaldos\EcolacteosHuata",
    [int]$Dias = 30,
    [string]$MysqlBin = "C:\xampp\mysql\bin"
)

$ErrorActionPreference = "Stop"
$raiz = Split-Path -Parent $PSScriptRoot          # carpeta ecolacteos-api
$log = Join-Path $Destino "respaldos.log"

function Registrar([string]$texto) {
    $linea = "{0}  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $texto
    Write-Output $linea
    Add-Content -Path $log -Value $linea -Encoding UTF8
}

New-Item -ItemType Directory -Force -Path $Destino | Out-Null

try {
    # --- conexion desde el .env ---
    $env_ = @{}
    Get-Content (Join-Path $raiz ".env") | Where-Object { $_ -match '^\s*DB_[A-Z]+\s*=' } | ForEach-Object {
        $k, $v = $_ -split '=', 2
        $env_[$k.Trim()] = $v.Trim().Trim('"').Trim("'")
    }
    $bd = $env_["DB_DATABASE"]
    if (-not $bd) { throw "No se encontro DB_DATABASE en el .env" }
    $hostBd = if ($env_["DB_HOST"]) { $env_["DB_HOST"] } else { "127.0.0.1" }
    $puerto = if ($env_["DB_PORT"]) { $env_["DB_PORT"] } else { "3306" }
    $usuario = if ($env_["DB_USERNAME"]) { $env_["DB_USERNAME"] } else { "root" }

    # la clave va por variable de entorno, no en la linea de comandos
    $env:MYSQL_PWD = $env_["DB_PASSWORD"]

    $marca = Get-Date -Format "yyyy-MM-dd_HHmm"
    $sql = Join-Path $Destino "${bd}_$marca.sql"
    $zip = "$sql.zip"

    & (Join-Path $MysqlBin "mysqldump.exe") `
        "--host=$hostBd" "--port=$puerto" "--user=$usuario" `
        --single-transaction --routines --triggers --default-character-set=utf8mb4 `
        "--result-file=$sql" $bd
    if ($LASTEXITCODE -ne 0) { throw "mysqldump termino con codigo $LASTEXITCODE" }

    # un volcado completo termina con "-- Dump completed"
    $final = Get-Content $sql -Tail 3 -Encoding UTF8
    if (-not ($final -match "Dump completed")) { throw "El respaldo quedo incompleto: $sql" }

    Compress-Archive -Path $sql -DestinationPath $zip -Force
    Remove-Item $sql
    $mb = [math]::Round((Get-Item $zip).Length / 1MB, 2)
    Registrar "OK  $zip  ($mb MB)"

    # --- rotacion ---
    $viejos = Get-ChildItem $Destino -Filter "${bd}_*.sql.zip" |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$Dias) }
    foreach ($f in $viejos) { Remove-Item $f.FullName; Registrar "borrado por antiguedad: $($f.Name)" }
    exit 0
}
catch {
    Registrar "ERROR  $($_.Exception.Message)"
    exit 1
}
finally {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
}
