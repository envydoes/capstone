<?php
session_start();
$accountRoles = $_SESSION['account_role'] ?? [];
if (is_string($accountRoles)) {
    $accountRoles = [$accountRoles];
}
$backHref = 'residentProfile.php';
if (in_array('business', $accountRoles, true) || in_array('non_resident', $accountRoles, true)) {
    $backHref = 'nonResidentProfile.php';
} elseif (in_array('resident', $accountRoles, true)) {
    $backHref = 'residentProfile.php';
}
?>
<!DOCTYPE html> 
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="../assets/responsive-global.css">
  <title>Verification - SumEste Portal</title>
  <link rel="icon" href="../assets/logo2.png" type="image/png">
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/tailwind/input.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'DM Sans', sans-serif; background: #f0fdf4; min-height: 100vh; }

    /* Navbar */
    .nav-link { position: relative; transition: color 0.2s; }
    .nav-link::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 0; height: 2px; background: #16a34a; transition: width 0.3s; }
    .nav-link:hover::after { width: 100%; }
    .nav-link:hover { color: #15803d; }

    /* Progress */
    .step-circle {
      width: 36px; height: 36px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: 0.85rem; transition: all 0.3s;
      flex-shrink: 0;
    }
    .step-done  { background: #15803d; color: #fff; border: 3px solid #15803d; }
    .step-active{ background: #16a34a; color: #fff; border: 3px solid #16a34a; box-shadow: 0 0 0 5px rgba(22,163,74,0.18); }
    .step-idle  { background: #fff; color: #9ca3af; border: 3px solid #d1d5db; }
    .step-line  { flex: 1; height: 3px; border-radius: 2px; }
    .line-done  { background: #15803d; }
    .line-idle  { background: #d1d5db; }

    /* Card */
    .form-card {
      background: #fff;
      border: 1px solid #d1fae5;
      border-radius: 24px;
      box-shadow: 0 8px 40px rgba(21,128,61,0.07);
    }

    /* Section card */
    .section-card {
      background: #fff; border: 1px solid #e5e7eb;
      border-radius: 16px; overflow: hidden;
    }
    .section-header {
      background: #f0fdf4; border-bottom: 1px solid #dcfce7;
      padding: 14px 20px; display: flex; align-items: center; gap: 10px;
    }

    /* Upload zone */
    .upload-zone {
      border: 2px dashed #86efac;
      border-radius: 14px;
      background: #f0fdf4;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      cursor: pointer; transition: border-color 0.2s, background 0.2s;
      position: relative; overflow: hidden;
      min-height: 180px;
    }
    .upload-zone:hover { border-color: #16a34a; background: #dcfce7; }
    .upload-zone.has-file { border-style: solid; border-color: #16a34a; background: #fff; }
    .upload-zone.drag-over { border-color: #15803d; background: #bbf7d0; }

    /* Buttons */
    .btn-upload {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 20px; background: #15803d; color: #fff;
      border-radius: 10px; font-weight: 600; font-size: 0.83rem;
      transition: background 0.2s, transform 0.15s; cursor: pointer;
      border: none;
    }
    .btn-upload:hover { background: #166534; transform: translateY(-1px); }
    .btn-remove {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 20px; background: #fee2e2; color: #dc2626;
      border-radius: 10px; font-weight: 600; font-size: 0.83rem;
      transition: background 0.2s; cursor: pointer; border: none;
    }
    .btn-remove:hover { background: #fecaca; }

    .submit-btn {
      display: flex; align-items: center; justify-content: center; gap: 9px;
      padding: 13px 32px; background: #15803d; color: #fff;
      border-radius: 12px; font-weight: 700; font-size: 0.95rem;
      transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
      border: none; cursor: pointer;
      box-shadow: 0 4px 14px rgba(21,128,61,0.25);
    }
    .submit-btn:hover { background: #166534; transform: translateY(-1px); box-shadow: 0 8px 20px rgba(21,128,61,0.3); }
    .submit-btn:disabled { background: #9ca3af; box-shadow: none; transform: none; cursor: not-allowed; }

    /* Back link */
    .back-link {
      display: inline-flex; align-items: center; gap: 6px;
      color: #15803d; font-weight: 600; font-size: 0.88rem;
      transition: gap 0.2s, color 0.2s; text-decoration: none;
    }
    .back-link:hover { color: #166534; gap: 10px; }

    /* Accepted types chip */
    .type-chip {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 3px 10px; border-radius: 999px;
      background: #dcfce7; color: #15803d;
      font-size: 0.72rem; font-weight: 700;
    }

    /* Fade-up animations */
    @keyframes fadeUp { from { opacity:0; transform:translateY(18px); } to { opacity:1; transform:translateY(0); } }
    .fade-1 { animation: fadeUp 0.5s ease both; }
    .fade-2 { animation: fadeUp 0.5s 0.1s ease both; }
    .fade-3 { animation: fadeUp 0.5s 0.2s ease both; }
    .fade-4 { animation: fadeUp 0.5s 0.3s ease both; }
  </style>
</head>
<body>

<!-- NAVBAR -->
<header class="w-full h-[68px] border-b border-green-100 flex items-center px-8 bg-white shadow-sm sticky top-0 z-50">
  <div class="flex items-center gap-3">
    <a href="../landing.php" class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-green-700 flex items-center justify-center shadow overflow-hidden">
        <img src="../assets/logo2.png" alt="Logo" class="w-full h-full object-contain" />
      </div>
      <div>
        <p class="font-bold text-green-900 text-base leading-tight">SumEste Portal</p>
        <p class="text-[10px] text-green-600 tracking-widest uppercase">Sumacab Este</p>
      </div>
    </a>
  </div>
  <nav class="ml-auto flex gap-8 text-gray-600 text-sm font-medium items-center">
    <a href="announcements.php" class="nav-link">Announcements</a>
    <a href="business.php" class="nav-link">Business</a>
    <a href="apartment.php" class="nav-link">Apartment</a>
    <a href="login.php" class="px-5 py-2 bg-green-700 hover:bg-green-800 text-white rounded-lg transition text-sm font-semibold shadow">Login / Register</a>
  </nav>
</header>

<main class="max-w-2xl mx-auto px-4 py-12">

  <!-- Title -->
  <div class="text-center mb-10 fade-1">
    <p class="text-xs font-semibold text-green-600 uppercase tracking-widest mb-2">Step 3 of 3</p>
    <h1 class="text-3xl font-bold text-green-950" style="font-family:'Playfair Display',serif;">SumEste Resident Registration</h1>
  </div>

  <!-- PROGRESS STEPS -->
  <div class="flex items-center mb-10 fade-2 px-2">
    <!-- Step 1 done -->
    <div class="flex flex-col items-center">
      <div class="step-circle step-done"><i class="fa-solid fa-check text-sm"></i></div>
      <p class="mt-2 text-xs font-semibold text-green-700 text-center whitespace-nowrap">Account Creation</p>
    </div>
    <div class="step-line line-done mx-3 mb-5"></div>
    <!-- Step 2 done -->
    <div class="flex flex-col items-center">
      <div class="step-circle step-done"><i class="fa-solid fa-check text-sm"></i></div>
      <p class="mt-2 text-xs font-semibold text-green-700 text-center whitespace-nowrap">Personal Information</p>
    </div>
    <div class="step-line line-done mx-3 mb-5"></div>
    <!-- Step 3 active -->
    <div class="flex flex-col items-center">
      <div class="step-circle step-active">3</div>
      <p class="mt-2 text-xs font-bold text-green-800 text-center whitespace-nowrap">Verification</p>
    </div>
  </div>

  <!-- Back link -->
  <div class="mb-6 fade-3">
    <a href="<?php echo htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8'); ?>" class="back-link">
      <i class="fa-solid fa-angle-left"></i>
      Personal Information
    </a>
  </div>

  <!-- FORM CARD -->
  <div class="form-card p-8 fade-4">

    <!-- Card header -->
    <div class="bg-gradient-to-r from-green-700 to-green-600 -mx-8 -mt-8 px-8 py-5 rounded-t-2xl mb-8">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center">
          <i class="fa-solid fa-id-card text-white text-lg"></i>
        </div>
        <div>
          <h2 class="text-white font-bold text-lg">Upload a Valid Government-Issued ID</h2>
          <p class="text-green-200 text-xs mt-0.5">Your ID must clearly show your name and address</p>
        </div>
      </div>
    </div>

    <form id="verificationForm" action="process_verification.php" method="post" enctype="multipart/form-data" class="flex flex-col gap-7">

      <!-- Accepted types note -->
      <div class="flex flex-wrap gap-2 items-center bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
        <i class="fa-solid fa-circle-info text-amber-500 text-sm"></i>
        <span class="text-amber-700 text-xs font-semibold">Accepted formats:</span>
        <span class="type-chip"><i class="fa-solid fa-image text-xs"></i> JPG</span>
        <span class="type-chip"><i class="fa-solid fa-image text-xs"></i> PNG</span>
        <span class="type-chip"><i class="fa-solid fa-file-pdf text-xs"></i> PDF</span>
        <span class="ml-auto text-amber-600 text-xs font-semibold">Max 5MB per file</span>
      </div>

      <!-- Error message (PHP-driven, shown when $status === 'error') -->
      <div id="errorBanner" class="hidden border border-red-200 bg-red-50 text-red-700 p-4 rounded-xl text-sm flex items-start gap-3">
        <i class="fa-solid fa-circle-exclamation text-red-500 mt-0.5"></i>
        <span id="errorText"></span>
      </div>

      <!-- FRONT SIDE -->
      <div>
        <div class="flex items-center gap-2 mb-3">
          <div class="w-7 h-7 rounded-lg bg-green-100 flex items-center justify-center">
            <i class="fa-solid fa-id-card text-green-700 text-xs"></i>
          </div>
          <label class="font-bold text-gray-800 text-sm">ID Front Side <span class="text-red-500">*</span></label>
        </div>

        <div class="upload-zone" id="frontZone"
             ondragover="dragOver(event,'frontZone')" ondragleave="dragLeave('frontZone')" ondrop="dropFile(event,'frontFile','frontZone','frontPreview','frontName')">
          <!-- Placeholder -->
          <div id="frontPlaceholder" class="flex flex-col items-center gap-3 py-8 px-4 text-center">
            <div class="w-14 h-14 rounded-2xl bg-green-100 flex items-center justify-center">
              <i class="fa-solid fa-cloud-arrow-up text-green-600 text-2xl"></i>
            </div>
            <div>
              <p class="font-semibold text-gray-700 text-sm">Drag & drop or click to upload</p>
              <p class="text-gray-400 text-xs mt-1">Front side of your government-issued ID</p>
            </div>
          </div>
          <!-- Preview (hidden until file chosen) -->
          <div id="frontPreview" class="absolute inset-0 hidden bg-cover bg-center rounded-[12px]"></div>
          <!-- File name pill (for PDF) -->
          <div id="frontName" class="absolute bottom-3 left-1/2 -translate-x-1/2 hidden bg-white/90 backdrop-blur-sm px-4 py-1.5 rounded-full shadow text-xs font-semibold text-green-800 max-w-[80%] truncate"></div>
          <!-- Click target -->
          <input type="file" id="frontFile" name="id_front" accept=".jpg,.jpeg,.png,.pdf" class="absolute inset-0 opacity-0 cursor-pointer" required
                 onchange="handleFile('frontFile','frontZone','frontPreview','frontName','frontPlaceholder','frontActions')">
        </div>

        <!-- Actions shown after upload -->
        <div id="frontActions" class="hidden flex gap-3 mt-3">
          <label for="frontFile" class="btn-upload"><i class="fa-solid fa-arrow-up-from-bracket text-xs"></i> Replace</label>
          <button type="button" class="btn-remove" onclick="removeFile('frontFile','frontZone','frontPreview','frontName','frontPlaceholder','frontActions')">
            <i class="fa-solid fa-trash text-xs"></i> Remove
          </button>
        </div>
      </div>

      <!-- BACK SIDE -->
      <div>
        <div class="flex items-center gap-2 mb-3">
          <div class="w-7 h-7 rounded-lg bg-green-100 flex items-center justify-center">
            <i class="fa-solid fa-id-card-clip text-green-700 text-xs"></i>
          </div>
          <label class="font-bold text-gray-800 text-sm">ID Back Side <span class="text-red-500">*</span></label>
        </div>

        <div class="upload-zone" id="backZone"
             ondragover="dragOver(event,'backZone')" ondragleave="dragLeave('backZone')" ondrop="dropFile(event,'backFile','backZone','backPreview','backName')">
          <div id="backPlaceholder" class="flex flex-col items-center gap-3 py-8 px-4 text-center">
            <div class="w-14 h-14 rounded-2xl bg-green-100 flex items-center justify-center">
              <i class="fa-solid fa-cloud-arrow-up text-green-600 text-2xl"></i>
            </div>
            <div>
              <p class="font-semibold text-gray-700 text-sm">Drag & drop or click to upload</p>
              <p class="text-gray-400 text-xs mt-1">Back side of your government-issued ID</p>
            </div>
          </div>
          <div id="backPreview" class="absolute inset-0 hidden bg-cover bg-center rounded-[12px]"></div>
          <div id="backName" class="absolute bottom-3 left-1/2 -translate-x-1/2 hidden bg-white/90 backdrop-blur-sm px-4 py-1.5 rounded-full shadow text-xs font-semibold text-green-800 max-w-[80%] truncate"></div>
          <input type="file" id="backFile" name="id_back" accept=".jpg,.jpeg,.png,.pdf" class="absolute inset-0 opacity-0 cursor-pointer" required
                 onchange="handleFile('backFile','backZone','backPreview','backName','backPlaceholder','backActions')">
        </div>

        <div id="backActions" class="hidden flex gap-3 mt-3">
          <label for="backFile" class="btn-upload"><i class="fa-solid fa-arrow-up-from-bracket text-xs"></i> Replace</label>
          <button type="button" class="btn-remove" onclick="removeFile('backFile','backZone','backPreview','backName','backPlaceholder','backActions')">
            <i class="fa-solid fa-trash text-xs"></i> Remove
          </button>
        </div>
      </div>

      <!-- Verify progress indicator -->
      <div id="verify-progress" class="hidden border border-green-200 bg-green-50 rounded-xl px-5 py-4 text-sm text-green-800">
        <div class="flex items-center gap-2 font-bold mb-1">
          <i class="fa-solid fa-spinner fa-spin text-green-600"></i>
          Verifying your ID...
        </div>
        <p class="text-green-600 text-xs">Please wait while we scan and match your ID details. This may take a moment.</p>
        <div class="mt-3 h-1.5 bg-green-200 rounded-full overflow-hidden">
          <div class="h-full bg-green-600 rounded-full animate-pulse" style="width:60%"></div>
        </div>
      </div>

      <!-- Submit row -->
      <div class="flex items-center justify-between pt-2 border-t border-gray-100">
        <p class="text-xs text-gray-400 flex items-center gap-1.5">
          <i class="fa-solid fa-lock text-green-400"></i>
          Your data is encrypted and secure
        </p>
        <button id="verifySubmitBtn" type="submit" class="submit-btn">
          Verify ID <i class="fa-solid fa-arrow-right text-sm"></i>
        </button>
      </div>

    </form>
  </div>

</main>

<!-- FOOTER -->
<footer class="mt-16 bg-green-950 text-white pt-14 pb-6 px-4">
  <div class="max-w-6xl mx-auto">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-10 pb-10 border-b border-green-800">
      <div>
        <div class="flex items-center gap-3 mb-4">
          <div class="w-12 h-12 rounded-xl bg-green-700 flex items-center justify-center"><i class="fa-solid fa-leaf text-white text-xl"></i></div>
          <div><h3 class="text-lg font-bold">SumEste Portal</h3><p class="text-green-400 text-xs tracking-widest uppercase">Sumacab Este</p></div>
        </div>
        <div class="space-y-2 text-sm text-green-300">
          <p><i class="fa-solid fa-location-dot mr-2 text-green-500"></i>Sumacab Este, Cabanatuan City</p>
          <p><i class="fa-solid fa-envelope mr-2 text-green-500"></i>barangaysumacabeste@gmail.com</p>
          <p><i class="fa-solid fa-phone mr-2 text-green-500"></i>0994-294-6442</p>
        </div>
        <a href="https://www.facebook.com/profile.php?id=61572407528959" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm transition"><i class="fab fa-facebook"></i> Facebook Page</a>
      </div>
      <div>
        <h4 class="font-semibold text-green-300 text-xs uppercase tracking-widest mb-4">Legal</h4>
        <div class="space-y-2">
          <a href="../infoSecurity/dataProtection.php" class="block text-sm text-green-400 hover:text-white transition">Privacy Policy</a>
          <a href="../infoSecurity/terms.php" class="block text-sm text-green-400 hover:text-white transition">Terms of Service</a>
          <a href="../infoSecurity/dataProtection.php" class="block text-sm text-green-400 hover:text-white transition">Data Protection Notice</a>
        </div>
      </div>
      <div>
        <h4 class="font-semibold text-green-300 text-xs uppercase tracking-widest mb-4">Quick Links</h4>
        <div class="space-y-2">
          <a href="#" class="block text-sm text-green-400 hover:text-white transition">Services</a>
          <a href="#" class="block text-sm text-green-400 hover:text-white transition">Apartments</a>
          <a href="#" class="block text-sm text-green-400 hover:text-white transition">Business Directory</a>
        </div>
      </div>
    </div>
    <div class="text-center mt-6 text-green-500 text-sm">© 2026 SumEste Portal. All Rights Reserved. Made with ❤️ for Sumacab Este.</div>
  </div>
</footer>

<script>
  /* --- File handling --- */
  function handleFile(inputId, zoneId, previewId, nameId, placeholderId, actionsId) {
    const file = document.getElementById(inputId).files[0];
    if (!file) return;
    const zone     = document.getElementById(zoneId);
    const preview  = document.getElementById(previewId);
    const namePill = document.getElementById(nameId);
    const ph       = document.getElementById(placeholderId);
    const actions  = document.getElementById(actionsId);

    zone.classList.add('has-file');
    ph.classList.add('hidden');
    actions.classList.remove('hidden');

    if (file.type.startsWith('image/')) {
      const reader = new FileReader();
      reader.onload = e => {
        preview.style.backgroundImage = `url(${e.target.result})`;
        preview.classList.remove('hidden');
        namePill.classList.add('hidden');
      };
      reader.readAsDataURL(file);
    } else {
      preview.classList.add('hidden');
      namePill.textContent = file.name;
      namePill.classList.remove('hidden');
    }
  }

  function removeFile(inputId, zoneId, previewId, nameId, placeholderId, actionsId) {
    document.getElementById(inputId).value = '';
    document.getElementById(zoneId).classList.remove('has-file');
    const preview = document.getElementById(previewId);
    preview.style.backgroundImage = '';
    preview.classList.add('hidden');
    document.getElementById(nameId).classList.add('hidden');
    document.getElementById(placeholderId).classList.remove('hidden');
    document.getElementById(actionsId).classList.add('hidden');
  }

  /* --- Drag & drop --- */
  function dragOver(e, zoneId) {
    e.preventDefault();
    document.getElementById(zoneId).classList.add('drag-over');
  }
  function dragLeave(zoneId) {
    document.getElementById(zoneId).classList.remove('drag-over');
  }
  function dropFile(e, inputId, zoneId, previewId, nameId) {
    e.preventDefault();
    document.getElementById(zoneId).classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const input = document.getElementById(inputId);
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    const actionsId = inputId === 'frontFile' ? 'frontActions' : 'backActions';
    const placeholderId = inputId === 'frontFile' ? 'frontPlaceholder' : 'backPlaceholder';
    handleFile(inputId, zoneId, previewId, nameId, placeholderId, actionsId);
  }

  /* --- Submit --- */
  const form = document.getElementById('verificationForm');
  const submitBtn = document.getElementById('verifySubmitBtn');
  const progress  = document.getElementById('verify-progress');

  form.addEventListener('submit', function(e) {
    if (!form.checkValidity()) return;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
    progress.classList.remove('hidden');
  });
</script>

<script>
  (function () {
    document.querySelectorAll("a[href]").forEach(function (link) {
      const href = link.getAttribute("href");
      if (!href || link.hasAttribute("data-nav")) return;
      const lower = href.toLowerCase();
      if (href.startsWith("#") || lower.startsWith("javascript:") || lower.startsWith("mailto:") || lower.startsWith("tel:")) return;
      link.setAttribute("data-nav", href);
      link.setAttribute("href", "javascript:void(0)");
      link.addEventListener("click", function (e) {
        e.preventDefault();
        const target = link.getAttribute("data-nav");
        if (!target) return;
        if (link.getAttribute("target") === "_blank") {
          window.open(target, "_blank", "noopener");
        } else {
          window.location.href = target;
        }
      });
    });
  })();
</script>

</body>
</html>