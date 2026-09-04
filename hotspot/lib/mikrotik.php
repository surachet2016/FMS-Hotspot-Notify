<?php
require_once __DIR__ . '/../config.php';

class MikrotikClient
{
    /** @var resource|null */
    private $socket = null;

    public function connect(string $host, int $port, int $timeout = 3): void
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($sock === false) {
            throw new RuntimeException("Cannot connect to MikroTik {$host}:{$port}: {$errstr} ({$errno})");
        }
        stream_set_timeout($sock, $timeout);
        $this->socket = $sock;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function encodeLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        if ($len < 0x4000) {
            return pack('n', 0x8000 | $len);
        }
        if ($len > 0x1FFFFF) {
            throw new RuntimeException("Word too long to encode: {$len}");
        }
        // 3-byte encoding: 0xC00000 | len — pack as 4-byte big-endian, drop the leading byte
        $encoded = pack('N', 0xC00000 | $len);
        return substr($encoded, 1);
    }

    private function readByte(): int
    {
        $b = fread($this->socket, 1);
        if ($b === false || $b === '') {
            throw new RuntimeException('MikroTik connection closed or timed out');
        }
        return ord($b);
    }

    private function writeWord(string $word): void
    {
        $len  = strlen($word);
        $data = $this->encodeLength($len) . $word;
        fwrite($this->socket, $data);
    }

    private function writeSentence(array $words): void
    {
        foreach ($words as $word) {
            $this->writeWord($word);
        }
        fwrite($this->socket, chr(0)); // end-of-sentence: empty word
    }

    private function readLength(): int
    {
        $b = $this->readByte();
        if ($b < 0x80) {
            return $b;
        }
        if ($b < 0xC0) {
            $b2 = $this->readByte();
            return (($b & 0x3F) << 8) | $b2;
        }
        $b2 = $this->readByte();
        $b3 = $this->readByte();
        return (($b & 0x1F) << 16) | ($b2 << 8) | $b3;
    }

    private function readWord(): string
    {
        $len = $this->readLength();
        if ($len === 0) {
            return '';
        }
        $data = '';
        while (strlen($data) < $len) {
            $chunk = fread($this->socket, $len - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('MikroTik connection closed or timed out while reading word');
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function readSentence(): array
    {
        $words = [];
        while (true) {
            $word = $this->readWord();
            if ($word === '') {
                break;
            }
            $words[] = $word;
        }
        return $words;
    }

    private function checkTrap(array $sentence): void
    {
        foreach ($sentence as $word) {
            if ($word === '!trap') {
                $msg = '';
                foreach ($sentence as $w) {
                    if (strpos($w, '=message=') === 0) {
                        $msg = substr($w, 9);
                        break;
                    }
                }
                throw new RuntimeException('MikroTik error: ' . ($msg ?: 'unknown trap'));
            }
        }
    }

    public function login(string $user, string $pass): void
    {
        // Modern login (RouterOS v6.43+): send name + password in one sentence
        $this->writeSentence(['/login', '=name=' . $user, '=password=' . $pass]);
        $response = $this->readSentence();

        if (in_array('!done', $response, true)) {
            return; // success — modern firmware accepted immediately
        }

        // Older firmware returns a challenge via =ret=<hex>
        $ret = null;
        foreach ($response as $word) {
            if (strpos($word, '=ret=') === 0) {
                $ret = substr($word, 5);
                break;
            }
        }

        if ($ret !== null) {
            // MD5 challenge-response: null byte + password + binary challenge
            $hash = md5(chr(0) . $pass . hex2bin($ret));
            $this->writeSentence(['/login', '=name=' . $user, '=response=00' . $hash]);
            $response2 = $this->readSentence();
            $this->checkTrap($response2);
            if (!in_array('!done', $response2, true)) {
                throw new RuntimeException('MikroTik login failed');
            }
            return;
        }

        // No !done and no challenge — check for trap then fail
        $this->checkTrap($response);
        throw new RuntimeException('MikroTik login failed: unexpected response');
    }

    public function addHotspotUser(
        string $citizenId,
        string $password,
        string $profile,
        string $comment
    ): bool {
        // RouterOS =comment= field is capped at 255 chars; truncate to 200 for safety
        $comment = mb_substr($comment, 0, 200);

        $this->writeSentence([
            '/ip/hotspot/user/add',
            '=name='     . $citizenId,
            '=password=' . $password,
            '=profile='  . $profile,
            '=comment='  . $comment,
        ]);
        $response = $this->readSentence();

        if (in_array('!done', $response, true)) {
            return true;
        }

        // A !trap with "already exists" / "already" means the user is already there — idempotent
        foreach ($response as $word) {
            if ($word === '!trap') {
                $msg = '';
                foreach ($response as $w) {
                    if (strpos($w, '=message=') === 0) {
                        $msg = strtolower(substr($w, 9));
                        break;
                    }
                }
                if (strpos($msg, 'already') !== false || strpos($msg, 'exists') !== false) {
                    return true;
                }
                throw new RuntimeException('MikroTik addHotspotUser error: ' . $msg);
            }
        }

        return false;
    }

    public function removeHotspotUser(string $citizenId): bool {
        // Find the internal .id for this user
        $this->writeSentence(['/ip/hotspot/user/print', '?name=' . $citizenId]);
        $response = $this->readSentence();

        $internalId = null;
        foreach ($response as $word) {
            if (strpos($word, '=.id=') === 0) {
                $internalId = substr($word, 5);
                break;
            }
        }

        if ($internalId === null) {
            return false; // user not found — treat as already removed
        }

        $this->writeSentence(['/ip/hotspot/user/remove', '=.id=' . $internalId]);
        $response = $this->readSentence();
        return in_array('!done', $response, true);
    }

    public function addTrialClearScheduler(): bool {
        $name    = 'clear-trial-users';
        $onEvent = '/ip hotspot user remove [find where name~"^T-"]';

        // ตรวจว่ามีอยู่แล้วหรือยัง
        $this->writeSentence(['/system/scheduler/print', '?name=' . $name]);
        $existing = $this->readSentence();
        foreach ($existing as $word) {
            if (strpos($word, '=name=') === 0 && substr($word, 6) === $name) {
                return true; // already exists
            }
        }

        $this->writeSentence([
            '/system/scheduler/add',
            '=name='       . $name,
            '=interval='   . '1d',
            '=start-time=' . '00:00:00',
            '=on-event='   . $onEvent,
        ]);
        $response = $this->readSentence();
        return in_array('!done', $response, true);
    }

    // Read all !re sentences until !done; returns list of parsed attribute maps
    private function readAllRows(): array
    {
        $rows = [];
        while (true) {
            $sentence = $this->readSentence();
            if (in_array('!done', $sentence, true) || empty($sentence)) {
                break;
            }
            if (in_array('!trap', $sentence, true)) {
                break;
            }
            if (!in_array('!re', $sentence, true)) {
                continue;
            }
            $row = [];
            foreach ($sentence as $word) {
                if (strpos($word, '=') !== 0) continue;
                $eq = strpos($word, '=', 1);
                if ($eq === false) continue;
                $row[substr($word, 1, $eq - 1)] = substr($word, $eq + 1);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    // Resolve the active hotspot session for a given client IP.
    // Returns ['user' => ..., 'mac-address' => ...] or null if not found.
    public function getActiveSessionByIp(string $ip): ?array
    {
        $this->writeSentence(['/ip/hotspot/active/print', '?address=' . $ip]);
        $rows = $this->readAllRows();
        return $rows[0] ?? null;
    }

    public function cleanupHotspotSession(string $username, string $mac): void
    {
        // Remove all hotspot cookies for this user (prevents auto re-login)
        if ($username !== '') {
            $this->writeSentence(['/ip/hotspot/cookie/print', '?user=' . $username]);
            foreach ($this->readAllRows() as $row) {
                if (!isset($row['.id'])) continue;
                $this->writeSentence(['/ip/hotspot/cookie/remove', '=.id=' . $row['.id']]);
                $this->readSentence(); // consume !done
            }
        }

        // Release DHCP lease so the IP returns to the pool immediately
        if ($mac !== '') {
            $this->writeSentence(['/ip/dhcp-server/lease/print', '?mac-address=' . $mac]);
            foreach ($this->readAllRows() as $row) {
                if (!isset($row['.id'])) continue;
                $this->writeSentence(['/ip/dhcp-server/lease/remove', '=.id=' . $row['.id']]);
                $this->readSentence(); // consume !done
            }
        }
    }

    public function disableHotspotUser(string $citizenId): bool {
        $this->writeSentence(['/ip/hotspot/user/print', '?name=' . $citizenId]);
        $response = $this->readSentence();

        $internalId = null;
        foreach ($response as $word) {
            if (strpos($word, '=.id=') === 0) {
                $internalId = substr($word, 5);
                break;
            }
        }

        if ($internalId === null) {
            return false;
        }

        $this->writeSentence(['/ip/hotspot/user/set', '=.id=' . $internalId, '=disabled=yes']);
        $response = $this->readSentence();
        return in_array('!done', $response, true);
    }
}
