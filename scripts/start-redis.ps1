# Start Laragon Redis if not already listening on 6379.
$ErrorActionPreference = 'Stop'
$redisDir = 'D:\laragon\bin\redis\redis-x64-5.0.14.1'
$cli = Join-Path $redisDir 'redis-cli.exe'
$server = Join-Path $redisDir 'redis-server.exe'
$conf = Join-Path $redisDir 'redis.windows.conf'

if (-not (Test-Path $cli)) {
    Write-Error "Redis not found at $redisDir — enable Redis in Laragon first."
}

try {
    $pong = & $cli ping 2>$null
    if ($pong -eq 'PONG') {
        Write-Host 'Redis already running (PONG)'
        exit 0
    }
} catch {
    # not running
}

Start-Process -FilePath $server -ArgumentList $conf -WindowStyle Hidden
Start-Sleep -Seconds 1
$pong = & $cli ping
if ($pong -ne 'PONG') {
    Write-Error 'Failed to start Redis'
}
Write-Host 'Redis started (PONG)'
