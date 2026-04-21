@echo off
REM filepath: c:\xampp\htdocs\library\admin\run_worker.bat

title Library Worker Service
color 0A

:loop
cls
echo.
echo ╔════════════════════════════════════════════════════╗
echo ║         Library Worker Service                     ║
echo ║         تشغيل معالج الطابور...                   ║
echo ╚════════════════════════════════════════════════════╝
echo.
echo [%date% %time%] جاري المعالجة...
echo.

C:\xampp\php\php.exe C:\xampp\htdocs\library\admin\worker.php

echo.
echo ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo انتظار 60 ثانية قبل التشغيل التالي...
echo ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo.

timeout /t 60 /nobreak

goto loop