<?php
namespace App\Services\Dashboard;

/**
 * Private enrollment transport, not proof that its issuer is authorized.
 * The controller must authenticate the caller and derive the payload from
 * WorkspaceAuthority; only the native process may receive the decrypted data.
 *
 * PHP 8.2 has no OAEP digest parameter. Its OpenSSL OAEP default is SHA-1;
 * Node explicitly selects the same OAEP/MGF1 digest and empty label. This
 * wraps only a random 32-byte AES key. The payload uses AES-256-GCM with AAD.
 * https://www.php.net/manual/en/function.openssl-public-encrypt.php
 * https://www.php.net/manual/en/function.openssl-encrypt.php
 * https://docs.openssl.org/3.0/man3/RSA_public_encrypt/
 */
final class DesktopDashboardWorkspaceEnrollmentSeal
{
    public const MAX_PLAINTEXT_BYTES = 17 * 1024 * 1024;
    public const MAX_ENVELOPE_BYTES = 24 * 1024 * 1024;
    private const MAX_MANIFEST_BYTES = 16 * 1024 * 1024;
    private const KIND = 'desktop-dashboard-workspace-enrollment-seal';
    private const SAFE = 9007199254740991;

    public function seal(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] string $publicKey, string $workspaceId, string $nonce): array
    {
        $key = '';
        try {
            $this->need($this->uuid($workspaceId) && $this->hash($nonce), 'CONTEXT_INVALID');
            $this->payload($payload, $workspaceId);
            $this->need(strlen($publicKey) <= 2048 && preg_match('/\A-----BEGIN PUBLIC KEY-----\r?\n[A-Za-z0-9+\/=\r\n]+-----END PUBLIC KEY-----\r?\n?\z/D', $publicKey), 'RECIPIENT_INVALID');
            $public = @openssl_pkey_get_public($publicKey);
            $details = $public ? @openssl_pkey_get_details($public) : false;
            $this->need(is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA
                && in_array($details['bits'] ?? null, [3072, 4096], true)
                && ($details['rsa']['e'] ?? null) === "\x01\x00\x01", 'RECIPIENT_INVALID');
            $der = $this->publicDer($details['key']);
            $header = ['format'=>2, 'kind'=>self::KIND, 'workspace_id'=>$workspaceId,
                'recipient_sha256'=>hash('sha256', $der), 'nonce_sha256'=>hash('sha256', $nonce)];
            $plaintext = DesktopDashboardWorkspaceAuthority::canonical($payload);
            $this->need(strlen($plaintext) <= self::MAX_PLAINTEXT_BYTES, 'PAYLOAD_TOO_LARGE');
            $key = random_bytes(32); $iv = random_bytes(12); $tag = '';
            $ciphertext = @openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag,
                DesktopDashboardWorkspaceAuthority::canonical($header), 16);
            $this->need(is_string($ciphertext) && strlen($tag) === 16, 'CRYPTO_FAILED');
            $wrapped = '';
            $ok = @openssl_public_encrypt($key, $wrapped, $public, OPENSSL_PKCS1_OAEP_PADDING);
            $this->need($ok && strlen($wrapped) === intdiv($details['bits'], 8), 'CRYPTO_FAILED');
            $envelope = ['header'=>$header, 'encrypted_key'=>base64_encode($wrapped), 'iv'=>base64_encode($iv),
                'ciphertext'=>base64_encode($ciphertext), 'tag'=>base64_encode($tag)];
            $this->need(strlen(self::encode($envelope)) <= self::MAX_ENVELOPE_BYTES, 'ENVELOPE_TOO_LARGE');
            return $envelope;
        } catch (\Throwable $error) {
            if ($error instanceof \InvalidArgumentException
                && preg_match('/\AWORKSPACE_ENROLLMENT_SEAL_[A-Z_]+\z/D', $error->getMessage())) throw $error;
            throw new \InvalidArgumentException('WORKSPACE_ENROLLMENT_SEAL_INVALID');
        } finally {
            // Do not expose OpenSSL diagnostics, keys or payload values to logs.
            try { if ($key !== '' && function_exists('sodium_memzero')) sodium_memzero($key); } catch (\Throwable $ignored) {}
            if (function_exists('openssl_error_string')) while (openssl_error_string() !== false) {}
        }
    }

    /** Send only this sealed canonical JSON directly with application/json. */
    public static function encode(#[\SensitiveParameter] array $envelope): string
    {
        $guard = new self;
        try {
            // Reject a caller accidentally handing the private payload to the
            // transport serializer. The receiver validates every crypto field.
            $guard->need($guard->exact($envelope, ['header','encrypted_key','iv','ciphertext','tag'])
                && is_array($envelope['header'])
                && $guard->exact($envelope['header'], ['format','kind','workspace_id','recipient_sha256','nonce_sha256'])
                && $envelope['header']['format'] === 2 && $envelope['header']['kind'] === self::KIND
                && $guard->uuid($envelope['header']['workspace_id'])
                && $guard->hash($envelope['header']['recipient_sha256'])
                && $guard->hash($envelope['header']['nonce_sha256']), 'ENVELOPE_INVALID');
            foreach (['encrypted_key'=>684, 'iv'=>16, 'tag'=>24,
                'ciphertext'=>4 * intdiv(self::MAX_PLAINTEXT_BYTES + 2, 3)] as $field=>$maximum) {
                $value = $envelope[$field];
                $guard->need(is_string($value) && $value !== '' && strlen($value) <= $maximum
                    && strlen($value) % 4 === 0 && preg_match('/\A[A-Za-z0-9+\/]*={0,2}\z/D', $value), 'ENVELOPE_INVALID');
            }
            $json = DesktopDashboardWorkspaceAuthority::canonical($envelope);
            $guard->need(strlen($json) <= self::MAX_ENVELOPE_BYTES, 'ENVELOPE_TOO_LARGE');
            return $json;
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('WORKSPACE_ENROLLMENT_SEAL_ENVELOPE_INVALID');
        }
    }

    private function payload(#[\SensitiveParameter] array $payload, string $workspaceId): void
    {
        $this->need($this->exact($payload, ['credential','manifest']), 'PAYLOAD_INVALID');
        $credential = $payload['credential']; $manifest = $payload['manifest'];
        $this->need(is_array($credential) && $this->exact($credential, ['workspaceId','serverOrigin','token','enrollmentId'])
            && $credential['workspaceId'] === $workspaceId && $this->origin($credential['serverOrigin'])
            && $this->hash($credential['token']) && $this->hash($credential['enrollmentId']), 'PAYLOAD_INVALID');
        $this->need(is_array($manifest) && $this->exact($manifest, ['format','kind','workspace_id','revision','root_fingerprint','members','roster_sha256','signature'])
            && $manifest['format'] === 2 && $manifest['kind'] === 'desktop-dashboard-workspace-members'
            && $manifest['workspace_id'] === $workspaceId && $this->integer($manifest['revision'])
            && $manifest['root_fingerprint'] === $credential['enrollmentId']
            && $this->hash($manifest['roster_sha256']) && $this->hash($manifest['signature'])
            && is_array($manifest['members']) && array_is_list($manifest['members'])
            && count($manifest['members']) > 0 && count($manifest['members']) <= 4096, 'PAYLOAD_INVALID');
        $seen = []; $previous = 0;
        foreach ($manifest['members'] as $member) {
            $this->need(is_array($member) && $this->exact($member,
                ['actor_id','role','enabled','aliases','branches','permissions','password_binding','grant'])
                && $this->integer($member['actor_id']) && $member['actor_id'] > $previous
                && in_array($member['role'], ['admin','vendor','resturant_owner'], true) && $member['enabled'] === true
                && $this->strings($member['aliases'], 2, 320, true) && count($member['aliases']) > 0
                && $this->strings($member['branches'], 10000, 24) && $this->hash($member['password_binding'])
                && $this->hash($member['grant']) && is_array($member['permissions'])
                && $this->exact($member['permissions'], ['super_admin','names','roles'])
                && is_bool($member['permissions']['super_admin'])
                && $this->permissionStrings($member['permissions']['names'])
                && $this->permissionStrings($member['permissions']['roles']), 'PAYLOAD_INVALID');
            foreach ($member['branches'] as $branch) $this->need((bool)preg_match('/\A(?:f|gs):[1-9][0-9]{0,18}\z/D', $branch), 'PAYLOAD_INVALID');
            foreach ($member['aliases'] as $alias) {
                $this->need(!isset($seen[$alias]), 'PAYLOAD_INVALID'); $seen[$alias] = true;
            }
            $previous = $member['actor_id'];
        }
        $this->need(hash_equals($manifest['roster_sha256'], hash('sha256', DesktopDashboardWorkspaceAuthority::canonical($manifest['members'])))
            && strlen(DesktopDashboardWorkspaceAuthority::canonical($manifest)) <= self::MAX_MANIFEST_BYTES, 'PAYLOAD_INVALID');
    }

    private function publicDer(string $pem): string
    {
        $encoded = str_replace(["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\r", "\n"], '', $pem);
        $der = base64_decode($encoded, true);
        $this->need(is_string($der) && base64_encode($der) === $encoded, 'RECIPIENT_INVALID'); return $der;
    }
    private function strings($values, int $maximum, int $length, bool $lowercase = false): bool
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > $maximum) return false;
        $previous = null;
        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 4 * $length || trim($value) !== $value
                || preg_match('/[\x00-\x1f\x7f]/', $value)
                || preg_match('/\A[\p{Zs}\x{FEFF}\x{2028}\x{2029}]|[\p{Zs}\x{FEFF}\x{2028}\x{2029}]\z/u', $value)
                || ($lowercase && (strtolower($value) !== $value || preg_match('/\p{Uppercase}|\p{Lt}/u', $value)))
                || ($previous !== null && strcmp($previous, $value) >= 0)) return false;
            // Match JavaScript string.length: supplementary characters use two UTF-16 units.
            $points = preg_match_all('/./us', $value, $unused);
            $supplementary = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value, $unused);
            if ($points === false || $supplementary === false || $points + $supplementary > $length) return false;
            $previous = $value;
        }
        return true;
    }
    private function origin($value): bool
    {
        if (!is_string($value) || strlen($value) > 2048) return false;
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || array_diff(array_keys($parts), ['scheme','host','port'])) return false;
        $host = $parts['host']; $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        if (strtolower($host) !== $host || preg_match('/[^\x21-\x7e]/', $host)
            || ($parts['port'] ?? null) === 443 || str_contains($host, '%')) return false;
        if (preg_match('/(?:\A|\.)(?:[0-9]+|0x[a-f0-9]+)\z/D', $host)
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
        return $value === 'https://'.$host.$port;
    }
    private function permissionStrings($values): bool
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 4096) return false;
        $previous = null;
        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)
                || ($previous !== null && strcmp($previous, $value) >= 0)) return false;
            $previous = $value;
        }
        return true;
    }
    private function exact(array $value, array $keys): bool
    { return count($value) === count($keys) && array_diff(array_keys($value), $keys) === []; }
    private function integer($value): bool { return is_int($value) && $value > 0 && $value <= self::SAFE; }
    private function uuid($value): bool { return is_string($value) && (bool)preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $value); }
    private function hash($value): bool { return is_string($value) && (bool)preg_match('/\A[a-f0-9]{64}\z/D', $value); }
    private function need(bool $value, string $code): void
    { if (!$value) throw new \InvalidArgumentException('WORKSPACE_ENROLLMENT_SEAL_'.$code); }
}
