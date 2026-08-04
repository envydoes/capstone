<!-- PERSONAL INFORMATION -->
<div class="section-card">
  <div class="section-title"><div class="section-icon"><i class="fa-solid fa-user text-green-700 text-sm"></i></div>Personal Information</div>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
      <label class="field-label">First Name <span class="required-star">*</span></label>
      <input type="text" id="e_firstname" maxlength="100" class="field-input" placeholder="First name">
    </div>
    <div>
      <label class="field-label">Last Name <span class="required-star">*</span></label>
      <input type="text" id="e_lastname" maxlength="100" class="field-input" placeholder="Last name">
    </div>
    <div>
      <label class="field-label">Middle Name</label>
      <input type="text" id="e_middlename" maxlength="100" class="field-input" placeholder="Middle name">
    </div>
    <div>
      <label class="field-label">Suffix</label>
      <input type="text" id="e_suffix" maxlength="20" class="field-input" placeholder="e.g. Jr., Sr., III">
    </div>
    <div>
      <label class="field-label">Family Role <span class="required-star">*</span></label>
      <select id="e_family_role" class="field-input">
        <option value="">Select Family Role</option>
        <option value="head">Head of Family</option>
        <option value="spouse">Spouse</option>
        <option value="child">Child</option>
        <option value="parent">Parent</option>
        <option value="other">Other</option>
      </select>
    </div>
    <div>
      <label class="field-label">Gender <span class="required-star">*</span></label>
      <select id="e_gender" class="field-input">
        <option value="">Select Gender</option>
        <option value="male">Male</option>
        <option value="female">Female</option>
        <option value="other">Other</option>
      </select>
    </div>
    <div>
      <label class="field-label">Birthday <span class="required-star">*</span></label>
      <input type="date" id="e_birthday" max="<?= date('Y-m-d') ?>" class="field-input">
    </div>
    <div>
      <label class="field-label">Birthplace <span class="required-star">*</span></label>
      <input type="text" id="e_birthplace" maxlength="200" class="field-input" placeholder="City, Province, Country">
    </div>
    <div>
      <label class="field-label">Civil Status <span class="required-star">*</span></label>
      <select id="e_civil_status" class="field-input">
        <option value="">Select Civil Status</option>
        <option value="single">Single</option>
        <option value="married">Married</option>
        <option value="divorced">Divorced</option>
        <option value="widowed">Widowed</option>
        <option value="separated">Separated</option>
      </select>
    </div>
    <div>
      <label class="field-label">Citizenship <span class="required-star">*</span></label>
      <input type="text" id="e_citizenship" maxlength="100" class="field-input" placeholder="e.g. Filipino">
    </div>
    <div>
      <label class="field-label">Religion</label>
      <input type="text" id="e_religion" maxlength="100" class="field-input" placeholder="e.g. Catholic">
    </div>
    <div>
      <label class="field-label">Ethnicity</label>
      <input type="text" id="e_ethnicity" maxlength="100" class="field-input" placeholder="e.g. Tagalog">
    </div>
  </div>
</div>

<!-- ADDRESS -->
<div class="section-card">
  <div class="section-title"><div class="section-icon"><i class="fa-solid fa-location-dot text-green-700 text-sm"></i></div>Complete Address Information</div>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
      <label class="field-label">Street Address <span class="required-star">*</span></label>
      <input type="text" id="e_street" maxlength="200" class="field-input" placeholder="Street name and number">
    </div>
    <div>
      <label class="field-label">Barangay <span class="required-star">*</span></label>
      <input type="text" id="e_barangay" maxlength="100" class="field-input" placeholder="Barangay">
    </div>
    <div>
      <label class="field-label">City / Municipality <span class="required-star">*</span></label>
      <input type="text" id="e_city" maxlength="100" class="field-input" placeholder="City or Municipality">
    </div>
    <div>
      <label class="field-label">Province <span class="required-star">*</span></label>
      <input type="text" id="e_province" maxlength="100" class="field-input" placeholder="Province">
    </div>
    <div>
      <label class="field-label">ZIP Code <span class="required-star">*</span></label>
      <input type="text" id="e_zip" maxlength="10" class="field-input" placeholder="ZIP Code">
    </div>
  </div>
