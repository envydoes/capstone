<?php
/**
 * includes/global_list_query.php
 * ------------------------------------------------------------
 * Shared query builder for the admin "Global List" feature.
 * Used by BOTH ajax/search_global.php (on-screen table) and
 * print_global_list.php (printable / PDF-able report), so the two
 * are always guaranteed to return identical results for the same
 * set of filters.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/global_list_query.php';
 *   $result = gf_run_global_list_query($conn, $_GET);
 *   // $result = ['count' => int, 'columns' => [...], 'data' => [...], 'filters' => [...]]
 * ------------------------------------------------------------
 */

if (!function_exists('gf_str')) {
    function gf_str(array $get, string $key): string
    {
        return isset($get[$key]) ? trim((string) $get[$key]) : '';
    }
}
if (!function_exists('gf_num')) {
    function gf_num(array $get, string $key)
    {
        $v = gf_str($get, $key);
        return ($v !== '' && is_numeric($v)) ? $v : null;
    }
}
if (!function_exists('gf_label')) {
    function gf_label($str): string
    {
        $str = trim((string) $str);
        if ($str === '') return '-';
        return ucwords(str_replace('_', ' ', $str));
    }
}
if (!function_exists('gf_yesno')) {
    function gf_yesno($bool): string
    {
        return $bool ? 'Yes' : 'No';
    }
}

