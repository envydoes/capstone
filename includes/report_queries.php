<?php
/**
 * includes/report_queries.php
 * ------------------------------------------------------------
 * Query builders for the three non-"Global List" printable reports.
 * These mirror the exact logic already used by:
 *   ajax/search_nonbeneficiaries.php
 *   ajax/search_owners.php
 *   ajax/search_borrowed.php
 * so the printed reports always match what's on screen. Kept in this
 * shared include (rather than duplicated inside the ajax files) so
 * print_global_list.php can reuse them directly.
 * ------------------------------------------------------------
 */

if (!function_exists('gf_roster_row')) {
    /**
     * Builds one name/age/birthdate/contact/address/role row from a
     * tbl_userinfo record (optionally joined to tbl_beneficiary). Shared
     * by all four "roster" report queries below so the identical column
     * shape print_global_list.php renders for each of them stays in one
     * place.
     */
    function gf_roster_row(array $r): array
    {
        $name = trim(implode(' ', array_filter([
            $r['firstname'] ?? '',
            !empty($r['middlename']) ? $r['middlename'] : '',
            $r['lastname'] ?? '',
            $r['suffix'] ?? '',
        ])));
        if ($name === '') $name = '(No name on file)';

        $age = null;
        if (!empty($r['birthday'])) {
            try {
                $age = (new DateTime())->diff(new DateTime($r['birthday']))->y;
            } catch (Exception $e) {
                $age = null;
            }
        }

        $roleRaw   = trim((string) ($r['account_role_csv'] ?? ''));
        $roleParts = array_values(array_filter(array_map('trim', explode(',', $roleRaw))));
        // '-/' added as extra word boundaries so "non-resident" -> "Non-Resident"
        // and "business/apartment owner" -> "Business/Apartment Owner".
        $role = !empty($roleParts)
            ? implode(', ', array_map(fn($p) => ucwords($p, " \t\r\n\f\v-/"), $roleParts))
            : '-';

        return [
            'name'           => $name,
            'age'            => $age ?? '-',
            'birthdate'      => !empty($r['birthday']) ? date('F j, Y', strtotime($r['birthday'])) : '-',
            'contact_number' => $r['phone'] ?: '-',
            'address'        => trim(implode(', ', array_filter([
                $r['street']   ?? '',
                $r['barangay'] ?? '',
                $r['city']     ?? '',
                $r['province'] ?? '',
            ]))) ?: '-',
            'role' => $role,
        ];
    }
}

if (!function_exists('gf_roster_result')) {
    /**
     * Runs a roster SQL string (no user input, so no bound params needed)
     * and shapes the result the way print_global_list.php's 'roster'
     * analytics branch expects: ['count' => int, 'rows' => [...]].
     */
    function gf_roster_result(mysqli $conn, string $sql): array
    {
        $out = [];
        $res = mysqli_query($conn, $sql);
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $out[] = gf_roster_row($r);
            }
        }

        return [
            'count' => count($out),
            'rows'  => $out,
        ];
    }
}

if (!function_exists('gf_run_new_registrations_month_query')) {
    /**
     * "New Registrations This Month" (User / Accounts). Mirrors the
     * this_month counter on userManagement.php: every account registered
     * so far this month, resident or non-resident (staff/admin accounts
     * don't carry a resident/non-resident role, so the LIKE filter below
     * excludes them the same way the dashboard counter does).
     */
    function gf_run_new_registrations_month_query(mysqli $conn): array
    {
        $sql = "
            SELECT firstname, middlename, lastname, suffix, birthday, phone,
                   street, barangay, city, province, account_role_csv, dateRegistered
            FROM tbl_userinfo
            WHERE account_role_csv LIKE '%resident%'
              AND dateRegistered >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
            ORDER BY dateRegistered DESC
        ";
        return gf_roster_result($conn, $sql);
    }
}

