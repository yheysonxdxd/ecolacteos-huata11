# Prende MySQL de XAMPP y el servidor de Ecolacteos Huata sin abrir ventanas.
#
# - Si ya estan prendidos (por ejemplo, alguien pulso Start en XAMPP), no hace nada.
# - Lo corre la tarea "Ecolacteos - prender servidor" al iniciar sesion en Windows.
# - Deja un registro en storage\logs\prender-servidor.log.
#
# Uso manual:
#   powershell -ExecutionPolicy Bypass -File scripts\prender-servidor.ps1
#
# Para apagar: XAMPP Control Panel > Stop en MySQL, y en el Administrador de
# tareas terminar "php.exe".

param(
    [string]$Xampp = "C:\xampp82",
    [int]$Puerto = 8000
)

$raiz = Split-Path -Parent $PSScriptRoot          # carpeta ecolacteos-api
$log = Join-Path $raiz "storage\logs\prender-servidor.log"

function Registrar([string]$texto) {
    $linea = "{0}  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $texto
    Write-Output $linea
    Add-Content -Path $log -Value $linea -Encoding UTF8
}

function Escucha([int]$p) {
    $c = New-Object Net.Sockets.TcpClient
    try { $c.Connect("127.0.0.1", $p); return $true } catch { return $false } finally { $c.Close() }
}

# --- MySQL (igual que mysql_start.bat de XAMPP, pero sin ventana) ---
if (Escucha 3306) {
    Registrar "MySQL ya estaba prendido"
} else {
    Start-Process -FilePath (Join-Path $Xampp "mysql\bin\mysqld.exe") `
        -ArgumentList "--defaults-file=mysql\bin\my.ini", "--standalone" `
        -WorkingDirectory $Xampp -WindowStyle Hidden
    $limite = (Get-Date).AddMinutes(2)
    while (-not (Escucha 3306) -and (Get-Date) -lt $limite) { Start-Sleep -Seconds 2 }
    if (Escucha 3306) { Registrar "MySQL prendido" } else { Registrar "ERROR  MySQL no prendio (revisar XAMPP)"; exit 1 }
}

# --- servidor Laravel (la web de oficina y la app) ---
if (Escucha $Puerto) {
    Registrar "El servidor ya estaba prendido en el puerto $Puerto"
} else {
    Start-Process -FilePath (Join-Path $Xampp "php\php.exe") `
        -ArgumentList "artisan", "serve", "--host=0.0.0.0", "--port=$Puerto" `
        -WorkingDirectory $raiz -WindowStyle Hidden
    $limite = (Get-Date).AddSeconds(30)
    while (-not (Escucha $Puerto) -and (Get-Date) -lt $limite) { Start-Sleep -Seconds 1 }
    if (Escucha $Puerto) { Registrar "Servidor prendido: http://localhost:$Puerto/oficina" }
    else { Registrar "ERROR  el servidor no prendio en el puerto $Puerto"; exit 1 }
}
exit 0
