@echo off
:loop
C:\xampp\php\php.exe C:\xampp\htdocs\library\admin\worker.php
echo Worker توقف - إعادة تشغيل بعد 10 ثواني...
timeout /t 10
goto loop