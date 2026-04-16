@echo off
REM filepath: c:\xampp\htdocs\library\admin\start_worker_silent.bat

REM تشغيل run_worker.bat في الخلفية بدون نافذة
START "" /MIN C:\xampp\htdocs\library\admin\run_worker.bat

REM الخروج من هذا الملف
exit /b