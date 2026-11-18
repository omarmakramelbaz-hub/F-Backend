<?php

/**
 * Hash the server's exact row JSON, before PHP can re-encode its numeric tokens.
 * The caller decodes the same bounded input first. No snapshot-supplied hash map
 * is accepted, and row bytes are never copied into a second whole-table string.
 */
final class DesktopSnapshotRowHashes
{
    private const LIMIT = 256 * 1024 * 1024;
    private const STRING_BREAKS = "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
        . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f";
    private int $offset = 0;
    private int $length;
    private array $hashes = [];

    private function __construct(private string $input)
    {
        $this->length = strlen($input);
        self::need($this->length > 0 && $this->length <= self::LIMIT);
    }

    public static function fromJson(string $input): array
    {
        $reader = new self($input);
        $reader->object('root', 1);
        $reader->space();
        self::need($reader->offset === $reader->length);
        return $reader->hashes;
    }

    private static function need(bool $condition): void
    {
        if (!$condition) throw new RuntimeException('Snapshot row transport is invalid.');
    }

    private function space(): void
    {
        $this->offset += strspn($this->input, " \t\r\n", $this->offset);
    }

    private function take(string $character): void
    {
        $this->space();
        self::need($this->offset < $this->length && $this->input[$this->offset] === $character);
        $this->offset++;
    }

    private function string(bool $key = false): ?string
    {
        $this->space();
        $start = $this->offset;
        self::need($start < $this->length && $this->input[$this->offset++] === '"');
        while ($this->offset < $this->length) {
            // Ordinary UTF-8 text needs no PHP byte-by-byte loop. Only quotes,
            // escapes and forbidden unescaped controls can end a plain run.
            $this->offset += strcspn($this->input, self::STRING_BREAKS, $this->offset);
            if ($this->offset === $this->length) break;
            $character = $this->input[$this->offset++];
            if ($character === '"') {
                if (!$key) return null;
                self::need($this->offset - $start <= 1024);
                $value = json_decode(substr($this->input, $start, $this->offset - $start), true, 2, JSON_THROW_ON_ERROR);
                self::need(is_string($value));
                return $value;
            }
            self::need(ord($character) >= 32);
            if ($character !== '\\') continue;
            self::need($this->offset < $this->length);
            $escape = $this->input[$this->offset++];
            if ($escape === 'u') {
                self::need($this->offset <= $this->length - 4
                    && strspn($this->input, '0123456789abcdefABCDEF', $this->offset, 4) === 4);
                $this->offset += 4;
            } else self::need(str_contains('"\\/bfnrt', $escape));
        }
        self::need(false);
        return null;
    }

    /** Only protocol object keys need a duplicate-key set; business rows stay streaming. */
    private function object(string $kind, int $depth, ?string $table = null): void
    {
        self::need($depth <= 512);
        $this->take('{');
        $seen = [];
        $this->space();
        if ($this->offset < $this->length && $this->input[$this->offset] === '}') {
            $this->offset++;
        } else while (true) {
            $name = $this->string(true);
            self::need(!array_key_exists($name, $seen) && count($seen) < 4096);
            $seen[$name] = true;
            $this->take(':');
            if ($kind === 'root' && $name === 'tables') {
                $this->object('tables', $depth + 1);
            } elseif ($kind === 'tables') {
                self::need(preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) === 1);
                $this->object('part', $depth + 1, $name);
            } elseif ($kind === 'part' && $name === 'rows') {
                $this->space();
                self::need($this->offset < $this->length && $this->input[$this->offset] === '[');
                $start = $this->offset;
                $this->value($depth + 1);
                $hash = hash_init('sha256');
                for ($at = $start; $at < $this->offset; $at += 65536)
                    hash_update($hash, substr($this->input, $at, min(65536, $this->offset - $at)));
                $this->hashes[$table] = hash_final($hash);
            } else $this->value($depth + 1);
            $this->space();
            self::need($this->offset < $this->length);
            $separator = $this->input[$this->offset++];
            if ($separator === '}') break;
            self::need($separator === ',');
        }
        if ($kind === 'root') self::need(isset($seen['tables']));
        if ($kind === 'part') self::need(isset($seen['rows']));
    }

    private function value(int $depth): void
    {
        self::need($depth <= 512);
        $this->space();
        self::need($this->offset < $this->length);
        $character = $this->input[$this->offset];
        if ($character === '"') { $this->string(); return; }
        if ($character === '{') {
            $this->offset++;
            $this->space();
            if ($this->offset < $this->length && $this->input[$this->offset] === '}') { $this->offset++; return; }
            while (true) {
                $this->string(); $this->take(':'); $this->value($depth + 1); $this->space();
                self::need($this->offset < $this->length);
                $separator = $this->input[$this->offset++];
                if ($separator === '}') return;
                self::need($separator === ',');
            }
        }
        if ($character === '[') {
            $this->offset++;
            $this->space();
            if ($this->offset < $this->length && $this->input[$this->offset] === ']') { $this->offset++; return; }
            while (true) {
                $this->value($depth + 1); $this->space();
                self::need($this->offset < $this->length);
                $separator = $this->input[$this->offset++];
                if ($separator === ']') return;
                self::need($separator === ',');
            }
        }
        foreach (['true', 'false', 'null'] as $literal) {
            if (substr_compare($this->input, $literal, $this->offset, strlen($literal)) === 0) {
                $this->offset += strlen($literal);
                return;
            }
        }
        $start = $this->offset;
        if ($character === '-') $this->offset++;
        self::need($this->offset < $this->length);
        if ($this->input[$this->offset] === '0') $this->offset++;
        else {
            self::need(str_contains('123456789', $this->input[$this->offset]));
            $this->offset += strspn($this->input, '0123456789', $this->offset);
        }
        if ($this->offset < $this->length && $this->input[$this->offset] === '.') {
            $this->offset++;
            $digits = strspn($this->input, '0123456789', $this->offset);
            self::need($digits > 0); $this->offset += $digits;
        }
        if ($this->offset < $this->length && str_contains('eE', $this->input[$this->offset])) {
            $this->offset++;
            if ($this->offset < $this->length && str_contains('+-', $this->input[$this->offset])) $this->offset++;
            $digits = strspn($this->input, '0123456789', $this->offset);
            self::need($digits > 0); $this->offset += $digits;
        }
        // json_decode accepts overflow as INF and nonzero underflow as zero.
        // The server's PHP encoder cannot emit either; retain its finite-value
        // boundary before any staging write. A native double's exact decimal
        // representation fits comfortably below this bounded token allocation,
        // including unusually high serialize_precision and subnormal values.
        self::need($this->offset - $start <= 2048);
        $number = substr($this->input, $start, $this->offset - $start);
        $value = (float)$number;
        self::need(is_finite($value));
        if ($value === 0.0)
            self::need(strpbrk(substr($number, 0, strcspn($number, 'eE')), '123456789') === false);
    }
}
