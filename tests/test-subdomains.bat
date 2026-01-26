@echo off
REM Test script for subdomain setup on Windows
REM Run this to verify your wildcard DNS and subdomain routing is working

echo 🧪 Testing Subdomain Setup...
echo ================================

REM Test DNS resolution
echo 1. Testing DNS Resolution...
echo    jackpot.local: 
nslookup jackpot.local >nul 2>&1
if %errorlevel%==0 (
    echo    ✅ Resolves
) else (
    echo    ❌ Failed to resolve
)

echo    test.jackpot.local: 
nslookup test.jackpot.local >nul 2>&1
if %errorlevel%==0 (
    echo    ✅ Resolves ^(wildcard working!^)
) else (
    echo    ❌ Failed to resolve ^(wildcard not working^)
)

REM Test HTTP connectivity
echo.
echo 2. Testing HTTP Connectivity...

echo    http://jackpot.local: 
curl -s -o nul -w "%%{http_code}" http://jackpot.local | findstr /C:"200" >nul
if %errorlevel%==0 (
    echo    ✅ Accessible
) else (
    curl -s -o nul -w "%%{http_code}" http://jackpot.local | findstr /C:"302" >nul
    if %errorlevel%==0 (
        echo    ✅ Accessible
    ) else (
        echo    ❌ Not accessible
    )
)

echo    http://test-company.jackpot.local: 
curl -s -o nul -w "%%{http_code}" http://test-company.jackpot.local | findstr /C:"200" >nul
if %errorlevel%==0 (
    echo    ✅ Accessible ^(subdomain routing working!^)
) else (
    curl -s -o nul -w "%%{http_code}" http://test-company.jackpot.local | findstr /C:"302" >nul
    if %errorlevel%==0 (
        echo    ✅ Accessible ^(subdomain routing working!^)
    ) else (
        echo    ❌ Not accessible ^(check nginx/server setup^)
    )
)

echo.
echo ================================
echo 🎯 Setup Status Summary:
echo.

REM Check if both DNS and HTTP are working
nslookup test.jackpot.local >nul 2>&1
set DNS_OK=%errorlevel%

curl -s -o nul http://test-company.jackpot.local >nul 2>&1
set HTTP_OK=%errorlevel%

if %DNS_OK%==0 if %HTTP_OK%==0 (
    echo ✅ Everything looks good! Wildcard subdomains should work.
    echo    Try: http://your-company-name.jackpot.local
) else if %DNS_OK%==0 (
    echo ⚠️  DNS is working but HTTP server needs configuration.
    echo    Check nginx configuration and restart services.
) else if %HTTP_OK%==0 (
    echo ⚠️  HTTP is working but wildcard DNS needs setup.
    echo    Use Laragon or configure DNS manually.
) else (
    echo ❌ Both DNS and HTTP need configuration.
    echo    Follow the setup guide in SUBDOMAIN_SETUP.md
)

echo.
echo 📖 For detailed setup instructions, see: SUBDOMAIN_SETUP.md
echo.
echo Windows Users: Consider using Laragon for automatic wildcard DNS
echo https://laragon.org/

pause