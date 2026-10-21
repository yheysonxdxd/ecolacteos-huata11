# Conecta solo el celular al servidor por el cable USB.
#
# "adb reverse" se borra cada vez que se desconecta el cable. Este script queda
# vigilando (cada 3 segundos) y lo vuelve a poner apenas se conecta un celular
# con la depuracion USB aceptada. En la app el servidor es http://127.0.0.1:8000.
#
# Lo corre la tarea "Ecolacteos - cable del celular" al iniciar sesion en Windows.
# Registro en storage\logs\cable-celular.log.
#
# Uso manual:
#   powershell -ExecutionPolicy Bypass -File scripts\cable-celular.ps1

param(
    [string]$Adb = (Join-Path $env:LOCALAPPDATA "Android\Sdk\platform-tools\adb.exe"),
    [int]$Puerto = 8000
)

$raiz = Split-Path -Parent $PSScriptRoot          # carpeta ecolacteos-api
$log = Join-Path $raiz "storage\logs\cable-celular.log"

function Registrar([string]$texto) {
    $linea = "{0}  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $texto
    Write-Output $linea
    Add-Content -Path $log -Value $linea -Encoding UTF8
}

if (-not (Test-Path $Adb)) { Registrar "ERROR  no se encontro adb en $Adb"; exit 1 }

$listos = @{}   # celulares que ya tienen el puente puesto
Registrar "vigilando el cable USB"
while ($true) {
    # "R5CX60PX3NL    device" -> solo los que aceptaron la depuracion USB
    $conectados = @(& $Adb devices 2>$null | Select-String "^(\S+)\s+device$" | ForEach-Object { $_.Matches[0].Groups[1].Value })

    foreach ($s in $conectados) {
        # se revisa siempre: el puente tambien se pierde si se reinicia adb sin desconectar
        $puesto = (& $Adb -s $s reverse --list 2>$null) -match "tcp:$Puerto\s+tcp:$Puerto"
        if (-not $puesto) {
            & $Adb -s $s reverse "tcp:$Puerto" "tcp:$Puerto" 2>$null | Out-Null
            if ($LASTEXITCODE -eq 0) { $listos[$s] = $true; Registrar "celular $s conectado al servidor" }
        } else { $listos[$s] = $true }
    }
    foreach ($s in @($listos.Keys)) {
        if ($conectados -notcontains $s) { $listos.Remove($s); Registrar "celular $s desconectado" }
    }
    Start-Sleep -Seconds 3
}
