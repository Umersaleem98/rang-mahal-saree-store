$ErrorActionPreference = 'Stop'
$localStore = 'C:\xampp\htdocs\zariya-saree-store'
$php = 'C:\xampp\php\php.exe'
if (!(Test-Path -LiteralPath (Join-Path $localStore '.env'))) { throw 'Local store environment is missing.' }
if (!(Select-String -LiteralPath (Join-Path $localStore '.env') -Pattern '^APP_ENV=(local|"local")$' -Quiet)) { throw 'This worker is restricted to the local environment.' }
$process = Start-Process -FilePath $php -ArgumentList @((Join-Path $localStore 'artisan'), 'orders:send-emails', '--limit=20') -WorkingDirectory $localStore -WindowStyle Hidden -Wait -PassThru
exit $process.ExitCode
