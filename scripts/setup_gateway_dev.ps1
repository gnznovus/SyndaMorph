# SyndaMorph local Secure Gateway bootstrap.
# Existing .env values (including AI provider credentials) are preserved.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$envPath = Join-Path $root '.env'
$existing = if (Test-Path -LiteralPath $envPath) { [System.IO.File]::ReadAllText($envPath) } else { '' }

function New-GatewaySecret {
    $data = New-Object byte[] 48
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($data) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($data).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

$changed = $false
foreach ($key in @('SYNDAMORPH_GATEWAY_PEPPER', 'SYNDAMORPH_GATEWAY_ADMIN_TOKEN')) {
    $escaped = [regex]::Escape($key)
    $pattern = "(?m)^\s*$escaped\s*=\s*(.*)$"
    $match = [regex]::Match($existing, $pattern)
    if ($match.Success) {
        if (-not [string]::IsNullOrWhiteSpace($match.Groups[1].Value)) {
            Write-Host "$key already configured; preserving existing value."
            continue
        }
        $existing = [regex]::Replace($existing, $pattern, { param($m) "$key=$(New-GatewaySecret)" }, 1)
    } else {
        if ($existing.Length -gt 0 -and -not $existing.EndsWith("`n")) { $existing += "`n" }
        $existing += "$key=$(New-GatewaySecret)`n"
    }
    $changed = $true
    Write-Host "$key generated (secret hidden)."
}

if ($changed) {
    [System.IO.File]::WriteAllText($envPath, $existing, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host 'Local .env updated. Keep it out of source control.'
} else {
    Write-Host 'Gateway secrets already present. No changes made.'
}
Write-Host 'Next: docker compose up -d --build gateway wordpress'
