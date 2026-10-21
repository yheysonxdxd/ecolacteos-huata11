# Muestra los links para que los companeros se conecten por el WiFi en el que
# esta la PC ahora (la IP cambia con cada WiFi) y copia el de la web al portapapeles.
#
# Uso: doble clic en "Ver link Ecolacteos" del escritorio, o
#   powershell -ExecutionPolicy Bypass -File scripts\mi-link.ps1

param([int]$Puerto = 8000)

$wifi = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object { $_.InterfaceAlias -match 'Wi-?Fi|WLAN|Inal' -and $_.IPAddress -notmatch '^169\.254' } |
    Select-Object -First 1
$red = (Get-NetConnectionProfile -ErrorAction SilentlyContinue | Where-Object { $_.InterfaceAlias -eq $wifi.InterfaceAlias }).Name

Write-Host ""
if (-not $wifi) {
    Write-Host "  La PC no esta conectada a ningun WiFi." -ForegroundColor Yellow
} else {
    $ip = $wifi.IPAddress
    $c = New-Object Net.Sockets.TcpClient
    $prendido = try { $c.Connect("127.0.0.1", $Puerto); $true } catch { $false } finally { $c.Close() }

    Write-Host "  WiFi: $red" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "  Web de oficina (otra PC o celular):  http://${ip}:$Puerto/oficina" -ForegroundColor Green
    Write-Host "  App del celular, en 'Servidor':      http://${ip}:$Puerto" -ForegroundColor Green
    Write-Host ""
    if ($prendido) { Write-Host "  El servidor esta prendido." }
    else { Write-Host "  OJO: el servidor esta APAGADO. Prendelo con XAMPP (MySQL) y php artisan serve." -ForegroundColor Yellow }
    Write-Host "  Los companeros deben estar en el mismo WiFi: $red"
    Set-Clipboard -Value "http://${ip}:$Puerto/oficina"
    Write-Host ""
    Write-Host "  (El link de la web ya esta copiado: pegalo con Ctrl+V en WhatsApp.)"
}
Write-Host ""
Read-Host "  Enter para cerrar"
