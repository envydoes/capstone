<?php
/**
 * includes/normalize_helpers.php
 * ------------------------------------------------------------
 * Shared data-normalization helpers used across the registration flow
 * (residentProfile.php, nonresidentProfile.php) and again as a final
 * safety net in session_data.php right before the DB insert. Applying
 * the same rules at both points means no matter which form a value
 * came through, what actually lands in tbl_userinfo — and therefore
 * everything downstream that reads it (admin listings, printed
 * reports) — is in one consistent shape.
 * ------------------------------------------------------------
 */

if (!function_exists('normalize_ph_phone')) {
    /**
     * Normalizes a Philippine mobile number to the 09XXXXXXXXX shape,
     * regardless of whether it was typed as "+639171234567",
     * "639171234567", or "09171234567" (with or without spaces/dashes/
     * parentheses). Returns the input unchanged (whitespace-trimmed
     * only) if it doesn't match a recognizable PH mobile pattern, so an
     * already-invalid value is left for the existing validators to
     * catch rather than being silently mangled into something that
     * looks valid.
     */
    function normalize_ph_phone(string $phone): string
    {
        $cleaned = preg_replace('/[\s\-()]/', '', trim($phone));
        if ($cleaned === '') {
            return '';
        }

        if (preg_match('/^(?:\+63|63)9(\d{9})$/', $cleaned, $m)) {
            return '09' . $m[1];
        }
        if (preg_match('/^09\d{9}$/', $cleaned)) {
            return $cleaned;
        }

        return $cleaned;
    }
}

if (!function_exists('normalize_person_name')) {
    /**
     * Title-cases a person's name while respecting punctuation that
     * legitimately shows up in Filipino names: apostrophes (O'Brien),
     * hyphens (Dela Cruz-Santos), multi-word surnames (Dela Cruz), and
     * the common "Mc"/"Mac" capitalization.
     */
    function normalize_person_name(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            return '';
        }

        // Title-case; mb_convert_case treats spaces AND hyphens as word
        // boundaries, so "dela cruz-santos" -> "Dela Cruz-Santos".
        $name = mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        // Fix apostrophe names: "O'brien" -> "O'Brien"
        $name = preg_replace_callback("/'([a-z])/u", fn($m) => "'" . mb_strtoupper($m[1], 'UTF-8'), $name);

        // Fix Mc/Mac prefixes: "Mcdonald" -> "McDonald", "Macarthur" -> "MacArthur"
        $name = preg_replace_callback('/\bMc([a-z])/u', fn($m) => 'Mc' . mb_strtoupper($m[1], 'UTF-8'), $name);
        $name = preg_replace_callback('/\bMac([a-z])/u', fn($m) => 'Mac' . mb_strtoupper($m[1], 'UTF-8'), $name);

        return $name;
    }
}

if (!function_exists('normalize_name_suffix')) {
    /**
     * Normalizes generation suffixes to their conventional form no
     * matter how the user typed it: "jr", "JR", "jr." -> "Jr."
     */
    function normalize_name_suffix(string $suffix): string
    {
        $suffix = trim($suffix);
        if ($suffix === '') {
            return '';
        }

        $key = strtolower(str_replace('.', '', $suffix));
        $map = [
            'jr'  => 'Jr.',
            'sr'  => 'Sr.',
            'ii'  => 'II',
            'iii' => 'III',
            'iv'  => 'IV',
            'v'   => 'V',
        ];

        return $map[$key] ?? normalize_person_name($suffix);
    }
}

if (!function_exists('normalize_street_address')) {
    /**
     * Cleans up the free-text "street" field so it never ends up
     * duplicating information that's already captured separately by
     * barangay/city/province — the most common cause being someone
     * pasting a full map search result (which already includes the
     * barangay/city/province) into the street box. Also collapses a
     * literal repeated segment within the street text itself (e.g. a
     * copy-paste that doubled the same fragment: "075, Purok 3, 075,
     * Purok 3, Sumacab Este").
     *
     * @param string   $street      Raw street text.
     * @param string[] $knownPlaces Barangay/city/province already
     *                              stored separately — segments that
     *                              exactly match one of these (after
     *                              trimming/case-folding) are dropped
     *                              from the street text.
     */
    function normalize_street_address(string $street, array $knownPlaces = []): string
    {
        $street = trim(preg_replace('/\s+/', ' ', $street));
        if ($street === '') {
            return '';
        }

        $knownLower = array_values(array_filter(array_map(
            fn($p) => mb_strtolower(trim((string) $p), 'UTF-8'),
            $knownPlaces
        ), fn($p) => $p !== ''));

        $segments = array_map('trim', explode(',', $street));
        $segments = array_values(array_filter($segments, fn($s) => $s !== ''));

        $clean = [];
        $prevLower = null;
        foreach ($segments as $segment) {
            $segLower = mb_strtolower($segment, 'UTF-8');

            // Drop a segment that's an exact repeat of the one right before it.
            if ($segLower === $prevLower) {
                continue;
            }
            // Drop a segment that just repeats the barangay/city/province,
            // which is already displayed alongside the street value.
            if (in_array($segLower, $knownLower, true)) {
                $prevLower = $segLower;
                continue;
            }

            $clean[] = $segment;
            $prevLower = $segLower;
        }

        // If everything got stripped out (edge case: the whole field was
        // just the barangay/city/province typed in), fall back to the
        // original text rather than saving an empty required field.
        return !empty($clean) ? implode(', ', $clean) : $street;
    }
}
