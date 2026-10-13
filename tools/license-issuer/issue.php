<?php

/**
 * Smart Campus license issuer (COM-02) — VENDOR SIDE ONLY.
 *
 * Not part of the deployed product. Run on the vendor's offline signing
 * workstation. The Ed25519 private key must live OUTSIDE this repository
 * (the tool refuses key paths inside it). Requires ext-sodium; if the local
 * PHP lacks it, run inside the backend image:
 *
 *   docker run --rm -v "$PWD/tools/license-issuer:/issuer:ro" -v "/secure/keys:/keys" \
 *     -v "$PWD/out:/out" smart-campus/backend:dev php /issuer/issue.php sign ...
 *
 * Commands:
 *   keygen  --key-id=ID --out=/secure/dir/ID.secret
 *           Writes the private key (base64) with mode 0600 and prints the
 *           PUBLIC key to add to apps/backend/config/license.php.
 *   sign    --key=/secure/dir/ID.secret --key-id=ID --spec=license.json --out=customer.lic
 *           Signs a license spec (see LICENSE_MODEL.md for the schema).
 *   revoke  --key=... --key-id=ID --sequence=N --ids=uuid1,uuid2 --out=revocations.lic
 *           Signs a revocation list; sequence must increase for every list.
 *   verify  --public-key=BASE64 --in=customer.lic
 *           Checks a signed file against a public key and prints its payload.
 */
const FORMAT = 'smart-campus-signed/v1';
const REPO_ROOT = __DIR__.'/../..';

function fail(string $message): never
{
    fwrite(STDERR, "error: {$message}\n");
    exit(1);
}

function b64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function b64url_decode(string $value): string
{
    $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

    return $decoded === false ? fail('invalid base64url') : $decoded;
}

/** Refuse any private-key path inside the repository working tree. */
function assert_outside_repo(string $path): void
{
    $repo = realpath(REPO_ROOT);
    $dir = realpath(dirname($path));
    if ($dir === false) {
        fail("directory does not exist: ".dirname($path));
    }
    $normalise = fn (string $p) => rtrim(strtolower(str_replace('\\', '/', $p)), '/').'/';
    if ($repo !== false && str_starts_with($normalise($dir), $normalise($repo))) {
        fail('private keys must be stored outside the repository');
    }
}

function opt(array $opts, string $name): string
{
    $value = $opts[$name] ?? null;

    return is_string($value) && $value !== '' ? $value : fail("--{$name} is required");
}

function load_secret(string $path): string
{
    assert_outside_repo($path);
    $raw = @file_get_contents($path);
    $key = $raw === false ? false : base64_decode(trim($raw), true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fail("not a valid secret key file: {$path}");
    }

    return $key;
}

function uuid_v4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function key_id(string $id): string
{
    return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) ? $id : fail('key id must match [A-Za-z0-9_-]{1,64}');
}

function envelope(string $keyId, array $payload, string $secret): string
{
    $bytes = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return json_encode([
        'format' => FORMAT,
        'key_id' => $keyId,
        'payload' => b64url($bytes),
        'signature' => b64url(sodium_crypto_sign_detached($bytes, $secret)),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
}

function write_out(string $path, string $content): void
{
    if (file_put_contents($path, $content) === false) {
        fail("cannot write {$path}");
    }
    fwrite(STDOUT, "wrote {$path}\n");
}

if (! function_exists('sodium_crypto_sign_detached')) {
    fail('ext-sodium is required (run inside the backend image, see header)');
}

$command = $argv[1] ?? '';
// getopt() stops at the first non-option argument (the command), so the
// --name=value options after it are parsed explicitly.
$known = ['key-id', 'out', 'key', 'spec', 'sequence', 'ids', 'public-key', 'in'];
$opts = [];
foreach (array_slice($argv, 2) as $arg) {
    if (! preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m) || ! in_array($m[1], $known, true)) {
        fail("unknown or malformed option: {$arg} (use --name=value)");
    }
    $opts[$m[1]] = $m[2];
}

switch ($command) {
    case 'keygen':
        $keyId = key_id(opt($opts, 'key-id'));
        $out = opt($opts, 'out');
        assert_outside_repo($out);
        if (file_exists($out)) {
            fail("refusing to overwrite {$out}");
        }
        $pair = sodium_crypto_sign_keypair();
        write_out($out, base64_encode(sodium_crypto_sign_secretkey($pair))."\n");
        @chmod($out, 0600);
        fwrite(STDOUT, "public key ({$keyId}): ".base64_encode(sodium_crypto_sign_publickey($pair))."\n");
        fwrite(STDOUT, "Back up the secret key offline (two separate encrypted copies). Never commit it.\n");
        break;

    case 'sign':
        $keyId = key_id(opt($opts, 'key-id'));
        $secret = load_secret(opt($opts, 'key'));
        $spec = json_decode((string) @file_get_contents(opt($opts, 'spec')), true);
        if (! is_array($spec)) {
            fail('spec is not valid JSON');
        }
        $payload = array_merge($spec, [
            'type' => 'license',
            'format_version' => 1,
            'license_id' => $spec['license_id'] ?? uuid_v4(),
            'issued_at' => $spec['issued_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        foreach (['installation_id', 'licensee', 'edition', 'plan', 'features', 'limits', 'not_before', 'expires_at', 'grace_days'] as $required) {
            if (! array_key_exists($required, $payload)) {
                fail("spec is missing {$required}");
            }
        }
        write_out(opt($opts, 'out'), envelope($keyId, $payload, $secret));
        fwrite(STDOUT, "license_id: {$payload['license_id']}\n");
        break;

    case 'revoke':
        $keyId = key_id(opt($opts, 'key-id'));
        $secret = load_secret(opt($opts, 'key'));
        $sequence = filter_var(opt($opts, 'sequence'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($sequence === false) {
            fail('--sequence must be a positive integer');
        }
        $ids = array_values(array_filter(array_map('trim', explode(',', opt($opts, 'ids')))));
        write_out(opt($opts, 'out'), envelope($keyId, [
            'type' => 'revocation_list',
            'format_version' => 1,
            'sequence' => $sequence,
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'revoked_license_ids' => $ids,
        ], $secret));
        break;

    case 'verify':
        $public = base64_decode(opt($opts, 'public-key'), true);
        $outer = json_decode((string) @file_get_contents(opt($opts, 'in')), true);
        if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || ! is_array($outer)) {
            fail('invalid public key or input file');
        }
        $bytes = b64url_decode((string) ($outer['payload'] ?? ''));
        $ok = sodium_crypto_sign_verify_detached(b64url_decode((string) ($outer['signature'] ?? '')), $bytes, $public);
        fwrite(STDOUT, ($ok ? 'SIGNATURE OK' : 'SIGNATURE INVALID')."\n".$bytes."\n");
        exit($ok ? 0 : 1);

    default:
        fwrite(STDERR, "usage: php issue.php keygen|sign|revoke|verify [options] (see file header)\n");
        exit(2);
}
