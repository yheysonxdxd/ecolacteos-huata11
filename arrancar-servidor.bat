@echo off
rem Ecolacteos Huata: prende MySQL, el servidor Laravel y el tunel ngrok.
rem Se llama solo al prender la PC (acceso directo en la carpeta Inicio de Windows).
rem Si algo ya esta prendido no lo vuelve a prender. El servidor y ngrok se reinician solos si se caen.

set NGROK=%LOCALAPPDATA%\Microsoft\WinGet\Packages\Ngrok.Ngrok_Microsoft.Winget.Source_8wekyb3d8bbwe\ngrok.exe
set PHP=C:\xampp82\php\php.exe

if "%1"=="servidor" goto servidor
if "%1"=="tunel" goto tunel

netstat -ano | find ":3306 " | find "LISTENING" >nul || start "MySQL Huata" /min /d C:\xampp82\mysql bin\mysqld.exe --defaults-file=bin\my.ini --standalone
timeout /t 8 /nobreak >nul
netstat -ano | find ":8000 " | find "LISTENING" >nul || start "Servidor Huata" /min cmd /c "%~f0" servidor
tasklist /fi "imagename eq ngrok.exe" | find /i "ngrok.exe" >nul || start "ngrok Huata" /min cmd /c "%~f0" tunel
exit /b

:servidor
cd /d D:\Huata2.0\ecolacteos-api
"%PHP%" artisan serve --host=0.0.0.0 --port=8000
timeout /t 5 /nobreak >nul
goto servidor

:tunel
"%NGROK%" http 8000
timeout /t 10 /nobreak >nul
goto tunel
