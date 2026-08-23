<?php
/**
 * includes/analytics_report_items.php
 * ------------------------------------------------------------
 * Single source of truth for every chart/graph the "Print Report"
 * feature can include (adminDashboard.php). Used to:
 *   1. Build the "Set Conditions"-style checkbox modal (grouped by tab/section)
 *   2. Look up each item's title + explanatory summary when rendering
 *      the printed report (print_global_list.php, ?list=analytics)
 *
 * type:
 *   'image'  - a Google Chart; the browser captures it via chart.getImageURI()
 *              and posts the PNG data URI to the print page.
 *   'bars'   - a plain HTML/CSS bar panel (no chart library involved); the
 *              browser reads the numbers straight out of the DOM and posts
 *              them as small JSON, which the print page redraws as crisp
 *              (non-image) bars.
 *   'roster' - a count + a name/age/birthdate/contact/address/role table.
 *              Unlike 'image'/'bars', this is NOT captured from the
 *              browser DOM - print_global_list.php looks up the matching
 *              query function via $ANALYTICS_ROSTER_QUERIES below and runs
 *              it directly against the database at print time, so the
 *              roster is always fresh as of the moment the report is
 *              generated.
 *   'list'   - like 'roster', but with its own custom columns instead of
 *              the fixed Age/Birthdate/Contact/Address/Role shape. The
 *              query function is given directly on the item via its
 *              'query' key (see the Business/Apartment, Equipment, and
 *              Document Requests items below) rather than through a
 *              separate lookup table.
 * ------------------------------------------------------------
 */

