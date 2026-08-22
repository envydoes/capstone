<?php
// includes/password_rules.php
//
// Shared password-strength helpers. Used by accountCreation's
// process_account.php AND by the account-settings "change password" pages
// (residentEditPassword.php / nonresidentEditPassword.php) so both places
// enforce the exact same rules and never drift out of sync again.
//
// Guard against double-inclusion since multiple pages may require this.
if (!function_exists('isCommonPassword')) {

// ?? Helper: commonly used / frequently-breached passwords ??????????????????????
function isCommonPassword(string $pw): bool {
    static $commonPasswords = [
        'password','12345678','123456789','1234567890','123456','1234567','12345',
        'qwerty','qwerty123','qwertyuiop','qazwsx','1q2w3e4r','1qaz2wsx','zxcvbnm','zxcvbn',
        'abc123','abc12345','password1','password12','password123','password1234','passw0rd','p@ssw0rd',
        'letmein','letmein123','welcome','welcome1','welcome123',
        'admin','admin123','admin1234','root','toor','login','default','changeme','changeme123',
        'iloveyou','iloveyou1','monkey','dragon','dragon123',
        'football','football1','football123','baseball','baseball1','basketball','soccer','hockey',
        'starwars','superman','master','sunshine','princess','shadow','freedom','trustno1',
        'whatever','solo','1q2w3e4r5t','asdf1234','asdfghjkl',
        '123123','111111','000000','666666','696969','654321','987654321','121212','112233',
        '11111111','00000000','87654321','12341234','1122334455','aaaaaaaa','abcdefgh','abcd1234',
        'mypassword','loveme','ashley','jennifer','jessica','michael','michelle','charlie','donald',
        'jordan','hunter2','access','yankees','mustang','ninja','azerty',
        'test1234','temp1234','tinkerbell','liverpool','chelsea','arsenal','flower','hottie','biteme',
        'q1w2e3r4','1q2w3e4r5t6y','google','facebook','instagram','snapchat','tinder',
        '123qwe','000000000','111111111',
    ];

    $lower = strtolower($pw);
    if (in_array($lower, $commonPasswords, true)) {
        return true;
    }

    // Catch variants like "Password1234!" by stripping leading/trailing digits & symbols
    $stripped = preg_replace('/^[^a-z]+|[^a-z]+$/', '', $lower);
    if (strlen($stripped) >= 4 && in_array($stripped, $commonPasswords, true)) {
        return true;
    }

    return false;
}

// ?? Helper: check password against Have I Been Pwned's breached-password DB ????
// Uses k-anonymity: only the first 5 hex chars of the SHA-1 hash are ever sent
// over the network, never the password itself and never the full hash.
// Fails OPEN (returns false / "not flagged") if the API can't be reached, so an
// outage on HIBP's end never blocks someone from changing/creating a password.
// The local isCommonPassword() list above still applies regardless of this check.
function isPwnedPassword(string $pw): bool {
    $sha1   = strtoupper(sha1($pw));
    $prefix = substr($sha1, 0, 5);
    $suffix = substr($sha1, 5);

    $ch = curl_init("https://api.pwnedpasswords.com/range/{$prefix}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER     => ['User-Agent: BarangayApp-PasswordCheck'],
    ]);
    $response = curl_exec($ch);
    $hadError = curl_errno($ch) !== 0;
    curl_close($ch);

    if ($hadError || $response === false || $response === '') {
        return false; // API unreachable — don't block on this check
    }

    foreach (explode("\r\n", trim($response)) as $line) {
        $parts = explode(':', $line);
        if (isset($parts[0]) && $parts[0] === $suffix) {
            return true;
        }
    }
    return false;
}

}