</div>

<!-- CONTACT & HEALTH -->
<div class="section-card">
  <div class="section-title"><div class="section-icon"><i class="fa-solid fa-phone text-green-700 text-sm"></i></div>Contact and Health Information</div>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
      <label class="field-label">Phone Number <span class="required-star">*</span></label>
      <input type="tel" id="e_phone" maxlength="20" class="field-input" placeholder="+63 912 345 6789">
      <span class="text-gray-400 text-xs mt-1 block">Format: +639XXXXXXXXX or 09XXXXXXXXX</span>
    </div>
    <div>
      <label class="field-label">Email <span class="required-star">*</span></label>
      <input type="email" id="e_email" maxlength="254" class="field-input" placeholder="Email address">
    </div>
    <div>
      <label class="field-label">Emergency Contact</label>
      <input type="text" id="e_emergency_contact" maxlength="150" class="field-input" placeholder="Name of emergency contact">
    </div>
    <div>
      <label class="field-label">Emergency Contact Phone</label>
      <input type="tel" id="e_emergency_phone" maxlength="20" class="field-input" placeholder="Emergency contact number">
    </div>
    <div class="md:col-span-2">
      <label class="field-label">Blood Type</label>
      <input type="text" id="e_health_conditions" maxlength="10" class="field-input" placeholder="e.g. O+, A-, B+">
    </div>
  </div>
</div>

<!-- EMPLOYMENT -->
<div class="section-card">
  <div class="section-title"><div class="section-icon"><i class="fa-solid fa-briefcase text-green-700 text-sm"></i></div>Employment Information</div>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
      <label class="field-label">Employment Status <span class="required-star">*</span></label>
      <select id="e_employment_status" class="field-input">
        <option value="">Select Employment Status</option>
        <option value="employed">Employed</option>
        <option value="self-employed">Self-Employed</option>
        <option value="unemployed">Unemployed</option>
        <option value="student">Student</option>
        <option value="retired">Retired</option>
        <option value="other">Other</option>
      </select>
    </div>
    <div>
      <label class="field-label">Job Title</label>
      <input type="text" id="e_job_title" maxlength="150" class="field-input" placeholder="Your job title">
    </div>
    <div>
      <label class="field-label">Monthly Income (PHP)</label>
      <input type="number" id="e_monthly_income" min="0" max="9999999" step="1" class="field-input" placeholder="e.g. 25000">
    </div>
  </div>
</div>

<!-- VOTER -->
<div class="section-card">
  <div class="section-title"><div class="section-icon"><i class="fa-solid fa-check-to-slot text-green-700 text-sm"></i></div>Voter Information</div>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
      <label class="field-label">Voter ID Number</label>
      <input type="text" id="e_voter_id" maxlength="50" class="field-input" placeholder="Voter ID if applicable">
    </div>
    <div>
      <label class="field-label">Precinct Number</label>
      <input type="text" id="e_precinct" maxlength="50" class="field-input" placeholder="Precinct number">
    </div>
  </div>
</div>

<!-- RESIDENCY -->
<div class="section-card">
  <div class="section-title"><div class="section-icon"><i class="fa-solid fa-house text-green-700 text-sm"></i></div>Residency Information</div>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
      <label class="field-label">Years as Resident <span class="required-star">*</span></label>
      <input type="number" id="e_years_resident" min="0" max="120" step="1" class="field-input" placeholder="Number of years">
    </div>
    <div class="flex items-end pb-1">
      <label class="flex items-center gap-3 cursor-pointer select-none" onclick="toggleEditResidentBirth()">
        <div class="toggle-track" id="editResidentBirthToggle">
          <div class="toggle-thumb"></div>
        </div>
        <span class="text-sm font-semibold text-gray-700">Resident since Birth</span>
      </label>
      <input type="hidden" id="e_resident_birth" value="0">
    </div>
  </div>
</div>