if (!isset($ANALYTICS_REPORT_ITEMS)) {
    $ANALYTICS_REPORT_ITEMS = [
        'donutchart' => [
            'title'   => 'Demographic Distribution',
            'group'   => 'Overview',
            'type'    => 'image',
            'summary' => "Shows the male-to-female split among all registered residents, giving a quick read on the sex balance of the barangay population.",
        ],
        'barchart' => [
            'title'   => 'Monthly Service Requests',
            'group'   => 'Overview',
            'type'    => 'image',
            'summary' => "Compares document requests, beneficiary applications, and equipment borrowing volume over the last six months to highlight demand trends.",
        ],
        'ageDistribution' => [
            'title'   => 'Age Distribution',
            'group'   => 'Overview',
            'type'    => 'bars',
            'summary' => "Breaks down the resident population into five age brackets (0-17, 18-30, 31-45, 46-60, 60+) to support age-targeted program planning.",
        ],
        'requestStatus' => [
            'title'   => 'Request Status Overview',
            'group'   => 'Overview',
            'type'    => 'bars',
            'summary' => "Shows the proportion of all service requests that are approved, pending, or rejected \u{2014} an indicator of processing efficiency.",
        ],
        'incomeBracket' => [
            'title'   => 'Income Bracket Distribution',
            'group'   => 'Overview',
            'type'    => 'bars',
            'summary' => "Groups residents by self-reported monthly household income to help identify the share of the population that may qualify for economic assistance programs.",
        ],
        'incomeVsAgeChart' => [
            'title'   => 'Income vs Age Group',
            'group'   => 'Overview',
            'type'    => 'image',
            'summary' => "Cross-references income bracket against age group to reveal which age segments are most represented at each income level.",
        ],

        'chartPurok' => [
            'title'   => 'Population by Purok / Zone',
            'group'   => 'Resident Management',
            'type'    => 'image',
            'summary' => "Shows how the resident population is distributed across the barangay's puroks/zones \u{2014} useful for planning outreach and resource allocation.",
        ],
        'chartAgeBracket' => [
            'title'   => 'Age Bracket Breakdown',
            'group'   => 'Resident Management',
            'type'    => 'image',
            'summary' => "A proportional view of the same age-bracket data as a pie chart, for at-a-glance comparison of generational segments.",
        ],
        'newResidentsMonth' => [
            'title'   => 'New Residents This Month',
            'group'   => 'Resident Management',
            'type'    => 'roster',
            'summary' => "Lists every approved resident account (non-resident accounts excluded) registered so far this month.",
        ],

        'chartBenPrograms' => [
            'title'   => 'Approved Beneficiaries by Category',
            'group'   => 'Beneficiary Management',
            'type'    => 'image',
            'summary' => "Shows how many residents are approved under each social welfare program category, such as 4Ps, PWD, Senior Citizen, and Solo Parent.",
        ],
        'newBeneficiariesMonth' => [
            'title'   => 'New Beneficiaries This Month',
            'group'   => 'Beneficiary Management',
            'type'    => 'roster',
            'summary' => "Lists every resident approved as a beneficiary so far this month.",
        ],

        'chartListingType' => [
            'title'   => 'Active Listings by Type',
            'group'   => 'Business / Apartment',
            'type'    => 'image',
            'summary' => "Compares the number of active business listings against apartment listings currently registered in the barangay.",
        ],
        'chartOccupancy' => [
            'title'   => 'Apartment Occupancy',
            'group'   => 'Business / Apartment',
            'type'    => 'image',
            'summary' => "Shows the proportion of registered apartment units that are currently occupied versus available.",
        ],
        'listingsNewThisMonth' => [
            'title'   => 'New Listings This Month',
            'group'   => 'Business / Apartment',
            'type'    => 'list',
            'query'   => 'gf_run_new_listings_this_month_query',
            'summary' => "Lists residents and non-resident business/apartment owners with new listings created this month.",
        ],

        'chartMostBorrowed' => [
            'title'   => 'Most-Borrowed Equipment (Top 5)',
            'group'   => 'Equipment',
            'type'    => 'image',
            'summary' => "Ranks the five most frequently borrowed equipment items \u{2014} useful for inventory and procurement planning.",
        ],
        'equipBorrowThisMonth' => [
            'title'   => 'Borrow Requests This Month',
            'group'   => 'Equipment',
            'type'    => 'list',
            'query'   => 'gf_run_equip_borrow_this_month_query',
            'summary' => "Lists every resident who requested to borrow equipment this month, with the item and its current status in place of role.",
        ],
        'equipRepeatBorrowers' => [
            'title'   => 'Repeat Borrowers',
            'group'   => 'Equipment',
            'type'    => 'list',
            'query'   => 'gf_run_equip_repeat_borrowers_query',
            'summary' => "Lists residents who borrowed 3 or more pieces of equipment this month, with the items borrowed and how many times, in place of role.",
        ],

        'chartDocMonthly' => [
            'title'   => 'Document Request Volume Trend',
            'group'   => 'Document Requests',
            'type'    => 'image',
            'summary' => "Tracks the monthly volume of document requests over time to help identify seasonal demand patterns.",
        ],
        'docRequestsThisMonth' => [
            'title'   => 'Requests This Month',
            'group'   => 'Document Requests',
            'type'    => 'list',
            'query'   => 'gf_run_doc_requests_this_month_query',
            'summary' => "Lists every resident and non-resident who filed a document request this month, with the document type(s) they requested in place of role.",
        ],
        'docRepeatRequesters' => [
            'title'   => 'Repeat Requesters',
            'group'   => 'Document Requests',
            'type'    => 'list',
            'query'   => 'gf_run_doc_repeat_requesters_query',
            'summary' => "Lists residents and non-residents who have filed 2 or more document requests, with the document type(s) they've requested in place of role.",
        ],

        'chartRoleCounts' => [
            'title'   => 'Accounts by Role',
            'group'   => 'User / Accounts',
            'type'    => 'image',
            'summary' => "Shows the distribution of user accounts across roles: resident, non-resident, business/apartment owner, and admin.",
        ],
        'chartAccountStatus' => [
            'title'   => 'Active vs Inactive Accounts',
            'group'   => 'User / Accounts',
            'type'    => 'image',
            'summary' => "Compares the number of currently active accounts against inactive, pending, or rejected ones.",
        ],
        'chartRegTrend' => [
            'title'   => 'Registration Trend Over Time',
            'group'   => 'User / Accounts',
            'type'    => 'image',
            'summary' => "Tracks new account registrations by month \u{2014} helpful for spotting growth trends and the effect of outreach campaigns.",
        ],
        'newRegistrationsMonth' => [
            'title'   => 'New Registrations This Month',
            'group'   => 'User / Accounts',
            'type'    => 'roster',
            'summary' => "Lists every resident and non-resident account registered so far this month.",
        ],
        'accountsRegisteredToday' => [
            'title'   => 'Accounts Registered Today',
            'group'   => 'User / Accounts',
            'type'    => 'roster',
            'summary' => "Lists every resident and non-resident account registered today.",
        ],
    ];

    /* Which report tab (if any) needs to be switched-to/drawn before this
       item's chart exists in the DOM. Items not listed here are always
       available (drawn once on initial page load, or - for 'roster'/'list'
       items - fetched straight from the database at print time). */
    $ANALYTICS_TAB_MAP = [
        'chartPurok'         => 'resident',
        'chartAgeBracket'    => 'resident',
        'chartBenPrograms'   => 'beneficiary',
        'chartListingType'   => 'business',
        'chartOccupancy'     => 'business',
        'chartMostBorrowed'  => 'equipment',
        'chartDocMonthly'    => 'documents',
        'chartRoleCounts'    => 'accounts',
        'chartAccountStatus' => 'accounts',
        'chartRegTrend'      => 'accounts',
    ];

    /* Maps each 'roster'-type item key to the report_queries.php function
       that fetches its count + name/age/birthdate/contact/address/role
       rows. print_global_list.php calls this directly - roster items are
       never drawn client-side, so they have no entry in
       $ANALYTICS_TAB_MAP above.
       ('list'-type items don't need an entry here - their query function
       is given inline via each item's own 'query' key above.) */
    $ANALYTICS_ROSTER_QUERIES = [
        'newRegistrationsMonth'   => 'gf_run_new_registrations_month_query',
        'accountsRegisteredToday' => 'gf_run_accounts_today_query',
        'newResidentsMonth'       => 'gf_run_new_residents_month_query',
        'newBeneficiariesMonth'   => 'gf_run_new_beneficiaries_month_query',
    ];
}