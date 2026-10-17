# Luce Bianca — Launch Frontend Dev Server
$frontendDir = if ($PSScriptRoot) { "$PSScriptRoot\frontend" } else { "C:\Users\ULTRA PC\Desktop\lucebianca\frontend" }
Set-Location -Path $frontendDir
Write-Host "Starting Luce Bianca Frontend (Next.js) on http://localhost:3000..." -ForegroundColor Cyan
npm run dev
