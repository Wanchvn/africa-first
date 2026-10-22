<?php
// api/includes/QarotaTOTP.php

class QarotaTOTP {
    private $codeLength = 6;
    private $base32Map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // 1. Generate a secure Base32 secret
    public function createSecret($length = 16) {
        $secret = '';
        $randomBytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $secret .= $this->base32Map[ord($randomBytes[$i]) & 31];
        }
        return $secret;
    }

    // 2. Decode Base32 string to binary
    private function base32Decode($secret) {
        if (empty($secret)) return '';
        $secret = strtoupper($secret);
        $secret = str_replace('=', '', $secret);
        $binary = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos($this->base32Map, $char);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }
        return $decoded;
    }

    // 3. Calculate the code for a specific time
    public function getCode($secret, $timeSlice = null) {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }
        $secretKey = $this->base32Decode($secret);
        
        // Pack time into binary string (8 bytes)
        $time = chr(0).chr(0).chr(0).chr(0).pack('N*', $timeSlice);
        
        // Hash it
        $hm = hash_hmac('SHA1', $time, $secretKey, true);
        
        // Dynamic truncation
        $offset = ord(substr($hm, -1)) & 0x0F;
        $hashPart = substr($hm, $offset, 4);
        
        // Unpack safely
        $value = unpack('N', $hashPart);
        if ($value === false) return '000000'; // Failsafe
        
        $value = $value[1] & 0x7FFFFFFF;
        $modulo = pow(10, $this->codeLength);
        
        return str_pad($value % $modulo, $this->codeLength, '0', STR_PAD_LEFT);
    }

    // 4. Verify the code with time drift tolerance
    public function verifyCode($secret, $code, $discrepancy = 1) {
        $currentTimeSlice = floor(time() / 30);
        
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = $this->getCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculatedCode, (string)$code)) {
                return true;
            }
        }
        return false;
    }
}