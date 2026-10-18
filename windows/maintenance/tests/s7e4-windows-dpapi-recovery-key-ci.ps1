# F1-S7E4B: disposable Windows cryptographic primitive self-test.
# NO production key management, no real offsite provider, no true clean-machine restore.
$ErrorActionPreference = 'Stop'
if ($env:GITHUB_ACTIONS -ne 'true' -or $env:RUNNER_OS -ne 'Windows' -or
    [string]::IsNullOrWhiteSpace($env:RUNNER_TEMP)) { throw 'S7E4B_WINDOWS_RUNNER_ONLY' }
$root = Join-Path $env:RUNNER_TEMP ('sokna-f1-s7e4b-'+[guid]::NewGuid().ToString('N'))
$bundle = Join-Path $root 'encrypted-backup-artifact'
$operator = Join-Path $root 'operator-held-recovery-key-NOT-BACKUP'
$local = Join-Path $root 'windows-service-protected-storage-NOT-BACKUP'
$null = New-Item -ItemType Directory -Path $bundle,$operator,$local -Force
try {
  $dek = [System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32)
  $nonce = [System.Security.Cryptography.RandomNumberGenerator]::GetBytes(12)
  $plain = [Text.Encoding]::UTF8.GetBytes('CI_SAMPLE_BUSINESS_DATA_NOT_REAL_CAFE')
  $cipher = [byte[]]::new($plain.Length)
  $tag = [byte[]]::new(16)
  $aad = [Text.Encoding]::UTF8.GetBytes('sokna-ci-business-backup-v1|sample|no-real-machine-identity')
  $aes = [System.Security.Cryptography.AesGcm]::new($dek,16)
  try { $aes.Encrypt($nonce,$plain,$cipher,$tag,$aad) } finally { $aes.Dispose() }
  $rsa = [System.Security.Cryptography.RSA]::Create(3072)
  try {
    $privateKey = $rsa.ExportPkcs8PrivateKey()
    $publicKey = $rsa.ExportSubjectPublicKeyInfo()
    [IO.File]::WriteAllBytes((Join-Path $operator 'operator-recovery-test-only.pk8'),$privateKey)
    $wrapped = $rsa.Encrypt($dek,[System.Security.Cryptography.RSAEncryptionPadding]::OaepSHA256)
  } finally { $rsa.Dispose() }

  # Local automated schedule can decrypt its own DPAPI-protected DEK. Keep the
  # private key and DPAPI-protected copy OUTSIDE the off-device backup artifact.
  $entropy = [Text.Encoding]::UTF8.GetBytes('SOKNA-S7E4B-CI-ONLY-DPAPI-2026')
  $protected = [System.Security.Cryptography.ProtectedData]::Protect(
    $dek,$entropy,[System.Security.Cryptography.DataProtectionScope]::CurrentUser)
  [IO.File]::WriteAllBytes((Join-Path $local 'scheduled-key.dpapi'),$protected)
  $unwrappedLocal = [System.Security.Cryptography.ProtectedData]::Unprotect(
    $protected,$entropy,[System.Security.Cryptography.DataProtectionScope]::CurrentUser)
  if (-not [System.Security.Cryptography.CryptographicOperations]::FixedTimeEquals(
    $dek,$unwrappedLocal)) { throw 'S7E4B_DPAPI_ROUNDTRIP_FAILED' }

  [IO.File]::WriteAllBytes((Join-Path $bundle 'payload.cipher'),$cipher)
  [IO.File]::WriteAllBytes((Join-Path $bundle 'payload.tag'),$tag)
  [IO.File]::WriteAllBytes((Join-Path $bundle 'payload.nonce'),$nonce)
  [IO.File]::WriteAllBytes((Join-Path $bundle 'dek.oaep-wrapped'),$wrapped)
  $manifest = [ordered]@{
    format='sokna-s7e4b-offdevice-backup-fixture-v1'
    algorithm='AES-256-GCM'; dek_wrap_algorithm='RSA-OAEP-SHA256'
    ciphertext_sha256=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($cipher)).ToLowerInvariant()
    key_id='CI-ONLY-RECOVERY-KEY'; production_custody_qualified=$false
  }
  $manifest | ConvertTo-Json -Compress | Set-Content -LiteralPath (Join-Path $bundle 'manifest.json') -Encoding utf8

  foreach ($file in @(Get-ChildItem -LiteralPath $bundle -File)) {
    if ($file.Name -match 'private|\.pk8|\.dpapi|machine-secret|\.sql$|\.pem$') {
      throw 'S7E4B_PRIVATE_KEY_OR_PLAINTEXT_IN_BACKUP_ARTIFACT'
    }
    $blob = [IO.File]::ReadAllBytes($file.FullName)
    if ([Text.Encoding]::UTF8.GetString($blob).Contains('CI_SAMPLE_BUSINESS_DATA_NOT_REAL_CAFE')) {
      throw 'S7E4B_PLAINTEXT_IN_BACKUP_ARTIFACT'
    }
  }

  # Simulated independent recovery: use ONLY the operator Recovery File and
  # backup artifact; do not use the DPAPI key or Windows service store.
  $importRsa = [System.Security.Cryptography.RSA]::Create()
  try {
    $readKey = [IO.File]::ReadAllBytes((Join-Path $operator 'operator-recovery-test-only.pk8'))
    $consumed = 0
    $importRsa.ImportPkcs8PrivateKey($readKey,[ref]$consumed)
    $restoredDek = $importRsa.Decrypt([IO.File]::ReadAllBytes(
      (Join-Path $bundle 'dek.oaep-wrapped')),[Security.Cryptography.RSAEncryptionPadding]::OaepSHA256)
  } finally { $importRsa.Dispose() }
  if (-not [Security.Cryptography.CryptographicOperations]::FixedTimeEquals($restoredDek,$dek)) {
    throw 'S7E4B_OPERATOR_KEY_COULD_NOT_UNWRAP_DEK'
  }
  $newCipher = [IO.File]::ReadAllBytes((Join-Path $bundle 'payload.cipher'))
  $newNonce = [IO.File]::ReadAllBytes((Join-Path $bundle 'payload.nonce'))
  $newTag = [IO.File]::ReadAllBytes((Join-Path $bundle 'payload.tag'))
  $newPlain = [byte[]]::new($newCipher.Length)
  $dec = [Security.Cryptography.AesGcm]::new($restoredDek,16)
  try {
    $dec.Decrypt($newNonce,$newCipher,$newTag,$newPlain,$aad)
    if (-not [Security.Cryptography.CryptographicOperations]::FixedTimeEquals($newPlain,$plain)) {
      throw 'S7E4B_RECOVERY_DATA_MISMATCH'
    }
    $corruptedCipher = [byte[]]$newCipher.Clone()
    $corruptedCipher[0] = $corruptedCipher[0] -bxor 1
    $denied = $false
    try { $dec.Decrypt($newNonce,$corruptedCipher,$newTag,[byte[]]::new($newPlain.Length),$aad) }
    catch [System.Security.Cryptography.AuthenticationTagMismatchException] { $denied = $true }
    if (-not $denied) { throw 'S7E4B_TAMPERED_CIPHER_ACCEPTED' }
    $denied = $false
    try { $dec.Decrypt($newNonce,$newCipher,$newTag,[byte[]]::new($newPlain.Length),
      [Text.Encoding]::UTF8.GetBytes('SOKNA-MISMATCHED-BACKUP-METADATA')) }
    catch [System.Security.Cryptography.AuthenticationTagMismatchException] { $denied = $true }
    if (-not $denied) { throw 'S7E4B_AAD_CHANGE_ACCEPTED' }
  } finally { $dec.Dispose() }

  $wrongRsa = [Security.Cryptography.RSA]::Create(3072)
  try {
    $wrongAccepted = $false
    try { $null = $wrongRsa.Decrypt($wrapped,[Security.Cryptography.RSAEncryptionPadding]::OaepSHA256);$wrongAccepted=$true }
    catch [System.Security.Cryptography.CryptographicException] { }
    if ($wrongAccepted) { throw 'S7E4B_WRONG_RECOVERY_KEY_ACCEPTED' }
  } finally { $wrongRsa.Dispose() }
  @{ format='sokna-s7e4b-windows-dpapi-recovery-primitives-v1'; result='PASS';
     dpapi_current_user_roundtrip=$true; backup_artifact_excludes_local_and_recovery_keys=$true;
     simulated_operator_recovery_without_dpapi=$true; tampered_cipher_rejected=$true;
     aad_tamper_rejected=$true; wrong_recovery_key_rejected=$true;
     separate_host_recovery_tested=$false; production_key_custody_qualified=$false;
     real_provider_kms_tested=$false; panel_update_ready=$false } | ConvertTo-Json -Compress
} finally {
  Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}