@echo off
echo ========================================
echo MySQL Permission Reset Script
echo ========================================
echo.
echo Step 1: Stopping MySQL...
taskkill /F /IM mysqld.exe >nul 2>&1
timeout /t 3 >nul

echo Step 2: Starting MySQL in safe mode (skip-grant-tables)...
start "MySQL Safe Mode" /MIN "C:\xampp\mysql\bin\mysqld.exe" --skip-grant-tables --skip-networking
timeout /t 5 >nul

echo Step 3: Resetting permissions...
echo FLUSH PRIVILEGES; > C:\xampp\htdocs\Empower\reset_perms.sql
echo CREATE USER IF NOT EXISTS 'root'@'localhost' IDENTIFIED BY ''; >> C:\xampp\htdocs\Empower\reset_perms.sql
echo GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' WITH GRANT OPTION; >> C:\xampp\htdocs\Empower\reset_perms.sql
echo CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY ''; >> C:\xampp\htdocs\Empower\reset_perms.sql
echo GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION; >> C:\xampp\htdocs\Empower\reset_perms.sql
echo FLUSH PRIVILEGES; >> C:\xampp\htdocs\Empower\reset_perms.sql

"C:\xampp\mysql\bin\mysql.exe" -u root < C:\xampp\htdocs\Empower\reset_perms.sql

echo Step 4: Stopping safe mode...
taskkill /F /IM mysqld.exe >nul 2>&1
timeout /t 3 >nul

echo Step 5: Starting MySQL normally...
start "MySQL Normal" /MIN "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini"

timeout /t 5 >nul
echo.
echo ========================================
echo Done! MySQL permissions have been reset.
echo ========================================
echo.
echo Test your connection now:
echo http://localhost/empower/index.php?page=test-connection
echo.
pause