if (!function_exists('gf_run_global_list_query')) {
    /**
     * @param mysqli $conn
     * @param array  $get             Usually $_GET - any array of filter params
     * @param array  $myPermissions   The calling account's permission keys
     *                                (from get_my_permissions()). Any filter
     *                                that reads from tbl_beneficiary is
     *                                silently ignored unless 'manage_beneficiaries'
     *                                is present - this is enforced HERE, not
     *                                just in the UI, so a hand-crafted request
     *                                can't bypass a disabled dropdown.
     * @return array{count:int, columns:array, data:array, filters:array}
     */
    function gf_run_global_list_query(mysqli $conn, array $get, array $myPermissions = []): array
    {
        // Filters that only make sense with Beneficiary Management access,
        // because they read from tbl_beneficiary (joined as `b` below).
        if (!in_array('manage_beneficiaries', $myPermissions, true)) {
            foreach ([
                'housing_status', 'house_material', 'electricity', 'water_source', 'toilet_type',
                'pregnant_children', 'is_pwd', 'is_solo_parent', 'is_indigenous', 'is_4ps',
                'is_scholarship', 'is_kabataan', 'pension_status', 'school', 'course', 'year_level', 'gwa',
            ] as $benOnlyKey) {
                unset($get[$benOnlyKey]);
            }
        }

        /* ?? Read filters ?? */
        $accountRole      = gf_str($get, 'account_role');
        $dateFrom         = gf_str($get, 'date_from');
        $dateTo           = gf_str($get, 'date_to');
        $sex              = gf_str($get, 'sex');
        $birthMonth       = gf_num($get, 'birth_month');
        $birthYear        = gf_num($get, 'birth_year');
        $ageMin           = gf_num($get, 'age_min');
        $ageMax           = gf_num($get, 'age_max');
        $address          = gf_str($get, 'address');
        $familyRole       = gf_str($get, 'family_role');
        $civilStatus      = gf_str($get, 'civil_status');
        $citizenship      = gf_str($get, 'citizenship');
        $religion         = gf_str($get, 'religion');
        $ethnicity        = gf_str($get, 'ethnicity');
        $bloodType        = gf_str($get, 'blood_type');
        $employmentStatus = gf_str($get, 'employment_status');
        $incomeMin        = gf_num($get, 'income_min');
        $incomeMax        = gf_num($get, 'income_max');
        $housingStatus    = gf_str($get, 'housing_status');
        $houseMaterial    = gf_str($get, 'house_material');
        $electricity      = gf_str($get, 'electricity');
        $waterSource      = gf_str($get, 'water_source');
        $toiletType       = gf_str($get, 'toilet_type');
        $pregnantChildren = gf_str($get, 'pregnant_children'); // '1' | '0'
        $isPwd            = gf_str($get, 'is_pwd');
        $isSoloParent     = gf_str($get, 'is_solo_parent');
        $isIndigenous     = gf_str($get, 'is_indigenous');
        $is4ps            = gf_str($get, 'is_4ps');
        $isScholarship    = gf_str($get, 'is_scholarship');
        $isKabataan       = gf_str($get, 'is_kabataan');
        $pensionStatus    = gf_str($get, 'pension_status');
        $isVoter          = gf_str($get, 'is_voter');
        $residentBirth    = gf_str($get, 'resident_birth');
        $school           = gf_str($get, 'school');
        $course           = gf_str($get, 'course');
        $yearLevel        = gf_str($get, 'year_level');
        $gwa              = gf_str($get, 'gwa');

        /* ?? Build the SQL-expressible portion of the WHERE clause ?? */
        $where  = ["u.userStatus = 'approved'"];
        $types  = '';
        $params = [];

        if ($accountRole !== '') { $where[] = "a.account_role LIKE ?"; $types .= 's'; $params[] = '%' . $accountRole . '%'; }
        if ($dateFrom !== '')    { $where[] = "u.dateRegistered >= ?"; $types .= 's'; $params[] = $dateFrom . ' 00:00:00'; }
        if ($dateTo !== '')      { $where[] = "u.dateRegistered <= ?"; $types .= 's'; $params[] = $dateTo . ' 23:59:59'; }
        if ($sex !== '')         { $where[] = "LOWER(u.gender) = ?"; $types .= 's'; $params[] = strtolower($sex); }
        if ($birthMonth !== null){ $where[] = "MONTH(u.birthday) = ?"; $types .= 'i'; $params[] = (int) $birthMonth; }
        if ($birthYear !== null) { $where[] = "YEAR(u.birthday) = ?"; $types .= 'i'; $params[] = (int) $birthYear; }
        if ($address !== '') {
            $where[] = "(u.street LIKE ? OR u.barangay LIKE ? OR u.city LIKE ? OR u.province LIKE ?)";
            $types  .= 'ssss';
            $like    = '%' . $address . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($familyRole !== '')       { $where[] = "LOWER(u.family_role) = ?"; $types .= 's'; $params[] = strtolower($familyRole); }
        if ($civilStatus !== '')      { $where[] = "LOWER(u.civil_status) = ?"; $types .= 's'; $params[] = strtolower($civilStatus); }
        if ($citizenship !== '')      { $where[] = "u.citizenship LIKE ?"; $types .= 's'; $params[] = '%' . $citizenship . '%'; }
        if ($religion !== '')         { $where[] = "u.religion LIKE ?"; $types .= 's'; $params[] = '%' . $religion . '%'; }
        if ($ethnicity !== '')        { $where[] = "u.ethnicity LIKE ?"; $types .= 's'; $params[] = '%' . $ethnicity . '%'; }
        if ($bloodType !== '')        { $where[] = "UPPER(u.health_conditions) = ?"; $types .= 's'; $params[] = strtoupper($bloodType); }
        if ($employmentStatus !== '') { $where[] = "LOWER(u.employment_status) = ?"; $types .= 's'; $params[] = strtolower($employmentStatus); }
        if ($incomeMin !== null)      { $where[] = "u.monthly_income >= ?"; $types .= 'd'; $params[] = (float) $incomeMin; }
        if ($incomeMax !== null)      { $where[] = "u.monthly_income <= ?"; $types .= 'd'; $params[] = (float) $incomeMax; }
        if ($housingStatus !== '')    { $where[] = "LOWER(b.housing_status) = ?"; $types .= 's'; $params[] = strtolower($housingStatus); }
        if ($houseMaterial !== '')    { $where[] = "LOWER(b.house_material) = ?"; $types .= 's'; $params[] = strtolower($houseMaterial); }
        if ($electricity !== '')      { $where[] = "LOWER(b.electricity) = ?"; $types .= 's'; $params[] = strtolower($electricity); }
        if ($waterSource !== '')      { $where[] = "LOWER(b.water_source) = ?"; $types .= 's'; $params[] = strtolower($waterSource); }
        if ($toiletType !== '')       { $where[] = "LOWER(b.toilet_type) = ?"; $types .= 's'; $params[] = strtolower($toiletType); }
        if ($pregnantChildren !== '') { $where[] = "b.pregnant_or_children = ?"; $types .= 'i'; $params[] = (int) $pregnantChildren; }
        if ($isPwd !== '')            { $where[] = "b.is_pwd = ?"; $types .= 'i'; $params[] = (int) $isPwd; }
        if ($isSoloParent !== '')     { $where[] = "b.is_solo_parent = ?"; $types .= 'i'; $params[] = (int) $isSoloParent; }
        if ($isIndigenous !== '')     { $where[] = "b.is_indigenous = ?"; $types .= 'i'; $params[] = (int) $isIndigenous; }
        if ($pensionStatus !== '')    { $where[] = "LOWER(b.pension_status) = ?"; $types .= 's'; $params[] = strtolower($pensionStatus); }
        if ($isVoter !== '') {
            $where[] = ($isVoter === '1')
                ? "(u.voter_id IS NOT NULL AND u.voter_id <> '')"
                : "(u.voter_id IS NULL OR u.voter_id = '')";
        }
        if ($residentBirth !== '') { $where[] = "u.resident_birth = ?"; $types .= 'i'; $params[] = (int) $residentBirth; }
        if ($school !== '')        { $where[] = "b.school_name LIKE ?"; $types .= 's'; $params[] = '%' . $school . '%'; }
        if ($course !== '')        { $where[] = "b.course LIKE ?"; $types .= 's'; $params[] = '%' . $course . '%'; }
        if ($yearLevel !== '')     { $where[] = "LOWER(b.year_level) = ?"; $types .= 's'; $params[] = strtolower($yearLevel); }
        if ($gwa !== '')           { $where[] = "b.gwa_gpa LIKE ?"; $types .= 's'; $params[] = '%' . $gwa . '%'; }

        $sql = "
            SELECT
                u.userID, u.firstname, u.middlename, u.lastname, u.suffix,
                u.gender, u.birthday, u.family_role, u.civil_status, u.citizenship,
                u.religion, u.ethnicity, u.health_conditions AS blood_type,
                u.employment_status, u.job_title, u.monthly_income,
                u.street, u.barangay, u.city, u.province,
                u.voter_id, u.precinct, u.resident_birth, u.dateRegistered,
                a.account_role,
                b.housing_status, b.house_material, b.electricity, b.water_source, b.toilet_type,
                b.pregnant_or_children, b.is_pwd, b.pwd_id_number, b.is_solo_parent, b.is_indigenous,
                b.pension_status, b.school_name, b.course, b.year_level, b.gwa_gpa,
                b.health_hypertension, b.health_diabetes, b.health_asthma, b.health_other, b.health_other_specify, b.health_none
            FROM tbl_userinfo u
            JOIN tbl_useracc a ON u.accID = a.accID
            LEFT JOIN tbl_beneficiary b ON b.userId = u.userID
            WHERE " . implode(' AND ', $where) . "
            ORDER BY u.firstname ASC
        ";

        $rows = [];
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            if ($types !== '') {
                mysqli_stmt_bind_param($stmt, $types, ...$params);
            }
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($r = mysqli_fetch_assoc($result)) {
                $rows[] = $r;
            }
            mysqli_stmt_close($stmt);
        }

        /* ?? PHP-side: age + the derived program flags (mirrors the logic used for the
              Beneficiary Programs chart elsewhere in adminDashboard.php), then build rows ?? */

        $jobTitleExempt = ['student', 'unemployed'];

        $out = [];
        foreach ($rows as $r) {
            $age = null;
            if (!empty($r['birthday'])) {
                try {
                    $age = (new DateTime())->diff(new DateTime($r['birthday']))->y;
                } catch (Exception $e) {
                    $age = null;
                }
            }

            if ($ageMin !== null && ($age === null || $age < (int) $ageMin)) continue;
            if ($ageMax !== null && ($age === null || $age > (int) $ageMax)) continue;

            $bad_house  = in_array(strtolower($r['housing_status'] ?? ''), ['informal_settler', 'shared', 'government_housing']);
            $bad_mat    = in_array(strtolower($r['house_material'] ?? ''), ['light_materials', 'makeshift', 'wood']);
            $bad_elec   = in_array(strtolower($r['electricity'] ?? ''), ['shared', 'no_electricity']);
            $bad_water  = (strtolower($r['water_source'] ?? '') === 'shared_well');
            $bad_toilet = in_array(strtolower($r['toilet_type'] ?? ''), ['none_pit', 'shared_public']);
            $preg_child = !empty($r['pregnant_or_children']);
            $income     = (float) ($r['monthly_income'] ?? 0);
            $flag4ps    = $bad_house && $bad_mat && $bad_elec && $bad_water && $bad_toilet && $preg_child && $income < 14000;

            $gwaVal          = (($r['gwa_gpa'] ?? '') !== '') ? (float) $r['gwa_gpa'] : null;
            $flagScholarship = ($r['school_name'] ?? '') !== '' && ($r['year_level'] ?? '') !== '' && $gwaVal !== null && $gwaVal >= 1.00 && $gwaVal <= 1.75;

            $flagKabataan = ($age !== null && $age >= 15 && $age <= 30);
            $flagVoter    = !empty($r['voter_id']);

            if ($is4ps !== ''         && (($is4ps === '1') !== $flag4ps))                continue;
            if ($isScholarship !== '' && (($isScholarship === '1') !== $flagScholarship)) continue;
            if ($isKabataan !== ''    && (($isKabataan === '1') !== $flagKabataan))       continue;

            $name = trim(implode(' ', array_filter([
                $r['firstname'],
                $r['middlename'] ? $r['middlename'] . '.' : '',
                $r['lastname'],
                $r['suffix'],
            ])));
            if ($name === '') $name = '(No name on file)';

            $row = ['name' => $name];

            if ($accountRole !== '')                 $row['account_role'] = gf_label($r['account_role']);
            if ($dateFrom !== '' || $dateTo !== '')   $row['date_created'] = !empty($r['dateRegistered']) ? date('M d, Y', strtotime($r['dateRegistered'])) : '-';
            if ($sex !== '')                          $row['sex'] = gf_label($r['gender']);
            if ($birthMonth !== null)                 $row['birth_month'] = !empty($r['birthday']) ? date('F', strtotime($r['birthday'])) : '-';
            if ($birthYear !== null)                  $row['birth_year'] = !empty($r['birthday']) ? date('Y', strtotime($r['birthday'])) : '-';
            if ($ageMin !== null || $ageMax !== null) $row['age'] = $age ?? '-';
            if ($address !== '')                      $row['address'] = trim(implode(', ', array_filter([$r['street'], $r['barangay'], $r['city'], $r['province']]))) ?: '-';
            if ($familyRole !== '')                   $row['family_role'] = gf_label($r['family_role']);
            if ($civilStatus !== '')                  $row['civil_status'] = gf_label($r['civil_status']);
            if ($citizenship !== '')                  $row['citizenship'] = $r['citizenship'] ?: '-';
            if ($religion !== '')                     $row['religion'] = $r['religion'] ?: '-';
            if ($ethnicity !== '')                    $row['ethnicity'] = $r['ethnicity'] ?: '-';
            if ($bloodType !== '')                    $row['blood_type'] = $r['blood_type'] ?: '-';

            if ($employmentStatus !== '') {
                $row['employment_status'] = gf_label($r['employment_status']);
                if (!in_array(strtolower($r['employment_status'] ?? ''), $jobTitleExempt)) {
                    $row['job_title'] = $r['job_title'] ?: '-';
                }
            }

            if ($incomeMin !== null || $incomeMax !== null) $row['monthly_income'] = '?' . number_format((float) ($r['monthly_income'] ?? 0), 2);
            if ($housingStatus !== '') $row['housing_status'] = gf_label($r['housing_status']);
            if ($houseMaterial !== '') $row['house_material'] = gf_label($r['house_material']);
            if ($electricity !== '')   $row['electricity'] = gf_label($r['electricity']);
            if ($waterSource !== '')   $row['water_source'] = gf_label($r['water_source']);
            if ($toiletType !== '')    $row['toilet_type'] = gf_label($r['toilet_type']);
            if ($pregnantChildren !== '') $row['pregnant_children'] = gf_yesno(!empty($r['pregnant_or_children']));

            if ($isPwd !== '') {
                $row['is_pwd']        = gf_yesno(!empty($r['is_pwd']));
                $row['pwd_id_number'] = $r['pwd_id_number'] ?: '-';
                $healthTypes = [];
                if (!empty($r['health_hypertension'])) $healthTypes[] = 'Hypertension';
                if (!empty($r['health_diabetes']))     $healthTypes[] = 'Diabetes';
                if (!empty($r['health_asthma']))       $healthTypes[] = 'Asthma';
                if (!empty($r['health_other']))        $healthTypes[] = ($r['health_other_specify'] ?: 'Other');
                if (empty($healthTypes) && !empty($r['health_none'])) $healthTypes[] = 'None';
                $row['health_type'] = $healthTypes ? implode(', ', $healthTypes) : '-';
            }

            if ($isSoloParent !== '')  $row['is_solo_parent'] = gf_yesno(!empty($r['is_solo_parent']));
            if ($isIndigenous !== '')  $row['is_indigenous'] = gf_yesno(!empty($r['is_indigenous']));
            if ($is4ps !== '')         $row['is_4ps'] = gf_yesno($flag4ps);
            if ($isScholarship !== '') $row['is_scholarship'] = gf_yesno($flagScholarship);
            if ($isKabataan !== '')    $row['is_kabataan'] = gf_yesno($flagKabataan);
            if ($pensionStatus !== '') $row['pension_status'] = gf_label($r['pension_status']);

            if ($isVoter !== '') {
                $row['is_voter'] = gf_yesno($flagVoter);
                $row['voter_id'] = $r['voter_id'] ?: '-';
                $row['precinct'] = $r['precinct'] ?: '-';
            }

            if ($residentBirth !== '') $row['resident_birth'] = gf_yesno(!empty($r['resident_birth']));
            if ($school !== '')        $row['school'] = $r['school_name'] ?: '-';
            if ($course !== '')        $row['course'] = $r['course'] ?: '-';
            if ($yearLevel !== '')     $row['year_level'] = gf_label($r['year_level']);
            if ($gwa !== '')           $row['gwa'] = $r['gwa_gpa'] ?: '-';

            $out[] = $row;
        }

        /* ?? Column metadata, in the same order as the filter spec ?? */
        $jobTitleActive = ($employmentStatus !== '' && !in_array(strtolower($employmentStatus), $jobTitleExempt));

        $columnDefs = [
            ['key' => 'account_role',      'label' => 'Account Role',         'active' => $accountRole !== ''],
            ['key' => 'date_created',      'label' => 'Date Created',         'active' => ($dateFrom !== '' || $dateTo !== '')],
            ['key' => 'sex',               'label' => 'Sex',                  'active' => $sex !== ''],
            ['key' => 'birth_month',       'label' => 'Birth Month',          'active' => $birthMonth !== null],
            ['key' => 'birth_year',        'label' => 'Birth Year',           'active' => $birthYear !== null],
            ['key' => 'age',               'label' => 'Age',                  'active' => ($ageMin !== null || $ageMax !== null)],
            ['key' => 'address',           'label' => 'Address',              'active' => $address !== ''],
            ['key' => 'family_role',       'label' => 'Family Role',          'active' => $familyRole !== ''],
            ['key' => 'civil_status',      'label' => 'Civil Status',         'active' => $civilStatus !== ''],
            ['key' => 'citizenship',       'label' => 'Citizenship',          'active' => $citizenship !== ''],
            ['key' => 'religion',          'label' => 'Religion',             'active' => $religion !== ''],
            ['key' => 'ethnicity',         'label' => 'Ethnicity',            'active' => $ethnicity !== ''],
            ['key' => 'blood_type',        'label' => 'Blood Type',           'active' => $bloodType !== ''],
            ['key' => 'employment_status', 'label' => 'Employment Status',    'active' => $employmentStatus !== ''],
            ['key' => 'job_title',         'label' => 'Job Title',            'active' => $jobTitleActive],
            ['key' => 'monthly_income',    'label' => 'Monthly Income',       'active' => ($incomeMin !== null || $incomeMax !== null)],
            ['key' => 'housing_status',    'label' => 'Housing Status',       'active' => $housingStatus !== ''],
            ['key' => 'house_material',    'label' => 'House Material',       'active' => $houseMaterial !== ''],
            ['key' => 'electricity',       'label' => 'Electricity',          'active' => $electricity !== ''],
            ['key' => 'water_source',      'label' => 'Water Source',         'active' => $waterSource !== ''],
            ['key' => 'toilet_type',       'label' => 'Toilet Type',          'active' => $toiletType !== ''],
            ['key' => 'pregnant_children', 'label' => 'Pregnant/Children<5',  'active' => $pregnantChildren !== ''],
            ['key' => 'is_pwd',            'label' => 'Is PWD',               'active' => $isPwd !== ''],
            ['key' => 'pwd_id_number',     'label' => 'PWD Number',           'active' => $isPwd !== ''],
            ['key' => 'health_type',       'label' => 'Health Type',          'active' => $isPwd !== ''],
            ['key' => 'is_solo_parent',    'label' => 'Solo Parent',          'active' => $isSoloParent !== ''],
            ['key' => 'is_indigenous',     'label' => 'Indigenous Person',    'active' => $isIndigenous !== ''],
            ['key' => 'is_4ps',            'label' => '4Ps Member',           'active' => $is4ps !== ''],
            ['key' => 'is_scholarship',    'label' => 'Scholarship',          'active' => $isScholarship !== ''],
            ['key' => 'is_kabataan',       'label' => 'Kabataan',             'active' => $isKabataan !== ''],
            ['key' => 'pension_status',    'label' => 'Pension Status',       'active' => $pensionStatus !== ''],
            ['key' => 'is_voter',          'label' => 'Voter',                'active' => $isVoter !== ''],
            ['key' => 'voter_id',          'label' => 'Voter ID',             'active' => $isVoter !== ''],
            ['key' => 'precinct',          'label' => 'Precinct',             'active' => $isVoter !== ''],
            ['key' => 'resident_birth',    'label' => 'Resident Since Birth', 'active' => $residentBirth !== ''],
            ['key' => 'school',            'label' => 'School',               'active' => $school !== ''],
            ['key' => 'course',            'label' => 'Course',               'active' => $course !== ''],
            ['key' => 'year_level',        'label' => 'Year Level',           'active' => $yearLevel !== ''],
            ['key' => 'gwa',               'label' => 'GWA/GPA',              'active' => $gwa !== ''],
        ];

        $columns = [];
        foreach ($columnDefs as $c) {
            if ($c['active']) $columns[] = ['key' => $c['key'], 'label' => $c['label']];
        }

        /* ?? Human-readable summary of the conditions applied (for the printable report) ?? */
        $filters = [];
        if ($accountRole !== '')      $filters[] = ['label' => 'Account Role', 'value' => gf_label($accountRole)];
        if ($dateFrom !== '' || $dateTo !== '') {
            $filters[] = ['label' => 'Date Created', 'value' => trim(($dateFrom !== '' ? date('M d, Y', strtotime($dateFrom)) : 'Any') . ' - ' . ($dateTo !== '' ? date('M d, Y', strtotime($dateTo)) : 'Any'))];
        }
        if ($sex !== '')               $filters[] = ['label' => 'Sex', 'value' => gf_label($sex)];
        if ($birthMonth !== null)      $filters[] = ['label' => 'Birth Month', 'value' => date('F', mktime(0, 0, 0, (int) $birthMonth, 1))];
        if ($birthYear !== null)       $filters[] = ['label' => 'Birth Year', 'value' => (string) $birthYear];
        if ($ageMin !== null || $ageMax !== null) $filters[] = ['label' => 'Age', 'value' => ($ageMin ?? 'Any') . ' - ' . ($ageMax ?? 'Any')];
        if ($address !== '')           $filters[] = ['label' => 'Address', 'value' => $address];
        if ($familyRole !== '')        $filters[] = ['label' => 'Family Role', 'value' => gf_label($familyRole)];
        if ($civilStatus !== '')       $filters[] = ['label' => 'Civil Status', 'value' => gf_label($civilStatus)];
        if ($citizenship !== '')       $filters[] = ['label' => 'Citizenship', 'value' => $citizenship];
        if ($religion !== '')          $filters[] = ['label' => 'Religion', 'value' => $religion];
        if ($ethnicity !== '')         $filters[] = ['label' => 'Ethnicity', 'value' => $ethnicity];
        if ($bloodType !== '')         $filters[] = ['label' => 'Blood Type', 'value' => strtoupper($bloodType)];
        if ($employmentStatus !== '')  $filters[] = ['label' => 'Employment Status', 'value' => gf_label($employmentStatus)];
        if ($incomeMin !== null || $incomeMax !== null) $filters[] = ['label' => 'Monthly Income', 'value' => '?' . ($incomeMin ?? '0') . ' - ?' . ($incomeMax ?? 'Any')];
        if ($housingStatus !== '')     $filters[] = ['label' => 'Housing Status', 'value' => gf_label($housingStatus)];
        if ($houseMaterial !== '')     $filters[] = ['label' => 'House Material', 'value' => gf_label($houseMaterial)];
        if ($electricity !== '')       $filters[] = ['label' => 'Electricity', 'value' => gf_label($electricity)];
        if ($waterSource !== '')       $filters[] = ['label' => 'Water Source', 'value' => gf_label($waterSource)];
        if ($toiletType !== '')        $filters[] = ['label' => 'Toilet Type', 'value' => gf_label($toiletType)];
        if ($pregnantChildren !== '')  $filters[] = ['label' => 'Pregnant/Children<5', 'value' => gf_yesno($pregnantChildren === '1')];
        if ($isPwd !== '')             $filters[] = ['label' => 'Is PWD', 'value' => gf_yesno($isPwd === '1')];
        if ($isSoloParent !== '')      $filters[] = ['label' => 'Solo Parent', 'value' => gf_yesno($isSoloParent === '1')];
        if ($isIndigenous !== '')      $filters[] = ['label' => 'Indigenous Person', 'value' => gf_yesno($isIndigenous === '1')];
        if ($is4ps !== '')             $filters[] = ['label' => '4Ps Member', 'value' => gf_yesno($is4ps === '1')];
        if ($isScholarship !== '')     $filters[] = ['label' => 'Scholarship', 'value' => gf_yesno($isScholarship === '1')];
        if ($isKabataan !== '')        $filters[] = ['label' => 'Kabataan', 'value' => gf_yesno($isKabataan === '1')];
        if ($pensionStatus !== '')     $filters[] = ['label' => 'Pension Status', 'value' => gf_label($pensionStatus)];
        if ($isVoter !== '')           $filters[] = ['label' => 'Voter', 'value' => gf_yesno($isVoter === '1')];
        if ($residentBirth !== '')     $filters[] = ['label' => 'Resident Since Birth', 'value' => gf_yesno($residentBirth === '1')];
        if ($school !== '')            $filters[] = ['label' => 'School', 'value' => $school];
        if ($course !== '')            $filters[] = ['label' => 'Course', 'value' => $course];
        if ($yearLevel !== '')         $filters[] = ['label' => 'Year Level', 'value' => gf_label($yearLevel)];
        if ($gwa !== '')               $filters[] = ['label' => 'GWA/GPA', 'value' => $gwa];

        return [
            'count'   => count($out),
            'columns' => $columns,
            'data'    => $out,
            'filters' => $filters,
        ];
    }
}