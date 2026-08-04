  <!-- ══════════════════════════════════════════════════════
           ADDITIONAL REPORTS
      ═══════════════════════════════════════════════════════ -->
      <div class="panel f3">
        <div class="panel-head" style="padding-bottom:10px;">
          <p class="panel-title">Additional Reports</p>
        </div>

        <div class="report-tab-bar">
          <button class="report-tab-btn active" data-tab="resident"    onclick="switchReportTab('resident',this)">Resident Management</button>
          <button class="report-tab-btn"        data-tab="beneficiary" onclick="switchReportTab('beneficiary',this)">Beneficiary Management</button>
          <button class="report-tab-btn"        data-tab="business"    onclick="switchReportTab('business',this)">Business / Apartment</button>
          <button class="report-tab-btn"        data-tab="equipment"   onclick="switchReportTab('equipment',this)">Equipment</button>
          <button class="report-tab-btn"        data-tab="documents"   onclick="switchReportTab('documents',this)">Document Requests</button>
          <button class="report-tab-btn"        data-tab="accounts"    onclick="switchReportTab('accounts',this)">User / Accounts</button>
        </div>

        <!-- ══ RESIDENT MANAGEMENT ══ -->
        <div class="report-pane" id="pane-resident">

          <div class="subpanel">
            <p class="subpanel-title">Population by Purok / Zone</p>
            <div id="chartPurok" class="w-full h-[280px]"></div>
          </div>

          <div class="subpanel">
            <p class="subpanel-title">Age Bracket Breakdown</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div id="chartAgeBracket" class="w-full h-[220px]"></div>
              <div class="flex flex-col justify-center gap-3 text-sm">
                <div class="flex justify-between border-b border-gray-100 pb-2"><span>Minors (0–17)</span><strong id="bkMinors">0</strong></div>
                <div class="flex justify-between border-b border-gray-100 pb-2"><span>Working Age (18–59)</span><strong id="bkWorking">0</strong></div>
                <div class="flex justify-between"><span>Seniors (60+)</span><strong id="bkSeniors">0</strong></div>
              </div>
            </div>
          </div>

        </div>

        <!-- ══ BENEFICIARY MANAGEMENT ══ -->
        <div class="report-pane hidden" id="pane-beneficiary">

          <div class="subpanel">
            <p class="subpanel-title">Approved Beneficiaries by Category</p>
            <div id="chartBenPrograms" class="w-full h-[280px]"></div>
          </div>

     
          <div class="subpanel">
            <p class="subpanel-title">Residents NOT Yet Registered as Beneficiaries <span class="subpanel-note">(outreach list)</span></p>
            <div class="mini-filter-row">
              <div class="mini-search-wrap" style="flex:1;min-width:200px;">
                <input type="text" id="nonBenSearch" placeholder="Search name or purok..." style="width:100%;" oninput="nonBenTable.debounced()">
                <span class="mini-search-spinner" id="nonBenSearchSpinner"></span>
              </div>
              <span class="mini-stat-inline"><span class="num" id="nonBenCount">0</span><span class="text-xs text-gray-400 ml-1">residents</span></span>
              <button type="button" class="btn-print-list" onclick="printOutreachList()">
                <i class="fa-solid fa-print"></i> Print This List
              </button>
            </div>
            <div class="mini-table-wrap">
              <div class="mini-loading-overlay" id="nonBenLoading"><div class="mini-spinner"></div><span class="mini-loading-text">Fetching...</span></div>
            <table class="mini-table">
                <thead><tr><th>Name</th><th>Purok / Street</th><th>Phone</th><th>Email</th></tr></thead>
                <tbody id="nonBenTableBody"></tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ══ BUSINESS / APARTMENT MANAGEMENT ══ -->

        <div class="report-pane hidden" id="pane-business">

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="subpanel">
              <p class="subpanel-title">Active Listings by Type</p>
              <div id="chartListingType" class="w-full h-[240px]"></div>
            </div>
            <div class="subpanel">
              <p class="subpanel-title">Apartment Occupancy</p>
              <div id="chartOccupancy" class="w-full h-[240px]"></div>
            </div>
          </div>

          <div class="subpanel">
            <p class="subpanel-title">Owner Directory <span class="subpanel-note">(who owns what)</span></p>
            <div class="mini-filter-row">
              <div class="mini-search-wrap" style="flex:1;min-width:200px;">
                <input type="text" id="ownerSearch" placeholder="Search owner..." style="width:100%;" oninput="ownerTable.debounced()">
                <span class="mini-search-spinner" id="ownerSearchSpinner"></span>
              </div>
              <span class="mini-stat-inline"><span class="num" id="ownerCount">0</span><span class="text-xs text-gray-400 ml-1">owners</span></span>
              <button type="button" class="btn-print-list" onclick="printOwnerDirectory()">
                <i class="fa-solid fa-print"></i> Print This List
              </button>
            </div>
            <div class="mini-table-wrap">
              <div class="mini-loading-overlay" id="ownerLoading"><div class="mini-spinner"></div><span class="mini-loading-text">Fetching...</span></div>
              <table class="mini-table">
                <thead><tr><th>Owner</th><th>Total Listings</th><th>Apartments</th><th>Businesses</th></tr></thead>
                <tbody id="ownerTableBody"></tbody>
              </table>
            </div>
          </div>

        </div>

        <!-- ══ EQUIPMENT ══ -->
        <div class="report-pane hidden" id="pane-equipment">

          <div class="subpanel">
            <p class="subpanel-title">Most-Borrowed Equipment <span class="subpanel-note">(top 5)</span></p>
            <div id="chartMostBorrowed" class="w-full h-[260px]"></div>
          </div>

          <div class="subpanel">
            <p class="subpanel-title">Currently Borrowed / Overdue Items <span class="subpanel-note">(borrowers list)</span></p>
            <div class="mini-filter-row">
              <div class="mini-search-wrap" style="flex:1;min-width:200px;">
                <input type="text" id="borrowedSearch" placeholder="Search item or borrower..." style="width:100%;" oninput="borrowedTable.debounced()">
                <span class="mini-search-spinner" id="borrowedSearchSpinner"></span>
              </div>
              <span class="mini-stat-inline"><span class="num" id="borrowedCount">0</span><span class="text-xs text-gray-400 ml-1">out</span></span>
              <button type="button" class="btn-print-list" onclick="printBorrowedList()">
                <i class="fa-solid fa-print"></i> Print This List
              </button>
            </div>
            <div class="mini-table-wrap">
              <div class="mini-loading-overlay" id="borrowedLoading"><div class="mini-spinner"></div><span class="mini-loading-text">Fetching...</span></div>
              <table class="mini-table">
                <thead><tr><th>Item</th><th>Qty</th><th>Borrower</th><th>Return Date</th><th>Status</th></tr></thead>
                <tbody id="borrowedTableBody"></tbody>
              </table>
            </div>
          </div>

        </div>