if (!function_exists('gf_run_accounts_today_query')) {
    /**
     * "Accounts Registered Today" (User / Accounts). Mirrors the
     * "registered today" counter on userManagement.php.
     */
    function gf_run_accounts_today_query(mysqli $conn): array
    {
        $sql = "
            SELECT firstname, middlename, lastname, suffix, birthday, phone,
                   street, barangay, city, province, account_role_csv, dateRegistered
            FROM tbl_userinfo
            WHERE account_role_csv LIKE '%resident%'
              AND DATE(dateRegistered) = CURDATE()
            ORDER BY dateRegistered DESC
        ";
        return gf_roster_result($conn, $sql);
    }
}

if (!function_exists('gf_run_new_residents_month_query')) {
    /**
     * "New Residents This Month" (Resident Management). Mirrors
     * $residentFilter + the "New Residents This Month" counter on
     * residentManagement.php: approved accounts with a resident role,
     * excluding non-residents.
     */
    function gf_run_new_residents_month_query(mysqli $conn): array
    {
        $sql = "
            SELECT firstname, middlename, lastname, suffix, birthday, phone,
                   street, barangay, city, province, account_role_csv, dateRegistered
            FROM tbl_userinfo
            WHERE LOWER(userStatus) = 'approved'
              AND account_role_csv LIKE '%resident%'
              AND NOT account_role_csv LIKE '%non-resident%'
              AND dateRegistered >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
            ORDER BY dateRegistered DESC
        ";
        return gf_roster_result($conn, $sql);
    }
}

if (!function_exists('gf_run_new_beneficiaries_month_query')) {
    /**
     * "New Beneficiaries This Month" (Beneficiary Management). Mirrors
     * the "New Beneficiaries This Month" counter on
     * beneficiaryManagement.php: approved beneficiaries, using
     * updated_at as an "approved on" proxy (same caveat noted there -
     * updated_at stamps on any row edit, not only approval).
     */
    function gf_run_new_beneficiaries_month_query(mysqli $conn): array
    {
        $sql = "
            SELECT ui.firstname, ui.middlename, ui.lastname, ui.suffix, ui.birthday, ui.phone,
                   ui.street, ui.barangay, ui.city, ui.province, ui.account_role_csv, b.updated_at
            FROM tbl_beneficiary b
            JOIN tbl_userinfo ui ON b.userID = ui.userID
            WHERE b.status = 'approved'
              AND b.updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
            ORDER BY b.updated_at DESC
        ";
        return gf_roster_result($conn, $sql);
    }
}

if (!function_exists('gf_run_nonbeneficiaries_query')) {
    function gf_run_nonbeneficiaries_query(mysqli $conn, array $get): array
    {
        $q = trim($get['q'] ?? '');

        $sql = "
            SELECT u.userID, u.firstname, u.lastname, u.middlename, u.suffix, u.street, u.phone, u.email
            FROM tbl_userinfo u
            LEFT JOIN tbl_beneficiary b ON b.userId = u.userID
            WHERE b.id IS NULL AND LOWER(u.userStatus) = 'approved'
        ";
        $params = [];
        $types  = '';

        if ($q !== '') {
            $sql .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.middlename LIKE ? OR u.street LIKE ?)";
            $like   = '%' . $q . '%';
            $params = [$like, $like, $like, $like];
            $types  = 'ssss';
        }

        $sql .= " ORDER BY u.lastname ASC";

        $out  = [];
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            if ($types !== '') {
                mysqli_stmt_bind_param($stmt, $types, ...$params);
            }
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($r = mysqli_fetch_assoc($result)) {
                $name = trim(implode(' ', array_filter([
                    $r['firstname'],
                    $r['middlename'] ? $r['middlename']: '',
                    $r['lastname'],
                    $r['suffix'],
                ])));
                $out[] = [
                    'name'   => $name !== '' ? $name : '(No name on file)',
                    'street' => $r['street'] ?: '-',
                    'phone'  => $r['phone'] ?: '-',
                    'email'  => $r['email'] ?: '-',
                ];
            }
            mysqli_stmt_close($stmt);
        }

        return [
            'count'   => count($out),
            'columns' => [
                ['key' => 'street', 'label' => 'Purok / Street'],
                ['key' => 'phone',  'label' => 'Phone'],
                ['key' => 'email',  'label' => 'Email'],
            ],
            'data' => $out,
        ];
    }
}

