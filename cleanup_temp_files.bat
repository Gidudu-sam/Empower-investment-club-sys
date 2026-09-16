@echo off
echo ===============================================
echo  Empower - Cleanup Temporary Files
echo ===============================================
echo.
echo This will delete temporary files created during development.
echo.
pause

echo Deleting temporary files...

del /Q fix_mysql_permissions.bat 2>nul
if exist fix_mysql_permissions.bat (
    echo [X] Failed to delete fix_mysql_permissions.bat
) else (
    echo [√] Deleted fix_mysql_permissions.bat
)

del /Q test_connection.php 2>nul
if exist test_connection.php (
    echo [X] Failed to delete test_connection.php
) else (
    echo [√] Deleted test_connection.php
)

echo.
echo Deleting test output files...
del /Q tests\*_output.txt 2>nul
echo [√] Deleted test output files

echo.
echo ===============================================
echo  Cleanup Complete!
echo ===============================================
echo.
echo Remaining files:
echo - Documentation files (*.md) - kept for reference
echo - Test scripts (tests/*.php) - kept for testing
echo - Database migrations - kept for version control
echo.
pause
