# Luce Bianca — Launch PHP Backend API Server
$apiDir = if ($PSScriptRoot) { "$PSScriptRoot\api" } else { "C:\Users\ULTRA PC\Desktop\lucebianca\api" }
Set-Location -Path $apiDir
Write-Host "Starting Luce Bianca API on http://127.0.0.1:8090..." -ForegroundColor Green
php -S 127.0.0.1:8090 public/index.php