if (!function_exists('gf_run_owners_query')) {
    function gf_run_owners_query(mysqli $conn, array $get): array
    {
        $q = trim($get['q'] ?? '');

        $ownerDirRes = mysqli_query($conn, "
            SELECT userId,
                   COUNT(*) AS listing_count,
                   SUM(CASE WHEN listingType = 'apartment' THEN 1 ELSE 0 END) AS apt_count,
                   SUM(CASE WHEN listingType = 'business'  THEN 1 ELSE 0 END) AS biz_count
            FROM tbl_busaptlisting
            GROUP BY userId
            ORDER BY listing_count DESC
        ");

        $owners = [];
        if ($ownerDirRes) {
            while ($r = mysqli_fetch_assoc($ownerDirRes)) {
                $owners[] = $r;
            }
        }

        foreach ($owners as &$ownerRow) {
            $accId = $ownerRow['userId'];
            $stmt  = mysqli_prepare($conn, "SELECT firstname, lastname FROM tbl_userinfo WHERE accID = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 's', $accId);
            mysqli_stmt_execute($stmt);
            $nameRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $ownerRow['owner_name'] = $nameRow ? trim($nameRow['firstname'] . ' ' . $nameRow['lastname']) : $accId;
        }
        unset($ownerRow);

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $owners = array_values(array_filter($owners, function ($o) use ($needle) {
                return mb_strpos(mb_strtolower($o['owner_name']), $needle) !== false;
            }));
        }

        $out = [];
        foreach ($owners as $o) {
            $out[] = [
                'name'          => $o['owner_name'],
                'listing_count' => (string) $o['listing_count'],
                'apt_count'     => (string) $o['apt_count'],
                'biz_count'     => (string) $o['biz_count'],
            ];
        }

        return [
            'count'   => count($out),
            'columns' => [
                ['key' => 'listing_count', 'label' => 'Total Listings'],
                ['key' => 'apt_count',     'label' => 'Apartments'],
                ['key' => 'biz_count',     'label' => 'Businesses'],
            ],
            'data' => $out,
        ];
    }
}

if (!function_exists('gf_run_borrowed_query')) {
    function gf_run_borrowed_query(mysqli $conn, array $get): array
    {
        $q = trim($get['q'] ?? '');

        $borrowedRes = mysqli_query($conn, "
            SELECT r.id, e.equipmentName, r.quantityRequested, r.returnDate,
                   CONCAT(u.firstname, ' ', u.lastname) AS borrower_name
            FROM tbl_equipmentrequest r
            JOIN tbl_equipmentlist e ON r.equipmentId = e.equipmentId
            JOIN tbl_userinfo u ON r.userId = u.userID
            WHERE LOWER(r.status) = 'borrowed'
            ORDER BY r.returnDate ASC
        ");

        $rows = [];
        if ($borrowedRes) {
            while ($r = mysqli_fetch_assoc($borrowedRes)) {
                $r['is_overdue'] = !empty($r['returnDate']) && strtotime($r['returnDate']) < strtotime('today');
                $rows[] = $r;
            }
        }

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                $hay = mb_strtolower($r['equipmentName'] . ' ' . $r['borrower_name']);
                return mb_strpos($hay, $needle) !== false;
            }));
        }

        $out = [];
        foreach ($rows as $r) {
            $dt = !empty($r['returnDate']) ? date('M d, Y', strtotime($r['returnDate'])) : '-';
            $statusHtml = $r['is_overdue']
                ? '<span class="mini-badge mini-badge-overdue">Overdue</span>'
                : '<span class="mini-badge mini-badge-ontime">On Time</span>';
            $out[] = [
                'name'        => $r['equipmentName'],
                'qty'         => (string) $r['quantityRequested'],
                'borrower'    => $r['borrower_name'],
                'return_date' => $dt,
                'status'      => $statusHtml,
            ];
        }

        return [
            'count'   => count($out),
            'columns' => [
                ['key' => 'qty',         'label' => 'Qty'],
                ['key' => 'borrower',    'label' => 'Borrower'],
                ['key' => 'return_date', 'label' => 'Return Date'],
                ['key' => 'status',      'label' => 'Status', 'raw' => true],
            ],
            'data' => $out,
        ];
    }
}
